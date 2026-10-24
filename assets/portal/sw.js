// MailGuard Service-Worker — macht das Portal zu einer installierbaren PWA.
//
// Strategie:
//  - App-Shell (HTML/CSS/JS/Fonts/Icons): cache-first mit Fallback auf network,
//    damit die PWA auch bei kurzer Offline-Phase startet.
//  - API-Requests (/wp-json/itdatex-mailguard/*): network-first, kein Cache —
//    Mail-Daten sollen immer frisch sein.
//  - Alles andere: network passthrough.
//
// Version anheben, wenn sich sw.js oder manifest.webmanifest aendert — dann
// invalidiert der Browser den Cache.

const SW_VERSION = 'mg-sw-v1';
const SHELL_CACHE = 'mg-shell-' + SW_VERSION;

// Minimal-Shell: Portal-Root plus Mark-Icons. Build-Assets (vite-hashed)
// werden lazy gecacht beim ersten Besuch.
const SHELL_URLS = [
  '/portal/',
  '/wp-content/plugins/itdatex-mailguard/assets/img/pwa/icon-192-white.png',
  '/wp-content/plugins/itdatex-mailguard/assets/img/pwa/icon-512-white.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE).then((cache) => cache.addAll(SHELL_URLS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keys = await caches.keys();
      await Promise.all(keys.filter((k) => k.startsWith('mg-shell-') && k !== SHELL_CACHE).map((k) => caches.delete(k)));
      await self.clients.claim();
    })()
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  // Nur GET-Requests cachen — POST/PUT/DELETE immer live.
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // API-Calls: network-first, kein Cache.
  if (url.pathname.startsWith('/wp-json/') || url.pathname.startsWith('/wp-admin/admin-ajax')) {
    return;
  }

  // Portal-Shell + Assets: cache-first.
  if (url.pathname.startsWith('/portal') || url.pathname.includes('/itdatex-mailguard/assets/')) {
    event.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        if (res && res.status === 200 && res.type === 'basic') {
          const copy = res.clone();
          caches.open(SHELL_CACHE).then((cache) => cache.put(req, copy));
        }
        return res;
      }).catch(() => caches.match('/portal/')))
    );
  }
});
