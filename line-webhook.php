<?php
header('Content-Type: application/json; charset=utf-8');

// OPcacheが古いline-handler.phpを使い続けないようにする
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__DIR__ . '/line-handler.php', true);
}

// ファイルベースのデバッグログ（api.php不要・サーバー上のwh_debug.logに書き込む）
function wh_log($msg) {
    $line = date('Y-m-d H:i:s') . ' ' . $msg . "\n";
    @file_put_contents(__DIR__ . '/wh_debug.log', $line, FILE_APPEND | LOCK_EX);
}

wh_log('[START] webhook called v20260528-1500');

// APIキー・LINEトークンはapi-config.phpから取得
$api_key             = '';
$LINE_CHANNEL_SECRET = '';
$LINE_CHANNEL_TOKEN  = '';
$config_file = __DIR__ . '/api-config.php';
if (file_exists($config_file)) {
    require $config_file;
    $api_key             = defined('ANTHROPIC_API_KEY')   ? ANTHROPIC_API_KEY   : '';
    $LINE_CHANNEL_SECRET = defined('LINE_CHANNEL_SECRET') ? LINE_CHANNEL_SECRET : '';
    $LINE_CHANNEL_TOKEN  = defined('LINE_CHANNEL_TOKEN')  ? LINE_CHANNEL_TOKEN  : '';
    wh_log('[OK] api-config.php loaded. api_key=' . (strlen($api_key) > 0 ? 'SET' : 'EMPTY') . ' token=' . (strlen($LINE_CHANNEL_TOKEN) > 0 ? 'SET' : 'EMPTY'));
} else {
    wh_log('[NG] api-config.php NOT FOUND');
}

// 許可ユーザーIDはKVストアから動的に読み込む
$allowed_ids = [];
$allowed_raw = ago_kv_get('ago_line_allowed_users');
if ($allowed_raw) {
    $allowed_ids = json_decode($allowed_raw, true) ?: [];
}

$body = file_get_contents('php://input');
$data = json_decode($body, true);

// 署名検証（チャンネルシークレットが設定済みの場合）
wh_log('[INFO] body_len=' . strlen($body) . ' events=' . count($data['events'] ?? []));
if ($LINE_CHANNEL_SECRET) {
    $signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';
    $expected  = base64_encode(hash_hmac('sha256', $body, $LINE_CHANNEL_SECRET, true));
    if (!hash_equals($expected, $signature)) {
        wh_log('[NG] signature mismatch – returning 403');
        http_response_code(403);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
    wh_log('[OK] signature verified');
}

// イベント処理
$events   = $data['events'] ?? [];
$deferred = []; // AI処理が必要なイベントをここに積む
foreach ($events as $event) {
    if ($event['type'] !== 'message') continue;

    // ファイル・画像メッセージ: ダウンロードしてキャッシュ（返信なし）
    if (in_array($event['message']['type'], ['file', 'image']) && $LINE_CHANNEL_TOKEN) {
        $fid   = $event['message']['id'];
        $fname = $event['message']['fileName'] ?? ($event['message']['type'] . '_' . $fid);
        $url   = save_line_file($fid, $LINE_CHANNEL_TOKEN, $fname);
        if ($url) {
            $msg_cache = json_decode(ago_kv_get('ago_line_msg_cache') ?? '{}', true) ?: [];
            $msg_cache[$fid] = [
                            'type'    => $event['message']['type'],
                            'url'     => $url,
                            'filename'=> $fname,
                            'groupId' => $event['source']['groupId'] ?? null,
                            'userId'  => $event['source']['userId'] ?? null,
                            'ts'      => time(),
                        ];
            if (count($msg_cache) > 200) $msg_cache = array_slice($msg_cache, -200, null, true);
            ago_kv_set('ago_line_msg_cache', json_encode($msg_cache, JSON_UNESCAPED_UNICODE));
            wh_log('[FILE] saved id=' . $fid . ' url=' . $url);
        } else {
            wh_log('[FILE] download failed id=' . $fid);
        }
    }
    if ($event['message']['type'] !== 'text') continue;

    $userId     = $event['source']['userId'] ?? '';
    $text       = trim($event['message']['text'] ?? '');
    $groupId    = $event['source']['groupId'] ?? null;
    $source     = $groupId ? 'group' : 'direct';
    $replyToken = $event['replyToken'] ?? null;

    // 直近ファイルのコンテキスト自動注入（引用なしで「PDFにして」等を言った場合）
    // 同じグループで最後に送ったファイルをAIに見せる（時間制限なし・引用なしで「PDFにして」等を言った場合）
    if (empty($event['message']['quotedMessageId']) && preg_match('/PDF|変換|ドライブ|送って|保存|印刷/u', $text)) {
        $f_cache    = json_decode(ago_kv_get('ago_line_msg_cache') ?? '{}', true) ?: [];
        $candidates = [];
        foreach ($f_cache as $fid => $fdata) {
            if (!is_array($fdata)) continue;
            $same_ctx = $groupId ? (($fdata['groupId'] ?? null) === $groupId) : (($fdata['userId'] ?? null) === $userId);
            if ($same_ctx && !empty($fdata['url'])) {
                $candidates[] = $fdata;
            }
        }
        if ($candidates) {
            // 最新3件を注入（tsで降順ソート）
            usort($candidates, fn($a, $b) => ($b['ts'] ?? 0) - ($a['ts'] ?? 0));
            $top3  = array_slice($candidates, 0, 3);
            $lines = array_map(fn($f) => '[直近ファイル: ' . ($f['filename'] ?? 'file') . ' URL: ' . ($f['url'] ?? '') . ']', $top3);
            $text .= "\n\n【直近のファイル（引用なし・新しい順）】\n" . implode("\n", $lines);
            wh_log('[CTX_INJECT] injected ' . count($top3) . ' recent file(s)');
        }
    }

    // リプライ（引用）メッセージの内容を取得
    $quoted_text = null;
    if (!empty($event['message']['quotedMessageId'])) {
        $quoted_id = $event['message']['quotedMessageId'];
        $q = $event['message']['quote'] ?? [];

        // ① テキスト引用
        if (!empty($q['text'])) {
            $quoted_text = $q['text'];
        }

        // ② ファイル・画像・動画の引用 → その場で即ダウンロード
        if (!$quoted_text && !empty($q['type']) && in_array($q['type'], ['file', 'image', 'video']) && $LINE_CHANNEL_TOKEN) {
            $qtype = $q['type'];
            $fname = $q['fileName'] ?? ($qtype === 'image' ? $quoted_id . '.jpg' : $quoted_id . '.bin');
            $url   = save_line_file($quoted_id, $LINE_CHANNEL_TOKEN, $fname);
            if ($url) {
                $quoted_text = '[ファイル:' . $fname . ' URL:' . $url . ']';
                wh_log('[QUOTE_DL] type=' . $qtype . ' url=' . $url);
            } else {
                $quoted_text = '[ファイル:' . $fname . ' (ダウンロード失敗)]';
                wh_log('[QUOTE_DL] FAIL type=' . $qtype . ' id=' . $quoted_id);
            }
        }

        // ③ キャッシュから照合（フォールバック）
        if (!$quoted_text) {
            $msg_cache = json_decode(ago_kv_get('ago_line_msg_cache') ?? '{}', true) ?: [];
            $cached = $msg_cache[$quoted_id] ?? null;
            if (is_array($cached) && isset($cached['url'])) {
                $quoted_text = '[ファイル:' . ($cached['filename'] ?? 'file') . ' URL:' . $cached['url'] . ']';
            } elseif (is_string($cached)) {
                $quoted_text = $cached;
            }
        }

        wh_log('[QUOTE] id=' . $quoted_id . ' q_type=' . ($q['type'] ?? 'none') . ' result=' . mb_substr($quoted_text ?? '(none)', 0, 60));
    }

    if (empty($text) || empty($userId)) continue;

    // 完了タスクの保留通知があれば即リプライで伝える
    $notify_key = 'ago_pending_notify_' . ($groupId ?? $userId);
    $pending_notify = ago_kv_get($notify_key);
    if ($pending_notify && $replyToken && $LINE_CHANNEL_TOKEN) {
        line_reply($LINE_CHANNEL_TOKEN, $replyToken, $pending_notify);
        ago_kv_set($notify_key, '');
        wh_log('[NOTIFY] delivered pending result to ' . ($groupId ?? $userId));
        // ユーザー名・グループ名を簡易解決してログ保存
        $_u_map  = json_decode(ago_kv_get('ago_line_users')  ?? '{}', true) ?: [];
        $_u_name = $_u_map[$userId] ?? ('スタッフ(' . substr($userId, -6) . ')');
        $_g_name = null;
        if ($groupId) {
            $_g_map  = json_decode(ago_kv_get('ago_line_groups') ?? '{}', true) ?: [];
            $_g_name = $_g_map[$groupId] ?? ('グループ(' . substr($groupId, -6) . ')');
        }
        ago_log_save([
            'id'          => date('YmdHis') . '_' . substr($userId, -6),
            'ts'          => date('Y-m-d H:i:s'),
            'userId'      => $userId,
            'user_name'   => $_u_name,
            'groupId'     => $groupId,
            'group_name'  => $_g_name,
            'source'      => $source,
            'text'        => $text,
            'quoted_text' => $quoted_text,
            'status'      => 'replied',
            'ai_reply'    => mb_substr($pending_notify, 0, 1000),
            'project_id'  => null,
            'error'       => null,
            'reply_token' => null,
        ]);
        continue; // line-handler.php には渡さない
    }

    // グループチャットの場合：「ウルバン」が含まれなければ無視
    if ($groupId) {
        if (stripos($text, 'ウルバン') === false && stripos($text, 'urvan') === false) continue;
        wh_log('[GROUP] trigger matched userId=' . $userId . ' text=' . mb_substr($text, 0, 30));
    }

    // 許可ユーザーチェック（リストが空=未設定の間は全員受信）
    $allowed = empty($allowed_ids) || in_array($userId, $allowed_ids);

    // ユーザー名・グループ名を解決
    $users_map_raw  = ago_kv_get('ago_line_users');
    $users_map_wh   = $users_map_raw ? (json_decode($users_map_raw, true) ?: []) : [];
    $user_name_wh   = $users_map_wh[$userId] ?? ('スタッフ(' . substr($userId, -6) . ')');
    $group_name_wh  = null;
    if ($groupId) {
        $groups_map_raw = ago_kv_get('ago_line_groups');
        $groups_map_wh  = $groups_map_raw ? (json_decode($groups_map_raw, true) ?: []) : [];
        $group_name_wh  = $groups_map_wh[$groupId] ?? ('グループ(' . substr($groupId, -6) . ')');

        // グループアイコン・正確な名前をLINE APIから取得してKVキャッシュ（未取得の場合のみ）
        if ($LINE_CHANNEL_TOKEN) {
            $icons_raw = ago_kv_get('ago_line_group_icons');
            $icons_map = $icons_raw ? (json_decode($icons_raw, true) ?: []) : [];
            if (empty($icons_map[$groupId])) {
                $gch = curl_init('https://api.line.me/v2/bot/group/' . $groupId . '/summary');
                curl_setopt_array($gch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $LINE_CHANNEL_TOKEN],
                    CURLOPT_TIMEOUT        => 5,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                ]);
                $gres  = curl_exec($gch);
                $gcode = curl_getinfo($gch, CURLINFO_HTTP_CODE);
                curl_close($gch);
                wh_log('[GROUP_META] code=' . $gcode . ' groupId=' . $groupId);
                if ($gcode === 200 && $gres) {
                    $gsummary = json_decode($gres, true);
                    // アイコンURLを保存
                    if (!empty($gsummary['pictureUrl'])) {
                        $icons_map[$groupId] = $gsummary['pictureUrl'];
                        ago_kv_set('ago_line_group_icons', json_encode($icons_map, JSON_UNESCAPED_UNICODE));
                    }
                    // グループ名も正確な名前で上書き
                    if (!empty($gsummary['groupName'])) {
                        $groups_map_wh[$groupId] = $gsummary['groupName'];
                        ago_kv_set('ago_line_groups', json_encode($groups_map_wh, JSON_UNESCAPED_UNICODE));
                        $group_name_wh = $gsummary['groupName'];
                    }
                }
            }
        }
    }

    // 受信ログに記録
    $log_entry = [
        'id'          => date('YmdHis') . '_' . substr($userId, -6),
        'ts'          => date('Y-m-d H:i:s'),
        'userId'      => $userId,
        'user_name'   => $user_name_wh,
        'groupId'     => $groupId,
        'group_name'  => $group_name_wh,
        'source'      => $source,
        'text'        => $text,
        'quoted_text' => $quoted_text,
        'status'      => $allowed ? 'received' : 'blocked',
        'project_id'  => null,
        'error'       => null,
        'reply_token' => $replyToken
    ];
    ago_log_save($log_entry);

    if (!$allowed) {
        // ブロックされたユーザーには何も返さない
        continue;
    }

    // APIキーがあればdeferred配列に積む（fastcgi_finish_request後にバックグラウンド処理）
    if ($api_key) {
        wh_log('[INFO] queued userId=' . $userId . ' text=' . mb_substr($text, 0, 30));
        $deferred[] = $log_entry;
    } else {
        // APIキー未設定の場合は受信確認だけ返す（トークンがあれば）
        if ($LINE_CHANNEL_TOKEN && $replyToken) {
            line_reply($LINE_CHANNEL_TOKEN, $replyToken, 'メッセージを受信しました。APIキーが未設定のためAI処理はできません。');
        }
    }
}

// LINE に 200 を即返してコネクションを閉じる
echo json_encode(['status' => 'ok']);
if (function_exists('fastcgi_finish_request')) {
    @ob_end_flush();
    @flush();
    fastcgi_finish_request();
    wh_log('[BGstart] connection closed to LINE via fastcgi_finish_request');
}

// バックグラウンドでAI処理（LINE接続切断後・タイムアウト延長）
if (!empty($deferred)) {
    ignore_user_abort(true);
    set_time_limit(300);
    require_once __DIR__ . '/line-handler.php';
    foreach ($deferred as $entry) {
        wh_log('[BG] processing userId=' . $entry['userId'] . ' text=' . mb_substr($entry['text'], 0, 30));

        // ── 「PDFにして」PHP直接処理（AIを通さず確実に実行） ──────────
        $raw_text = $entry['text'] ?? '';
        if (preg_match('/PDFにして|PDFに変換|PDF.*にして/u', $raw_text) && empty($entry['quoted_text'])) {
            wh_log('[PDF_SHORTCUT] detected PDF pattern, bypassing AI');

            // 直近ファイルをキャッシュから取得
            $fc      = json_decode(ago_kv_get('ago_line_msg_cache') ?? '{}', true) ?: [];
            $gid     = $entry['groupId'] ?? null;
            $uid     = $entry['userId']  ?? '';
            $hits    = [];
            foreach ($fc as $fid => $fd) {
                if (!is_array($fd) || empty($fd['url'])) continue;
                // 同じユーザーが送ったファイルのみ（グループ内の他人のファイルは除外）
                if (($fd['userId'] ?? null) !== $uid) continue;
                // 画像は除外（引用なしの「PDFにして」はドキュメント系が対象。画像をPDFにするには引用してから送ること）
                if (($fd['type'] ?? '') === 'image') continue;
                $hits[] = $fd;
            }
            usort($hits, fn($a, $b) => ($b['ts'] ?? 0) - ($a['ts'] ?? 0));

            $users_m  = json_decode(ago_kv_get('ago_line_users') ?? '{}', true) ?: [];
            $sender   = $users_m[$uid] ?? ('スタッフ(' . substr($uid, -6) . ')');
            $file_info = !empty($hits[0])
                ? 'ファイル名: ' . ($hits[0]['filename'] ?? 'file') . "\nダウンロードURL: " . $hits[0]['url']
                : '（ファイル情報なし）';
            $filename = $hits[0]['filename'] ?? '';
            $file_url = $hits[0]['url'] ?? '';

            $prompt = "##NODISPATCH##\n"
                . "{$sender}からの依頼: 「PDFにして」\n\n"
                . "## 対象ファイル\n{$file_info}\n\n"
                . "## 実行手順（DISPATCHは禁止。あなた自身がBashで直接実行してください）\n"
                . "1. まず `C:\\Users\\Administrator\\Desktop\\AI版AGO\\` 配下で `{$filename}` を探す\n"
                . "2. 見つかった場合 → そのファイルを使用\n"
                . "3. 見つからない場合 → URLからダウンロード: `Invoke-WebRequest '{$file_url}' -OutFile 'C:\\temp\\{$filename}'`\n"
                . "4. ファイル拡張子を確認:\n"
                . "   - `.pdf` の場合: そのまま送付\n"
                . "   - `.txt` の場合: wkhtmltopdf または LibreOffice で変換。どちらもなければ Pythonのfpdfで変換\n"
                . "5. `python lineworks_send.py \"AI事業\" 対象ファイルパス --file --headless` でAI事業グループに送付\n"
                . "6. 送付完了後: `完了: PDFを送付しました [FILE:対象ファイルパス]` と出力する\n"
                . "7. tempファイルがあれば削除\n";

            // ELVIN VPS に直接投入
            $bt_secret = defined('ELVIN_VPS_SECRET') ? ELVIN_VPS_SECRET : 'elvin2026';
            $bt_body   = json_encode([
                'client_id' => 'ago_001',
                'type'      => 'ELVIN_task',
                'payload'   => [
                    'prompt'         => $prompt,
                    'requester_id'   => $gid ?? $uid,
                    'requester_name' => $sender,
                    'log_id'         => $entry['id'] ?? null,
                    'recent_context' => '',
                ],
            ]);
            $ch = curl_init('https://api.nihon-neon.jp/api/v1/tasks');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $bt_body,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Daemon-Secret: ' . $bt_secret],
                CURLOPT_TIMEOUT        => 15,
            ]);
            $bt_res  = curl_exec($ch);
            $bt_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            wh_log('[PDF_SHORTCUT] ELVIN queued code=' . $bt_code . ' file=' . ($hits[0]['filename'] ?? 'none'));

            // LINE に即返信
            if ($LINE_CHANNEL_TOKEN && !empty($entry['reply_token'])) {
                line_reply($LINE_CHANNEL_TOKEN, $entry['reply_token'], "確認しました。PDFに変換してお送りします📄");
            }
            ago_log_update($entry['id'] ?? '', ['status' => 'replied', 'ai_reply' => 'PDF_SHORTCUT']);
            continue;
        }
        // ─────────────────────────────────────────────────────────────

        processLineMessage($entry, $api_key, $LINE_CHANNEL_TOKEN);
        wh_log('[BG] done userId=' . $entry['userId']);
    }
}

// ── LINE返信 ────────────────────────────────────────────────────

function line_reply($token, $replyToken, $message) {
    if (empty($token) || empty($replyToken)) {
        wh_log('[line_reply] skipped: token=' . (empty($token) ? 'EMPTY' : 'SET') . ' replyToken=' . (empty($replyToken) ? 'EMPTY' : 'SET'));
        return;
    }
    $payload = [
        'replyToken' => $replyToken,
        'messages'   => [['type' => 'text', 'text' => $message]]
    ];
    $ch = curl_init('https://api.line.me/v2/bot/message/reply');
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
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    wh_log('[line_reply] status=' . $code . ' err=' . ($err ?: 'none') . ' body=' . mb_substr($res, 0, 200));
    return json_decode($res, true) ?: [];
}

// ── KVストアヘルパー（api.phpはkv_getAll/kv_setのみ対応） ────────
// $_AGO_KV_CACHE はリクエスト内キャッシュ。ago_kv_set が書いたら即反映させる
// （旧 static キャッシュでは kv_set 後も古い値が残り ago_log_update が [] で上書きする問題が発生した）
$_AGO_KV_CACHE = null;

function _kv_base_url() {
    return 'https://' . $_SERVER['HTTP_HOST'];
}

function _kv_fetch_all() {
    global $_AGO_KV_CACHE;
    if ($_AGO_KV_CACHE !== null) return $_AGO_KV_CACHE;
    $ch = curl_init(_kv_base_url() . '/api.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['action' => 'kv_getAll']),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-AGO-Token: system002-od'],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || !$res) {
        wh_log('[kv_getAll err] ' . $err);
        $_AGO_KV_CACHE = [];
        return $_AGO_KV_CACHE;
    }
    $data  = json_decode($res, true);
    $_AGO_KV_CACHE = $data['data'] ?? [];
    wh_log('[kv_getAll] ' . count($_AGO_KV_CACHE) . ' keys loaded');
    return $_AGO_KV_CACHE;
}

function ago_kv_get($key) {
    $all = _kv_fetch_all();
    return $all[$key] ?? null;
}

function ago_kv_set($key, $value) {
    global $_AGO_KV_CACHE;
    if ($_AGO_KV_CACHE !== null) $_AGO_KV_CACHE[$key] = $value;
    $url = _kv_base_url() . '/api.php';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['action' => 'kv_set', 'key' => $key, 'value' => $value]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-AGO-Token: system002-od'],
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    wh_log('[kv_set] key=' . $key . ' url=' . $url . ' code=' . $code . ' err=' . ($err ?: 'none') . ' res=' . mb_substr($res ?? '', 0, 80));
}

// LINE Content API からファイルをダウンロードしてサーバーに保存
function save_line_file($message_id, $token, $filename) {
    $ch = curl_init('https://api-data.line.me/v2/bot/message/' . $message_id . '/content');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $content = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err     = curl_error($ch);
    curl_close($ch);
    wh_log('[save_line_file] id=' . $message_id . ' code=' . $code . ' err=' . ($err ?: 'none') . ' size=' . strlen($content ?? ''));
    if ($code !== 200 || !$content) return null;
    $dir = __DIR__ . '/uploads/line_files/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $safe   = preg_replace('/[^a-zA-Z0-9._\-]/u', '_', $filename);
    $unique = date('YmdHis') . '_' . substr($message_id, -8) . '_' . $safe;
    if (file_put_contents($dir . $unique, $content) === false) return null;
    return 'https://' . $_SERVER['HTTP_HOST'] . '/uploads/line_files/' . $unique;
}

function ago_log_save($new_entry) {
    $raw  = ago_kv_get('ago_line_logs');
    wh_log('[ago_log_save] raw=' . ($raw === null ? 'NULL' : mb_substr($raw, 0, 60)));
    $logs = json_decode($raw ?? '[]', true) ?: [];
    array_unshift($logs, $new_entry);
    if (count($logs) > 200) $logs = array_slice($logs, 0, 200);
    $encoded = json_encode($logs, JSON_UNESCAPED_UNICODE);
    wh_log('[ago_log_save] saving count=' . count($logs) . ' encoded_len=' . strlen($encoded));
    ago_kv_set('ago_line_logs', $encoded);
    wh_log('[ago_log_save] kv_set done');
}
