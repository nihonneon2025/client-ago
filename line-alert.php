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
$projects  = json_decode(ago_kv_get('ago_projects')       ?? '[]', true) ?: [];
$orders    = json_decode(ago_kv_get('ago_orders')         ?? '[]', true) ?: [];
$schedule  = json_decode(ago_kv_get('ago_kanno_schedule') ?? '{}', true) ?: [];

$now   = time();
$today = date('Y-m-d');
$hour  = (int)date('H');
$is_morning = ($hour < 12);

// ── 案件チェック ──────────────────────────────────────────────────
$TERMINAL_PHASES = ['invoiced', 'cancelled'];

$PHASE_THRESHOLDS = [
    'reception'          => 3,
    'designing'          => 7,
    'estimating'         => 10,
    'contracting'        => 7,
    'ordering'           => 5,
    'construction-adj'   => 5,
    'construction'       => 14,
    'completion-pending' => 3,
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

// 案件フェーズを自動で1つ進める提案（次フェーズが明確な場合のみ）
$NEXT_PHASE = [
    'construction' => 'completion-pending',
];

$tomorrow    = date('Y-m-d', strtotime('+1 day'));
$urgent      = [];
$today_works = [];
$stale       = [];

foreach ($projects as $p) {
    $phase = $p['phase'] ?? '';
    if (in_array($phase, $TERMINAL_PHASES)) continue;

    $name    = $p['name'] ?? '案件名不明';
    $client  = $p['client_name'] ?? '';
    $label   = $name . ($client ? "（{$client}）" : '');
    $start   = $p['start_date'] ?? null;
    $updated = strtotime($p['updated_at'] ?? $p['created_at'] ?? '');
    $days    = $updated ? (int)(($now - $updated) / 86400) : 999;

    // ⑦ 今日・明日が施工開始日
    if ($start && ($start === $today || $start === $tomorrow)) {
        $when = ($start === $today) ? '今日' : '明日';
        $today_works[] = "・{$label} → {$when}施工開始";
    }

    // ④ 完工・請求書未作成（緊急）
    if ($phase === 'completion-pending' && $days >= 3) {
        $urgent[] = "・{$label} → {$days}日請求書が未作成です";
        continue;
    }

    $threshold = $PHASE_THRESHOLDS[$phase] ?? null;
    if ($threshold && $days >= $threshold) {
        $stale[] = [
            'id'         => $p['id'],
            'label'      => $label,
            'phase'      => $PHASE_LABELS[$phase] ?? $phase,
            'days'       => $days,
            'next_phase' => $NEXT_PHASE[$phase] ?? null,
            'kind'       => 'project',
        ];
    }
}

// ── 資材チェック ──────────────────────────────────────────────────
$TERMINAL_ORDERS = ['delivered', 'cancel_customer', 'cancel_factory'];

// 資材ステータスの次の提案
$NEXT_ORDER_STATUS = [
    'ordered_china'     => ['status' => 'factory_confirmed', 'question' => '工場から確認が来ましたか？'],
    'factory_confirmed' => ['status' => 'shipping_prep',    'question' => '配送準備に入りましたか？'],
    'shipping_prep'     => ['status' => 'in_transit',       'question' => '発送されましたか？'],
    'in_transit'        => ['status' => 'delivered',        'question' => '届きましたか？'],
];

// 到着予定超過 → "届きましたか？"
$OVERDUE_QUESTION = ['status' => 'delivered', 'question' => '届きましたか？'];

$overdue_orders = [];
$china_stale    = [];
$order_stale    = [];

foreach ($orders as $o) {
    if (in_array($o['status'] ?? '', $TERMINAL_ORDERS)) continue;

    $product = $o['product'] ?? '商品名不明';
    $status  = $o['status'] ?? '';
    $arrival = $o['arrival_date'] ?? null;
    $updated = strtotime($o['updated_at'] ?? $o['created_at'] ?? '');
    $days    = $updated ? (int)(($now - $updated) / 86400) : 999;

    // 到着予定日を超過
    if ($arrival && strtotime($arrival) < $now) {
        $overdue_orders[] = [
            'id'      => $o['id'],
            'label'   => "{$product}（{$arrival}着予定）",
            'days'    => $days,
            'next'    => $OVERDUE_QUESTION,
            'kind'    => 'order',
        ];
    // ⑧ 中国発注7日以上
    } elseif ($status === 'ordered_china' && $days >= 7) {
        $china_stale[] = [
            'id'    => $o['id'],
            'label' => "{$product}（中国発注 {$days}日経過）",
            'days'  => $days,
            'next'  => $NEXT_ORDER_STATUS[$status] ?? null,
            'kind'  => 'order',
        ];
    // 10日以上更新なし
    } elseif ($days >= 10) {
        $order_stale[] = [
            'id'    => $o['id'],
            'label' => "{$product}（" . order_label($status) . "・{$days}日更新なし）",
            'days'  => $days,
            'next'  => $NEXT_ORDER_STATUS[$status] ?? null,
            'kind'  => 'order',
        ];
    }
}

// ── スケジュール ──────────────────────────────────────────────────
$today_schedule    = $schedule[$today] ?? [];
$tomorrow_schedule = $schedule[$tomorrow] ?? [];

// ── 確認が必要なアクション候補をまとめる ─────────────────────────
$actionable = array_merge(
    array_filter($stale,        fn($x) => !empty($x['next_phase'])),
    $overdue_orders,
    $china_stale,
    array_filter($order_stale,  fn($x) => !empty($x['next']))
);

// ── メッセージ組み立て ────────────────────────────────────────────
$has_content = $urgent || $today_works || $stale || $overdue_orders || $china_stale || $order_stale;
if (!$has_content && !$today_schedule && !$is_morning) {
    echo 'ok_no_alerts'; exit;
}

$lines = [];
$lines[] = $is_morning
    ? '📅 AGO おはようございます ' . date('n月j日')
    : '🔔 AGO 夕方確認 '           . date('n月j日 H:i');

// 今日の予定
if ($today_schedule) {
    $lines[] = '';
    $lines[] = '■ 今日の予定';
    foreach ($today_schedule as $e) {
        $lines[] = '・' . ($e['time'] ? $e['time'] . ' ' : '') . $e['desc'];
    }
}
if ($is_morning && $tomorrow_schedule) {
    $lines[] = '';
    $lines[] = '■ 明日の予定';
    foreach ($tomorrow_schedule as $e) {
        $lines[] = '・' . ($e['time'] ? $e['time'] . ' ' : '') . $e['desc'];
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

// 放置案件（確認不要なもの）
$info_stale = array_filter($stale, fn($x) => empty($x['next_phase']));
if ($info_stale) {
    $lines[] = '';
    $lines[] = '■ 動いていない案件';
    foreach ($info_stale as $s) {
        $lines[] = "・{$s['label']}\n  {$s['phase']} → {$s['days']}日更新なし";
    }
}

// 到着遅延（確認付き）
if ($overdue_orders) {
    $lines[] = '';
    $lines[] = '■ 到着予定を過ぎた資材';
    foreach ($overdue_orders as $o) {
        $lines[] = "・{$o['label']}";
    }
}

// 中国発注放置
if ($china_stale) {
    $lines[] = '';
    $lines[] = '■ 工場確認が来ていない資材';
    foreach ($china_stale as $o) {
        $lines[] = "・{$o['label']}";
    }
}

// 長期放置資材（確認不要なもの）
$info_orders = array_filter($order_stale, fn($x) => empty($x['next']));
if ($info_orders) {
    $lines[] = '';
    $lines[] = '■ 長期間更新がない資材';
    foreach ($info_orders as $o) {
        $lines[] = "・{$o['label']}";
    }
}

// ── 確認セクション（番号付き）────────────────────────────────────
$pending_actions = [];
if ($actionable) {
    $lines[] = '';
    $lines[] = '──────────────';
    $lines[] = '以下を動かしていいですか？';
    $no = 1;
    foreach (array_values($actionable) as $item) {
        if ($item['kind'] === 'project' && !empty($item['next_phase'])) {
            $next_label = $PHASE_LABELS[$item['next_phase']] ?? $item['next_phase'];
            $lines[] = "【{$no}】{$item['label']}\n  {$item['phase']}→{$next_label}に進めていいですか？";
            $pending_actions[] = [
                'no'     => $no,
                'type'   => 'update_phase',
                'id'     => $item['id'],
                'value'  => $item['next_phase'],
                'label'  => $item['label'],
            ];
        } elseif ($item['kind'] === 'order' && !empty($item['next'])) {
            $next_label = order_label($item['next']['status']);
            $question   = $item['next']['question'];
            $lines[]    = "【{$no}】{$item['label']}\n  {$question}（はいで{$next_label}に更新）";
            $pending_actions[] = [
                'no'    => $no,
                'type'  => 'update_order_status',
                'id'    => $item['id'],
                'value' => $item['next']['status'],
                'label' => $item['label'],
            ];
        } else {
            $no--;
        }
        $no++;
    }
    if ($pending_actions) {
        $lines[] = '';
        $lines[] = '「【1】はい 【2】まだ」のように返信してください';
    }
}

// KVに保存（line-handler.phpが参照）
if ($pending_actions) {
    ago_kv_set('ago_pending_actions', json_encode([
        'generated_at' => date('Y-m-d H:i:s'),
        'expires'      => date('Y-m-d', strtotime('+1 day')),
        'actions'      => $pending_actions,
    ], JSON_UNESCAPED_UNICODE));
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
        'ts'     => date('Y-m-d H:i:s'),
        'message'=> $message,
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
        'delivered'         => '配送完了',
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
