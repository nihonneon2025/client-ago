/**
 * AGO SYSTEM MANAGER - サービスワーカー
 * オフライン対応とキャッシュ管理
 */

const CACHE_NAME = 'ago-system-v4';
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
  const badgeCount = data.badge_count || 1;
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
    ])
  );
});

// 通知クリック時にチャットページを開く
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
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
