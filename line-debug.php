<?php
// line-debug.php — 一時的な診断用ファイル（確認後に削除する）
header('Content-Type: text/plain; charset=utf-8');

// 1. api-config.php の読み込み確認
$config_file = __DIR__ . '/api-config.php';
if (!file_exists($config_file)) {
    echo "[NG] api-config.php が見つからない\n";
    exit;
}
require $config_file;

$api_key = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
$token   = defined('LINE_CHANNEL_TOKEN') ? LINE_CHANNEL_TOKEN : '';
echo '[OK] api-config.php 読み込み済み' . "\n";
echo '     APIキー先頭: ' . substr($api_key, 0, 20) . '...' . "\n";
echo '     LINEトークン先頭: ' . substr($token, 0, 10) . '...' . "\n\n";

// 2. curl が使えるか
if (!function_exists('curl_init')) {
    echo "[NG] curl が使えない（PHP拡張が未有効）\n";
    exit;
}
echo "[OK] curl 使用可能\n\n";

// 3. Anthropic API テスト呼び出し
echo "--- Anthropic API テスト ---\n";
$payload = [
    'model'      => 'claude-haiku-4-5-20251001',
    'max_tokens' => 50,
    'messages'   => [['role' => 'user', 'content' => 'テスト。「接続OK」とだけ返して。']],
];
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'x-api-key: ' . $api_key,
        'anthropic-version: 2023-06-01',
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$res = curl_exec($ch);
$err = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) {
    echo "[NG] curl エラー: {$err}\n";
} else {
    echo "[OK] HTTPステータス: {$code}\n";
    $data = json_decode($res, true);
    if (isset($data['content'][0]['text'])) {
        echo "[OK] AIの返答: " . $data['content'][0]['text'] . "\n";
    } elseif (isset($data['error'])) {
        echo "[NG] APIエラー: " . ($data['error']['message'] ?? $res) . "\n";
    } else {
        echo "生レスポンス: {$res}\n";
    }
}

echo "\n--- 完了 ---\n";
echo "（確認後 line-debug.php は削除してください）\n";
