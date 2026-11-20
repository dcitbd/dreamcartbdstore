const DCBD_CACHE = 'dcbd-pwa-v2';
const SCOPE_PATH = new URL(self.registration.scope).pathname.replace(/\/$/, '');
const SHELL_ASSETS = [
  `${SCOPE_PATH}/public/manifest.webmanifest`,
  `${SCOPE_PATH}/public/icon/icon-192.png`,
  `${SCOPE_PATH}/public/icon/icon-512.png`,
  `${SCOPE_PATH}/public/icon/logo.png`
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(DCBD_CACHE).then((cache) => cache.addAll(SHELL_ASSETS)).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== DCBD_CACHE).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (url.pathname.startsWith(`${SCOPE_PATH}/api`) || url.pathname.startsWith(`${SCOPE_PATH}/admin`)) return;

  if (url.pathname.startsWith(`${SCOPE_PATH}/public/icon/`) || url.pathname === `${SCOPE_PATH}/public/manifest.webmanifest`) {
    event.respondWith(
      caches.match(req).then((cached) => cached || fetch(req).then((res) => {
        if (res && res.ok) {
          const copy = res.clone();
          caches.open(DCBD_CACHE).then((c) => c.put(req, copy)).catch(() => {});
        }
        return res;
      }))
    );
    return;
  }

  event.respondWith(fetch(req).catch(() => caches.match(req)));
});
