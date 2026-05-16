<?php
// line-alert.php — 定期チェック＆プッシュ通知
//
// Xserver cron設定（コントロールパネル → cron設定）:
//   0 9  * * * wget -q "https://system002-od.ordermade-neon.com/line-alert.php?t=AGO_ALERT_2026" >/dev/null 2>&1
//   0 17 * * * wget -q "https://system002-od.ordermade-neon.com/line-alert.php?t=AGO_ALERT_2026" >/dev/null 2>&1
//
// api-config.phpに以下を追記してから使う:
//   define('LINE_CHANNEL_TOKEN', 'xxxx'); // LINEチャンネルアクセストークン
//   define('KANNO_LINE_ID', 'Uxxxx');     // 菅野さんのLINEユーザーID（ログから確認）
//   define('ALERT_SECRET', 'AGO_ALERT_2026'); // cronのURLに付けるキー

define('BASE_URL', 'https://system002-od.ordermade-neon.com');
define('AGO_TOKEN', 'system002-od');

// ── 設定読込 ─────────────────────────────────────────────────────
$LINE_TOKEN  = '';
$KANNO_ID    = '';
$SECRET      = 'AGO_ALERT_2026'; // デフォルトキー（api-config.phpで上書き可）

if (file_exists(__DIR__ . '/api-config.php')) {
    require __DIR__ . '/api-config.php';
    if (defined('LINE_CHANNEL_TOKEN')) $LINE_TOKEN = LINE_CHANNEL_TOKEN;
    if (defined('KANNO_LINE_ID'))      $KANNO_ID   = KANNO_LINE_ID;
    if (defined('ALERT_SECRET'))       $SECRET     = ALERT_SECRET;
}

// ── セキュリティ ──────────────────────────────────────────────────
if (($_GET['t'] ?? '') !== $SECRET) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

// ── 重複送信防止（同じ時間帯に複数回実行されても1回だけ） ─────────
$slot_key = 'ago_alert_slot_' . date('YmdH');
if (ago_kv_get($slot_key)) { echo 'already_sent_this_hour'; exit; }
ago_kv_set($slot_key, '1');

// ── データ読込 ────────────────────────────────────────────────────
$projects = json_decode(ago_kv_get('ago_projects') ?? '[]', true) ?: [];
$orders   = json_decode(ago_kv_get('ago_orders')   ?? '[]', true) ?: [];
$now      = time();

// ── チェック①: 止まっている案件（5日以上更新なし・非終端フェーズ） ─
$TERMINAL_PHASES = ['completed', 'invoiced', 'cancelled'];
$stale_projects  = [];
foreach ($projects as $p) {
    if (in_array($p['phase'] ?? '', $TERMINAL_PHASES)) continue;
    $updated = strtotime($p['updated_at'] ?? $p['created_at'] ?? '');
    if (!$updated) continue;
    $days = (int)(($now - $updated) / 86400);
    if ($days >= 5) {
        $stale_projects[] = [
            'name'  => $p['name']  ?? '案件名不明',
            'client'=> $p['client_name'] ?? '',
            'phase' => phase_label($p['phase'] ?? ''),
            'days'  => $days,
        ];
    }
}

// ── チェック②: 資材 — 到着予定日超過 or 10日以上更新なし ──────────
$TERMINAL_ORDERS = ['delivered', 'cancel_customer', 'cancel_factory'];
$stale_orders    = [];
foreach ($orders as $o) {
    if (in_array($o['status'] ?? '', $TERMINAL_ORDERS)) continue;
    $arrival = $o['arrival_date'] ?? null;
    $updated = strtotime($o['updated_at'] ?? $o['created_at'] ?? '');
    $days_stale     = $updated ? (int)(($now - $updated) / 86400) : 999;
    $arrival_overdue = $arrival && strtotime($arrival) < $now;

    if ($arrival_overdue || $days_stale >= 10) {
        $stale_orders[] = [
            'product' => $o['product'] ?? '商品名不明',
            'status'  => order_status_label($o['status'] ?? ''),
            'arrival' => $arrival,
            'overdue' => $arrival_overdue,
            'days'    => $days_stale,
        ];
    }
}

// ── アラートなしなら終了 ──────────────────────────────────────────
if (empty($stale_projects) && empty($stale_orders)) {
    echo 'ok_no_alerts';
    exit;
}

// ── メッセージ組み立て ────────────────────────────────────────────
$lines = ['🔔 AGOシステム確認 ' . date('n/j H:i')];

if ($stale_projects) {
    $lines[] = '';
    $lines[] = '■ 動いていない案件';
    foreach ($stale_projects as $p) {
        $client = $p['client'] ? "（{$p['client']}）" : '';
        $lines[] = "・{$p['name']}{$client}\n  {$p['phase']} → {$p['days']}日更新なし";
    }
}

if ($stale_orders) {
    $lines[] = '';
    $lines[] = '■ 資材の確認が必要';
    foreach ($stale_orders as $o) {
        if ($o['overdue']) {
            $lines[] = "・{$o['product']}\n  {$o['arrival']}着予定 → まだ届いていません";
        } else {
            $lines[] = "・{$o['product']}\n  {$o['status']} → {$o['days']}日更新なし";
        }
    }
}

$message = implode("\n", $lines);

// ── 送信 ─────────────────────────────────────────────────────────
if ($LINE_TOKEN && $KANNO_ID) {
    $result = line_push($LINE_TOKEN, $KANNO_ID, $message);
    // 送信履歴を残す
    ago_kv_set('ago_alert_last_sent', json_encode([
        'ts'      => date('Y-m-d H:i:s'),
        'message' => $message,
        'result'  => $result,
    ], JSON_UNESCAPED_UNICODE));
    echo 'sent';
} else {
    // 設定未完了の間はKVにメッセージを保存（画面で確認可能）
    ago_kv_set('ago_alert_pending', json_encode([
        'ts'      => date('Y-m-d H:i:s'),
        'message' => $message,
        'reason'  => !$LINE_TOKEN ? 'LINE_TOKEN未設定' : 'KANNO_LINE_ID未設定',
    ], JSON_UNESCAPED_UNICODE));
    echo 'config_pending';
    echo "\n---\n" . $message;
}

// ── 関数 ─────────────────────────────────────────────────────────

function line_push($token, $userId, $message) {
    $payload = [
        'to'       => $userId,
        'messages' => [['type' => 'text', 'text' => $message]]
    ];
    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_TIMEOUT => 10
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function phase_label($phase) {
    $map = [
        'reception'          => '受付',
        'designing'          => '設計中',
        'estimating'         => '見積中',
        'contracting'        => '契約中',
        'ordering'           => '発注中',
        'construction-adj'   => '施工調整中',
        'construction'       => '施工中',
        'completion-pending' => '完工・請求書未作成',
        'invoiced'           => '請求済',
        'cancelled'          => 'キャンセル',
    ];
    return $map[$phase] ?? $phase;
}

function order_status_label($status) {
    $map = [
        'received'          => '受注済み',
        'ordered_china'     => '中国発注済み',
        'factory_confirmed' => '工場確認済み',
        'shipping_prep'     => '配送準備中',
        'in_transit'        => '配送中',
    ];
    return $map[$status] ?? $status;
}

function ago_kv_get($key) {
    $res = @file_get_contents(BASE_URL . '/api.php', false, stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nX-AGO-Token: " . AGO_TOKEN . "\r\n",
            'content' => json_encode(['action' => 'kv_get', 'key' => $key]),
            'timeout' => 10,
        ]
    ]));
    if (!$res) return null;
    $data = json_decode($res, true);
    return $data['value'] ?? null;
}

function ago_kv_set($key, $value) {
    @file_get_contents(BASE_URL . '/api.php', false, stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nX-AGO-Token: " . AGO_TOKEN . "\r\n",
            'content' => json_encode(['action' => 'kv_set', 'key' => $key, 'value' => $value]),
            'timeout' => 10,
        ]
    ]));
}
