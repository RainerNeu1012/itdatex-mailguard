// MailGuard Service-Worker — macht das Portal zu einer installierbaren PWA.
//
// Strategie:
//  - HTML-Shell (/portal/): network-first mit Cache-Fallback — frische Asset-Hashes,
//    aber im Offline-Fall die letzte bekannte Seite.
//  - Build-Assets (/portal/portal-*.css|js, Fonts, Icons): cache-first mit lazy fill,
//    da sie content-hashed sind und sich nie ändern.
//  - API-Requests (/wp-json/*): network-only, nie cachen.
//  - sw.js + manifest.webmanifest: NIE cachen, sonst kann sich der SW nicht updaten.
//
// Version anheben, wenn sich sw.js aendert — invalidiert alle alten Shell-Caches.

const SW_VERSION = 'mg-sw-v2';
const SHELL_CACHE = 'mg-shell-' + SW_VERSION;

const SHELL_URLS = [
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
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // SW und Manifest nie cachen — sonst self-lock.
  if (url.pathname === '/portal/sw.js' || url.pathname === '/portal/manifest.webmanifest') {
    return;
  }

  // API-Calls: network-only.
  if (url.pathname.startsWith('/wp-json/') || url.pathname.startsWith('/wp-admin/admin-ajax')) {
    return;
  }

  // HTML-Shell: network-first, Cache nur als Offline-Fallback.
  const isPortalHtml = url.pathname === '/portal' || url.pathname === '/portal/' || url.pathname.match(/^\/portal\/[^.]*$/);
  if (isPortalHtml) {
    event.respondWith(
      fetch(req).then((res) => {
        if (res && res.status === 200 && res.type === 'basic') {
          const copy = res.clone();
          caches.open(SHELL_CACHE).then((cache) => cache.put('/portal/', copy));
        }
        return res;
      }).catch(() => caches.match('/portal/'))
    );
    return;
  }

  // Content-hashed Assets: cache-first mit lazy fill.
  const isHashedAsset = url.pathname.startsWith('/portal/') || url.pathname.includes('/itdatex-mailguard/assets/');
  if (isHashedAsset) {
    event.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        if (res && res.status === 200 && res.type === 'basic') {
          const copy = res.clone();
          caches.open(SHELL_CACHE).then((cache) => cache.put(req, copy));
        }
        return res;
      }))
    );
  }
});
