<?php
// 見積書・請求書 印刷/PDF出力ページ
// Usage: print-doc.php?key=ago_estimates&id=1
//        print-doc.php?key=ago_invoices&id=2
header('Content-Type: text/html; charset=utf-8');
$key = htmlspecialchars($_GET['key'] ?? '');
$id  = htmlspecialchars($_GET['id']  ?? '');
if (!in_array($key, ['ago_estimates','ago_invoices','ago_purchase_orders'])) {
    $key = ''; // JSで判断
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>書類印刷</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Hiragino Kaku Gothic ProN','Hiragino Sans','Meiryo',sans-serif; font-size: 13px; color: #222; background: #f0f0f0; }

/* 印刷時は白背景・コントロール非表示 */
@media print {
  body { background: #fff; }
  .no-print { display: none !important; }
  .page { box-shadow: none; margin: 0; padding: 20mm 20mm 20mm 20mm; }
}

.no-print {
  background: #333;
  color: #fff;
  padding: 12px 20px;
  display: flex;
  align-items: center;
  gap: 12px;
}
.no-print button {
  background: #06c755;
  color: #fff;
  border: none;
  padding: 8px 20px;
  border-radius: 6px;
  font-size: 14px;
  cursor: pointer;
  font-weight: 600;
}
.no-print button:hover { background: #05a845; }
.no-print .hint { font-size: 12px; color: #ccc; }

.page {
  background: #fff;
  width: 210mm;
  min-height: 297mm;
  margin: 20px auto;
  padding: 20mm 20mm 20mm 20mm;
  box-shadow: 0 2px 12px rgba(0,0,0,0.15);
}

/* ヘッダー */
.doc-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; }
.company-info { text-align: right; font-size: 11px; color: #444; line-height: 1.8; }
.company-info .company-name { font-size: 14px; font-weight: 700; color: #222; }
.doc-title { font-size: 28px; font-weight: 700; letter-spacing: 8px; text-align: center; margin-bottom: 28px; padding-bottom: 8px; border-bottom: 2px solid #222; }

/* 宛先・日付エリア */
.meta-row { display: flex; justify-content: space-between; margin-bottom: 20px; }
.to-block { font-size: 14px; }
.to-block .to-name { font-size: 18px; font-weight: 700; border-bottom: 1px solid #222; padding-bottom: 2px; display: inline-block; min-width: 200px; margin-bottom: 4px; }
.to-block .to-honorific { font-size: 13px; color: #444; margin-left: 4px; }
.date-block { font-size: 12px; color: #444; text-align: right; line-height: 2; }
.date-block td { padding-left: 12px; }

/* 件名・合計金額 */
.subject-line { font-size: 13px; margin-bottom: 16px; }
.subject-line span { font-weight: 600; }
.total-box {
  border: 2px solid #222;
  padding: 14px 24px;
  margin-bottom: 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.total-box .total-label { font-size: 13px; color: #444; }
.total-box .total-amount { font-size: 22px; font-weight: 700; color: #222; }

/* 明細テーブル */
.items-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 12px; }
.items-table th {
  background: #222;
  color: #fff;
  padding: 6px 8px;
  text-align: center;
  font-weight: 600;
}
.items-table td {
  border: 1px solid #ccc;
  padding: 6px 8px;
  vertical-align: top;
}
.items-table .col-desc { text-align: left; }
.items-table .col-num  { text-align: center; width: 50px; }
.items-table .col-unit { text-align: center; width: 40px; }
.items-table .col-price{ text-align: right;  width: 90px; }
.items-table .col-amt  { text-align: right;  width: 90px; }
.items-table tr:nth-child(even) td { background: #fafafa; }

/* 小計・消費税・合計 */
.subtotal-block { width: 100%; display: flex; justify-content: flex-end; margin-bottom: 20px; }
.subtotal-table { border-collapse: collapse; font-size: 12px; }
.subtotal-table td { padding: 5px 12px; border: 1px solid #ddd; }
.subtotal-table .label-cell { text-align: right; color: #555; background: #f5f5f5; width: 120px; }
.subtotal-table .value-cell { text-align: right; width: 110px; }
.subtotal-table .total-row td { font-weight: 700; font-size: 14px; background: #f0f0f0; }

/* 備考・有効期限 */
.notes-section { margin-bottom: 20px; }
.notes-section h4 { font-size: 12px; color: #444; margin-bottom: 4px; border-bottom: 1px solid #ddd; padding-bottom: 2px; }
.notes-section p { font-size: 12px; line-height: 1.8; color: #555; white-space: pre-wrap; }
.validity-line { font-size: 12px; color: #444; margin-bottom: 16px; }

/* 発行者署名欄 */
.issuer-footer { border-top: 1px solid #ddd; padding-top: 16px; display: flex; justify-content: flex-end; }
.issuer-block { font-size: 11px; color: #444; line-height: 2; text-align: right; }
.issuer-block .issuer-name { font-size: 14px; font-weight: 700; color: #222; }
.stamp-area { width: 60px; height: 60px; border: 1px solid #bbb; margin-left: 20px; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #aaa; }

/* エラー表示 */
.error-msg { text-align: center; padding: 60px; font-size: 16px; color: #888; }
.source-badge { display: inline-block; font-size: 10px; background: #06c755; color: #fff; padding: 1px 6px; border-radius: 3px; margin-left: 8px; vertical-align: middle; }
</style>
</head>
<body>

<div class="no-print">
  <button onclick="window.print()">🖨 印刷 / PDF保存</button>
  <span class="hint">Ctrl+P → 「PDFに保存」で書類として保管できます</span>
  <span style="margin-left:auto;font-size:11px">印刷設定: A4・余白=標準・ヘッダーフッター=なし 推奨</span>
</div>

<div class="page" id="doc-page">
  <div class="error-msg" id="loading">読み込み中...</div>
</div>

<script>
(function() {
  const KEY = '<?= $key ?>';
  const ID  = '<?= $id ?>';

  if (!KEY || !ID) {
    document.getElementById('loading').textContent = '書類IDが指定されていません。';
    return;
  }

  // localStorage から書類データを取得
  let docs = [];
  try { docs = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch(e) {}
  const doc = docs.find(d => String(d.id) === String(ID));

  if (!doc) {
    document.getElementById('loading').textContent = '書類が見つかりません。（key=' + KEY + ', id=' + ID + '）';
    return;
  }

  renderDocument(doc, KEY);
})();

function yen(n) {
  return '¥' + Number(n || 0).toLocaleString();
}
function esc(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function formatDate(d) {
  if (!d) return '';
  // YYYY-MM-DD → YYYY年MM月DD日
  const m = String(d).match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (m) return m[1] + '年' + parseInt(m[2]) + '月' + parseInt(m[3]) + '日';
  return d;
}

function renderDocument(doc, key) {
  const isEstimate = key === 'ago_estimates' || doc.doc_type === 'estimate';
  const isInvoice  = key === 'ago_invoices'  || doc.doc_type === 'invoice';
  const isPO       = key === 'ago_purchase_orders' || doc.doc_type === 'purchase_order';

  const docTitle  = isEstimate ? '御　見　積　書' : isInvoice ? '御　請　求　書' : '発　注　書';
  const docTypeJA = isEstimate ? '見積書' : isInvoice ? '請求書' : '発注書';

  // フィールド正規化（新旧両フォーマット対応）
  const client    = doc.client_name || doc.client || doc.clientName || '';
  const dateStr   = doc.issue_date  || doc.date   || '';
  const docNum    = doc.doc_number  || String(doc.id || '');
  const subject   = doc.subject     || ((doc.project_name || '') + ' ' + docTypeJA);
  const notes     = doc.notes       || '';
  const dueDate   = doc.due_date    || '';
  const validUntil= doc.valid_until || doc.validUntil || '';
  const sourceByLine = doc.source === 'line';

  // 金額
  const items  = doc.items || [];
  let subtotal = parseInt(doc.amount || doc.subtotal || 0);
  let tax      = parseInt(doc.tax || 0);
  let total    = parseInt(doc.total || 0) || (subtotal + tax);

  // items から小計を再計算（金額フィールドが不明な場合）
  if (!subtotal && items.length) {
    subtotal = items.reduce((s, i) => {
      const amt = parseInt(i.amount || 0) || (parseInt(i.qty || 1) * parseInt(i.unit_price || 0));
      return s + amt;
    }, 0);
    if (!tax) tax = Math.round(subtotal * 0.1);
    if (!total) total = subtotal + tax;
  }

  // AGO会社情報（書式確定後にここを更新）
  const issuer = {
    name:    '株式会社AGOグループ',
    zip:     '',
    address: '',
    tel:     '',
    email:   '',
    bank:    ''
  };

  // 明細行HTML
  const itemRows = items.map(i => {
    const desc    = i.description || i.label || '';
    const qty     = i.qty    || 1;
    const unit    = i.unit   || '式';
    const price   = parseInt(i.unit_price || 0);
    const amount  = parseInt(i.amount || 0) || (qty * price);
    return `<tr>
      <td class="col-desc">${esc(desc)}</td>
      <td class="col-num">${qty}</td>
      <td class="col-unit">${esc(unit)}</td>
      <td class="col-price">${price ? Number(price).toLocaleString() : ''}</td>
      <td class="col-amt">${Number(amount).toLocaleString()}</td>
    </tr>`;
  }).join('');

  // 空白行を追加（最低8行）
  const blankRows = Math.max(0, 8 - items.length);
  const blanks = Array(blankRows).fill('<tr><td class="col-desc">&nbsp;</td><td class="col-num"></td><td class="col-unit"></td><td class="col-price"></td><td class="col-amt"></td></tr>').join('');

  // 有効期限/支払期日
  let conditionHTML = '';
  if (isEstimate && validUntil) {
    conditionHTML = `<p class="validity-line">有効期限：${formatDate(validUntil)}</p>`;
  } else if (isInvoice && dueDate) {
    conditionHTML = `<p class="validity-line">お支払期日：${formatDate(dueDate)}</p>`;
  }

  // 銀行口座（請求書のみ）
  let bankHTML = '';
  if (isInvoice && issuer.bank) {
    bankHTML = `<div class="notes-section"><h4>お振込先</h4><p>${esc(issuer.bank)}</p></div>`;
  }

  // 備考
  const notesHTML = notes
    ? `<div class="notes-section"><h4>備考</h4><p>${esc(notes)}</p></div>`
    : '';

  const html = `
  <div class="doc-header">
    <div style="font-size:18px;font-weight:700;color:#222;">${esc(issuer.name)}</div>
    <div class="company-info">
      ${issuer.zip     ? '<span>' + esc(issuer.zip) + '</span><br>' : ''}
      ${issuer.address ? '<span>' + esc(issuer.address) + '</span><br>' : ''}
      ${issuer.tel     ? 'TEL: ' + esc(issuer.tel) + '<br>' : ''}
      ${issuer.email   ? esc(issuer.email) + '<br>' : ''}
    </div>
  </div>

  <div class="doc-title">${docTitle}${sourceByLine ? '<span class="source-badge">LINE AI</span>' : ''}</div>

  <div class="meta-row">
    <div class="to-block">
      <div><span class="to-name">${esc(client)}</span><span class="to-honorific"> 御中</span></div>
      ${subject ? '<div style="margin-top:8px;font-size:13px;">件名：<strong>' + esc(subject) + '</strong></div>' : ''}
    </div>
    <div class="date-block">
      <table>
        <tr><td>発行日</td><td>${formatDate(dateStr)}</td></tr>
        <tr><td>${docTypeJA}番号</td><td>${esc(docNum)}</td></tr>
      </table>
    </div>
  </div>

  <div class="total-box">
    <span class="total-label">${isEstimate ? '合計金額（税込）' : isInvoice ? 'ご請求金額（税込）' : '発注金額（税込）'}</span>
    <span class="total-amount">${yen(total)}</span>
  </div>

  <p style="font-size:12px;color:#555;margin-bottom:10px;">
    ${isEstimate ? '下記の通りお見積り申し上げます。' : isInvoice ? '下記の通りご請求申し上げます。' : '下記の通り発注いたします。'}
  </p>

  <table class="items-table">
    <thead>
      <tr>
        <th class="col-desc">品名・摘要</th>
        <th class="col-num">数量</th>
        <th class="col-unit">単位</th>
        <th class="col-price">単価</th>
        <th class="col-amt">金額</th>
      </tr>
    </thead>
    <tbody>
      ${itemRows}
      ${blanks}
    </tbody>
  </table>

  <div class="subtotal-block">
    <table class="subtotal-table">
      <tr>
        <td class="label-cell">小計</td>
        <td class="value-cell">${Number(subtotal).toLocaleString()}</td>
      </tr>
      <tr>
        <td class="label-cell">消費税（10%）</td>
        <td class="value-cell">${Number(tax).toLocaleString()}</td>
      </tr>
      <tr class="total-row">
        <td class="label-cell">合計</td>
        <td class="value-cell">${yen(total)}</td>
      </tr>
    </table>
  </div>

  ${conditionHTML}
  ${notesHTML}
  ${bankHTML}

  <div class="issuer-footer">
    <div class="issuer-block">
      <div class="issuer-name">${esc(issuer.name)}</div>
      ${issuer.zip     ? '<div>' + esc(issuer.zip) + ' ' + esc(issuer.address) + '</div>' : ''}
      ${issuer.tel     ? '<div>TEL: ' + esc(issuer.tel) + '</div>' : ''}
      ${issuer.email   ? '<div>' + esc(issuer.email) + '</div>' : ''}
    </div>
    <div class="stamp-area">印</div>
  </div>
  `;

  document.getElementById('doc-page').innerHTML = html;
}
</script>
</body>
</html>
