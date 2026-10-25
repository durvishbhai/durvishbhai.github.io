/* Two jobs. (1) Offline page: pages, forms and anything about a person are NEVER stored; the worker keeps one small "You are offline" page and its styles and
 * shows it when a page cannot be loaded because the device has no connection. (2) Notifications: shows the push messages the server sends to this device and opens
 * the right page when one is tapped. */
const CACHE = 'kis-offline-v2';

self.addEventListener('push', (event) => {
  let d = {};
  try { d = event.data ? event.data.json() : {}; } catch (e) { d = { body: event.data ? event.data.text() : '' }; }
  event.waitUntil(self.registration.showNotification(d.title || 'Krazy Integrated Solutions', {
    body: d.body || '', icon: d.icon, badge: d.badge, tag: d.tag, renotify: !!d.tag, data: { url: d.url || '/notifications' },
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL((event.notification.data && event.notification.data.url) || '/notifications', self.location.origin);
  if (target.origin !== self.location.origin) { return; }
  event.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of all) {
      if (c.url.startsWith(self.location.origin) && 'focus' in c) {
        await c.focus();
        if ('navigate' in c) { try { await c.navigate(target.href); } catch (e) { /* the page stays as it is */ } }
        return;
      }
    }
    await self.clients.openWindow(target.href);
  })());
});

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE);
    const res = await fetch('/offline', { cache: 'reload' });
    const html = await res.clone().text();
    await cache.put('/offline', res);
    const assets = [...html.matchAll(/(?:href|src)="(\/assets\/[^"]+)"/g)].map((m) => m[1]);
    await Promise.all(assets.map((u) => cache.add(u).catch(() => undefined)));
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    for (const k of await caches.keys()) {
      if (k !== CACHE) await caches.delete(k);
    }
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(async () => (await caches.match('/offline')) || Response.error()));
    return;
  }
  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(fetch(req).catch(async () => (await caches.match(req)) || Response.error()));
  }
});
