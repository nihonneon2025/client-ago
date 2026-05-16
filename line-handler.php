<?php
// LINE受信メッセージをAIで意図判定→AGOマネに反映→LINEに返信

function processLineMessage($log_entry, $api_key, $line_token = '') {
    $text        = $log_entry['text'];
    $log_id      = $log_entry['id'];
    $ts          = $log_entry['ts'];
    $reply_token = $log_entry['reply_token'] ?? null;

    ago_log_update($log_id, ['status' => 'processing']);

    // ── 現在の案件一覧・書類一覧を取得 ──────────────────────────
    $raw_projects = ago_kv_get('ago_projects');
    $projects     = json_decode($raw_projects ?? '[]', true) ?: [];

    // ── 会話履歴を取得（最新5件） ────────────────────────────────
    $raw_conv = ago_kv_get('ago_line_conversation');
    $conv     = json_decode($raw_conv ?? '[]', true) ?: [];
    $recent   = array_slice($conv, 0, 5);

    // ── 案件サマリー（AIに渡す） ─────────────────────────────────
    $projects_summary = '';
    foreach (array_slice($projects, 0, 20) as $p) {
        $projects_summary .= sprintf(
            "  ID:%d 案件名:%s 顧客:%s フェーズ:%s\n",
            $p['id'], $p['name'], $p['client_name'] ?? '未設定', $p['phase'] ?? '不明'
        );
    }

    $conv_text = '';
    foreach (array_reverse($recent) as $c) {
        $role = $c['role'] === 'user' ? '菅野さん' : 'AI';
        $conv_text .= $role . ': ' . $c['text'] . "\n";
    }

    $phase_map = '受付=reception, 受注確定=order-confirmed, 設計中=designing, 見積中=estimating, 契約中=contracting, 発注中=ordering, 施工調整中=construction-adj, 施工中=construction, 設計検査及び引渡し前=design-inspection, 施主検査=client-inspection, 是正=correction, 消防検査=fire-inspection, 保健所検査=health-inspection, 公安委員会検査=police-inspection, 完工及び請求書未作成=completion-pending, 請求済=invoiced, キャンセル=cancelled';

    // ── Step1: 意図判定 ──────────────────────────────────────────
    $intent_prompt = <<<EOT
あなたはAGO（看板・LED・電気工事・内装工事会社）の業務AIです。
スタッフのLINEメッセージから業務意図を判定し、JSONのみ返してください。

【現在の案件一覧】
{$projects_summary}
【フェーズ値】{$phase_map}
【直近の会話】
{$conv_text}

返すJSON:
{
  "intent": "new_project / update_phase / create_estimate / create_invoice / status_check / general",
  "project_id": null または案件ID（数値）,
  "phase": "フェーズのシステム値（update_phaseの時のみ）",
  "amount": 金額税抜（create_estimate/create_invoiceの時のみ・数値）,
  "reply": "菅野さんへのLINE返信文（丁寧・簡潔）",
  "new_project_data": {
    "name": "案件名",
    "client_name": "顧客名",
    "description": "概要2〜3文"
  }
}

判定ルール:
- 新しい依頼・案件・工事の話 → new_project（new_project_dataを必ず埋める）
- フェーズを進める・変える → update_phase（phaseを必ず埋める）
- 見積もり・見積書 → create_estimate（amountを埋める・不明は0）
- 請求書 → create_invoice（amountを埋める・不明は0）
- 現状確認・状況報告 → status_check
- その他・雑談 → general
EOT;

    $intent_result = ai_call($api_key, $intent_prompt, $text, 768);
    if (!$intent_result) {
        $msg = 'AIとの通信でエラーが発生しました。しばらくして再送してください。';
        ago_log_update($log_id, ['status' => 'error', 'error' => 'AI接続エラー']);
        if ($line_token && $reply_token) line_reply($line_token, $reply_token, $msg);
        return;
    }

    preg_match('/\{[\s\S]*\}/u', $intent_result, $matches);
    $parsed = json_decode($matches[0] ?? '{}', true);

    if (empty($parsed['intent'])) {
        $msg = 'メッセージを処理できませんでした。もう少し具体的に送ってください。';
        ago_log_update($log_id, ['status' => 'error', 'error' => 'AI判定失敗']);
        if ($line_token && $reply_token) line_reply($line_token, $reply_token, $msg);
        return;
    }

    $intent     = $parsed['intent'];
    $project_id = isset($parsed['project_id']) ? (int)$parsed['project_id'] : null;
    $reply_msg  = $parsed['reply'] ?? '処理しました。';

    // ── Step2: 意図別処理 ────────────────────────────────────────
    $result_project_id = null;
    $ai_result_log     = [];

    switch ($intent) {

        // ── 新規案件登録 ────────────────────────────────────────
        case 'new_project':
            $nd = $parsed['new_project_data'] ?? [];
            if (!empty($nd['name'])) {
                $projs    = json_decode(ago_kv_get('ago_projects') ?? '[]', true) ?: [];
                $ids      = array_column($projs, 'id');
                $new_id   = count($ids) > 0 ? max($ids) + 1 : 1;
                $new_proj = [
                    'id'          => $new_id,
                    'name'        => $nd['name'],
                    'client_name' => $nd['client_name'] ?? '未設定',
                    'phase'       => 'reception',
                    'description' => $nd['description'] ?? '',
                    'created_at'  => $ts,
                    'updated_at'  => $ts,
                    'source'      => 'line',
                    'color'       => '#06c755'
                ];
                array_unshift($projs, $new_proj);
                ago_kv_set('ago_projects', json_encode($projs, JSON_UNESCAPED_UNICODE));
                // 操作ログ
                ago_project_log($new_id, 'LINE', "新規案件登録（{$nd['name']}）", $ts);
                $result_project_id = $new_id;
                $ai_result_log = ['action' => 'new_project', 'name' => $nd['name'], 'client_name' => $nd['client_name'] ?? '未設定'];
            }
            break;

        // ── フェーズ更新 ────────────────────────────────────────
        case 'update_phase':
            $new_phase = $parsed['phase'] ?? '';
            if ($project_id && $new_phase) {
                $projs = json_decode(ago_kv_get('ago_projects') ?? '[]', true) ?: [];
                $old_phase = '';
                foreach ($projs as &$p) {
                    if ($p['id'] == $project_id) {
                        $old_phase      = $p['phase'] ?? '';
                        $p['phase']     = $new_phase;
                        $p['updated_at'] = $ts;
                        break;
                    }
                }
                unset($p);
                ago_kv_set('ago_projects', json_encode($projs, JSON_UNESCAPED_UNICODE));
                // 操作ログ
                ago_project_log($project_id, 'LINE', "フェーズ変更: {$old_phase} → {$new_phase}", $ts);
                $result_project_id = $project_id;
                $ai_result_log = ['action' => 'update_phase', 'phase' => $new_phase, 'old_phase' => $old_phase];
            }
            break;

        // ── 見積書作成 ──────────────────────────────────────────
        case 'create_estimate':
            $amount = (int)($parsed['amount'] ?? 0);
            $target = find_project($projects, $project_id);
            if ($target) {
                // Step2b: AIで見積書明細を生成
                $items = generate_items($api_key, $text, $amount, $target);
                if (!$amount && !empty($items)) {
                    $amount = array_sum(array_map(fn($i) => ($i['qty'] ?? 1) * ($i['unit_price'] ?? 0), $items));
                }
                $tax_amt = (int)round($amount * 0.1);
                $ests    = json_decode(ago_kv_get('ago_estimates') ?? '[]', true) ?: [];
                $new_eid = count($ests) > 0 ? max(array_column($ests, 'id')) + 1 : 1;
                $doc_no  = 'EST-' . date('Ym') . '-' . str_pad($new_eid, 4, '0', STR_PAD_LEFT);
                $new_est = [
                    'id'           => $new_eid,
                    'doc_type'     => 'estimate',
                    'doc_number'   => $doc_no,
                    'project_id'   => (string)$project_id,
                    'project_name' => $target['name'] ?? '',
                    'subject'      => ($target['name'] ?? '案件') . ' 見積書',
                    'client_name'  => $target['client_name'] ?? '未設定',
                    'issue_date'   => date('Y-m-d'),
                    'amount'       => $amount,
                    'tax'          => $tax_amt,
                    'total'        => $amount + $tax_amt,
                    'items'        => $items,
                    'status'       => 'draft',
                    'created_by'   => 'LINE_AI',
                    'created_at'   => $ts,
                    'source'       => 'line'
                ];
                array_unshift($ests, $new_est);
                ago_kv_set('ago_estimates', json_encode($ests, JSON_UNESCAPED_UNICODE));
                // フェーズを見積中に更新
                update_project_phase($project_id, 'estimating', $ts);
                ago_project_log($project_id, 'LINE', "見積書作成（税抜{$amount}円）", $ts);
                $result_project_id = $project_id;
                $ai_result_log = ['action' => 'create_estimate', 'amount' => $amount, 'items_count' => count($items)];
                $reply_msg .= "\n書類タブで見積書をご確認・修正いただけます。";
            } else {
                $reply_msg = '対象の案件が特定できませんでした。案件名を含めてもう一度送ってください。';
            }
            break;

        // ── 請求書作成 ──────────────────────────────────────────
        case 'create_invoice':
            $amount = (int)($parsed['amount'] ?? 0);
            $target = find_project($projects, $project_id);
            if ($target) {
                $items   = generate_items($api_key, $text, $amount, $target);
                if (!$amount && !empty($items)) {
                    $amount = array_sum(array_map(fn($i) => ($i['qty'] ?? 1) * ($i['unit_price'] ?? 0), $items));
                }
                $tax_inv = (int)round($amount * 0.1);
                $invs    = json_decode(ago_kv_get('ago_invoices') ?? '[]', true) ?: [];
                $new_iid = count($invs) > 0 ? max(array_column($invs, 'id')) + 1 : 1;
                $doc_no_inv = 'INV-' . date('Ym') . '-' . str_pad($new_iid, 4, '0', STR_PAD_LEFT);
                $new_inv = [
                    'id'           => $new_iid,
                    'doc_type'     => 'invoice',
                    'doc_number'   => $doc_no_inv,
                    'project_id'   => (string)$project_id,
                    'project_name' => $target['name'] ?? '',
                    'subject'      => ($target['name'] ?? '案件') . ' 工事代金',
                    'client_name'  => $target['client_name'] ?? '未設定',
                    'issue_date'   => date('Y-m-d'),
                    'due_date'     => date('Y-m-d', strtotime('+30 days')),
                    'amount'       => $amount,
                    'tax'          => $tax_inv,
                    'total'        => $amount + $tax_inv,
                    'items'        => $items,
                    'status'       => 'draft',
                    'created_by'   => 'LINE_AI',
                    'created_at'   => $ts,
                    'source'       => 'line'
                ];
                array_unshift($invs, $new_inv);
                ago_kv_set('ago_invoices', json_encode($invs, JSON_UNESCAPED_UNICODE));
                // フェーズを完工/請求未作成→請求済に更新
                update_project_phase($project_id, 'invoiced', $ts);
                ago_project_log($project_id, 'LINE', "請求書作成（税抜{$amount}円）", $ts);
                $result_project_id = $project_id;
                $ai_result_log = ['action' => 'create_invoice', 'amount' => $amount, 'items_count' => count($items)];
                $reply_msg .= "\n書類タブで請求書をご確認・修正いただけます。";
            } else {
                $reply_msg = '対象の案件が特定できませんでした。案件名を含めてもう一度送ってください。';
            }
            break;

        // ── 状況確認・汎用 ─────────────────────────────────────
        case 'status_check':
        case 'general':
        default:
            $ai_result_log = ['action' => $intent];
            break;
    }

    // ── ログ更新 ────────────────────────────────────────────────
    ago_log_update($log_id, [
        'status'     => in_array($intent, ['status_check','general']) ? 'replied' : 'registered',
        'project_id' => $result_project_id ?? $project_id,
        'ai_result'  => $ai_result_log,
        'ai_reply'   => $reply_msg
    ]);

    // ── 会話履歴を保存 ───────────────────────────────────────────
    $conv[] = ['role' => 'user',      'text' => $text,      'ts' => $ts];
    $conv[] = ['role' => 'assistant', 'text' => $reply_msg, 'ts' => $ts];
    if (count($conv) > 20) $conv = array_slice($conv, -20);
    ago_kv_set('ago_line_conversation', json_encode($conv, JSON_UNESCAPED_UNICODE));

    // ── LINEに返信 ───────────────────────────────────────────────
    if ($line_token && $reply_token) {
        line_reply($line_token, $reply_token, $reply_msg);
    }
}

// ── 見積・請求書の明細をAIで生成 ────────────────────────────────

function generate_items($api_key, $user_text, $amount, $project) {
    $proj_name = $project['name'] ?? '案件';
    $client    = $project['client_name'] ?? '顧客';
    $desc      = $project['description'] ?? '';
    $amount_hint = $amount ? "合計金額（税抜）の目安: {$amount}円" : '';

    $prompt = <<<EOT
AGO（看板・LED・電気工事・内装工事会社）の見積書・請求書明細を生成してください。
案件名: {$proj_name}
顧客: {$client}
概要: {$desc}
{$amount_hint}
ユーザーの指示: {$user_text}

以下のJSON配列のみ返してください（3〜6項目）:
[
  {"description": "項目名", "qty": 数量（数値）, "unit_price": 単価（数値・税抜）},
  ...
]

看板・LED・電気工事・内装工事の現実的な項目と金額を生成してください。
EOT;

    $result = ai_call($api_key, '', $prompt, 512);
    if (!$result) return [['description' => '施工費', 'qty' => 1, 'unit_price' => $amount ?: 0]];

    preg_match('/\[[\s\S]*\]/u', $result, $m);
    $items = json_decode($m[0] ?? '[]', true);
    if (!is_array($items) || empty($items)) {
        return [['description' => '施工費', 'qty' => 1, 'unit_price' => $amount ?: 0]];
    }
    // 型を正規化
    return array_map(fn($i) => [
        'description' => (string)($i['description'] ?? '施工費'),
        'qty'         => (int)($i['qty'] ?? 1),
        'unit_price'  => (int)($i['unit_price'] ?? 0)
    ], $items);
}

// ── 共通ヘルパー ────────────────────────────────────────────────

function ai_call($api_key, $system, $user, $max_tokens = 512) {
    $payload = [
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => $max_tokens,
        'messages'   => [['role' => 'user', 'content' => $user]]
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
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 30
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
    $projs = json_decode(ago_kv_get('ago_projects') ?? '[]', true) ?: [];
    foreach ($projs as &$p) {
        if ($p['id'] == $project_id) {
            $p['phase']      = $phase;
            $p['updated_at'] = $ts;
            break;
        }
    }
    unset($p);
    ago_kv_set('ago_projects', json_encode($projs, JSON_UNESCAPED_UNICODE));
}

function ago_project_log($project_id, $agent, $message, $ts) {
    $raw  = ago_kv_get('ago_project_logs');
    $logs = json_decode($raw ?? '[]', true) ?: [];
    $ids  = array_column($logs, 'id');
    $logs[] = [
        'id'         => count($ids) > 0 ? max($ids) + 1 : 1,
        'project_id' => $project_id,
        'agent'      => $agent,
        'message'    => $message,
        'created_at' => $ts
    ];
    ago_kv_set('ago_project_logs', json_encode($logs, JSON_UNESCAPED_UNICODE));
}

// ── ログ更新 ────────────────────────────────────────────────────

function ago_log_update($log_id, $updates) {
    $raw  = ago_kv_get('ago_line_logs');
    $logs = json_decode($raw ?? '[]', true) ?: [];
    foreach ($logs as &$log) {
        if ($log['id'] === $log_id) {
            foreach ($updates as $k => $v) $log[$k] = $v;
            break;
        }
    }
    unset($log);
    ago_kv_set('ago_line_logs', json_encode($logs, JSON_UNESCAPED_UNICODE));
}
