const CACHE_NAME = 'ricepos-cache-v12';
const CORE_ASSETS = [
  './',
  './index.php',
  './assets/css/style.css',
  './assets/js/main.js',
  './assets/img/sunny.png',
  './assets/img/cloudy.png',
  './assets/img/rainy.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(CORE_ASSETS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.map((k) => k !== CACHE_NAME && caches.delete(k)))).then(() => self.clients.claim())
  );
});

// Network-first for PHP pages, cache-first for static assets
self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);
  if (req.method !== 'GET') return; // don't cache POSTs

  const isStatic = /\.(?:js|css|png|jpg|jpeg|webp|gif|svg|ico|woff2?)$/i.test(url.pathname);
  if (isStatic) {
    event.respondWith(
      caches.match(req).then((res) => res || fetch(req).then((netRes) => {
        const copy = netRes.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(req, copy));
        return netRes;
      }))
    );
    return;
  }

  // For dynamic/PHP, try network first, fallback to cache
  event.respondWith(
    fetch(req, { cache: 'no-store' }).then((netRes) => {
      return netRes;
    }).catch(() => caches.match(req))
  );
});


