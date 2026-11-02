/* Service worker: makes the site installable and keeps the screens loading on a weak signal.
   Live data (api/) is never cached; pages are network-first; scripts, styles and icons are cached. */
const CACHE = 'fst-v14';
const CORE = ['offline.html', 'assets/app.css', 'assets/app.js', 'assets/icon-192.png'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(CORE)).catch(() => {}).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

const isLib = (url) => /cdnjs\.cloudflare\.com|unpkg\.com|cdn\.jsdelivr\.net/.test(url.host);

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  if (url.origin === location.origin && url.pathname.includes('/api/')) return;   // always live

  if (req.mode === 'navigate') {
    e.respondWith(
      fetch(req).catch(() => caches.match(req).then((r) => r || caches.match('offline.html')))
    );
    return;
  }

  if ((url.origin === location.origin && url.pathname.includes('/assets/')) || isLib(url)) {
    e.respondWith(
      caches.open(CACHE).then((cache) =>
        cache.match(req).then((hit) => {
          const fresh = fetch(req).then((res) => { if (res.ok || res.type === 'opaque') cache.put(req, res.clone()); return res; }).catch(() => hit);
          return hit || fresh;
        })
      )
    );
  }
});
