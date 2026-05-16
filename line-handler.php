<?php
// line-handler.php — 自立型 LINE AI（2026-05-16 全面刷新）
// ・会話履歴はユーザーごとに独立（ago_line_conv_{userId}）
// ・全業務データをコンテキストに渡し Claude が自律判断
// ・書類URLの返答・複数案件一括処理・売上照会など何でも対応

function processLineMessage($log_entry, $api_key, $line_token = '') {
    $userId      = $log_entry['userId'];
    $text        = $log_entry['text'];
    $log_id      = $log_entry['id'];
    $ts          = $log_entry['ts'];
    $reply_token = $log_entry['reply_token'] ?? null;
    $base_url    = 'https://system002-od.ordermade-neon.com';

    ago_log_update($log_id, ['status' => 'processing']);

    // ── 1. ユーザー名解決 ───────────────────────────────────────────
    $users_map = json_decode(ago_kv_get('ago_line_users') ?? '{}', true) ?: [];
    $user_name = $users_map[$userId] ?? ('スタッフ(' . substr($userId, -6) . ')');

    // ── 2. 全業務データ読込 ─────────────────────────────────────────
    $projects  = json_decode(ago_kv_get('ago_projects')        ?? '[]', true) ?: [];
    $estimates = json_decode(ago_kv_get('ago_estimates')       ?? '[]', true) ?: [];
    $invoices  = json_decode(ago_kv_get('ago_invoices')        ?? '[]', true) ?: [];
    $pos       = json_decode(ago_kv_get('ago_purchase_orders') ?? '[]', true) ?: [];
    $orders    = json_decode(ago_kv_get('ago_orders')          ?? '[]', true) ?: [];
    $schedule  = json_decode(ago_kv_get('ago_kanno_schedule') ?? '{}', true) ?: [];

    // 確認待ちアクション（line-alert.phpが生成・菅野さんの返答待ち）
    $pending_raw  = ago_kv_get('ago_pending_actions');
    $pending_data = $pending_raw ? json_decode($pending_raw, true) : null;
    if ($pending_data && ($pending_data['expires'] ?? '') < date('Y-m-d')) {
        $pending_data = null; // 期限切れ
    }

    // ── 3. ユーザー別会話履歴（最新10往復） ────────────────────────
    $conv_key  = 'ago_line_conv_' . $userId;
    $conv_hist = json_decode(ago_kv_get($conv_key) ?? '[]', true) ?: [];

    // ── 4. 案件サマリー構築（書類URLつき） ─────────────────────────
    $proj_list = [];
    foreach (array_slice($projects, 0, 40) as $p) {
        $pid   = $p['id'];
        $entry = [
            'id'       => $pid,
            'name'     => $p['name'] ?? '',
            'client'   => $p['client_name'] ?? '',
            'phase'    => $p['phase'] ?? '',
            'location' => $p['location'] ?? '',
            'start'    => $p['start_date'] ?? '',
            'end'      => $p['end_date'] ?? '',
        ];

        $est_docs = array_values(array_filter($estimates, fn($e) => (string)$e['project_id'] === (string)$pid));
        $inv_docs = array_values(array_filter($invoices,  fn($i) => (string)$i['project_id'] === (string)$pid));
        $po_docs  = array_values(array_filter($pos,       fn($o) => (string)$o['project_id'] === (string)$pid));

        if ($est_docs) $entry['見積書'] = array_map(fn($e) => [
            'no' => $e['doc_number'] ?? '', 'total' => '¥' . number_format($e['total'] ?? 0),
            'url' => $base_url . '/print-doc.php?key=ago_estimates&id=' . $e['id']
        ], $est_docs);

        if ($inv_docs) $entry['請求書'] = array_map(fn($i) => [
            'no' => $i['doc_number'] ?? '', 'total' => '¥' . number_format($i['total'] ?? 0),
            'url' => $base_url . '/print-doc.php?key=ago_invoices&id=' . $i['id']
        ], $inv_docs);

        if ($po_docs)  $entry['発注書'] = array_map(fn($o) => [
            'no' => $o['doc_number'] ?? '', 'total' => '¥' . number_format($o['total'] ?? 0),
            'url' => $base_url . '/print-doc.php?key=ago_purchase_orders&id=' . $o['id']
        ], $po_docs);

        $proj_list[] = $entry;
    }

    // 菅野さんのスケジュール（今日〜3日分）
    $sched_lines = [];
    for ($i = 0; $i <= 3; $i++) {
        $d = date('Y-m-d', strtotime("+{$i} days"));
        if (!empty($schedule[$d])) {
            $label = $i === 0 ? '今日' : ($i === 1 ? '明日' : date('n/j', strtotime($d)));
            foreach ($schedule[$d] as $entry) {
                $t = $entry['time'] ?? '';
                $sched_lines[] = "{$label}" . ($t ? " {$t}" : '') . ": " . ($entry['desc'] ?? '');
            }
        }
    }
    $schedule_text = $sched_lines ? implode("\n", $sched_lines) : '（未登録）';

    // 確認待ちアクションのテキスト生成
    $pending_text = '（なし）';
    if ($pending_data && !empty($pending_data['actions'])) {
        $pending_lines = ['生成: ' . $pending_data['generated_at'] . ' / 有効期限: ' . $pending_data['expires']];
        foreach ($pending_data['actions'] as $pa) {
            $pending_lines[] = "【{$pa['no']}】{$pa['label']}（type={$pa['type']}, value={$pa['value']}, id={$pa['id']}）";
        }
        $pending_text = implode("\n", $pending_lines);
    }

    // 資材注文サマリー（進行中のみ）
    $order_list = [];
    foreach (array_slice($orders, 0, 40) as $o) {
        if (in_array($o['status'] ?? '', ['delivered','cancel_customer','cancel_factory'])) continue;
        $proj_name = '';
        if (!empty($o['project_link'])) {
            foreach ($projects as $p) {
                if ((string)$p['id'] === (string)$o['project_link']) { $proj_name = $p['name'] ?? ''; break; }
            }
        }
        $order_list[] = [
            'id'      => $o['id'],
            'code'    => $o['order_code'] ?? '',
            'product' => $o['product'] ?? '',
            'status'  => $o['status'] ?? '',
            'project' => $proj_name,
            'qty'     => $o['qty'] ?? 1,
        ];
    }

    // 今月売上
    $ym            = date('Y-m');
    $monthly_sales = array_sum(array_map(
        fn($i) => strpos($i['issue_date'] ?? '', $ym) === 0 ? ($i['total'] ?? 0) : 0,
        $invoices
    ));

    // ── 5. システムプロンプト ───────────────────────────────────────
    $data_json   = json_encode($proj_list,   JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $orders_json = json_encode($order_list,  JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $system = <<<SYS
あなたはAGO SYSTEM MANAGER（看板・LED・電気工事・内装工事会社）のLINE AIです。
スタッフからのLINEを受け取り、業務システムを操作しながら何でも自律的に対応します。
会話の文脈・言い回し・状況から意図を正確に読み取ってください。

## 送信者
{$user_name}

## 今日の日付
{$ts}

## 今月の請求済売上
¥{$monthly_sales}

## 現在の業務データ（書類URLつき）
{$data_json}

## 菅野さんのスケジュール（今日〜3日）
{$schedule_text}

## 確認待ちアクション（朝夕の自動通知で菅野さんに確認を求めたもの）
{$pending_text}

## 資材注文データ（進行中のみ）
{$orders_json}

## 案件フェーズ値
受付=reception / 設計中=designing / 見積中=estimating / 契約中=contracting /
発注中=ordering / 施工調整中=construction-adj / 施工中=construction /
完工(請求書未作成)=completion-pending / 請求済=invoiced / キャンセル=cancelled

## 資材注文ステータス値
受注済み=received / 中国発注済み=ordered_china / 工場確認済み=factory_confirmed /
配送準備中=shipping_prep / 配送中=in_transit / 配送完了=delivered /
キャンセル(顧客都合)=cancel_customer / キャンセル(工場都合)=cancel_factory

## できること
- 案件の登録・フェーズ更新・情報照会
- 資材注文のステータス更新（「届いた」「来た」「配送中」「キャンセル」など自然な言葉からステータスを推定）
- 見積書・請求書・発注書の作成（明細は内容から自動生成）
- 書類URLをreplyに含める（送信者がお客さんへ転送）
- 複数案件・複数書類を一括処理（「A社B社C社の請求書URL」など）
- 売上・進行状況のサマリー回答
- 送信者の名前登録（「私は菅野です」→ 以後その名前で呼ぶ）
- スケジュール登録・確認（「明日は田中建設の現場」「今週の予定は？」など）

## 返答フォーマット（JSONのみ・前後に余計なテキスト不要）
{
  "reply": "LINEに送るテキスト。URLはそのまま含める。改行は\nで",
  "actions": [
    // 操作が必要な場合のみ記載。不要なら []
    // {"type":"update_phase","project_id":123,"phase":"designing"}
    // {"type":"update_order_status","order_id":123,"status":"delivered"}
    // {"type":"create_project","name":"案件名","client_name":"顧客名","project_type":"signage","location":"住所","memo":"メモ"}
    // {"type":"create_estimate","project_id":123,"client_name":"顧客名","items":[{"description":"品名","qty":1,"unit":"式","unit_price":1000000}]}
    // {"type":"create_invoice","project_id":123,"client_name":"顧客名","items":[{"description":"品名","qty":1,"unit":"式","unit_price":1000000}]}
    // {"type":"create_purchase_order","project_id":123,"client_name":"発注先","items":[{"description":"品名","qty":1,"unit":"式","unit_price":500000}]}
    // {"type":"register_name","name":"登録する名前"}
    // {"type":"set_schedule","date":"2026-05-17","entries":[{"time":"10:00","desc":"田中建設の現場"},{"time":"14:00","desc":"打ち合わせ"}]}
    // {"type":"clear_schedule","date":"2026-05-17"}
    // {"type":"confirm_pending_actions","confirmed":[1,3],"declined":[2]}  // 確認待ちを承認/却下して即実行
  ]
}

## 注意
- JSON以外は返さない
- 書類URLはreplyにそのまま貼る（お客さん転送用）
- 金額は税抜で受け取り10%消費税を自動計算
- 案件・注文は名前・顧客名・現場名・IDで柔軟に特定する
- 「届いた」「来た」→ delivered、「配送中」→ in_transit、「工場確認」→ factory_confirmed など文脈から推定
- 明細が不明な場合は内容・金額から合理的に推測して生成
- 対象が複数あって特定できない場合は選択肢を返してユーザーに確認する
- 「明日は〇〇の現場」「今日の午後から打ち合わせ」などはset_scheduleで保存する
- 日付が省略された場合は文脈から推定（「明日」→ tomorrow の日付）
- 「今日の予定は？」「今週どうだっけ？」はスケジュールデータを参照してreplyに書く
- 「確認待ちアクション」がある状態で菅野さんが「【1】はい」「【2】まだ」のように返答したら confirm_pending_actions を使う
- 「全部はい」「全部OK」→ 全番号を confirmed に。「全部まだ」→ 全番号を declined に
- 承認した番号の実行結果をreplyで伝える（例:「【1】田中建設の資材を配送完了に更新しました」）
SYS;

    // ── 6. Claude API呼び出し ───────────────────────────────────────
    $messages   = array_values($conv_hist);
    $messages[] = ['role' => 'user', 'content' => $text];

    $raw = ai_call($api_key, $system, $messages, 1500);

    // JSONパース（```json...``` ブロックにも対応）
    $clean  = preg_replace('/^```json\s*|\s*```$/m', '', trim($raw ?? ''));
    preg_match('/\{[\s\S]*\}/u', $clean, $m);
    $parsed = json_decode($m[0] ?? '{}', true);

    $reply_msg = $parsed['reply'] ?? 'すみません、処理できませんでした。もう一度お試しください。';
    $actions   = $parsed['actions'] ?? [];

    // ── 7. アクション実行 ───────────────────────────────────────────
    foreach ($actions as $action) {
        execute_action($action, $userId, $users_map, $ts);
    }

    // ── 8. 会話履歴保存（ユーザーごと・最新20件=10往復） ───────────
    $conv_hist[] = ['role' => 'user',      'content' => $text];
    $conv_hist[] = ['role' => 'assistant', 'content' => $reply_msg];
    if (count($conv_hist) > 20) $conv_hist = array_slice($conv_hist, -20);
    ago_kv_set($conv_key, json_encode($conv_hist, JSON_UNESCAPED_UNICODE));

    // ── 9. LINE返信 ─────────────────────────────────────────────────
    if ($line_token && $reply_token) {
        line_reply($line_token, $reply_token, mb_substr($reply_msg, 0, 5000));
    }

    // ── 10. ログ更新 ────────────────────────────────────────────────
    ago_log_update($log_id, [
        'status'   => 'replied',
        'ai_reply' => mb_substr($reply_msg, 0, 200)
    ]);
}

// ── アクション実行 ────────────────────────────────────────────────
function execute_action($action, $userId, $users_map, $ts) {
    $type = $action['type'] ?? '';

    switch ($type) {

        case 'update_order_status':
            $oid    = (int)($action['order_id'] ?? 0);
            $status = $action['status'] ?? '';
            if (!$oid || !$status) break;
            $orders = json_decode(ago_kv_get('ago_orders') ?? '[]', true) ?: [];
            foreach ($orders as &$o) {
                if ($o['id'] == $oid) {
                    $o['status']     = $status;
                    $o['updated_at'] = $ts;
                    $o['_logs'][]    = ['status' => $status, 'updated_by' => 'LINE_AI', 'created_at' => $ts, 'note' => ''];
                    break;
                }
            }
            unset($o);
            ago_kv_set('ago_orders', json_encode($orders, JSON_UNESCAPED_UNICODE));
            break;

        case 'set_schedule':
            $date    = $action['date'] ?? date('Y-m-d');
            $entries = $action['entries'] ?? [];
            $sched   = json_decode(ago_kv_get('ago_kanno_schedule') ?? '{}', true) ?: [];
            $sched[$date] = $entries;
            // 過去分は削除（1日前より古いものは自動削除）
            $cutoff = date('Y-m-d', strtotime('-1 day'));
            foreach (array_keys($sched) as $d) { if ($d < $cutoff) unset($sched[$d]); }
            ago_kv_set('ago_kanno_schedule', json_encode($sched, JSON_UNESCAPED_UNICODE));
            break;

        case 'clear_schedule':
            $date  = $action['date'] ?? date('Y-m-d');
            $sched = json_decode(ago_kv_get('ago_kanno_schedule') ?? '{}', true) ?: [];
            unset($sched[$date]);
            ago_kv_set('ago_kanno_schedule', json_encode($sched, JSON_UNESCAPED_UNICODE));
            break;

        case 'register_name':
            $name = trim($action['name'] ?? '');
            if (!$name) break;
            $users_map[$userId] = $name;
            ago_kv_set('ago_line_users', json_encode($users_map, JSON_UNESCAPED_UNICODE));
            break;

        case 'update_phase':
            $pid   = (int)($action['project_id'] ?? 0);
            $phase = $action['phase'] ?? '';
            if (!$pid || !$phase) break;
            $projs = json_decode(ago_kv_get('ago_projects') ?? '[]', true) ?: [];
            $old   = '';
            foreach ($projs as &$p) {
                if ($p['id'] == $pid) {
                    $old = $p['phase'] ?? '';
                    $p['phase']      = $phase;
                    $p['updated_at'] = $ts;
                    break;
                }
            }
            unset($p);
            ago_kv_set('ago_projects', json_encode($projs, JSON_UNESCAPED_UNICODE));
            ago_project_log($pid, 'LINE_AI', "フェーズ変更: {$old} → {$phase}", $ts);
            break;

        case 'create_project':
            $projs  = json_decode(ago_kv_get('ago_projects') ?? '[]', true) ?: [];
            $new_id = count($projs) > 0 ? max(array_column($projs, 'id')) + 1 : 1;
            array_unshift($projs, [
                'id'          => $new_id,
                'name'        => $action['name'] ?? '新規案件',
                'client_name' => $action['client_name'] ?? '',
                'type'        => $action['project_type'] ?? 'signage',
                'phase'       => 'reception',
                'location'    => $action['location'] ?? '',
                'memo'        => $action['memo'] ?? '',
                'created_at'  => $ts,
                'updated_at'  => $ts,
                'created_by'  => 'LINE_AI',
                'source'      => 'line',
            ]);
            ago_kv_set('ago_projects', json_encode($projs, JSON_UNESCAPED_UNICODE));
            break;

        case 'create_estimate':
            $pid    = (string)($action['project_id'] ?? '');
            $items  = normalize_items($action['items'] ?? []);
            $amount = array_sum(array_map(fn($i) => $i['qty'] * $i['unit_price'], $items));
            $tax    = (int)round($amount * 0.1);
            $ests   = json_decode(ago_kv_get('ago_estimates') ?? '[]', true) ?: [];
            $new_id = count($ests) > 0 ? max(array_column($ests, 'id')) + 1 : 1;
            $proj   = find_proj_by_id($pid);
            $doc_no = 'EST-' . date('Ym') . '-' . str_pad($new_id, 4, '0', STR_PAD_LEFT);
            array_unshift($ests, [
                'id'           => $new_id,
                'doc_type'     => 'estimate',
                'doc_number'   => $doc_no,
                'project_id'   => $pid,
                'project_name' => $proj['name'] ?? '',
                'subject'      => ($proj['name'] ?? '案件') . ' 御見積書',
                'client_name'  => $action['client_name'] ?? ($proj['client_name'] ?? ''),
                'issue_date'   => date('Y-m-d'),
                'items'        => $items,
                'amount'       => $amount,
                'tax'          => $tax,
                'total'        => $amount + $tax,
                'status'       => 'draft',
                'created_by'   => 'LINE_AI',
                'created_at'   => $ts,
                'source'       => 'line',
            ]);
            ago_kv_set('ago_estimates', json_encode($ests, JSON_UNESCAPED_UNICODE));
            _set_phase((int)$pid, 'estimating', $ts);
            ago_project_log((int)$pid, 'LINE_AI', "見積書作成: {$doc_no}", $ts);
            break;

        case 'create_invoice':
            $pid    = (string)($action['project_id'] ?? '');
            $items  = normalize_items($action['items'] ?? []);
            $amount = array_sum(array_map(fn($i) => $i['qty'] * $i['unit_price'], $items));
            $tax    = (int)round($amount * 0.1);
            $invs   = json_decode(ago_kv_get('ago_invoices') ?? '[]', true) ?: [];
            $new_id = count($invs) > 0 ? max(array_column($invs, 'id')) + 1 : 1;
            $proj   = find_proj_by_id($pid);
            $doc_no = 'INV-' . date('Ym') . '-' . str_pad($new_id, 4, '0', STR_PAD_LEFT);
            array_unshift($invs, [
                'id'           => $new_id,
                'doc_type'     => 'invoice',
                'doc_number'   => $doc_no,
                'project_id'   => $pid,
                'project_name' => $proj['name'] ?? '',
                'subject'      => ($proj['name'] ?? '案件') . ' 工事代金',
                'client_name'  => $action['client_name'] ?? ($proj['client_name'] ?? ''),
                'issue_date'   => date('Y-m-d'),
                'due_date'     => date('Y-m-d', strtotime('+30 days')),
                'items'        => $items,
                'amount'       => $amount,
                'tax'          => $tax,
                'total'        => $amount + $tax,
                'status'       => 'draft',
                'created_by'   => 'LINE_AI',
                'created_at'   => $ts,
                'source'       => 'line',
            ]);
            ago_kv_set('ago_invoices', json_encode($invs, JSON_UNESCAPED_UNICODE));
            _set_phase((int)$pid, 'invoiced', $ts);
            ago_project_log((int)$pid, 'LINE_AI', "請求書作成: {$doc_no}", $ts);
            break;

        case 'create_purchase_order':
            $pid    = (string)($action['project_id'] ?? '');
            $items  = normalize_items($action['items'] ?? []);
            $amount = array_sum(array_map(fn($i) => $i['qty'] * $i['unit_price'], $items));
            $pos    = json_decode(ago_kv_get('ago_purchase_orders') ?? '[]', true) ?: [];
            $new_id = count($pos) > 0 ? max(array_column($pos, 'id')) + 1 : 1;
            $doc_no = 'PO-' . date('Ym') . '-' . str_pad($new_id, 4, '0', STR_PAD_LEFT);
            array_unshift($pos, [
                'id'           => $new_id,
                'doc_type'     => 'purchase_order',
                'doc_number'   => $doc_no,
                'project_id'   => $pid,
                'client_name'  => $action['client_name'] ?? '',
                'issue_date'   => date('Y-m-d'),
                'items'        => $items,
                'amount'       => $amount,
                'tax'          => 0,
                'total'        => $amount,
                'status'       => 'sent',
                'created_by'   => 'LINE_AI',
                'created_at'   => $ts,
                'source'       => 'line',
            ]);
            ago_kv_set('ago_purchase_orders', json_encode($pos, JSON_UNESCAPED_UNICODE));
            break;

        case 'confirm_pending_actions':
            $confirmed = $action['confirmed'] ?? [];
            if (!$confirmed) break;

            $praw = ago_kv_get('ago_pending_actions');
            if (!$praw) break;
            $pdata = json_decode($praw, true);
            if (!$pdata || empty($pdata['actions'])) break;

            $executed_nos = [];
            foreach ($pdata['actions'] as $pa) {
                if (!in_array($pa['no'], $confirmed)) continue;

                if ($pa['type'] === 'update_order_status') {
                    $oid    = (int)$pa['id'];
                    $status = $pa['value'];
                    $ords   = json_decode(ago_kv_get('ago_orders') ?? '[]', true) ?: [];
                    foreach ($ords as &$o) {
                        if ($o['id'] == $oid) {
                            $o['status']     = $status;
                            $o['updated_at'] = $ts;
                            $o['_logs'][]    = ['status' => $status, 'updated_by' => 'LINE_AI(菅野さん承認)', 'created_at' => $ts, 'note' => ''];
                            break;
                        }
                    }
                    unset($o);
                    ago_kv_set('ago_orders', json_encode($ords, JSON_UNESCAPED_UNICODE));

                } elseif ($pa['type'] === 'update_phase') {
                    $pid   = (int)$pa['id'];
                    $phase = $pa['value'];
                    $projs = json_decode(ago_kv_get('ago_projects') ?? '[]', true) ?: [];
                    $old   = '';
                    foreach ($projs as &$p) {
                        if ($p['id'] == $pid) {
                            $old = $p['phase'] ?? '';
                            $p['phase']      = $phase;
                            $p['updated_at'] = $ts;
                            break;
                        }
                    }
                    unset($p);
                    ago_kv_set('ago_projects', json_encode($projs, JSON_UNESCAPED_UNICODE));
                    ago_project_log($pid, 'LINE_AI', "フェーズ変更(菅野さん承認): {$old} → {$phase}", $ts);
                }

                $executed_nos[] = $pa['no'];
            }

            // 実行済みを pending から除去（残りがあれば保持・なければクリア）
            if ($executed_nos) {
                $remaining = array_values(array_filter($pdata['actions'], fn($a) => !in_array($a['no'], $executed_nos)));
                if ($remaining) {
                    $pdata['actions'] = $remaining;
                    ago_kv_set('ago_pending_actions', json_encode($pdata, JSON_UNESCAPED_UNICODE));
                } else {
                    ago_kv_set('ago_pending_actions', '');
                }
            }
            break;
    }
}

// ── 内部ヘルパー ──────────────────────────────────────────────────

function _set_phase($pid, $phase, $ts) {
    $projs = json_decode(ago_kv_get('ago_projects') ?? '[]', true) ?: [];
    foreach ($projs as &$p) {
        if ($p['id'] == $pid) { $p['phase'] = $phase; $p['updated_at'] = $ts; break; }
    }
    unset($p);
    ago_kv_set('ago_projects', json_encode($projs, JSON_UNESCAPED_UNICODE));
}

function find_proj_by_id($pid) {
    $projs = json_decode(ago_kv_get('ago_projects') ?? '[]', true) ?: [];
    foreach ($projs as $p) {
        if ((string)$p['id'] === (string)$pid) return $p;
    }
    return [];
}

function normalize_items($items) {
    return array_map(fn($i) => [
        'description' => (string)($i['description'] ?? '施工費'),
        'qty'         => max(1, (int)($i['qty'] ?? 1)),
        'unit'        => (string)($i['unit'] ?? '式'),
        'unit_price'  => (int)($i['unit_price'] ?? 0),
    ], $items);
}

function ai_call($api_key, $system, $messages, $max_tokens = 1500) {
    $payload = [
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => $max_tokens,
        'messages'   => $messages,
    ];
    if ($system) $payload['system'] = $system;

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return null;
    $data = json_decode($res, true);
    return $data['content'][0]['text'] ?? null;
}

function find_project($projects, $id) {
    foreach ($projects as $p) {
        if ($p['id'] == $id) return $p;
    }
    return null;
}

function update_project_phase($project_id, $phase, $ts) {
    _set_phase((int)$project_id, $phase, $ts);
}

function ago_project_log($project_id, $agent, $message, $ts) {
    $logs   = json_decode(ago_kv_get('ago_project_logs') ?? '[]', true) ?: [];
    $max_id = count($logs) > 0 ? max(array_column($logs, 'id')) : 0;
    $logs[] = [
        'id'         => $max_id + 1,
        'project_id' => $project_id,
        'agent'      => $agent,
        'message'    => $message,
        'created_at' => $ts,
    ];
    ago_kv_set('ago_project_logs', json_encode($logs, JSON_UNESCAPED_UNICODE));
}

function ago_log_update($log_id, $updates) {
    $logs = json_decode(ago_kv_get('ago_line_logs') ?? '[]', true) ?: [];
    foreach ($logs as &$log) {
        if ($log['id'] === $log_id) {
            foreach ($updates as $k => $v) $log[$k] = $v;
            break;
        }
    }
    unset($log);
    ago_kv_set('ago_line_logs', json_encode($logs, JSON_UNESCAPED_UNICODE));
}
