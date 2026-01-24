const CACHE_NAME = 'mbfd-checkout-v3';
const urlsToCache = [
  '/mbfd-checkout-system/',
  '/mbfd-checkout-system/index.html',
  '/mbfd-checkout-system/offline.html',
  '/mbfd-checkout-system/data/rescue_checklist.json',
  '/mbfd-checkout-system/data/engine_checklist.json',
  '/mbfd-checkout-system/data/ladder1_checklist.json',
  '/mbfd-checkout-system/data/ladder3_checklist.json',
  '/mbfd-checkout-system/data/rope_checklist.json'
];

self.addEventListener('install', (event) => {
  console.log('[SW] Install event - caching app shell and all checklists');
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => {
        console.log('[SW] Caching:', urlsToCache.length, 'resources');
        return cache.addAll(urlsToCache);
      })
      .then(() => self.skipWaiting()) // Activate immediately
  );
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  
  // Network-first for HTML navigation requests (fixes blank page bug)
  if (event.request.mode === 'navigate' || event.request.destination === 'document') {
    event.respondWith(
      fetch(event.request)
        .then(response => {
          // Cache the new version
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, response.clone()));
          return response;
        })
        .catch(() => {
          // Offline: serve from cache first, then offline page as fallback
          return caches.match(event.request)
            .then(response => {
              return response || caches.match('/mbfd-checkout-system/offline.html');
            });
        })
    );
    return;
  }
  
  // Network-first for ALL checklist JSON files (always get latest when online)
  if (url.pathname.includes('_checklist.json')) {
    event.respondWith(
      fetch(event.request)
        .then(response => {
          // Update cache with latest version
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, response.clone()));
          return response;
        })
        .catch(() => {
          // Offline: serve cached version
          return caches.match(event.request);
        })
    );
    return;
  }
  
  // Cache-first for other resources (JS, CSS, images)
  event.respondWith(
    caches.match(event.request)
      .then((response) => response || fetch(event.request))
  );
});

self.addEventListener('activate', (event) => {
  console.log('[SW] Activate event - cleaning up old caches');
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== CACHE_NAME) {
            console.log('[SW] Deleting old cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => {
      console.log('[SW] Service worker activated and taking control');
      return self.clients.claim(); // Take control immediately
    })
  );
});