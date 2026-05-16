<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// APIキーは api-config.php から取得（Gitには上げない・FTPで直接設置）
$config_file = __DIR__ . '/api-config.php';
if (!file_exists($config_file)) {
    echo json_encode(['reply' => 'APIキー設定ファイルが見つかりません。管理者に連絡してください。']);
    exit;
}
require $config_file;
$api_key = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
if (!$api_key) {
    echo json_encode(['reply' => 'APIキーが設定されていません。管理者に連絡してください。']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$context  = isset($body['context'])   ? trim($body['context'])   : '';
$proj_info = isset($body['proj_info']) ? trim($body['proj_info']) : '';
$message  = isset($body['message'])   ? trim($body['message'])   : '';

if (!$message) {
    echo json_encode(['reply' => 'メッセージが空です。']);
    exit;
}

$system_prompt = $context;
if ($proj_info) {
    $system_prompt .= "\n\n【対象案件情報】\n" . $proj_info;
}

$payload = [
    'model'      => 'claude-haiku-4-5-20251001',
    'max_tokens' => 1024,
    'system'     => $system_prompt,
    'messages'   => [
        ['role' => 'user', 'content' => $message]
    ]
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'x-api-key: ' . $api_key,
        'anthropic-version: 2023-06-01',
        'Content-Type: application/json'
    ],
    CURLOPT_TIMEOUT => 30
]);

$result = curl_exec($ch);
$err    = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(['reply' => 'AI接続エラー: ' . $err]);
    exit;
}

$data = json_decode($result, true);
$reply = isset($data['content'][0]['text']) ? $data['content'][0]['text'] : 'AIからの応答を取得できませんでした。';

echo json_encode(['reply' => $reply], JSON_UNESCAPED_UNICODE);
