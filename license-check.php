<?php
// license-check.php — ELVIN VPS ライセンス確認ゲート
// index.html の起動時に呼ばれる。VPS の client status を確認して返す。

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── 設定 ──────────────────────────────────────────────────────────────────
define('ELVIN_VPS_URL', 'https://api.nihon-neon.jp');
define('ELVIN_CLIENT_TOKEN', '4507171597d749daa3dd6d1d118122d3');
define('ELVIN_TIMEOUT', 5);

// ── VPS に確認（curl使用） ────────────────────────────────────────────────
$url = ELVIN_VPS_URL . '/api/v1/license/check';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => ELVIN_TIMEOUT,
    CURLOPT_HTTPHEADER     => ['X-Client-Token: ' . ELVIN_CLIENT_TOKEN],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$body = curl_exec($ch);
$err  = curl_errno($ch);
curl_close($ch);

// VPS無応答 → フェールオープン（業務を止めない）
if ($body === false || $err !== 0) {
    http_response_code(200);
    echo json_encode(['active' => true, 'reason' => 'vps_unreachable'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data   = json_decode($body, true);
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
