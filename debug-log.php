<?php
// 一時診断用（使用後削除）
if (($_GET['t'] ?? '') !== 'elvin2026') { http_response_code(403); exit; }
$log = __DIR__ . '/wh_debug.log';
if (!file_exists($log)) { echo "log not found"; exit; }
$lines = file($log);
$tail  = array_slice($lines, -80);
header('Content-Type: text/plain; charset=utf-8');
echo implode('', $tail);
