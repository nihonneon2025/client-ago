<?php
header('Content-Type: application/json; charset=utf-8');

// ファイルベースのデバッグログ（api.php不要・サーバー上のwh_debug.logに書き込む）
function wh_log($msg) {
    $line = date('Y-m-d H:i:s') . ' ' . $msg . "\n";
    @file_put_contents(__DIR__ . '/wh_debug.log', $line, FILE_APPEND | LOCK_EX);
}

wh_log('[START] webhook called');

// APIキー・LINEトークンはapi-config.phpから取得
$api_key             = '';
$LINE_CHANNEL_SECRET = '';
$LINE_CHANNEL_TOKEN  = '';
$config_file = __DIR__ . '/api-config.php';
if (file_exists($config_file)) {
    require $config_file;
    $api_key             = defined('ANTHROPIC_API_KEY')   ? ANTHROPIC_API_KEY   : '';
    $LINE_CHANNEL_SECRET = defined('LINE_CHANNEL_SECRET') ? LINE_CHANNEL_SECRET : '';
    $LINE_CHANNEL_TOKEN  = defined('LINE_CHANNEL_TOKEN')  ? LINE_CHANNEL_TOKEN  : '';
    wh_log('[OK] api-config.php loaded. api_key=' . (strlen($api_key) > 0 ? 'SET' : 'EMPTY') . ' token=' . (strlen($LINE_CHANNEL_TOKEN) > 0 ? 'SET' : 'EMPTY'));
} else {
    wh_log('[NG] api-config.php NOT FOUND');
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
wh_log('[INFO] body_len=' . strlen($body) . ' events=' . count($data['events'] ?? []));
if ($LINE_CHANNEL_SECRET) {
    $signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';
    $expected  = base64_encode(hash_hmac('sha256', $body, $LINE_CHANNEL_SECRET, true));
    if (!hash_equals($expected, $signature)) {
        wh_log('[NG] signature mismatch – returning 403');
        http_response_code(403);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
    wh_log('[OK] signature verified');
}

// イベント処理
$events = $data['events'] ?? [];
foreach ($events as $event) {
    if ($event['type'] !== 'message') continue;

    // ファイル・画像メッセージ: ダウンロードしてキャッシュ（返信なし）
    if (in_array($event['message']['type'], ['file', 'image']) && $LINE_CHANNEL_TOKEN) {
        $fid   = $event['message']['id'];
        $fname = $event['message']['fileName'] ?? ($event['message']['type'] . '_' . $fid);
        $url   = save_line_file($fid, $LINE_CHANNEL_TOKEN, $fname);
        if ($url) {
            $msg_cache = json_decode(ago_kv_get('ago_line_msg_cache') ?? '{}', true) ?: [];
            $msg_cache[$fid] = ['type' => $event['message']['type'], 'url' => $url, 'filename' => $fname];
            if (count($msg_cache) > 200) $msg_cache = array_slice($msg_cache, -200, null, true);
            ago_kv_set('ago_line_msg_cache', json_encode($msg_cache, JSON_UNESCAPED_UNICODE));
            wh_log('[FILE] saved id=' . $fid . ' url=' . $url);
        } else {
            wh_log('[FILE] download failed id=' . $fid);
        }
    }
    if ($event['message']['type'] !== 'text') continue;

    $userId     = $event['source']['userId'] ?? '';
    $text       = trim($event['message']['text'] ?? '');
    $groupId    = $event['source']['groupId'] ?? null;
    $source     = $groupId ? 'group' : 'direct';
    $replyToken = $event['replyToken'] ?? null;

    // リプライ（引用）メッセージの内容を取得
    $quoted_text = null;
    if (!empty($event['message']['quotedMessageId'])) {
        $quoted_id = $event['message']['quotedMessageId'];
        $q = $event['message']['quote'] ?? [];

        // ① テキスト引用
        if (!empty($q['text'])) {
            $quoted_text = $q['text'];
        }

        // ② ファイル・画像・動画の引用 → その場で即ダウンロード
        if (!$quoted_text && !empty($q['type']) && in_array($q['type'], ['file', 'image', 'video']) && $LINE_CHANNEL_TOKEN) {
            $qtype = $q['type'];
            $fname = $q['fileName'] ?? ($qtype === 'image' ? $quoted_id . '.jpg' : $quoted_id . '.bin');
            $url   = save_line_file($quoted_id, $LINE_CHANNEL_TOKEN, $fname);
            if ($url) {
                $quoted_text = '[ファイル:' . $fname . ' URL:' . $url . ']';
                wh_log('[QUOTE_DL] type=' . $qtype . ' url=' . $url);
            } else {
                $quoted_text = '[ファイル:' . $fname . ' (ダウンロード失敗)]';
                wh_log('[QUOTE_DL] FAIL type=' . $qtype . ' id=' . $quoted_id);
            }
        }

        // ③ キャッシュから照合（フォールバック）
        if (!$quoted_text) {
            $msg_cache = json_decode(ago_kv_get('ago_line_msg_cache') ?? '{}', true) ?: [];
            $cached = $msg_cache[$quoted_id] ?? null;
            if (is_array($cached) && isset($cached['url'])) {
                $quoted_text = '[ファイル:' . ($cached['filename'] ?? 'file') . ' URL:' . $cached['url'] . ']';
            } elseif (is_string($cached)) {
                $quoted_text = $cached;
            }
        }

        wh_log('[QUOTE] id=' . $quoted_id . ' q_type=' . ($q['type'] ?? 'none') . ' result=' . mb_substr($quoted_text ?? '(none)', 0, 60));
    }

    if (empty($text) || empty($userId)) continue;

    // 完了タスクの保留通知があれば即リプライで伝える
    $notify_key = 'ago_pending_notify_' . ($groupId ?? $userId);
    $pending_notify = ago_kv_get($notify_key);
    if ($pending_notify && $replyToken && $LINE_CHANNEL_TOKEN) {
        line_reply($LINE_CHANNEL_TOKEN, $replyToken, $pending_notify);
        ago_kv_set($notify_key, '');
        wh_log('[NOTIFY] delivered pending result to ' . ($groupId ?? $userId));
    }

    // グループチャットの場合：トリガーワードがなければ無視
    if ($groupId) {
        $trigger = '/^(ウルバン|urvan|URVAN|urban|URBAN)[\s、,　。]/ui';
        if (!preg_match($trigger, $text)) continue;
        // トリガーワードを除いた本文だけ処理する
        $text = trim(preg_replace($trigger, '', $text, 1));
        if (empty($text)) continue;
        wh_log('[GROUP] trigger matched userId=' . $userId . ' text=' . mb_substr($text, 0, 30));
    }

    // 許可ユーザーチェック（リストが空=未設定の間は全員受信）
    $allowed = empty($allowed_ids) || in_array($userId, $allowed_ids);

    // 受信ログに記録
    $log_entry = [
        'id'          => date('YmdHis') . '_' . substr($userId, -6),
        'ts'          => date('Y-m-d H:i:s'),
        'userId'      => $userId,
        'groupId'     => $groupId,
        'source'      => $source,
        'text'        => $text,
        'quoted_text' => $quoted_text,
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
        wh_log('[INFO] calling AI for userId=' . $userId . ' text=' . mb_substr($text, 0, 30));
        require_once __DIR__ . '/line-handler.php';
        processLineMessage($log_entry, $api_key, $LINE_CHANNEL_TOKEN);
        wh_log('[DONE] processLineMessage returned');
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
    if (empty($token) || empty($replyToken)) {
        wh_log('[line_reply] skipped: token=' . (empty($token) ? 'EMPTY' : 'SET') . ' replyToken=' . (empty($replyToken) ? 'EMPTY' : 'SET'));
        return;
    }
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
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    wh_log('[line_reply] status=' . $code . ' err=' . ($err ?: 'none') . ' body=' . mb_substr($res, 0, 200));
    return json_decode($res, true) ?: [];
}

// ── KVストアヘルパー（api.phpはkv_getAll/kv_setのみ対応） ────────

function _kv_base_url() {
    return 'https://' . $_SERVER['HTTP_HOST'];
}

function _kv_fetch_all() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $ch = curl_init(_kv_base_url() . '/api.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['action' => 'kv_getAll']),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-AGO-Token: system002-od'],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || !$res) {
        wh_log('[kv_getAll err] ' . $err);
        $cache = [];
        return $cache;
    }
    $data  = json_decode($res, true);
    $cache = $data['data'] ?? [];
    wh_log('[kv_getAll] ' . count($cache) . ' keys loaded');
    return $cache;
}

function ago_kv_get($key) {
    $all = _kv_fetch_all();
    return $all[$key] ?? null;
}

function ago_kv_set($key, $value) {
    $ch = curl_init(_kv_base_url() . '/api.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['action' => 'kv_set', 'key' => $key, 'value' => $value]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-AGO-Token: system002-od'],
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// LINE Content API からファイルをダウンロードしてサーバーに保存
function save_line_file($message_id, $token, $filename) {
    $ch = curl_init('https://api-data.line.me/v2/bot/message/' . $message_id . '/content');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $content = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err     = curl_error($ch);
    curl_close($ch);
    wh_log('[save_line_file] id=' . $message_id . ' code=' . $code . ' err=' . ($err ?: 'none') . ' size=' . strlen($content ?? ''));
    if ($code !== 200 || !$content) return null;
    $dir = __DIR__ . '/uploads/line_files/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $safe   = preg_replace('/[^a-zA-Z0-9._\-]/u', '_', $filename);
    $unique = date('YmdHis') . '_' . substr($message_id, -8) . '_' . $safe;
    if (file_put_contents($dir . $unique, $content) === false) return null;
    return 'https://' . $_SERVER['HTTP_HOST'] . '/uploads/line_files/' . $unique;
}

function ago_log_save($new_entry) {
    $raw  = ago_kv_get('ago_line_logs');
    wh_log('[ago_log_save] raw=' . ($raw === null ? 'NULL' : mb_substr($raw, 0, 60)));
    $logs = json_decode($raw ?? '[]', true) ?: [];
    array_unshift($logs, $new_entry);
    if (count($logs) > 200) $logs = array_slice($logs, 0, 200);
    $encoded = json_encode($logs, JSON_UNESCAPED_UNICODE);
    wh_log('[ago_log_save] saving count=' . count($logs) . ' encoded_len=' . strlen($encoded));
    ago_kv_set('ago_line_logs', $encoded);
    wh_log('[ago_log_save] kv_set done');
}
