/**
 * AGO SYSTEM MANAGER - サービスワーカー
 * オフライン対応とキャッシュ管理
 */

// OSバッジカウンター（SW生存期間中に蓄積・クリア時にリセット）
let _badgeCount = 0;

const CACHE_NAME = 'ago-system-v7';
const STATIC_ASSETS = [
  'config.js',
  'manifest.json',
];

// インストール時に静的ファイルをキャッシュ
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS))
  );
  self.skipWaiting();
});

// 古いキャッシュの削除
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

// リクエスト処理
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // PHPページ（動的）→ 常にネットワークから取得（キャッシュしない）
  if (url.pathname.endsWith('chat.php') || url.pathname.endsWith('agoline-icon.php')) {
    event.respondWith(fetch(event.request));
    return;
  }

  // ELVIN API (api.nihon-neon.jp) → 絶対にキャッシュしない。常にネットワーク
  if (url.hostname === 'api.nihon-neon.jp') {
    event.respondWith(
      fetch(event.request).catch(() =>
        new Response(JSON.stringify([]), {
          headers: { 'Content-Type': 'application/json' },
        })
      )
    );
    return;
  }

  // APIリクエスト → ネットワークファースト
  if (url.pathname.endsWith('api.php') || url.pathname.endsWith('subscribe.php')) {
    event.respondWith(
      fetch(event.request).catch(() =>
        new Response(JSON.stringify({ success: false, message: 'オフラインです' }), {
          headers: { 'Content-Type': 'application/json' },
        })
      )
    );
    return;
  }

  // index.html → ネットワーク優先（常に最新を取得・失敗時のみキャッシュ）
  if (url.pathname.endsWith('index.html') || url.pathname.endsWith('/')) {
    event.respondWith(
      fetch(event.request).then((response) => {
        if (response.ok) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
        }
        return response;
      }).catch(() => caches.match(event.request))
    );
    return;
  }

  // その他の静的ファイル → キャッシュファースト
  event.respondWith(
    caches.match(event.request).then((cached) => {
      if (cached) return cached;
      return fetch(event.request).then((response) => {
        if (response.ok && event.request.method === 'GET') {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
        }
        return response;
      });
    })
  );
});

// プッシュ通知の受信
self.addEventListener('push', (event) => {
  let data = { title: 'AGO SYSTEM MANAGER', body: '新しい通知があります' };
  try {
    data = event.data.json();
  } catch (e) {
    data.body = event.data ? event.data.text() : data.body;
  }
  _badgeCount++; // 累積カウント（SW生存期間中に蓄積）
  const badgeCount = _badgeCount;
  event.waitUntil(
    Promise.all([
      self.registration.showNotification(data.title || 'AGO SYSTEM MANAGER', {
        body: data.body || '',
        icon: data.icon || 'icon-192.png',
        badge: 'icon-192.png',
        data: { url: data.url || '/chat.php' },
        tag: 'ago-line-notif',
        renotify: true,
      }),
      self.navigator?.setAppBadge?.(badgeCount) ?? Promise.resolve(),
      // 開いているページに即時更新を通知
      clients.matchAll({ includeUncontrolled: true, type: 'window' }).then(ws =>
        ws.forEach(w => w.postMessage({ type: 'new-line-message', badge_count: badgeCount }))
      ),
    ])
  );
});

// 通知クリック時にチャットページを開く
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  _badgeCount = 0; // バッジカウンターをリセット
  self.navigator?.clearAppBadge?.();
  const targetUrl = (event.notification.data?.url) || '/chat.php';
  event.waitUntil(
    clients.matchAll({ type: 'window' }).then((clientList) => {
      // すでに chat.php が開いていればフォーカス
      for (const client of clientList) {
        if (client.url.includes('chat.php') && 'focus' in client) {
          return client.focus();
        }
      }
      return clients.openWindow(targetUrl);
    })
  );
});

// クライアントからバッジクリア通知を受けたらカウンターをリセット
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'badge-cleared') {
    _badgeCount = 0;
  }
});
