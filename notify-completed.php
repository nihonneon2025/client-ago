<?php
// notify-completed.php — 完了済みタスクへのWebプッシュ自動通知
// Xserver cron: */3 * * * * php /home/xs520103/system002-od.ordermade-neon.com/public_html/notify-completed.php

define('BASE_URL', 'https://system002-od.ordermade-neon.com');
define('API_TOKEN', 'system002-od');

function nc_kv_getall() {
    $ch = curl_init(BASE_URL . '/api.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => json_encode(['action' => 'kv_getAll']),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-AGO-Token: ' . API_TOKEN],
        CURLOPT_TIMEOUT        => 10, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true);
}

function nc_kv_set($key, $value) {
    $ch = curl_init(BASE_URL . '/api.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => json_encode(['action' => 'kv_set', 'key' => $key,
                                               'value'  => json_encode($value, JSON_UNESCAPED_UNICODE)]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-AGO-Token: ' . API_TOKEN],
        CURLOPT_TIMEOUT        => 10, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true);
}

function nc_webpush($title, $body, $url = '/chat.php', $badge_count = 1) {
    $ch = curl_init(BASE_URL . '/subscribe.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => json_encode(['action' => 'send_all', 'title' => $title, 'body' => $body, 'url' => $url, 'badge_count' => (int)$badge_count]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-AGO-Token: ' . API_TOKEN],
        CURLOPT_TIMEOUT        => 15, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true);
}

function nc_log($msg) {
    file_put_contents(__DIR__ . '/notify_debug.log',
        date('Y-m-d H:i:s') . ' [notify] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

// ── キュー取得 ────────────────────────────────────────────────────────
$all   = nc_kv_getall();
$queue = json_decode(($all['data'] ?? [])['ago_claude_queue'] ?? '[]', true) ?: [];

if (empty($queue)) {
    nc_log('queue empty');
    exit;
}

// ── 未通知の完了タスクを検索して通知 ─────────────────────────────────
$updated      = false;
$notify_count = 0;
$log_updates  = [];  // log_id => result のマップ

// バッジ数用に先にカウント
$pending_count = 0;
foreach ($queue as $t) {
    if (($t['status'] ?? '') === 'done' && empty($t['notified_at'])) $pending_count++;
}

$pushes = []; // 送信するWeb Push情報を一時保存

foreach ($queue as &$task) {
    if (($task['status'] ?? '') === 'done' && empty($task['notified_at'])) {
        $name   = $task['requester_name'] ?? 'スタッフ';
        $result = mb_substr($task['result'] ?? '作業が完了しました', 0, 100);
        $body   = "✅ {$name}さんの依頼が完了しました\n{$result}";

        $task['notified_at'] = date('Y-m-d H:i:s');
        $updated = true;
        $notify_count++;

        // LINEログ更新用にlog_idと結果を記録
        if (!empty($task['log_id'])) {
            $log_updates[$task['log_id']] = $task['result'] ?? '作業が完了しました';
        } else {
            // log_id未設定フォールバック: requester_idで最新のprocessingログを探す
            $fallback_updates[] = [
                'requester_id' => $task['requester_id'] ?? '',
                'result'       => $task['result'] ?? '作業が完了しました',
            ];
        }

        // Web Push情報を後送り用に積む（KV更新が先）
        $pushes[] = ['body' => $body, 'task_id' => $task['id'] ?? '?'];
    }
}
unset($task);

// ── KV更新を先に完了させる（Web Push前に済ませることでクリック時に最新データが見える）──

// LINEログを更新
if (!empty($log_updates) || !empty($fallback_updates)) {
    $logs = json_decode(($all['data'] ?? [])['ago_line_logs'] ?? '[]', true) ?: [];
    $log_changed = false;

    // log_idあり: 直接更新
    foreach ($logs as &$log) {
        $lid = $log['id'] ?? '';
        if (isset($log_updates[$lid])) {
            $log['ai_reply'] = mb_substr($log_updates[$lid], 0, 200);
            $log['status']   = 'replied';
            $log_changed     = true;
            nc_log('log_updated id=' . $lid);
        }
    }
    unset($log);

    // log_idなし: requester_idで最新のprocessingログを探して更新
    foreach ($fallback_updates as $fb) {
        $uid = $fb['requester_id'] ?? '';
        if (empty($uid)) continue;
        $best_idx = -1;
        $best_ts  = '';
        foreach ($logs as $idx => $log) {
            if (($log['userId'] ?? '') !== $uid) continue;
            if (($log['status'] ?? '') !== 'processing') continue;
            if ($best_idx < 0 || ($log['ts'] ?? '') > $best_ts) {
                $best_idx = $idx;
                $best_ts  = $log['ts'] ?? '';
            }
        }
        if ($best_idx >= 0) {
            $logs[$best_idx]['ai_reply'] = mb_substr($fb['result'], 0, 200);
            $logs[$best_idx]['status']   = 'replied';
            $log_changed = true;
            nc_log('fallback_log_updated userId=' . $uid . ' id=' . ($logs[$best_idx]['id'] ?? '?'));
        }
    }

    if ($log_changed) {
        nc_kv_set('ago_line_logs', $logs);
    }
}

if ($updated) {
    nc_kv_set('ago_claude_queue', $queue);
    nc_log("kv_updated. notified={$notify_count}");
}

// ── KV更新後にWeb Pushを送信 ─────────────────────────────────────────
foreach ($pushes as $p) {
    $push = nc_webpush('AGO SYSTEM MANAGER', $p['body'], '/chat.php', $pending_count);
    nc_log('task=' . $p['task_id'] . ' sent=' . ($push['sent'] ?? 0) . ' failed=' . ($push['failed'] ?? 0));
}

if (!$updated) {
    nc_log('no pending');
}
