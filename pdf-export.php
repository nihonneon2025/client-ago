<?php
// pdf-export.php — KVストア直読みのサーバーサイド書類レンダラー
// print-doc.php は localStorage 依存でデーモンから使えないため、こちらを利用する
// Usage: pdf-export.php?key=ago_invoices&id=X
// Basic Auth: system002:nihonneon

$_KV_CACHE = null;

function kv_fetch_all() {
    global $_KV_CACHE;
    if ($_KV_CACHE !== null) return $_KV_CACHE;
    $url = 'https://' . $_SERVER['HTTP_HOST'] . '/api.php';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['action' => 'kv_getAll']),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-AGO-Token: system002-od'],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res ?: '{}', true);
    $_KV_CACHE = $data['data'] ?? [];
    return $_KV_CACHE;
}

function kv_get($key) {
    return kv_fetch_all()[$key] ?? null;
}

if (($_GET['t'] ?? '') !== 'system002-od') {
    http_response_code(403);
    exit('<p style="padding:40px;font-family:sans-serif;color:#888">認証エラー</p>');
}

$allowed = ['ago_estimates', 'ago_invoices', 'ago_purchase_orders'];
$key = $_GET['key'] ?? '';
$id  = $_GET['id']  ?? '';

if (!in_array($key, $allowed) || !$id) {
    http_response_code(400);
    exit('<p style="padding:40px;font-family:sans-serif;color:#888">書類IDが指定されていません。</p>');
}

$docs = json_decode(kv_get($key) ?? '[]', true) ?: [];
$doc  = null;
foreach ($docs as $d) {
    if ((string)($d['id'] ?? '') === (string)$id) { $doc = $d; break; }
}

if (!$doc) {
    http_response_code(404);
    exit('<p style="padding:40px;font-family:sans-serif;color:#888">書類が見つかりません（key=' . htmlspecialchars($key) . ', id=' . htmlspecialchars($id) . '）</p>');
}

$is_est    = ($key === 'ago_estimates'       || ($doc['doc_type'] ?? '') === 'estimate');
$is_inv    = ($key === 'ago_invoices'        || ($doc['doc_type'] ?? '') === 'invoice');
$is_po     = ($key === 'ago_purchase_orders' || ($doc['doc_type'] ?? '') === 'purchase_order');
$doc_title = $is_est ? '御　見　積　書' : ($is_inv ? '御　請　求　書' : '発　注　書');
$doc_ja    = $is_est ? '見積書'         : ($is_inv ? '請求書'         : '発注書');

$client    = htmlspecialchars($doc['client_name'] ?? $doc['client'] ?? '');
$date_str  = $doc['issue_date'] ?? $doc['date'] ?? '';
$doc_num   = htmlspecialchars($doc['doc_number'] ?? (string)($doc['id'] ?? ''));
$subject   = htmlspecialchars($doc['subject'] ?? (($doc['project_name'] ?? '') . ' ' . $doc_ja));
$notes     = htmlspecialchars($doc['notes'] ?? '');
$due_date  = $doc['due_date'] ?? '';
$valid_end = $doc['valid_until'] ?? $doc['validUntil'] ?? '';

$items    = $doc['items'] ?? [];
$subtotal = (int)($doc['amount'] ?? $doc['subtotal'] ?? 0);
$tax      = (int)($doc['tax'] ?? 0);
$total    = (int)($doc['total'] ?? 0);
if (!$subtotal && $items) {
    foreach ($items as $i) {
        $subtotal += (int)($i['amount'] ?? 0) ?: ((int)($i['qty'] ?? 1) * (int)($i['unit_price'] ?? 0));
    }
    if (!$tax)   $tax   = (int)round($subtotal * 0.1);
}
if (!$total) $total = $subtotal + $tax;

function fd($d) {
    if (!$d) return '';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m))
        return $m[1] . '年' . (int)$m[2] . '月' . (int)$m[3] . '日';
    return htmlspecialchars($d);
}

$rows = '';
foreach ($items as $i) {
    $desc   = htmlspecialchars($i['description'] ?? $i['label'] ?? '');
    $qty    = (int)($i['qty'] ?? 1);
    $unit   = htmlspecialchars($i['unit'] ?? '式');
    $price  = (int)($i['unit_price'] ?? 0);
    $amount = (int)($i['amount'] ?? 0) ?: ($qty * $price);
    $rows  .= "<tr><td class='cd'>{$desc}</td><td class='cn'>{$qty}</td><td class='cu'>{$unit}</td>"
            . "<td class='cp'>" . ($price ? number_format($price) : '') . "</td>"
            . "<td class='ca'>" . number_format($amount) . "</td></tr>";
}
for ($b = 0; $b < max(0, 8 - count($items)); $b++) {
    $rows .= "<tr><td class='cd'>&nbsp;</td><td class='cn'></td><td class='cu'></td><td class='cp'></td><td class='ca'></td></tr>";
}

$cond_html  = '';
if ($is_est && $valid_end) $cond_html = "<p class='vl'>有効期限：" . fd($valid_end) . "</p>";
elseif ($is_inv && $due_date) $cond_html = "<p class='vl'>お支払期日：" . fd($due_date) . "</p>";

$notes_html = $notes ? "<div class='ns'><h4>備考</h4><p>{$notes}</p></div>" : '';
$badge      = (!empty($doc['source']) && $doc['source'] === 'line')
            ? '<span class="sb">LINE AI</span>' : '';
$issuer     = '株式会社AGOグループ';

$total_label = $is_est ? '合計金額（税込）' : ($is_inv ? 'ご請求金額（税込）' : '発注金額（税込）');
$body_text   = $is_est ? '下記の通りお見積り申し上げます。' : ($is_inv ? '下記の通りご請求申し上げます。' : '下記の通り発注いたします。');

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title><?= $doc_ja ?>（<?= $client ?>）</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Hiragino Kaku Gothic ProN','Hiragino Sans','Meiryo',sans-serif;font-size:13px;color:#222;background:#f0f0f0}
@media print{body{background:#fff}.np{display:none!important}.pg{margin:0;padding:20mm;box-shadow:none}}
.np{background:#333;color:#fff;padding:12px 20px;display:flex;align-items:center;gap:12px}
.np button{background:#06c755;color:#fff;border:none;padding:8px 20px;border-radius:6px;font-size:14px;cursor:pointer;font-weight:600}
.pg{background:#fff;width:210mm;min-height:297mm;margin:20px auto;padding:20mm;box-shadow:0 2px 12px rgba(0,0,0,.15)}
.dh{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px}
.dt{font-size:28px;font-weight:700;letter-spacing:8px;text-align:center;margin-bottom:28px;padding-bottom:8px;border-bottom:2px solid #222}
.mr{display:flex;justify-content:space-between;margin-bottom:20px}
.tn{font-size:18px;font-weight:700;border-bottom:1px solid #222;padding-bottom:2px;display:inline-block;min-width:200px;margin-bottom:4px}
.db{font-size:12px;color:#444;text-align:right;line-height:2}
.db td{padding-left:12px}
.tb{border:2px solid #222;padding:14px 24px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center}
.tl{font-size:13px;color:#444}.ta{font-size:22px;font-weight:700}
.it{width:100%;border-collapse:collapse;margin-bottom:16px;font-size:12px}
.it th{background:#222;color:#fff;padding:6px 8px;text-align:center;font-weight:600}
.it td{border:1px solid #ccc;padding:6px 8px;vertical-align:top}
.cd{text-align:left}.cn{text-align:center;width:50px}.cu{text-align:center;width:40px}
.cp{text-align:right;width:90px}.ca{text-align:right;width:90px}
.it tr:nth-child(even) td{background:#fafafa}
.sb2{width:100%;display:flex;justify-content:flex-end;margin-bottom:20px}
.st{border-collapse:collapse;font-size:12px}
.st td{padding:5px 12px;border:1px solid #ddd}
.lc{text-align:right;color:#555;background:#f5f5f5;width:120px}
.vc{text-align:right;width:110px}
.tr td{font-weight:700;font-size:14px;background:#f0f0f0}
.ns{margin-bottom:20px}.ns h4{font-size:12px;color:#444;margin-bottom:4px;border-bottom:1px solid #ddd;padding-bottom:2px}
.ns p{font-size:12px;line-height:1.8;color:#555;white-space:pre-wrap}
.vl{font-size:12px;color:#444;margin-bottom:16px}
.if2{border-top:1px solid #ddd;padding-top:16px;display:flex;justify-content:flex-end}
.ib{font-size:11px;color:#444;line-height:2;text-align:right}
.in{font-size:14px;font-weight:700;color:#222}
.sa{width:60px;height:60px;border:1px solid #bbb;margin-left:20px;display:flex;align-items:center;justify-content:center;font-size:10px;color:#aaa}
.sb{display:inline-block;font-size:10px;background:#06c755;color:#fff;padding:1px 6px;border-radius:3px;margin-left:8px;vertical-align:middle}
</style>
</head>
<body>
<div class="np">
  <button onclick="window.print()">🖨 印刷 / PDF保存</button>
  <span style="font-size:12px;color:#ccc">Ctrl+P → 「PDFに保存」</span>
</div>
<div class="pg">
  <div class="dh">
    <div style="font-size:18px;font-weight:700"><?= htmlspecialchars($issuer) ?></div>
  </div>
  <div class="dt"><?= $doc_title ?><?= $badge ?></div>
  <div class="mr">
    <div>
      <div><span class="tn"><?= $client ?></span><span style="font-size:13px;color:#444;margin-left:4px"> 御中</span></div>
      <?php if ($subject): ?><div style="margin-top:8px;font-size:13px">件名：<strong><?= $subject ?></strong></div><?php endif; ?>
    </div>
    <div class="db">
      <table>
        <tr><td>発行日</td><td><?= fd($date_str) ?></td></tr>
        <tr><td><?= $doc_ja ?>番号</td><td><?= $doc_num ?></td></tr>
      </table>
    </div>
  </div>
  <div class="tb">
    <span class="tl"><?= $total_label ?></span>
    <span class="ta">¥<?= number_format($total) ?></span>
  </div>
  <p style="font-size:12px;color:#555;margin-bottom:10px"><?= $body_text ?></p>
  <table class="it">
    <thead>
      <tr>
        <th class="cd">品名・摘要</th><th class="cn">数量</th><th class="cu">単位</th>
        <th class="cp">単価</th><th class="ca">金額</th>
      </tr>
    </thead>
    <tbody><?= $rows ?></tbody>
  </table>
  <div class="sb2">
    <table class="st">
      <tr><td class="lc">小計</td><td class="vc"><?= number_format($subtotal) ?></td></tr>
      <tr><td class="lc">消費税（10%）</td><td class="vc"><?= number_format($tax) ?></td></tr>
      <tr class="tr"><td class="lc">合計</td><td class="vc">¥<?= number_format($total) ?></td></tr>
    </table>
  </div>
  <?= $cond_html ?>
  <?= $notes_html ?>
  <div class="if2">
    <div class="ib"><div class="in"><?= htmlspecialchars($issuer) ?></div></div>
    <div class="sa">印</div>
  </div>
</div>
</body>
</html>
