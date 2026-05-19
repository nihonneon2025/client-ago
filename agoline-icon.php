<?php
// agoline-icon.php — AGOLINE ホーム画面アイコン（GD生成）
$s = max(48, min(512, (int)($_GET['s'] ?? 192)));

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');

$img   = imagecreatetruecolor($s, $s);
$green = imagecolorallocate($img, 6, 199, 85);   // #06C755
$white = imagecolorallocate($img, 255, 255, 255);
$dark  = imagecolorallocate($img, 3, 140, 60);   // 文字用・濃い緑

// 背景を緑で塗りつぶし
imagefill($img, 0, 0, $green);

// ── 吹き出し本体（白い角丸矩形）──
$bx  = (int)($s * 0.12);  // 左端
$by  = (int)($s * 0.12);  // 上端
$bw  = (int)($s * 0.76);  // 幅
$bh  = (int)($s * 0.52);  // 高さ
$r   = (int)($s * 0.10);  // 角丸半径

// 矩形を3ピースで描画して角丸を実現
imagefilledrectangle($img, $bx + $r, $by,      $bx + $bw - $r, $by + $bh,      $white);
imagefilledrectangle($img, $bx,      $by + $r, $bx + $bw,      $by + $bh - $r, $white);
imagefilledellipse($img, $bx + $r,        $by + $r,        $r * 2, $r * 2, $white);
imagefilledellipse($img, $bx + $bw - $r,  $by + $r,        $r * 2, $r * 2, $white);
imagefilledellipse($img, $bx + $r,        $by + $bh - $r,  $r * 2, $r * 2, $white);
imagefilledellipse($img, $bx + $bw - $r,  $by + $bh - $r,  $r * 2, $r * 2, $white);

// ── 吹き出しのしっぽ（白い三角）──
$tx = (int)($s * 0.22);
$ty = $by + $bh;
imagefilledpolygon($img, [
    $tx,                   $ty,
    $tx - (int)($s*0.09), $ty + (int)($s*0.18),
    $tx + (int)($s*0.20), $ty,
], $white);

// ── 「AGO」テキスト ──
// GD組み込みフォントは小さいので、サイズに応じて繰り返し描画して太く見せる
$font     = 5;
$fw       = imagefontwidth($font);
$fh       = imagefontheight($font);
$text     = 'AGO';
$scale    = max(1, (int)($s / 64)); // 192px → scale=3
$text_len = strlen($text);
$total_w  = $fw * $text_len * $scale;
$tx2      = (int)(($s - $total_w) / 2);
$ty2      = $by + (int)(($bh - $fh * $scale) / 2);

// 拡大文字（imagestring を scale 倍に拡大して貼る）
$tmp = imagecreatetruecolor($fw * $text_len, $fh);
$tmp_green = imagecolorallocate($tmp, 6, 199, 85);
$tmp_white = imagecolorallocate($tmp, 255, 255, 255);
imagefill($tmp, 0, 0, $tmp_white);
imagestring($tmp, $font, 0, 0, $text, $tmp_green);
imagecopyresized($img, $tmp, $tx2, $ty2, 0, 0,
    $fw * $text_len * $scale, $fh * $scale,
    $fw * $text_len, $fh);
imagedestroy($tmp);

imagepng($img);
imagedestroy($img);
