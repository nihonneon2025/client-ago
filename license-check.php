<?php
// license-check.php — ライセンス確認エンドポイント
// デーモン起動時・定期的に呼ばれる
// 有効: 200 {"active":true}  /  無効: 402 {"active":false, "reason":"..."}

header('Content-Type: application/json; charset=utf-8');

$token = $_GET['token'] ?? '';

// ── ライセンステーブル ────────────────────────────────────────────
// active を false にすればそのクライアントのデーモンが止まる
// expires: null=無期限 / "2027-01-01"=期限あり
$licenses = [
    'system002-od' => [
        'name'    => 'AGO グループ',
        'active'  => true,
        'expires' => null,
    ],
];
// ─────────────────────────────────────────────────────────────────

if (empty($token) || !isset($licenses[$token])) {
    http_response_code(402);
    echo json_encode(['active' => false, 'reason' => 'unknown token'], JSON_UNESCAPED_UNICODE);
    exit;
}

$lic = $licenses[$token];

if (empty($lic['active'])) {
    http_response_code(402);
    echo json_encode(['active' => false, 'reason' => 'license suspended'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($lic['expires']) && strtotime($lic['expires']) < time()) {
    http_response_code(402);
    echo json_encode(['active' => false, 'reason' => 'license expired'], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(200);
echo json_encode(['active' => true, 'name' => $lic['name']], JSON_UNESCAPED_UNICODE);
