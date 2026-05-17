<?php
// line-handler.php — 自立型 LINE AI（2026-05-16 全面刷新）
// ・会話履歴はユーザーごとに独立（ago_line_conv_{userId}）
// ・全業務データをコンテキストに渡し Claude が自律判断
// ・書類URLの返答・複数案件一括処理・売上照会など何でも対応

function processLineMessage($log_entry, $api_key, $line_token = '') {
    $userId      = $log_entry['userId'];
    $text        = $log_entry['text'];
    $quoted_text = $log_entry['quoted_text'] ?? null;
    $log_id      = $log_entry['id'];
    $ts          = $log_entry['ts'];
    $reply_token = $log_entry['reply_token'] ?? null;

    // リプライ（引用）がある場合は本文の前に引用元を付加
    if ($quoted_text) {
        $text = "【引用メッセージ】\n{$quoted_text}\n\n【指示】\n{$text}";
    }
    $base_url    = 'https://system002-od.ordermade-neon.com';

    ago_log_update($log_id, ['status' => 'processing']);

    // ── 1. ユーザー名解決 ───────────────────────────────────────────
    $users_map   = json_decode(ago_kv_get('ago_line_users') ?? '{}', true) ?: [];
    $kanno_id    = defined('KANNO_LINE_ID')   ? KANNO_LINE_ID   : '';
    $onodera_id  = defined('ONODERA_LINE_ID') ? ONODERA_LINE_ID : '';

    // 既知のIDは名前を自動セット（KVに未登録でも識別可能）
    if (empty($users_map[$kanno_id])   && $kanno_id)   $users_map[$kanno_id]   = '菅野社長';
    if (empty($users_map[$onodera_id]) && $onodera_id) $users_map[$onodera_id] = '小野寺';

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

    // LINE AI の学習データ（サーバーKVに永続保存・担当AI専用）
    $ai_learning = json_decode(ago_kv_get('ago_ai_learning_line') ?? '{}', true) ?: [];

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
    $is_onodera    = ($onodera_id && $userId === $onodera_id);
    $is_kanno_str  = ($kanno_id && $userId === $kanno_id) ? 'はい' : 'いいえ';
    $is_onodera_str = $is_onodera ? 'はい' : 'いいえ';
    $system = <<<SYS
あなたはAGOグループのLINE AIアシスタント「ウルバン」です。
スタッフからのLINEを受け取り、業務システムを操作しながら何でも自律的に対応します。
会話の文脈・言い回し・状況から意図を正確に読み取ってください。

## 会社情報
- 会社名: 株式会社AGOグループ
- 所在地: さいたま市南区
- 事業内容: LED看板・電気工事・内装工事・看板取り付け業
- 社員数: 約20名
- 顧客層: 飲食店・小売店・法人など幅広い

## 代表者
- 菅野社長（LINE ID登録済み）: AGOグループ代表。経営判断・承認の最終権限者。
  - 業務の承認・フェーズ変更・書類作成は菅野社長への確認フローを経る
  - 菅野社長からのメッセージには丁寧かつ簡潔に対応する

## AI組織
- このLINEボット（ウルバン）はAGOグループのAI本部長として機能する
- Claude Codeセッション上では複数のAI事業部（経理部・総務部・デザイン部など）が存在する
- LINEでのやり取りはスタッフ・菅野社長との直接コミュニケーション窓口
- 小野寺（LINE ID登録済み）: AGO SYSTEM MANAGERの開発担当。システム改修・設定の責任者。

## 送信者
{$user_name}（菅野社長本人: {$is_kanno_str} / 小野寺: {$is_onodera_str}）

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
    // {"type":"confirm_pending_actions","confirmed":[1,3],"declined":[{"no":2,"reason":"理由テキスト（省略可）"}]}  // 確認待ちを承認/却下。declinedにはno必須、reasonは菅野さんがNOの理由を添えた場合のみ
    // {"type":"claude_task","prompt":"デスクトップのClaude Codeに渡す指示を詳しく書く"}
    // ← 以下の場合にのみ使う: フォルダ作成・複数担当AIへの委託・外部ツール操作・複雑な多段階処理
    // ← 単純な状態更新・照会・書類作成はclaude_taskを使わず自分で処理する
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
## 確認フロー（最重要）
以下のアクションは必ず菅野さんの確認を経てから実行されます。

### 確認が必要なアクション
- create_estimate / create_invoice / create_purchase_order: 金銭書類のため毎回確認
- update_phase: フェーズ変更は業務判断のため毎回確認
- update_order_status / create_project: 学習中は確認あり（学習完了後は自動実行）

### 確認が必要なアクションをactionsに入れた場合のreplyルール
- 送信者がスタッフの場合: 「菅野社長に確認を取ります。承認後すぐに作業します」とreplyに書く
- 送信者が菅野さん本人の場合: 「確認のためもう一度確認を取ります」とreplyに書く

### 菅野さんからの承認/却下返信を受け取った場合
- 確認待ちアクションがある状態で「OK」「はい」「【1】OK」などが来たら confirm_pending_actions を使う
- confirmed: 承認した番号のリスト
- declined: 却下した番号とその理由。形式: [{"no":2,"reason":"理由（省略可）"}]
- 「全部OK」「全部はい」→ 全番号をconfirmedに
- 「全部NO」→ 全番号をdeclinedに（reason省略）
- 「【2】NO 資材確認が先」→ declined: [{"no":2,"reason":"資材確認が先"}]
- replyには「承認した操作を実行しました。スタッフへ通知します」のように書く
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

    // ── 7. アクション分類（即実行 vs 確認キュー） ────────────────────
    $CONFIRM_ALWAYS   = ['create_estimate','create_invoice','create_purchase_order','update_phase'];
    $CONFIRM_LEARNING = ['update_order_status','create_project'];
    $NO_CONFIRM       = ['register_name','set_schedule','clear_schedule','confirm_pending_actions','claude_task'];

    $to_execute = [];
    $to_confirm = [];

    foreach ($actions as $action) {
        $type = $action['type'] ?? '';
        if (in_array($type, $NO_CONFIRM) || $type === '') {
            $to_execute[] = $action;
        } elseif (in_array($type, $CONFIRM_ALWAYS)) {
            $to_confirm[] = $action;
        } elseif (in_array($type, $CONFIRM_LEARNING)) {
            // 学習データで自動実行可否を判定
            if (line_ai_should_auto($type, $ai_learning)) {
                $to_execute[] = $action;
            } else {
                $to_confirm[] = $action;
            }
        } else {
            $to_execute[] = $action;
        }
    }

    foreach ($to_execute as $action) {
        execute_action($action, $userId, $users_map, $ts, $line_token, $kanno_id);
    }

    if ($to_confirm) {
        $existing_raw  = ago_kv_get('ago_pending_actions') ?: '';
        $existing_data = $existing_raw ? json_decode($existing_raw, true) : null;
        if (!$existing_data || ($existing_data['expires'] ?? '') < date('Y-m-d')) {
            $existing_data = ['generated_at' => '', 'expires' => date('Y-m-d', strtotime('+1 day')), 'actions' => []];
        }
        $existing_data['generated_at'] = date('Y-m-d H:i:s');
        $max_no = count($existing_data['actions']) > 0 ? max(array_column($existing_data['actions'], 'no')) : 0;

        $is_kanno = ($userId === $kanno_id);

        $conf_lines = [];
        foreach ($to_confirm as $action) {
            $max_no++;
            $type     = $action['type'] ?? '';
            $readable = action_readable($action, $proj_list, $order_list);
            $stats    = $ai_learning[$type] ?? ['confirmed' => 0, 'declined' => 0];
            $total    = ($stats['confirmed'] ?? 0) + ($stats['declined'] ?? 0);
            $ai_note  = in_array($type, $CONFIRM_ALWAYS)
                ? '※金銭書類のため毎回確認'
                : '※学習中（' . ($total + 1) . '回目）';
            $existing_data['actions'][] = [
                'no'           => $max_no,
                'type'         => $type,
                'id'           => (int)($action['project_id'] ?? $action['order_id'] ?? 0),
                'value'        => $action['phase'] ?? $action['status'] ?? '',
                'label'        => $readable,
                'source'       => 'line',
                'sender'       => $user_name,
                'requester_id' => $userId,    // 承認/却下後の通知先
                'raw'          => $action,
            ];
            $conf_lines[] = "【{$max_no}】{$readable}\n　　{$ai_note}";
        }
        ago_kv_set('ago_pending_actions', json_encode($existing_data, JSON_UNESCAPED_UNICODE));

        if ($kanno_id && $line_token) {
            $from        = $is_kanno ? '菅野さん本人' : $user_name . 'さん';
            $quoted_text = '「' . mb_substr($text, 0, 40) . (mb_strlen($text) > 40 ? '…' : '') . '」';
            $no_example  = count($conf_lines) > 0 ? '【' . $existing_data['actions'][array_key_last($existing_data['actions'])]['no'] . '】' : '【1】';
            $push_msg    = "📋 {$from}からの依頼\n"
                . "━━━━━━━━━━━━━\n"
                . $quoted_text . "\n"
                . "━━━━━━━━━━━━━\n"
                . implode("\n\n", $conf_lines) . "\n\n"
                . "・「{$no_example}OK」→ 実行して" . ($is_kanno ? '完了' : $from . 'に完了通知') . "\n"
                . "・「{$no_example}NO」→ " . ($is_kanno ? '中止' : $from . 'に却下通知') . "\n"
                . "・「{$no_example}NO 理由」→ 理由も" . ($is_kanno ? '記録' : $from . 'に伝達') . "\n";
            line_push_msg($line_token, $kanno_id, $push_msg);
        }
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
function execute_action($action, $userId, $users_map, $ts, $line_token = '', $kanno_id = '') {
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

        case 'claude_task':
            $prompt = trim($action['prompt'] ?? '');
            if (!$prompt) break;
            $queue_raw = ago_kv_get('ago_claude_queue');
            $queue = json_decode($queue_raw ?? '[]', true) ?: [];
            $sender_name = $users_map[$userId] ?? ('スタッフ(' . substr($userId, -6) . ')');
            $queue[] = [
                'id'             => 'task_' . date('YmdHis') . '_' . substr($userId, -6),
                'status'         => 'pending',
                'prompt'         => $prompt,
                'requester_id'   => $userId,
                'requester_name' => $sender_name,
                'created_at'     => $ts,
                'result'         => null,
                'completed_at'   => null,
            ];
            ago_kv_set('ago_claude_queue', json_encode($queue, JSON_UNESCAPED_UNICODE));
            break;

        case 'confirm_pending_actions':
            $confirmed    = $action['confirmed'] ?? [];
            $declined_raw = $action['declined']  ?? [];
            // declined形式: [{"no":2,"reason":"理由"}] または後方互換の [2]
            $declined_map = [];
            foreach ($declined_raw as $d) {
                if (is_array($d)) {
                    $declined_map[(int)$d['no']] = $d['reason'] ?? '';
                } else {
                    $declined_map[(int)$d] = '';
                }
            }
            $declined_nos = array_keys($declined_map);

            if (!$confirmed && !$declined_nos) break;

            $praw = ago_kv_get('ago_pending_actions');
            if (!$praw) break;
            $pdata = json_decode($praw, true);
            if (!$pdata || empty($pdata['actions'])) break;

            $all_actions  = $pdata['actions']; // 学習・通知用に元リストを保持
            $executed_nos = [];

            foreach ($all_actions as $pa) {
                $pano = $pa['no'];

                if (in_array($pano, $confirmed)) {
                    // 実行
                    if (!empty($pa['raw'])) {
                        execute_action($pa['raw'], $userId, $users_map, $ts, $line_token, $kanno_id);
                    } elseif ($pa['type'] === 'update_order_status') {
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

                    $executed_nos[] = $pano;

                    // スタッフへ完了通知（依頼者が菅野さん以外の場合）
                    $requester_id = $pa['requester_id'] ?? '';
                    if ($requester_id && $requester_id !== $kanno_id && $line_token) {
                        line_push_msg($line_token, $requester_id,
                            "✅ {$pa['label']}\n菅野社長に承認いただきました。作業が完了しました。");
                    }

                } elseif (in_array($pano, $declined_nos)) {
                    // スタッフへ却下通知（依頼者が菅野さん以外の場合）
                    $requester_id = $pa['requester_id'] ?? '';
                    $reason       = $declined_map[$pano] ?? '';
                    if ($requester_id && $requester_id !== $kanno_id && $line_token) {
                        $msg = "❌ {$pa['label']}\n菅野社長に確認しましたが、今回は見送りとなりました。";
                        if ($reason) $msg .= "\n理由: {$reason}";
                        line_push_msg($line_token, $requester_id, $msg);
                    }
                }
            }

            // KVから処理済み（承認・却下）を削除
            $done_nos = array_merge($executed_nos, $declined_nos);
            if ($done_nos) {
                $remaining = array_values(array_filter($pdata['actions'], fn($a) => !in_array($a['no'], $done_nos)));
                if ($remaining) {
                    $pdata['actions'] = $remaining;
                    ago_kv_set('ago_pending_actions', json_encode($pdata, JSON_UNESCAPED_UNICODE));
                } else {
                    ago_kv_set('ago_pending_actions', '');
                }
            }

            // 担当AIごとの学習データを更新（承認 / 却下を記録）
            $learn_updates = [];
            foreach ($all_actions as $pa) {
                $pano      = $pa['no'];
                $atype     = $pa['raw']['type'] ?? $pa['type'];
                $src       = $pa['source'] ?? 'line';
                $learn_key = 'ago_ai_learning_' . $src;
                if (!isset($learn_updates[$learn_key])) $learn_updates[$learn_key] = [];
                if (!isset($learn_updates[$learn_key][$atype])) {
                    $learn_updates[$learn_key][$atype] = ['confirmed' => 0, 'declined' => 0];
                }
                if (in_array($pano, $confirmed)) {
                    $learn_updates[$learn_key][$atype]['confirmed']++;
                } elseif (in_array($pano, $declined_nos)) {
                    $learn_updates[$learn_key][$atype]['declined']++;
                }
            }
            foreach ($learn_updates as $learn_key => $updates) {
                $ldata = json_decode(ago_kv_get($learn_key) ?? '{}', true) ?: [];
                foreach ($updates as $atype => $delta) {
                    if (!isset($ldata[$atype])) $ldata[$atype] = ['confirmed' => 0, 'declined' => 0];
                    $ldata[$atype]['confirmed'] += $delta['confirmed'];
                    $ldata[$atype]['declined']  += $delta['declined'];
                }
                ago_kv_set($learn_key, json_encode($ldata, JSON_UNESCAPED_UNICODE));
            }
            break;
    }
}

// ── 内部ヘルパー ──────────────────────────────────────────────────

function action_readable($action, $proj_list, $order_list) {
    $phase_jp = [
        'reception' => '受付', 'designing' => '設計中', 'estimating' => '見積中',
        'contracting' => '契約中', 'ordering' => '発注中', 'construction-adj' => '施工調整中',
        'construction' => '施工中', 'completion-pending' => '完工（請求書未作成）',
        'invoiced' => '請求済', 'cancelled' => 'キャンセル',
    ];
    $status_jp = [
        'received' => '受注済み', 'ordered_china' => '中国発注済み',
        'factory_confirmed' => '工場確認済み', 'shipping_prep' => '配送準備中',
        'in_transit' => '配送中', 'delivered' => '配送完了',
        'cancel_customer' => 'キャンセル（顧客都合）', 'cancel_factory' => 'キャンセル（工場都合）',
    ];

    $find_proj  = function($id) use ($proj_list)  { foreach ($proj_list  as $p) { if ($p['id'] == $id) return $p['name']; } return null; };
    $find_order = function($id) use ($order_list) { foreach ($order_list as $o) { if ($o['id'] == $id) return $o['product']; } return null; };

    $type = $action['type'] ?? '';
    switch ($type) {
        case 'update_order_status':
            $product = $find_order($action['order_id'] ?? 0) ?? ('資材ID:' . ($action['order_id'] ?? '?'));
            $label   = $status_jp[$action['status'] ?? ''] ?? ($action['status'] ?? '');
            return "「{$product}」を「{$label}」に更新する";

        case 'update_phase':
            $proj  = $find_proj($action['project_id'] ?? 0) ?? ('案件ID:' . ($action['project_id'] ?? '?'));
            $label = $phase_jp[$action['phase'] ?? ''] ?? ($action['phase'] ?? '');
            return "「{$proj}」のフェーズを「{$label}」に変更する";

        case 'create_estimate':
            $proj = $find_proj($action['project_id'] ?? 0) ?? ('案件ID:' . ($action['project_id'] ?? '?'));
            return "「{$proj}」の見積書を作成する（{$action['client_name']}）";

        case 'create_invoice':
            $proj  = $find_proj($action['project_id'] ?? 0) ?? ('案件ID:' . ($action['project_id'] ?? '?'));
            $total = 0;
            foreach ($action['items'] ?? [] as $item) { $total += ($item['qty'] ?? 1) * ($item['unit_price'] ?? 0); }
            $tax_total = $total + (int)round($total * 0.1);
            $amount    = $tax_total > 0 ? '（税込 ¥' . number_format($tax_total) . '）' : '';
            return "「{$proj}」の請求書を作成する{$amount}（{$action['client_name']}）";

        case 'create_purchase_order':
            $proj = $find_proj($action['project_id'] ?? 0) ?? ('案件ID:' . ($action['project_id'] ?? '?'));
            return "「{$proj}」の発注書を作成する（発注先: {$action['client_name']}）";

        case 'create_project':
            return "新規案件「{$action['name']}」を登録する（顧客: {$action['client_name']}）";

        default:
            return action_summary($action);
    }
}

function line_ai_should_auto($type, $learning) {
    $stats    = $learning[$type] ?? [];
    $conf     = (int)($stats['confirmed'] ?? 0);
    $decl     = (int)($stats['declined']  ?? 0);
    $total    = $conf + $decl;
    if ($total < 3) return false;          // サンプル不足 → 確認する
    return ($conf / $total) >= 0.8;        // 承認率80%以上 → 自動実行
}

function action_summary($action) {
    $type = $action['type'] ?? '';
    switch ($type) {
        case 'create_estimate':
            return '見積書作成（案件ID:' . ($action['project_id'] ?? '?') . ' / ' . ($action['client_name'] ?? '') . '）';
        case 'create_invoice':
            return '請求書作成（案件ID:' . ($action['project_id'] ?? '?') . ' / ' . ($action['client_name'] ?? '') . '）';
        case 'create_purchase_order':
            return '発注書作成（案件ID:' . ($action['project_id'] ?? '?') . ' / 発注先:' . ($action['client_name'] ?? '') . '）';
        case 'update_phase':
            return '案件フェーズ変更（ID:' . ($action['project_id'] ?? '?') . ' → ' . ($action['phase'] ?? '') . '）';
        case 'update_order_status':
            return '資材ステータス更新（ID:' . ($action['order_id'] ?? '?') . ' → ' . ($action['status'] ?? '') . '）';
        case 'create_project':
            return '案件登録（' . ($action['name'] ?? '') . ' / ' . ($action['client_name'] ?? '') . '）';
        default:
            return $type;
    }
}

function line_push_msg($token, $userId, $message) {
    $payload = ['to' => $userId, 'messages' => [['type' => 'text', 'text' => $message]]];
    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

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
