<?php
// LINE受信メッセージをAIで意図判定→AGOマネに反映→LINEに返信

function processLineMessage($log_entry, $api_key, $line_token = '') {
    $text        = $log_entry['text'];
    $log_id      = $log_entry['id'];
    $ts          = $log_entry['ts'];
    $reply_token = $log_entry['reply_token'] ?? null;

    ago_log_update($log_id, ['status' => 'processing']);

    // ── 現在の案件一覧を取得 ─────────────────────────────────────
    $raw_projects = ago_kv_get('ago_projects');
    $projects     = json_decode($raw_projects ?? '[]', true) ?: [];

    // ── 会話履歴を取得（最新5件） ────────────────────────────────
    $raw_conv = ago_kv_get('ago_line_conversation');
    $conv     = json_decode($raw_conv ?? '[]', true) ?: [];
    $recent   = array_slice($conv, 0, 5);

    // ── AI判定プロンプト ─────────────────────────────────────────
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

    $phase_map = json_encode([
        '受付'             => 'reception',
        '受注確定'         => 'order-confirmed',
        '設計中'           => 'designing',
        '見積中'           => 'estimating',
        '契約中'           => 'contracting',
        '発注中'           => 'ordering',
        '施工調整中'       => 'construction-adj',
        '施工中'           => 'construction',
        '設計検査及び引渡し前' => 'design-inspection',
        '施主検査'         => 'client-inspection',
        '是正'             => 'correction',
        '消防検査'         => 'fire-inspection',
        '保健所検査'       => 'health-inspection',
        '公安委員会検査'   => 'police-inspection',
        '完工及び請求書未作成' => 'completion-pending',
        '請求済'           => 'invoiced',
        'キャンセル'       => 'cancelled',
    ], JSON_UNESCAPED_UNICODE);

    $system_prompt = <<<EOT
あなたはAGO（看板・LED・電気工事・内装工事会社）の業務AIアシスタントです。
スタッフ（主に菅野さん）がLINEで送ったメッセージを解析し、適切な業務アクションを決定してください。

【現在の案件一覧】
{$projects_summary}

【フェーズ値マッピング】
{$phase_map}

【直近の会話履歴】
{$conv_text}

以下のJSONのみを返してください。余計な説明不要。

{
  "intent": "one of: new_project / update_phase / create_estimate / create_invoice / status_check / general",
  "project_id": null または既存案件のID（数値）,
  "reply": "菅野さんへのLINE返信文（丁寧・簡潔・日本語）",
  "data": {
    // intent=new_project の場合:
    "name": "案件名",
    "client_name": "顧客名",
    "description": "概要",

    // intent=update_phase の場合:
    "phase": "フェーズのシステム値（例: designing）",

    // intent=create_estimate の場合:
    "amount": 金額（数値・税抜）,
    "items": [{"description":"内容","qty":1,"unit_price":金額}],

    // intent=create_invoice の場合:
    "amount": 金額（数値・税抜）,
    "items": [{"description":"内容","qty":1,"unit_price":金額}],

    // intent=status_check / general の場合:
    // data は空オブジェクト {}
  }
}

判定ルール:
- 新しい依頼・案件・工事の話 → new_project
- 「〇〇に進めて」「フェーズ変えて」「設計中にして」等 → update_phase
- 「見積作って」「見積もりお願い」等 → create_estimate
- 「請求書作って」「請求お願い」等 → create_invoice
- 「今の状況は？」「どうなってる？」等 → status_check
- その他・雑談・不明 → general

project_idは案件一覧から最も関連しそうなIDを選ぶ。新規案件の場合はnull。
EOT;

    $payload = [
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 1024,
        'system'     => $system_prompt,
        'messages'   => [['role' => 'user', 'content' => $text]]
    ];

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
    $result = curl_exec($ch);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($err) {
        $msg = 'AIとの通信でエラーが発生しました。しばらくしてから再送してください。';
        ago_log_update($log_id, ['status' => 'error', 'error' => 'AI接続エラー: ' . $err]);
        if ($line_token && $reply_token) line_reply($line_token, $reply_token, $msg);
        return;
    }

    $ai_data = json_decode($result, true);
    $ai_text = $ai_data['content'][0]['text'] ?? '';

    preg_match('/\{[\s\S]*\}/u', $ai_text, $matches);
    $parsed = json_decode($matches[0] ?? '{}', true);

    if (empty($parsed['intent'])) {
        $msg = 'メッセージを処理できませんでした。もう少し具体的に送ってください。';
        ago_log_update($log_id, ['status' => 'error', 'error' => 'AI判定失敗']);
        if ($line_token && $reply_token) line_reply($line_token, $reply_token, $msg);
        return;
    }

    $intent     = $parsed['intent'];
    $project_id = $parsed['project_id'] ?? null;
    $reply_msg  = $parsed['reply'] ?? '処理しました。';
    $data       = $parsed['data'] ?? [];

    // ── 意図別処理 ───────────────────────────────────────────────

    $result_project_id = null;

    switch ($intent) {

        case 'new_project':
            if (!empty($data['name'])) {
                $raw      = ago_kv_get('ago_projects');
                $projs    = json_decode($raw ?? '[]', true) ?: [];
                $ids      = array_column($projs, 'id');
                $new_id   = count($ids) > 0 ? max($ids) + 1 : 1;
                $new_proj = [
                    'id'          => $new_id,
                    'name'        => $data['name'],
                    'client_name' => $data['client_name'] ?? '未設定',
                    'phase'       => 'reception',
                    'description' => $data['description'] ?? '',
                    'created_at'  => $ts,
                    'source'      => 'line',
                    'color'       => '#06c755'
                ];
                array_unshift($projs, $new_proj);
                ago_kv_set('ago_projects', json_encode($projs, JSON_UNESCAPED_UNICODE));
                $result_project_id = $new_id;
                ago_log_update($log_id, [
                    'status'     => 'registered',
                    'project_id' => $new_id,
                    'ai_result'  => $data
                ]);
            }
            break;

        case 'update_phase':
            if ($project_id !== null && !empty($data['phase'])) {
                $raw   = ago_kv_get('ago_projects');
                $projs = json_decode($raw ?? '[]', true) ?: [];
                foreach ($projs as &$p) {
                    if ($p['id'] == $project_id) {
                        $p['phase'] = $data['phase'];
                        break;
                    }
                }
                unset($p);
                ago_kv_set('ago_projects', json_encode($projs, JSON_UNESCAPED_UNICODE));
                $result_project_id = $project_id;
                ago_log_update($log_id, [
                    'status'     => 'registered',
                    'project_id' => $project_id,
                    'ai_result'  => ['action' => 'update_phase', 'phase' => $data['phase']]
                ]);
            }
            break;

        case 'create_estimate':
            if ($project_id !== null && !empty($data['amount'])) {
                $target_proj = null;
                $raw_p = ago_kv_get('ago_projects');
                $projs = json_decode($raw_p ?? '[]', true) ?: [];
                foreach ($projs as $p) {
                    if ($p['id'] == $project_id) { $target_proj = $p; break; }
                }
                if ($target_proj) {
                    $raw_e    = ago_kv_get('ago_estimates');
                    $ests     = json_decode($raw_e ?? '[]', true) ?: [];
                    $eids     = array_column($ests, 'id');
                    $new_eid  = count($eids) > 0 ? max($eids) + 1 : 1;
                    $items    = !empty($data['items']) ? $data['items'] : [
                        ['description' => '施工費', 'qty' => 1, 'unit_price' => $data['amount']]
                    ];
                    $new_est  = [
                        'id'          => $new_eid,
                        'doc_type'    => 'estimate',
                        'project_id'  => (string)$project_id,
                        'client_name' => $target_proj['client_name'] ?? '未設定',
                        'issue_date'  => date('Y-m-d'),
                        'amount'      => $data['amount'],
                        'tax'         => round($data['amount'] * 0.1),
                        'items'       => $items,
                        'status'      => 'draft',
                        'created_at'  => $ts,
                        'source'      => 'line'
                    ];
                    array_unshift($ests, $new_est);
                    ago_kv_set('ago_estimates', json_encode($ests, JSON_UNESCAPED_UNICODE));
                    $result_project_id = $project_id;
                    ago_log_update($log_id, [
                        'status'     => 'registered',
                        'project_id' => $project_id,
                        'ai_result'  => ['action' => 'create_estimate', 'amount' => $data['amount']]
                    ]);
                }
            }
            break;

        case 'create_invoice':
            if ($project_id !== null && !empty($data['amount'])) {
                $target_proj = null;
                $raw_p = ago_kv_get('ago_projects');
                $projs = json_decode($raw_p ?? '[]', true) ?: [];
                foreach ($projs as $p) {
                    if ($p['id'] == $project_id) { $target_proj = $p; break; }
                }
                if ($target_proj) {
                    $raw_i   = ago_kv_get('ago_invoices');
                    $invs    = json_decode($raw_i ?? '[]', true) ?: [];
                    $iids    = array_column($invs, 'id');
                    $new_iid = count($iids) > 0 ? max($iids) + 1 : 1;
                    $items   = !empty($data['items']) ? $data['items'] : [
                        ['description' => '施工費', 'qty' => 1, 'unit_price' => $data['amount']]
                    ];
                    $new_inv = [
                        'id'          => $new_iid,
                        'doc_type'    => 'invoice',
                        'project_id'  => (string)$project_id,
                        'client_name' => $target_proj['client_name'] ?? '未設定',
                        'issue_date'  => date('Y-m-d'),
                        'amount'      => $data['amount'],
                        'tax'         => round($data['amount'] * 0.1),
                        'items'       => $items,
                        'status'      => 'unpaid',
                        'created_at'  => $ts,
                        'source'      => 'line'
                    ];
                    array_unshift($invs, $new_inv);
                    ago_kv_set('ago_invoices', json_encode($invs, JSON_UNESCAPED_UNICODE));
                    $result_project_id = $project_id;
                    ago_log_update($log_id, [
                        'status'     => 'registered',
                        'project_id' => $project_id,
                        'ai_result'  => ['action' => 'create_invoice', 'amount' => $data['amount']]
                    ]);
                }
            }
            break;

        case 'status_check':
        case 'general':
        default:
            ago_log_update($log_id, [
                'status'     => 'replied',
                'project_id' => $project_id,
                'ai_result'  => ['action' => $intent]
            ]);
            break;
    }

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

// ── ログ更新ヘルパー ─────────────────────────────────────────────

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
