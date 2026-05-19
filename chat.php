<?php
// chat.php — AGOLINE v4（グループアイコン自動取得・バッジ位置修正）

function chat_fetch_kv() {
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
    return $data['data'] ?? [];
}

function chat_kv_set($key, $value) {
    $url = 'https://' . $_SERVER['HTTP_HOST'] . '/api.php';
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
    curl_exec($ch);
    curl_close($ch);
}

$kv          = chat_fetch_kv();
$all_logs    = json_decode($kv['ago_line_logs']        ?? '[]', true) ?: []; // 新しい順
$group_icons = json_decode($kv['ago_line_group_icons'] ?? '{}', true) ?: [];
$groups_map  = json_decode($kv['ago_line_groups']      ?? '{}', true) ?: [];
$filter      = $_GET['g'] ?? null;

// ── チャットルーム一覧の構築 ──────────────────────────────────────
$rooms = [];
foreach ($all_logs as $log) {
    $gid = $log['groupId'] ?? '__direct__';
    if (!isset($rooms[$gid])) {
        $preview = trim($log['ai_reply'] ?? '') ?: trim($log['text'] ?? '');
        // グループ名: KV ago_line_groups を優先、次にログ内 group_name
        $room_name = $groups_map[$gid]
            ?? $log['group_name']
            ?? ($gid === '__direct__' ? 'ダイレクトメッセージ' : '');
        $rooms[$gid] = [
            'gid'     => $gid,
            'name'    => $room_name,
            'last_ts' => $log['ts'] ?? '',
            'preview' => $preview,
            'count'   => 1,
        ];
    } else {
        $rooms[$gid]['count']++;
    }
}

// ── アイコン・グループ名が未取得のルームを LINE API で補完 ────────
$LINE_CHANNEL_TOKEN = '';
$config_file = __DIR__ . '/api-config.php';
if (file_exists($config_file)) {
    require $config_file;
    $LINE_CHANNEL_TOKEN = defined('LINE_CHANNEL_TOKEN') ? LINE_CHANNEL_TOKEN : '';
}
if ($LINE_CHANNEL_TOKEN) {
    $icons_updated  = false;
    $groups_updated = false;
    foreach (array_keys($rooms) as $gid) {
        if ($gid === '__direct__') continue;
        if (!empty($group_icons[$gid]) && !empty($groups_map[$gid])) continue;
        $gch = curl_init('https://api.line.me/v2/bot/group/' . rawurlencode($gid) . '/summary');
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
        if ($gcode === 200 && $gres) {
            $gsummary = json_decode($gres, true);
            if (!empty($gsummary['pictureUrl']) && empty($group_icons[$gid])) {
                $group_icons[$gid] = $gsummary['pictureUrl'];
                $icons_updated = true;
            }
            if (!empty($gsummary['groupName']) && empty($groups_map[$gid])) {
                $groups_map[$gid]      = $gsummary['groupName'];
                $rooms[$gid]['name']   = $gsummary['groupName'];
                $groups_updated = true;
            }
        }
    }
    if ($icons_updated) {
        chat_kv_set('ago_line_group_icons', json_encode($group_icons, JSON_UNESCAPED_UNICODE));
    }
    if ($groups_updated) {
        chat_kv_set('ago_line_groups', json_encode($groups_map, JSON_UNESCAPED_UNICODE));
    }
}

// ── 詳細ビュー用ログ取得 ──────────────────────────────────────────
$detail_logs  = [];
$detail_name  = '';
if ($filter) {
    foreach ($all_logs as $log) {
        if (($log['groupId'] ?? '__direct__') === $filter) {
            $detail_logs[] = $log;
            if (!$detail_name) $detail_name = $groups_map[$filter] ?? $log['group_name'] ?? 'グループ';
        }
    }
    $detail_logs = array_reverse($detail_logs); // 古い順（下が最新）
}

// ── AGO LINE アイコン SVG ─────────────────────────────────────────
$ago_svg = '<svg viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg"><rect width="40" height="40" rx="10" fill="#06C755"/><rect x="5" y="6" width="30" height="20" rx="6" fill="white"/><path d="M9 26 L6 34 L18 29 Z" fill="white"/><text x="20" y="21" text-anchor="middle" font-family="Arial,sans-serif" font-size="10" font-weight="900" fill="#06C755" letter-spacing="0.5">AGO</text></svg>';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>AGOLINE</title>
  <link rel="manifest" href="/chat-manifest.json">
  <meta name="theme-color" content="#06C755">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="AGOLINE">
  <link rel="apple-touch-icon" href="/agoline-icon.php?s=180">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
    body {
      font-family: -apple-system, 'Hiragino Sans', 'Hiragino Kaku Gothic ProN', sans-serif;
      background: #f5f5f5;
      min-height: 100vh;
    }

    /* ── 共通ヘッダー ── */
    .header {
      background: #06C755;
      color: white;
      height: 52px;
      display: flex;
      align-items: center;
      padding: 0 14px;
      gap: 10px;
      position: sticky;
      top: 0;
      z-index: 100;
      box-shadow: 0 1px 4px rgba(0,0,0,0.2);
    }
    .header .logo { width: 30px; height: 30px; flex-shrink: 0; }
    .header h1 { font-size: 17px; font-weight: bold; flex: 1; }
    .header .back-btn {
      background: none; border: none; color: white;
      font-size: 22px; padding: 4px 8px 4px 0;
      cursor: pointer; line-height: 1;
    }
    .header .sub { font-size: 11px; opacity: 0.8; }
    .refresh-btn {
      background: rgba(255,255,255,0.2);
      border: 1px solid rgba(255,255,255,0.4);
      color: white; padding: 5px 12px;
      border-radius: 14px; font-size: 13px;
      cursor: pointer; white-space: nowrap;
    }
    .notif-btn {
      background: rgba(255,255,255,0.2);
      border: 1px solid rgba(255,255,255,0.4);
      color: white; padding: 5px 10px;
      border-radius: 14px; font-size: 15px;
      cursor: pointer; line-height: 1;
    }
    .notif-btn.enabled { background: rgba(16,185,129,0.3); border-color: #10b981; }
    .notif-btn.denied  { opacity: 0.45; cursor: not-allowed; }

    /* ════════════════════════════════════
       ルーム一覧
    ════════════════════════════════════ */
    #room-list { background: white; }
    .room-item {
      display: flex;
      align-items: center;
      padding: 12px 14px;
      gap: 12px;
      border-bottom: 1px solid #ebebeb;
      text-decoration: none;
      color: inherit;
      background: white;
      transition: background 0.1s;
    }
    .room-item:active { background: #f0f0f0; }

    /* アバター＋バッジのラッパー */
    .room-avatar-wrap {
      position: relative;
      width: 52px;
      height: 52px;
      flex-shrink: 0;
    }
    .room-avatar {
      width: 52px; height: 52px;
      border-radius: 50%;
      background: #06C755;
      display: flex; align-items: center; justify-content: center;
      overflow: hidden;
    }
    .room-avatar svg { width: 52px; height: 52px; }
    .room-avatar img { width: 52px; height: 52px; object-fit: cover; border-radius: 50%; }

    /* バッジ: アバター右下に重ねる */
    .room-badge {
      position: absolute;
      bottom: -1px;
      right: -4px;
      background: #E53935;
      color: white;
      font-size: 10px;
      font-weight: bold;
      min-width: 18px;
      height: 18px;
      border-radius: 9px;
      padding: 0 5px;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 2px solid white;
      line-height: 1;
    }

    .room-body { flex: 1; min-width: 0; }
    .room-name {
      font-size: 15px; font-weight: bold;
      color: #111; margin-bottom: 3px;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .room-preview {
      font-size: 13px; color: #888;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .room-meta {
      display: flex; flex-direction: column;
      align-items: flex-end; gap: 5px;
      flex-shrink: 0;
    }
    .room-time { font-size: 11px; color: #aaa; }
    .no-rooms {
      text-align: center; color: #aaa;
      padding: 60px 20px; font-size: 15px;
      background: white;
    }

    /* ════════════════════════════════════
       チャット詳細
    ════════════════════════════════════ */
    #chat-area {
      padding: 10px 10px 20px;
      display: flex; flex-direction: column; gap: 16px;
      background: #e8f5e9;
      min-height: calc(100vh - 52px);
    }
    .msg-pair { display: flex; flex-direction: column; gap: 5px; }
    .msg-ts {
      text-align: center; font-size: 11px; color: #777;
      background: rgba(255,255,255,0.55);
      display: inline-block; margin: 0 auto;
      padding: 2px 10px; border-radius: 10px;
    }
    /* ユーザー（左） */
    .user-row { display: flex; align-items: flex-end; gap: 7px; margin-top: 4px; }
    .avatar {
      width: 34px; height: 34px; border-radius: 50%;
      flex-shrink: 0; overflow: hidden;
      display: flex; align-items: center; justify-content: center;
      font-size: 18px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.12);
    }
    .avatar.user { background: #b0bec5; }
    .avatar.ai svg { width: 34px; height: 34px; }
    .user-info { display: flex; flex-direction: column; gap: 2px; }
    .sender-name { font-size: 11px; color: #555; padding-left: 2px; }
    .bubble {
      max-width: 72vw; padding: 9px 13px;
      font-size: 14px; line-height: 1.55;
      white-space: pre-wrap; word-break: break-word;
      box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    }
    .bubble.user { background: white; border-radius: 4px 16px 16px 16px; }
    /* AI（右） */
    .ai-row {
      display: flex; align-items: flex-end; gap: 7px;
      flex-direction: row-reverse; margin-top: 3px;
    }
    .ai-info { display: flex; flex-direction: column; gap: 2px; align-items: flex-end; }
    .sender-name.right { padding-right: 2px; padding-left: 0; }
    .bubble.ai { background: #b2dfdb; border-radius: 16px 4px 16px 16px; }
    /* ステータス */
    .status-wrap { display: flex; justify-content: flex-end; margin-right: 42px; }
    .status-badge {
      font-size: 11px; padding: 3px 10px; border-radius: 10px;
      display: inline-block; margin-top: 2px;
    }
    .processing  { background: #fff9c4; color: #f57f17; }
    .received    { background: #e3f2fd; color: #1565c0; }
    .error-badge { background: #ffebee; color: #c62828; }
    .no-msgs { text-align: center; color: #aaa; padding: 40px 20px; font-size: 14px; }
  </style>
</head>
<body>

<?php if ($filter): ?>
<!-- ════ チャット詳細 ════ -->
<div class="header">
  <button class="back-btn" onclick="history.back()">‹</button>
  <div style="flex:1">
    <div style="font-size:16px;font-weight:bold"><?= htmlspecialchars($detail_name) ?></div>
  </div>
  <button class="notif-btn" id="notif-btn-detail" onclick="chatEnablePush()">🔔</button>
  <button class="refresh-btn" onclick="location.reload()">↺</button>
</div>

<div id="chat-area">
<?php if (empty($detail_logs)): ?>
  <div class="no-msgs">メッセージがありません</div>
<?php else: ?>
<?php foreach ($detail_logs as $log): ?>
<?php
  $ts        = htmlspecialchars($log['ts']        ?? '');
  $user_name = htmlspecialchars($log['user_name'] ?? 'ユーザー');
  $text      = htmlspecialchars($log['text']      ?? '');
  $ai_reply  = htmlspecialchars($log['ai_reply']  ?? '');
  $status    = $log['status'] ?? '';
?>
<div class="msg-pair">
  <div class="msg-ts"><?= $ts ?></div>
  <div class="user-row">
    <div class="avatar user">👤</div>
    <div class="user-info">
      <span class="sender-name"><?= $user_name ?></span>
      <div class="bubble user"><?= $text ?></div>
    </div>
  </div>
  <?php if ($ai_reply): ?>
  <div class="ai-row">
    <div class="avatar ai"><?= $ago_svg ?></div>
    <div class="ai-info">
      <span class="sender-name right">AI URVAN</span>
      <div class="bubble ai"><?= $ai_reply ?></div>
    </div>
  </div>
  <?php elseif ($status === 'processing'): ?>
  <div class="status-wrap"><span class="status-badge processing">⏳ 処理中...</span></div>
  <?php elseif ($status === 'received'): ?>
  <div class="status-wrap"><span class="status-badge received">📨 受信済み</span></div>
  <?php elseif ($status === 'error'): ?>
  <div class="status-wrap"><span class="status-badge error-badge">❌ エラー</span></div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
<script>
  const _gid = <?= json_encode($filter) ?>;
  const _newestTs = <?= json_encode($detail_logs ? ($detail_logs[count($detail_logs)-1]['ts'] ?? '') : '') ?>;
  if (_gid && _newestTs) {
    localStorage.setItem('agoline_read_' + _gid, _newestTs);
    if ('clearAppBadge' in navigator) navigator.clearAppBadge();
  }
  window.addEventListener('load', () => window.scrollTo(0, document.body.scrollHeight));
</script>

<?php else: ?>
<!-- ════ チャットルーム一覧 ════ -->
<div class="header">
  <svg class="logo" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">
    <rect width="40" height="40" rx="10" fill="white"/>
    <rect x="5" y="6" width="30" height="20" rx="6" fill="#06C755"/>
    <path d="M9 26 L6 34 L18 29 Z" fill="#06C755"/>
    <text x="20" y="21" text-anchor="middle" font-family="Arial,sans-serif" font-size="10" font-weight="900" fill="white" letter-spacing="0.5">AGO</text>
  </svg>
  <h1>AGOLINE</h1>
  <button class="notif-btn" id="notif-btn-list" onclick="chatEnablePush()">🔔</button>
  <button class="refresh-btn" onclick="location.reload()">↺ 更新</button>
</div>

<div id="room-list">
<?php if (empty($rooms)): ?>
  <div class="no-rooms">チャットルームがありません</div>
<?php else: ?>
<?php foreach ($rooms as $room): ?>
<?php
  $gid     = rawurlencode($room['gid']);
  $name    = htmlspecialchars($room['name'] ?: 'グループ');
  $preview = htmlspecialchars(mb_substr($room['preview'], 0, 40));
  $ts_raw  = $room['last_ts'];
  $ts_disp = '';
  if ($ts_raw) {
    $d = date('Y-m-d');
    $ts_disp = str_starts_with($ts_raw, $d) ? substr($ts_raw, 11, 5) : substr($ts_raw, 5, 5);
  }
?>
<a class="room-item" href="?g=<?= $gid ?>"
   data-gid="<?= htmlspecialchars($room['gid']) ?>"
   data-last-ts="<?= htmlspecialchars($room['last_ts']) ?>">
  <div class="room-avatar-wrap">
    <div class="room-avatar">
      <?php if (!empty($group_icons[$room['gid']])): ?>
        <img src="<?= htmlspecialchars($group_icons[$room['gid']]) ?>" alt="">
      <?php else: ?>
        <?= $ago_svg ?>
      <?php endif; ?>
    </div>
    <div class="room-badge"><?= $room['count'] ?></div>
  </div>
  <div class="room-body">
    <div class="room-name"><?= $name ?></div>
    <div class="room-preview"><?= $preview ?: '（メッセージなし）' ?></div>
  </div>
  <div class="room-meta">
    <div class="room-time"><?= $ts_disp ?></div>
  </div>
</a>
<?php endforeach; ?>
<?php endif; ?>
</div>
<?php endif; ?>

<script>
<?php if (!$filter): ?>
document.addEventListener('DOMContentLoaded', function () {
  var unread = 0;
  document.querySelectorAll('.room-item').forEach(function (item) {
    var gid    = item.dataset.gid;
    var lastTs = item.dataset.lastTs;
    var read   = localStorage.getItem('agoline_read_' + gid);
    var badge  = item.querySelector('.room-badge');
    if (read && lastTs && lastTs <= read) {
      badge.style.display = 'none';
    } else {
      unread++;
    }
    item.addEventListener('click', function (e) {
      e.preventDefault();
      localStorage.setItem('agoline_read_' + gid, lastTs);
      window.location.href = item.getAttribute('href');
    });
  });
  if ('setAppBadge' in navigator) {
    unread > 0 ? navigator.setAppBadge(unread) : navigator.clearAppBadge();
  }
});

// SWからプッシュ到着通知を受けたらページをリロードして最新表示
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.addEventListener('message', function(e) {
    if (e.data && e.data.type === 'new-line-message') {
      if (!location.search.includes('g=')) location.reload();
    }
  });
}

// ── 通知許可ボタン ──────────────────────────────────────────
var CHAT_VAPID = 'BIk5ZM40CId0dIBLZ56RL9tqh2xRyBehkvBsZYcrJmHia65BOVSfLQAdsDC8NUZ6KOK_8G17DMO_FjyeWNZoXe0';

function chatUpdateNotifBtn() {
  var perm = ('Notification' in window) ? Notification.permission : 'unsupported';
  ['notif-btn-detail','notif-btn-list'].forEach(function(id) {
    var btn = document.getElementById(id);
    if (!btn) return;
    btn.classList.remove('enabled','denied');
    if (perm === 'granted')  { btn.classList.add('enabled'); btn.title = '通知有効'; }
    else if (perm === 'denied') { btn.classList.add('denied'); btn.title = '通知がブロックされています（ブラウザ設定から許可してください）'; }
    else { btn.title = 'タップして通知を有効にする'; }
  });
}

async function chatEnablePush() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
    alert('このブラウザはプッシュ通知に対応していません');
    return;
  }
  if (Notification.permission === 'denied') {
    alert('通知がブロックされています。ブラウザの設定から「通知を許可する」に変更してください。');
    return;
  }
  var perm = await Notification.requestPermission();
  if (perm !== 'granted') return;
  try {
    var reg = await navigator.serviceWorker.ready;
    var sub = await reg.pushManager.getSubscription();
    var storedKey = localStorage.getItem('push_vapid_key_chat');
    if (sub && storedKey !== CHAT_VAPID) { await sub.unsubscribe(); sub = null; }
    if (!sub) {
      var key = CHAT_VAPID;
      var padding = '='.repeat((4 - key.length % 4) % 4);
      var base64 = (key + padding).replace(/-/g,'+').replace(/_/g,'/');
      var raw = atob(base64);
      var arr = Uint8Array.from(Array.from(raw).map(function(c){return c.charCodeAt(0);}));
      sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: arr });
    }
    localStorage.setItem('push_vapid_key_chat', CHAT_VAPID);
    var body = Object.assign({action:'subscribe', user_name: localStorage.getItem('push_user')||'chat-user'}, sub.toJSON());
    await fetch('/subscribe.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)});
    chatUpdateNotifBtn();
    alert('✅ 通知を有効にしました');
  } catch(e) {
    alert('通知登録エラー: ' + e.message);
  }
}

document.addEventListener('DOMContentLoaded', function() {
  chatUpdateNotifBtn();
  // SWが準備できたら自動でサイレント登録（許可済みの場合のみ）
  if ('serviceWorker' in navigator && Notification.permission === 'granted') {
    navigator.serviceWorker.ready.then(function(reg) {
      return reg.pushManager.getSubscription();
    }).then(function(sub) {
      if (!sub) chatEnablePush();
    });
  }
});
<?php endif; ?>
</script>

</body>
</html>
