<?php
// ago-icon.php — AGO SYSTEM MANAGER ホーム画面アイコン（GD生成）
$s = max(48, min(512, (int)($_GET['s'] ?? 192)));

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');

$img    = imagecreatetruecolor($s, $s);

// #0f172a (濃いネイビー)
$bg     = imagecolorallocate($img, 15, 23, 42);
// #6366f1 (インディゴ)
$indigo = imagecolorallocate($img, 99, 102, 241);
// #818cf8 (ライトインディゴ)
$light  = imagecolorallocate($img, 129, 140, 248);
$white  = imagecolorallocate($img, 255, 255, 255);

// 背景を角丸っぽく（全体塗り）
imagefill($img, 0, 0, $bg);

// アイコン本体：インディゴの丸
$cx = (int)($s / 2);
$cy = (int)($s / 2);
$cr = (int)($s * 0.38);
imagefilledellipse($img, $cx, $cy, $cr * 2, $cr * 2, $indigo);

// 「A」テキスト（GD組み込みフォント）
$font     = 5;
$fw       = imagefontwidth($font);
$fh       = imagefontheight($font);
$text     = 'A';
$scale    = max(2, (int)($s / 48));
$total_w  = $fw * strlen($text) * $scale;
$tx       = (int)(($s - $total_w) / 2);
$ty       = (int)(($s - $fh * $scale) / 2);

$tmp      = imagecreatetruecolor($fw * strlen($text), $fh);
$tmp_bg   = imagecolorallocate($tmp, 99, 102, 241);
$tmp_wh   = imagecolorallocate($tmp, 255, 255, 255);
imagefill($tmp, 0, 0, $tmp_bg);
imagestring($tmp, $font, 0, 0, $text, $tmp_wh);
imagecopyresized($img, $tmp, $tx, $ty, 0, 0,
    $fw * strlen($text) * $scale, $fh * $scale,
    $fw * strlen($text), $fh);
imagedestroy($tmp);

imagepng($img);
imagedestroy($img);
