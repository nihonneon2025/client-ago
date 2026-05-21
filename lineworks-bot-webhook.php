<?php
/**
 * lineworks-bot-webhook.php
 * LINE WORKS Bot Webhook → Claude API → Bot返信（PHP版・Xサーバー用）
 *
 * 設置場所: Xサーバーのドキュメントルート配下
 * 設定:    同ディレクトリに lineworks-bot-config.php を置く（GitHub不可）
 * Callback URL: https://ドメイン/lineworks-bot-webhook.php
 */

// ── 設定読み込み ──────────────────────────────────────────────────
// lineworks-bot-config.php を同ディレクトリに設置（GitHubにはコミットしない）
$configFile = __DIR__ . '/lineworks-bot-config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    exit('config missing');
}
require $configFile;
// $CONFIG['client_id'], $CONFIG['client_secret'], $CONFIG['service_account_id'],
// $CONFIG['bot_id'], $CONFIG['bot_secret'], $CONFIG['anthropic_api_key'],
// $CONFIG['private_key'] (RSA秘密鍵の文字列)

define('TRIGGER', 'ウルバン');
define('LOG_FILE', __DIR__ . '/lineworks-bot.log');

// ── ログ ─────────────────────────────────────────────────────────
function wlog($msg) {
    $line = date('Y-m-d H:i:s') . ' ' . $msg . "\n";
    file_put_contents(LOG_FILE, $line, FILE_APPEND);
}

// ── 署名検証 ──────────────────────────────────────────────────────
function verifySignature($body, $signature, $botSecret) {
    $expected = base64_encode(hash_hmac('sha256', $body, $botSecret, true));
    return hash_equals($expected, $signature);
}

// ── JWT生成（RS256） ──────────────────────────────────────────────
function makeJWT($clientId, $serviceAccountId, $privateKey) {
    $now = time();
    $header  = base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = base64UrlEncode(json_encode([
        'iss' => $clientId,
        'sub' => $serviceAccountId,
        'iat' => $now,
        'exp' => $now + 1800,
    ]));
    $data = $header . '.' . $payload;
    openssl_sign($data, $sig, $privateKey, OPENSSL_ALGO_SHA256);
    return $data . '.' . base64UrlEncode($sig);
}

function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// ── LINE WORKS アクセストークン取得 ───────────────────────────────
function getAccessToken($cfg) {
    $jwt = makeJWT($cfg['client_id'], $cfg['service_account_id'], $cfg['private_key']);
    $resp = httpPost('https://auth.worksmobile.com/oauth2/v2.0/token',
        http_build_query([
            'grant_type'    => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'     => $jwt,
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'scope'         => 'bot',
        ]),
        ['Content-Type: application/x-www-form-urlencoded']
    );
    $data = json_decode($resp, true);
    return $data['access_token'] ?? null;
}

// ── LINE WORKS メッセージ送信 ────────────────────────────────────
function sendMessage($cfg, $token, $channelId, $userId, $text) {
    $text = mb_substr($text, 0, 4000);
    $body = json_encode(['content' => ['type' => 'text', 'text' => $text]]);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ];
    if ($channelId) {
        $url = "https://www.worksapis.com/v1.0/bots/{$cfg['bot_id']}/channels/{$channelId}/messages";
    } else {
        $url = "https://www.worksapis.com/v1.0/bots/{$cfg['bot_id']}/users/{$userId}/messages";
    }
    httpPost($url, $body, $headers);
    wlog('[SENT] to=' . ($channelId ? 'ch:'.$channelId : 'user:'.$userId));
}

// ── Claude API呼び出し ────────────────────────────────────────────
function callClaude($userMessage, $apiKey) {
    $body = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 1024,
        'system'     => 'あなたはAGOグループのAIアシスタント「ウルバン」です。LINE WORKSのグループチャットで質問や業務指示を受け付けます。日本語で、簡潔かつ丁寧に回答してください。',
        'messages'   => [['role' => 'user', 'content' => $userMessage]],
    ]);
    $resp = httpPost('https://api.anthropic.com/v1/messages', $body, [
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
        'Content-Type: application/json',
    ]);
    $data = json_decode($resp, true);
    return $data['content'][0]['text'] ?? '（応答なし）';
}

// ── HTTP POST共通関数 ─────────────────────────────────────────────
function httpPost($url, $body, $headers = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp;
}

// ── メイン処理 ────────────────────────────────────────────────────
$rawBody = file_get_contents('php://input');

// 署名検証
if (!empty($CONFIG['bot_secret'])) {
    $sig = $_SERVER['HTTP_X_WORKS_SIGNATURE'] ?? '';
    if (!verifySignature($rawBody, $sig, $CONFIG['bot_secret'])) {
        wlog('[WARN] 署名検証失敗');
        http_response_code(401);
        exit(json_encode(['error' => 'invalid signature']));
    }
}

$data   = json_decode($rawBody, true) ?? [];
$events = isset($data[0]) ? $data : [$data];

// 即座に200を返す（Webhookタイムアウト対策）
http_response_code(200);
header('Content-Type: application/json');
header('Content-Length: 2');
echo '{}';

// バッファをフラッシュして接続を閉じる
if (ob_get_level()) ob_end_flush();
flush();

// 接続を閉じた後も処理を継続
ignore_user_abort(true);
set_time_limit(120);

foreach ($events as $event) {
    if (($event['type'] ?? '') !== 'message') continue;
    $content = $event['content'] ?? [];
    if (($content['type'] ?? '') !== 'text') continue;

    $text = trim($content['text'] ?? '');
    wlog('[RECV] ' . mb_substr($text, 0, 60));

    if (strpos($text, TRIGGER) === false) {
        wlog('[SKIP] トリガーワードなし');
        continue;
    }

    $source    = $event['source'] ?? [];
    $channelId = $source['channelId'] ?? null;
    $userId    = $source['userId'] ?? null;

    wlog('[TASK] channel=' . $channelId . ' user=' . $userId);

    try {
        $token = getAccessToken($CONFIG);
        if (!$token) { wlog('[ERR] トークン取得失敗'); continue; }

        sendMessage($CONFIG, $token, $channelId, $userId, '処理中です。少々お待ちください...');

        wlog('[CLAUDE] 開始');
        $result = callClaude($text, $CONFIG['anthropic_api_key']);
        wlog('[CLAUDE] 完了 len=' . mb_strlen($result));

        sendMessage($CONFIG, $token, $channelId, $userId, '✅ ' . $result);
    } catch (Exception $e) {
        wlog('[ERR] ' . $e->getMessage());
    }
}
