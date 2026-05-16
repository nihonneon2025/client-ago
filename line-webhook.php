<?php
header('Content-Type: application/json; charset=utf-8');

// ===== 設定（AGO訪問時に入力する) =====
$LINE_CHANNEL_SECRET = '';  // ← LINEチャンネルシークレット
$LINE_CHANNEL_TOKEN  = '';  // ← LINEチャンネルアクセストークン
// ======================================

// APIキーはapi-config.phpから取得
$api_key = '';
$config_file = __DIR__ . '/api-config.php';
if (file_exists($config_file)) {
    require $config_file;
    $api_key = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
}

// 許可ユーザーIDはKVストアから動的に読み込む
$allowed_ids = [];
$allowed_raw = ago_kv_get('ago_line_allowed_users');
if ($allowed_raw) {
    $allowed_ids = json_decode($allowed_raw, true) ?: [];
}

$body = file_get_contents('php://input');
$data = json_decode($body, true);

// 署名検証（チャンネルシークレットが設定済みの場合）
if ($LINE_CHANNEL_SECRET) {
    $signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';
    $expected  = base64_encode(hash_hmac('sha256', $body, $LINE_CHANNEL_SECRET, true));
    if (!hash_equals($expected, $signature)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

// イベント処理
$events = $data['events'] ?? [];
foreach ($events as $event) {
    if ($event['type'] !== 'message') continue;
    if ($event['message']['type'] !== 'text') continue;

    $userId     = $event['source']['userId'] ?? '';
    $text       = trim($event['message']['text'] ?? '');
    $groupId    = $event['source']['groupId'] ?? null;
    $source     = $groupId ? 'group' : 'direct';
    $replyToken = $event['replyToken'] ?? null;

    if (empty($text) || empty($userId)) continue;

    // 許可ユーザーチェック（リストが空=未設定の間は全員受信）
    $allowed = empty($allowed_ids) || in_array($userId, $allowed_ids);

    // 受信ログに記録
    $log_entry = [
        'id'          => date('YmdHis') . '_' . substr($userId, -6),
        'ts'          => date('Y-m-d H:i:s'),
        'userId'      => $userId,
        'source'      => $source,
        'text'        => $text,
        'status'      => $allowed ? 'received' : 'blocked',
        'project_id'  => null,
        'error'       => null,
        'reply_token' => $replyToken
    ];
    ago_log_save($log_entry);

    if (!$allowed) {
        // ブロックされたユーザーには何も返さない
        continue;
    }

    // APIキーがあればAI処理
    if ($api_key) {
        require_once __DIR__ . '/line-handler.php';
        processLineMessage($log_entry, $api_key, $LINE_CHANNEL_TOKEN);
    } else {
        // APIキー未設定の場合は受信確認だけ返す（トークンがあれば）
        if ($LINE_CHANNEL_TOKEN && $replyToken) {
            line_reply($LINE_CHANNEL_TOKEN, $replyToken, 'メッセージを受信しました。APIキーが未設定のためAI処理はできません。');
        }
    }
}

echo json_encode(['status' => 'ok']);

// ── LINE返信 ────────────────────────────────────────────────────

function line_reply($token, $replyToken, $message) {
    if (empty($token) || empty($replyToken)) return;
    $payload = [
        'replyToken' => $replyToken,
        'messages'   => [['type' => 'text', 'text' => $message]]
    ];
    $ch = curl_init('https://api.line.me/v2/bot/message/reply');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_TIMEOUT => 10
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ── KVストアヘルパー ──────────────────────────────────────────────

function ago_kv_get($key) {
    $base = 'https://' . $_SERVER['HTTP_HOST'];
    $res  = @file_get_contents($base . '/api.php', false, stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nX-AGO-Token: system002-od\r\n",
            'content' => json_encode(['action' => 'kv_get', 'key' => $key]),
            'timeout' => 10
        ]
    ]));
    if (!$res) return null;
    $data = json_decode($res, true);
    return $data['value'] ?? null;
}

function ago_kv_set($key, $value) {
    $base = 'https://' . $_SERVER['HTTP_HOST'];
    @file_get_contents($base . '/api.php', false, stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nX-AGO-Token: system002-od\r\n",
            'content' => json_encode(['action' => 'kv_set', 'key' => $key, 'value' => $value]),
            'timeout' => 10
        ]
    ]));
}

function ago_log_save($new_entry) {
    $raw  = ago_kv_get('ago_line_logs');
    $logs = json_decode($raw ?? '[]', true) ?: [];
    array_unshift($logs, $new_entry);
    if (count($logs) > 200) $logs = array_slice($logs, 0, 200);
    ago_kv_set('ago_line_logs', json_encode($logs, JSON_UNESCAPED_UNICODE));
}
