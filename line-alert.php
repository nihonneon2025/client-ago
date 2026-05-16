<?php
// line-alert.php — 定期チェック＆秘書ブリーフィング
//
// Xserver cron設定:
//   0 9  * * * wget -q "https://system002-od.ordermade-neon.com/line-alert.php?t=AGO_ALERT_2026" >/dev/null 2>&1
//   0 17 * * * wget -q "https://system002-od.ordermade-neon.com/line-alert.php?t=AGO_ALERT_2026" >/dev/null 2>&1
//
// api-config.phpに以下を追記:
//   define('LINE_CHANNEL_TOKEN', 'xxxx');
//   define('KANNO_LINE_ID',      'Uxxxx');
//   define('ALERT_SECRET',       'AGO_ALERT_2026');

define('BASE_URL', 'https://system002-od.ordermade-neon.com');
define('AGO_TOKEN', 'system002-od');

// ── 設定読込 ─────────────────────────────────────────────────────
$LINE_TOKEN = '';
$KANNO_ID   = '';
$SECRET     = 'AGO_ALERT_2026';

if (file_exists(__DIR__ . '/api-config.php')) {
    require __DIR__ . '/api-config.php';
    if (defined('LINE_CHANNEL_TOKEN')) $LINE_TOKEN = LINE_CHANNEL_TOKEN;
    if (defined('KANNO_LINE_ID'))      $KANNO_ID   = KANNO_LINE_ID;
    if (defined('ALERT_SECRET'))       $SECRET     = ALERT_SECRET;
}

if (($_GET['t'] ?? '') !== $SECRET) { http_response_code(403); exit; }

// ── 重複送信防止 ──────────────────────────────────────────────────
$slot_key = 'ago_alert_slot_' . date('YmdH');
if (ago_kv_get($slot_key)) { echo 'already_sent_this_hour'; exit; }
ago_kv_set($slot_key, '1');

// ── データ読込 ────────────────────────────────────────────────────
$projects = json_decode(ago_kv_get('ago_projects')  ?? '[]', true) ?: [];
$orders   = json_decode(ago_kv_get('ago_orders')    ?? '[]', true) ?: [];
$schedule = json_decode(ago_kv_get('ago_kanno_schedule') ?? '{}', true) ?: [];

$now   = time();
$today = date('Y-m-d');
$hour  = (int)date('H');
$is_morning = ($hour < 12);

// ── 案件チェック ──────────────────────────────────────────────────
$TERMINAL_PHASES = ['invoiced', 'cancelled'];

$urgent      = []; // ④ 完工・請求書未作成 3日以上
$today_works = []; // ⑦ 今日・明日の施工開始
$stale       = []; // 各フェーズごとの放置

$PHASE_THRESHOLDS = [
    'reception'          => 3,
    'designing'          => 7,
    'estimating'         => 10,  // ⑥
    'contracting'        => 7,
    'ordering'           => 5,
    'construction-adj'   => 5,
    'construction'       => 14,  // ⑤
    'completion-pending' => 3,   // ④（urgentとして別扱い）
];

$PHASE_LABELS = [
    'reception'          => '受付',
    'designing'          => '設計中',
    'estimating'         => '見積中',
    'contracting'        => '契約中',
    'ordering'           => '発注中',
    'construction-adj'   => '施工調整中',
    'construction'       => '施工中',
    'completion-pending' => '完工（請求書未作成）',
];

$tomorrow = date('Y-m-d', strtotime('+1 day'));

foreach ($projects as $p) {
    $phase = $p['phase'] ?? '';
    if (in_array($phase, $TERMINAL_PHASES)) continue;

    $name    = $p['name'] ?? '案件名不明';
    $client  = $p['client_name'] ?? '';
    $start   = $p['start_date'] ?? null;
    $updated = strtotime($p['updated_at'] ?? $p['created_at'] ?? '');
    $days    = $updated ? (int)(($now - $updated) / 86400) : 999;

    // ⑦ 今日・明日が施工開始日
    if ($start && ($start === $today || $start === $tomorrow)) {
        $label = ($start === $today) ? '今日' : '明日';
        $today_works[] = "・{$name}" . ($client ? "（{$client}）" : '') . " → {$label}施工開始";
    }

    // ④ 完工・請求書未作成（緊急）
    if ($phase === 'completion-pending' && $days >= 3) {
        $urgent[] = "・{$name}" . ($client ? "（{$client}）" : '') . " → {$days}日請求書未作成";
        continue;
    }

    // フェーズ別放置チェック
    $threshold = $PHASE_THRESHOLDS[$phase] ?? null;
    if ($threshold && $days >= $threshold) {
        $stale[] = [
            'name'   => $name . ($client ? "（{$client}）" : ''),
            'phase'  => $PHASE_LABELS[$phase] ?? $phase,
            'days'   => $days,
        ];
    }
}

// ── 資材チェック ──────────────────────────────────────────────────
$TERMINAL_ORDERS = ['delivered', 'cancel_customer', 'cancel_factory'];

$overdue_orders  = []; // 到着予定日超過
$china_stale     = []; // ⑧ 中国発注済み7日以上
$order_stale     = []; // 10日以上更新なし

foreach ($orders as $o) {
    if (in_array($o['status'] ?? '', $TERMINAL_ORDERS)) continue;

    $product = $o['product'] ?? '商品名不明';
    $status  = $o['status'] ?? '';
    $arrival = $o['arrival_date'] ?? null;
    $updated = strtotime($o['updated_at'] ?? $o['created_at'] ?? '');
    $days    = $updated ? (int)(($now - $updated) / 86400) : 999;

    if ($arrival && strtotime($arrival) < $now) {
        $overdue_orders[] = "・{$product} → {$arrival}着予定で未着";
    } elseif ($status === 'ordered_china' && $days >= 7) {
        $china_stale[] = "・{$product} → 中国発注済み {$days}日経過・工場確認なし";
    } elseif ($days >= 10) {
        $order_stale[] = "・{$product}（" . order_label($status) . "）→ {$days}日更新なし";
    }
}

// ── 菅野さんのスケジュール（今日分） ─────────────────────────────
$today_schedule = $schedule[$today] ?? [];
$tomorrow_schedule = $schedule[$tomorrow] ?? [];

// ── 何もなければ終了 ──────────────────────────────────────────────
$has_alert = $urgent || $today_works || $stale || $overdue_orders || $china_stale || $order_stale;
if (!$has_alert && !$today_schedule && !$is_morning) {
    echo 'ok_no_alerts'; exit;
}

// ── メッセージ組み立て ────────────────────────────────────────────
$lines = [];

if ($is_morning) {
    $lines[] = '📅 AGO おはようございます ' . date('n月j日');
} else {
    $lines[] = '🔔 AGO 夕方確認 ' . date('n月j日 H:i');
}

// 菅野さんの今日のスケジュール
if ($today_schedule) {
    $lines[] = '';
    $lines[] = '■ 今日の予定';
    foreach ($today_schedule as $entry) {
        $time = $entry['time'] ?? '';
        $desc = $entry['desc'] ?? '';
        $lines[] = '・' . ($time ? "{$time} " : '') . $desc;
    }
}

// 明日のスケジュール（朝のみ）
if ($is_morning && $tomorrow_schedule) {
    $lines[] = '';
    $lines[] = '■ 明日の予定';
    foreach ($tomorrow_schedule as $entry) {
        $time = $entry['time'] ?? '';
        $desc = $entry['desc'] ?? '';
        $lines[] = '・' . ($time ? "{$time} " : '') . $desc;
    }
}

// 今日・明日の施工
if ($today_works) {
    $lines[] = '';
    $lines[] = '■ 施工予定';
    foreach ($today_works as $l) $lines[] = $l;
}

// 緊急：請求書未作成
if ($urgent) {
    $lines[] = '';
    $lines[] = '🚨 請求書がまだ未作成です';
    foreach ($urgent as $l) $lines[] = $l;
}

// 放置案件
if ($stale) {
    $lines[] = '';
    $lines[] = '■ 動いていない案件';
    foreach ($stale as $s) {
        $lines[] = "・{$s['name']}\n  {$s['phase']} → {$s['days']}日更新なし";
    }
}

// 資材：到着遅延
if ($overdue_orders) {
    $lines[] = '';
    $lines[] = '■ 到着が遅れている資材';
    foreach ($overdue_orders as $l) $lines[] = $l;
}

// 資材：中国発注放置
if ($china_stale) {
    $lines[] = '';
    $lines[] = '■ 工場からの確認がない資材';
    foreach ($china_stale as $l) $lines[] = $l;
}

// 資材：長期放置
if ($order_stale) {
    $lines[] = '';
    $lines[] = '■ 長期間更新がない資材';
    foreach ($order_stale as $l) $lines[] = $l;
}

$message = implode("\n", $lines);

// ── 送信 ─────────────────────────────────────────────────────────
if ($LINE_TOKEN && $KANNO_ID) {
    line_push($LINE_TOKEN, $KANNO_ID, $message);
    ago_kv_set('ago_alert_last_sent', json_encode([
        'ts' => date('Y-m-d H:i:s'), 'message' => $message,
    ], JSON_UNESCAPED_UNICODE));
    echo 'sent';
} else {
    ago_kv_set('ago_alert_pending', json_encode([
        'ts' => date('Y-m-d H:i:s'), 'message' => $message,
        'reason' => !$LINE_TOKEN ? 'LINE_TOKEN未設定' : 'KANNO_LINE_ID未設定',
    ], JSON_UNESCAPED_UNICODE));
    echo 'config_pending';
    echo "\n---\n" . $message;
}

// ── 関数 ─────────────────────────────────────────────────────────

function order_label($status) {
    $map = [
        'received'          => '受注済み',
        'ordered_china'     => '中国発注済み',
        'factory_confirmed' => '工場確認済み',
        'shipping_prep'     => '配送準備中',
        'in_transit'        => '配送中',
    ];
    return $map[$status] ?? $status;
}

function line_push($token, $userId, $message) {
    $payload = ['to' => $userId, 'messages' => [['type' => 'text', 'text' => $message]]];
    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 10,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function ago_kv_get($key) {
    $res = @file_get_contents(BASE_URL . '/api.php', false, stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nX-AGO-Token: " . AGO_TOKEN . "\r\n",
        'content' => json_encode(['action' => 'kv_get', 'key' => $key]),
        'timeout' => 10,
    ]]));
    if (!$res) return null;
    return json_decode($res, true)['value'] ?? null;
}

function ago_kv_set($key, $value) {
    @file_get_contents(BASE_URL . '/api.php', false, stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nX-AGO-Token: " . AGO_TOKEN . "\r\n",
        'content' => json_encode(['action' => 'kv_set', 'key' => $key, 'value' => $value]),
        'timeout' => 10,
    ]]));
}
