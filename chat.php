<?php
// chat.php — スマホ用 LINE ログビューア v2

// FastCGI環境対応
$_chat_user = $_SERVER['PHP_AUTH_USER'] ?? null;
$_chat_pass = $_SERVER['PHP_AUTH_PW']   ?? null;
if ($_chat_user === null) {
    $raw = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
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

$all_logs = array_reverse(chat_fetch_logs()); // 古い順（下が最新）

// グループ一覧を抽出（表示名 → groupId のマップ）
$groups = []; // groupId => group_name
foreach ($all_logs as $l) {
    $gid = $l['groupId'] ?? null;
    if ($gid && !isset($groups[$gid])) {
        $groups[$gid] = $l['group_name'] ?? ('グループ(' . substr($gid, -6) . ')');
    }
}

// フィルター適用
$filter = $_GET['g'] ?? 'all';
if ($filter !== 'all' && isset($groups[$filter])) {
    $logs = array_values(array_filter($all_logs, fn($l) => ($l['groupId'] ?? null) === $filter));
} else {
    $filter = 'all';
    $logs   = $all_logs;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>AGO チャット</title>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40"><rect width="40" height="40" rx="10" fill="#06C755"/><rect x="6" y="7" width="28" height="19" rx="5" fill="white"/><path d="M10 26 L7 33 L17 28 Z" fill="white"/><text x="20" y="21" text-anchor="middle" font-family="Arial,sans-serif" font-size="9" font-weight="bold" fill="#06C755">AGO</text></svg>') ?>">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, 'Hiragino Sans', 'Hiragino Kaku Gothic ProN', sans-serif;
      background: #e8f5e9;
      min-height: 100vh;
    }

    /* ── ヘッダー ── */
    #header {
      background: #06C755;
      color: white;
      padding: 10px 14px 10px;
      display: flex;
      align-items: center;
      gap: 10px;
      position: sticky;
      top: 0;
      z-index: 100;
      box-shadow: 0 2px 6px rgba(0,0,0,0.2);
    }
    #header .logo {
      width: 34px; height: 34px;
      flex-shrink: 0;
    }
    #header .title-block { flex: 1; }
    #header h1 { font-size: 16px; font-weight: bold; line-height: 1.2; }
    #header .meta { font-size: 11px; opacity: 0.8; margin-top: 1px; }
    .refresh-btn {
      background: rgba(255,255,255,0.2);
      border: 1px solid rgba(255,255,255,0.45);
      color: white;
      padding: 6px 13px;
      border-radius: 16px;
      font-size: 13px;
      cursor: pointer;
      white-space: nowrap;
      flex-shrink: 0;
    }

    /* ── グループタブ ── */
    #group-tabs {
      background: #04a244;
      display: flex;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      gap: 0;
      scrollbar-width: none;
    }
    #group-tabs::-webkit-scrollbar { display: none; }
    .tab {
      flex-shrink: 0;
      padding: 9px 16px;
      font-size: 13px;
      color: rgba(255,255,255,0.75);
      text-decoration: none;
      white-space: nowrap;
      border-bottom: 3px solid transparent;
      transition: color 0.15s;
    }
    .tab.active {
      color: white;
      border-bottom-color: white;
      font-weight: bold;
    }

    /* ── チャットエリア ── */
    #chat-container {
      padding: 12px 10px;
      display: flex;
      flex-direction: column;
      gap: 18px;
    }

    /* グループ区切り（全て表示時） */
    .group-divider {
      display: flex;
      align-items: center;
      gap: 8px;
      margin: 4px 0 0;
    }
    .group-divider .gd-line { flex: 1; height: 1px; background: rgba(0,0,0,0.1); }
    .group-divider .gd-name {
      font-size: 11px;
      color: #555;
      background: rgba(255,255,255,0.7);
      padding: 2px 10px;
      border-radius: 10px;
      white-space: nowrap;
    }

    .msg-pair { display: flex; flex-direction: column; gap: 5px; }
    .msg-ts {
      text-align: center;
      font-size: 11px;
      color: #777;
    }

    /* ユーザー吹き出し（左） */
    .user-row { display: flex; align-items: flex-end; gap: 7px; margin-top: 5px; }
    .avatar {
      width: 34px; height: 34px;
      border-radius: 50%;
      flex-shrink: 0;
      box-shadow: 0 1px 3px rgba(0,0,0,0.15);
      overflow: hidden;
      display: flex; align-items: center; justify-content: center;
    }
    .avatar.user { background: #b0bec5; font-size: 17px; }
    .avatar.ai   { background: transparent; }
    .avatar.ai svg { width: 34px; height: 34px; }
    .user-info { display: flex; flex-direction: column; gap: 2px; }
    .sender-name { font-size: 11px; color: #555; padding-left: 2px; }

    .bubble {
      max-width: 72vw;
      padding: 9px 13px;
      font-size: 14px;
      line-height: 1.55;
      white-space: pre-wrap;
      word-break: break-word;
      box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    }
    .bubble.user {
      background: white;
      border-radius: 4px 16px 16px 16px;
    }

    /* AI吹き出し（右） */
    .ai-row {
      display: flex; align-items: flex-end; gap: 7px;
      flex-direction: row-reverse;
      margin-top: 3px;
    }
    .ai-info { display: flex; flex-direction: column; gap: 2px; align-items: flex-end; }
    .sender-name.right { padding-right: 2px; padding-left: 0; }
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
    .processing  { background: #fff9c4; color: #f57f17; }
    .received    { background: #e3f2fd; color: #1565c0; }
    .error-badge { background: #ffebee; color: #c62828; }

    .no-logs {
      text-align: center;
      color: #888;
      padding: 60px 20px;
      font-size: 15px;
    }
  </style>
</head>
<body>

<!-- ヘッダー -->
<div id="header">
  <svg class="logo" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">
    <rect width="40" height="40" rx="10" fill="white"/>
    <rect x="5" y="6" width="30" height="20" rx="6" fill="#06C755"/>
    <path d="M9 26 L6 34 L18 29 Z" fill="#06C755"/>
    <text x="20" y="21" text-anchor="middle" font-family="Arial,sans-serif" font-size="10" font-weight="900" fill="white" letter-spacing="0.5">AGO</text>
  </svg>
  <div class="title-block">
    <h1>AGO チャット</h1>
    <div class="meta"><?= count($logs) ?> 件表示 ／ <?= date('H:i') ?></div>
  </div>
  <button class="refresh-btn" onclick="location.reload()">↺ 更新</button>
</div>

<!-- グループタブ -->
<div id="group-tabs">
  <a class="tab <?= $filter === 'all' ? 'active' : '' ?>" href="?g=all">すべて</a>
  <?php foreach ($groups as $gid => $gname): ?>
  <a class="tab <?= $filter === $gid ? 'active' : '' ?>"
     href="?g=<?= rawurlencode($gid) ?>">
    <?= htmlspecialchars($gname) ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- チャット本体 -->
<div id="chat-container">
<?php if (empty($logs)): ?>
  <div class="no-logs">メッセージがありません</div>
<?php else: ?>
<?php $prev_gid = null; foreach ($logs as $log): ?>
<?php
  $ts         = htmlspecialchars($log['ts']         ?? '');
  $user_name  = htmlspecialchars($log['user_name']  ?? 'ユーザー');
  $text       = htmlspecialchars($log['text']       ?? '');
  $ai_reply   = htmlspecialchars($log['ai_reply']   ?? '');
  $status     = $log['status'] ?? '';
  $cur_gid    = $log['groupId'] ?? null;
  $group_name = !empty($log['group_name']) ? htmlspecialchars($log['group_name']) : null;
?>
<?php if ($filter === 'all' && $group_name && $cur_gid !== $prev_gid): ?>
<div class="group-divider">
  <div class="gd-line"></div>
  <div class="gd-name">📢 <?= $group_name ?></div>
  <div class="gd-line"></div>
</div>
<?php $prev_gid = $cur_gid; endif; ?>

<div class="msg-pair">
  <div class="msg-ts"><?= $ts ?></div>

  <!-- ユーザー（左） -->
  <div class="user-row">
    <div class="avatar user">👤</div>
    <div class="user-info">
      <span class="sender-name"><?= $user_name ?></span>
      <div class="bubble user"><?= $text ?></div>
    </div>
  </div>

  <!-- AI（右） or ステータス -->
  <?php if ($ai_reply): ?>
  <div class="ai-row">
    <div class="avatar ai">
      <svg viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">
        <rect width="40" height="40" rx="10" fill="#06C755"/>
        <rect x="5" y="6" width="30" height="20" rx="6" fill="white"/>
        <path d="M9 26 L6 34 L18 29 Z" fill="white"/>
        <text x="20" y="21" text-anchor="middle" font-family="Arial,sans-serif" font-size="10" font-weight="900" fill="#06C755" letter-spacing="0.5">AGO</text>
      </svg>
    </div>
    <div class="ai-info">
      <span class="sender-name right">AI URVAN</span>
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

<script>
  // 読み込み完了後に一番下へスクロール
  window.addEventListener('load', () => {
    window.scrollTo(0, document.body.scrollHeight);
  });
</script>
</body>
</html>
