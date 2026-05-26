<?php
// license-check.php — ELVIN VPS ライセンス確認ゲート
// index.html の起動時に呼ばれる。VPS の client status を確認して返す。

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── 設定 ──────────────────────────────────────────────────────────────────
define('ELVIN_VPS_URL', 'https://api.nihon-neon.jp');
define('ELVIN_CLIENT_TOKEN', '4507171597d749daa3dd6d1d118122d3');
define('ELVIN_TIMEOUT', 5); // VPS応答タイムアウト秒

// ── VPS に確認 ─────────────────────────────────────────────────────────────
$ctx = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'header'  => 'X-Client-Token: ' . ELVIN_CLIENT_TOKEN,
        'timeout' => ELVIN_TIMEOUT,
    ],
    'ssl' => [
        'verify_peer'      => true,
        'verify_peer_name' => true,
    ],
]);

$url  = ELVIN_VPS_URL . '/api/v1/license/check';
$body = @file_get_contents($url, false, $ctx);

// VPS無応答 → フェールオープン（業務を止めない）
if ($body === false) {
    http_response_code(200);
    echo json_encode(['active' => true, 'reason' => 'vps_unreachable'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($body, true);
$active = $data['active'] ?? false;

if (!$active) {
    http_response_code(402);
    echo json_encode([
        'active' => false,
        'reason' => $data['reason'] ?? 'suspended',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(200);
echo json_encode(['active' => true, 'name' => $data['name'] ?? ''], JSON_UNESCAPED_UNICODE);
