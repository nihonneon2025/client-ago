<?php
// LINE受信メッセージをAIで清書→AGOマネに案件登録

function processLineMessage($log_entry, $api_key) {
    $text    = $log_entry['text'];
    $log_id  = $log_entry['id'];
    $ts      = $log_entry['ts'];

    // ステータスを「処理中」に更新
    ago_log_update($log_id, ['status' => 'processing']);

    // ── AI清書 ───────────────────────────────────────────────────
    $system_prompt = <<<'EOT'
あなたはAGO（看板・LED・電気工事・内装工事会社）の案件受付AIです。
スタッフがLINEで送ったメッセージから案件情報を読み取り、以下のJSON形式だけを返してください。
余計な説明や前置きは一切不要です。

{
  "name": "案件名（短く具体的に）",
  "client_name": "顧客名・会社名（不明なら「未設定」）",
  "description": "案件の概要（メッセージを整理して2〜3文で）"
}

案件名が不明な場合は顧客名＋「様 案件」などで補完してください。
EOT;

    $payload = [
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 512,
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
        ago_log_update($log_id, ['status' => 'error', 'error' => 'AI接続エラー: ' . $err]);
        return;
    }

    $ai_data = json_decode($result, true);
    $ai_text = $ai_data['content'][0]['text'] ?? '';

    preg_match('/\{[\s\S]*?\}/u', $ai_text, $matches);
    $project = json_decode($matches[0] ?? '{}', true);

    if (empty($project['name'])) {
        ago_log_update($log_id, ['status' => 'error', 'error' => 'AI清書に失敗（JSON取得不可）']);
        return;
    }

    // ── 案件登録 ────────────────────────────────────────────────
    $raw      = ago_kv_get('ago_projects');
    $projects = json_decode($raw ?? '[]', true) ?: [];

    $ids    = array_column($projects, 'id');
    $new_id = count($ids) > 0 ? max($ids) + 1 : 1;

    $new_project = [
        'id'          => $new_id,
        'name'        => $project['name'],
        'client_name' => $project['client_name'] ?? '未設定',
        'phase'       => 'reception',
        'description' => $project['description'] ?? '',
        'created_at'  => $ts,
        'source'      => 'line',
        'color'       => '#06c755'   // LINE緑で視覚的に識別しやすく
    ];
    array_unshift($projects, $new_project);

    ago_kv_set('ago_projects', json_encode($projects, JSON_UNESCAPED_UNICODE));

    // ログに登録結果を記録
    ago_log_update($log_id, [
        'status'     => 'registered',
        'project_id' => $new_id,
        'ai_result'  => $project
    ]);
}

// ── ログ更新ヘルパー ────────────────────────────────────────────

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
