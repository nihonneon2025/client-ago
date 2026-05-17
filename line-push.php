<?php
// デスクトップデーモン専用 LINE プッシュ送信エンドポイント
// X-AGO-Token ヘッダーで認証

$token_header = $_SERVER['HTTP_X_AGO_TOKEN'] ?? '';
if ($token_header !== 'system002-od') {
    http_response_code(403);
    exit;
}

$config_file = __DIR__ . '/api-config.php';
if (!file_exists($config_file)) {
    http_response_code(500);
    echo json_encode(['error' => 'config not found']);
    exit;
}
require $config_file;
$line_token = defined('LINE_CHANNEL_TOKEN') ? LINE_CHANNEL_TOKEN : '';
if (!$line_token) {
    http_response_code(500);
    echo json_encode(['error' => 'LINE_CHANNEL_TOKEN not set']);
    exit;
}

$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$to      = $input['to']      ?? '';
$message = $input['message'] ?? '';

if (!$to || !$message) {
    http_response_code(400);
    echo json_encode(['error' => 'to and message required']);
    exit;
}

$payload = json_encode([
    'to'       => $to,
    'messages' => [['type' => 'text', 'text' => mb_substr($message, 0, 5000)]]
]);

$ch = curl_init('https://api.line.me/v2/bot/message/push');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $line_token,
    ],
    CURLOPT_TIMEOUT => 10,
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

@file_put_contents(__DIR__ . '/line_push_debug.log',
    date('Y-m-d H:i:s') . " to=$to code=$code body=" . mb_substr($res ?? '', 0, 300) . "\n",
    FILE_APPEND | LOCK_EX);

http_response_code($code);
echo $res;
