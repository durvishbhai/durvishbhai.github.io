/* Offline page only. Pages, forms and anything about a person are NEVER stored: the worker keeps one small "You are offline" page and its styles, and shows it
 * when a page cannot be loaded because the device has no connection. */
const CACHE = 'kis-offline-v1';

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
