// Service Worker — PWA + Push Notifications
const CACHE = 'chat-v3';
const STATIC = [
  '/',
  '/chat.php',
  '/assets/app.js',
  '/assets/chat.css',
  '/assets/auth.css',
  '/assets/fonts/IranYekanx/fontiran.css',
  '/manifest.json'
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(STATIC)).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

// Network first برای API، cache first برای static
self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (url.pathname.startsWith('/api/') ||
      url.pathname.includes('avatar.php') ||
      url.pathname.includes('download.php')) {
    return; // همیشه از شبکه
  }
  e.respondWith(
    fetch(e.request)
      .then(res => {
        if (res.ok && e.request.method === 'GET') {
          const clone = res.clone();
          caches.open(CACHE).then(c => c.put(e.request, clone));
        }
        return res;
      })
      .catch(() => caches.match(e.request))
  );
});

// Push Notification
self.addEventListener('push', e => {
  let data = { title: 'پیام جدید', body: '', icon: '/assets/icons/icon-192.png' };
  try { data = { ...data, ...e.data.json() }; } catch {}
  e.waitUntil(
    self.registration.showNotification(data.title, {
      body: data.body,
      icon: data.icon || '/assets/icons/icon-192.png',
      badge: '/assets/icons/icon-192.png',
      dir: 'rtl',
      lang: 'fa',
      vibrate: [200, 100, 200],
      tag: data.tag || 'msg',
      renotify: true,
      data: { url: data.url || '/chat.php' }
    })
  );
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = e.notification.data?.url || '/chat.php';
  e.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(cls => {
      const existing = cls.find(c => c.url.includes('chat.php'));
      if (existing) return existing.focus();
      return clients.openWindow(url);
    })
  );
});
