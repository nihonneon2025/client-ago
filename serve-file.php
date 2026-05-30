<?php
// serve-file.php — uploads/line_files/からBasic認証なしで画像・ファイルを配信
// .htaccessで「Require all granted」が必要（line-webhook.phpと同様）
$f = $_GET['f'] ?? '';
// ファイル名の安全確認（パストラバーサル防止）
if (!$f || !preg_match('/^[a-zA-Z0-9_.\-]+$/', $f)) {
    http_response_code(400);
    echo 'invalid';
    exit;
}
$path = __DIR__ . '/uploads/line_files/' . $f;
if (!file_exists($path)) {
    http_response_code(404);
    echo 'not found';
    exit;
}
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime_map = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
    'bin'  => 'application/octet-stream',
];
$mime = $mime_map[$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
readfile($path);
