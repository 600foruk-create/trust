// Madrasa ERP - Lightweight Service Worker for PWA
const CACHE_NAME = 'madrasa-erp-cache-v1';
const STATIC_ASSETS = [
  'manifest.json',
  'assets/style.css',
  'assets/softifyx-logo.png',
  'assets/icon-192.png',
  'assets/icon-512.png',
  'assets/html2canvas.min.js'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(STATIC_ASSETS).catch((err) => {
        console.warn('Some static assets failed to cache:', err);
      });
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME) {
            return caches.delete(key);
          }
        })
      );
    })
  );
  self.clients.claim();
});

// Network-first strategy for PHP requests, Cache-first for static assets
self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  // Skip non-GET requests
  if (request.method !== 'GET') return;

  // Static assets: Cache first
  if (url.pathname.match(/\.(css|js|png|jpg|jpeg|svg|woff2|woff)$/i)) {
    event.respondWith(
      caches.match(request).then((cached) => {
        return cached || fetch(request).then((response) => {
          if (response && response.status === 200) {
            const responseClone = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, responseClone));
          }
          return response;
        });
      })
    );
    return;
  }

  // Dynamic pages: Network first
  event.respondWith(
    fetch(request).catch(() => caches.match(request))
  );
});

