<?php
// chat.php — スマホ用 LINE ログビューア
// Basic認証で保護・api.php 経由で ago_line_logs を取得して表示

// FastCGI環境対応: PHP_AUTH_USER が取れない場合は Authorization ヘッダーから直接読む
$_chat_user = $_SERVER['PHP_AUTH_USER'] ?? null;
$_chat_pass = $_SERVER['PHP_AUTH_PW']   ?? null;
if ($_chat_user === null) {
    $raw = $_SERVER['HTTP_AUTHORIZATION']          // .htaccess RewriteRule 経由
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] // リダイレクト経由
        ?? '';
    if (preg_match('/^Basic\s+(.+)$/i', $raw, $m)) {
        $dec   = base64_decode($m[1]);
        $colon = strpos($dec, ':');
        $_chat_user = $colon !== false ? substr($dec, 0, $colon)  : $dec;
        $_chat_pass = $colon !== false ? substr($dec, $colon + 1) : '';
    }
}
if ($_chat_user !== 'ago' || $_chat_pass !== 'neon2026') {
    header('WWW-Authenticate: Basic realm="AGO Chat"');
    header('HTTP/1.0 401 Unauthorized');
    exit;
}

function chat_fetch_logs() {
    $url = 'https://' . $_SERVER['HTTP_HOST'] . '/api.php';
    $ch = curl_init($url);
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
    curl_close($ch);
    if (!$res) return [];
    $data = json_decode($res, true);
    $all  = $data['data'] ?? [];
    return json_decode($all['ago_line_logs'] ?? '[]', true) ?: [];
}

$logs = chat_fetch_logs();
// ago_log_save は array_unshift で先頭に追加 → $logs[0] が最新
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>AGO チャット</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, 'Hiragino Sans', 'Hiragino Kaku Gothic ProN', sans-serif;
      background: #e8f5e9;
      min-height: 100vh;
    }
    #header {
      background: #4caf50;
      color: white;
      padding: 12px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky;
      top: 0;
      z-index: 100;
      box-shadow: 0 2px 6px rgba(0,0,0,0.25);
    }
    #header h1 { font-size: 17px; font-weight: bold; letter-spacing: 0.5px; }
    #header .meta { font-size: 11px; opacity: 0.85; margin-top: 2px; }
    .refresh-btn {
      background: rgba(255,255,255,0.25);
      border: 1px solid rgba(255,255,255,0.5);
      color: white;
      padding: 6px 14px;
      border-radius: 16px;
      font-size: 13px;
      cursor: pointer;
      white-space: nowrap;
    }
    #chat-container {
      padding: 12px 10px;
      display: flex;
      flex-direction: column;
      gap: 20px;
    }
    .msg-pair { display: flex; flex-direction: column; gap: 6px; }
    .msg-ts {
      text-align: center;
      font-size: 11px;
      color: #777;
      background: rgba(255,255,255,0.6);
      display: inline-block;
      margin: 0 auto;
      padding: 2px 10px;
      border-radius: 10px;
    }
    .group-tag {
      text-align: center;
      font-size: 11px;
      color: #555;
      margin-top: 1px;
    }
    /* ユーザー吹き出し（左側） */
    .user-row { display: flex; align-items: flex-end; gap: 8px; margin-top: 6px; }
    .avatar {
      width: 34px; height: 34px;
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 17px;
      flex-shrink: 0;
      box-shadow: 0 1px 3px rgba(0,0,0,0.15);
    }
    .avatar.user { background: #b0bec5; }
    .avatar.ai   { background: #43a047; color: white; }
    .user-info { display: flex; flex-direction: column; gap: 2px; }
    .sender-name { font-size: 11px; color: #555; margin-left: 2px; }
    .bubble {
      max-width: 75vw;
      padding: 9px 13px;
      font-size: 14px;
      line-height: 1.55;
      white-space: pre-wrap;
      word-break: break-word;
      box-shadow: 0 1px 2px rgba(0,0,0,0.12);
    }
    .bubble.user {
      background: white;
      border-radius: 4px 16px 16px 16px;
    }
    /* AI吹き出し（右側） */
    .ai-row { display: flex; align-items: flex-end; gap: 8px; flex-direction: row-reverse; margin-top: 4px; }
    .ai-info { display: flex; flex-direction: column; gap: 2px; align-items: flex-end; }
    .bubble.ai {
      background: #b2dfdb;
      border-radius: 16px 4px 16px 16px;
    }
    /* ステータスバッジ */
    .status-wrap { display: flex; justify-content: flex-end; margin-right: 42px; }
    .status-badge {
      font-size: 11px;
      padding: 3px 10px;
      border-radius: 10px;
      display: inline-block;
      margin-top: 2px;
    }
    .processing { background: #fff9c4; color: #f9a825; }
    .received   { background: #e3f2fd; color: #1565c0; }
    .error-badge { background: #ffebee; color: #c62828; }
    /* 空状態 */
    .no-logs {
      text-align: center;
      color: #888;
      padding: 60px 20px;
      font-size: 15px;
    }
  </style>
</head>
<body>
<div id="header">
  <div>
    <h1>💬 AGO チャット</h1>
    <div class="meta"><?= count($logs) ?> 件 ／ <?= date('H:i') ?> 現在</div>
  </div>
  <button class="refresh-btn" onclick="location.reload()">↺ 更新</button>
</div>

<div id="chat-container">
<?php if (empty($logs)): ?>
  <div class="no-logs">メッセージがありません</div>
<?php else: ?>
<?php foreach ($logs as $log): ?>
<?php
  $ts         = htmlspecialchars($log['ts']         ?? '');
  $user_name  = htmlspecialchars($log['user_name']  ?? 'ユーザー');
  $text       = htmlspecialchars($log['text']       ?? '');
  $ai_reply   = htmlspecialchars($log['ai_reply']   ?? '');
  $status     = $log['status'] ?? '';
  $group_name = !empty($log['group_name']) ? htmlspecialchars($log['group_name']) : null;
?>
<div class="msg-pair">
  <div class="msg-ts"><?= $ts ?></div>
  <?php if ($group_name): ?>
    <div class="group-tag">📢 <?= $group_name ?></div>
  <?php endif; ?>

  <!-- ユーザーメッセージ（左） -->
  <div class="user-row">
    <div class="avatar user">👤</div>
    <div class="user-info">
      <span class="sender-name"><?= $user_name ?></span>
      <div class="bubble user"><?= $text ?></div>
    </div>
  </div>

  <!-- AI返信（右） or ステータス -->
  <?php if ($ai_reply): ?>
  <div class="ai-row">
    <div class="avatar ai">🤖</div>
    <div class="ai-info">
      <span class="sender-name" style="margin-right:2px">AI URVAN</span>
      <div class="bubble ai"><?= $ai_reply ?></div>
    </div>
  </div>
  <?php elseif ($status === 'processing'): ?>
  <div class="status-wrap">
    <span class="status-badge processing">⏳ 処理中...</span>
  </div>
  <?php elseif ($status === 'received'): ?>
  <div class="status-wrap">
    <span class="status-badge received">📨 受信済み</span>
  </div>
  <?php elseif ($status === 'error'): ?>
  <div class="status-wrap">
    <span class="status-badge error-badge">❌ エラー</span>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>

</body>
</html>
