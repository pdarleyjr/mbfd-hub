const CACHE_NAME = 'mbfd-checkout-v2';
const API_CACHE_NAME = 'mbfd-api-cache-v2';
const ASSETS_TO_CACHE = [
  '/',
  '/index.html',
  '/manifest.json',
];

// Install event - cache static assets
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(ASSETS_TO_CACHE);
    })
  );
  self.skipWaiting();
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames
          .filter((name) => name !== CACHE_NAME && name !== API_CACHE_NAME)
          .map((name) => caches.delete(name))
      );
    })
  );
  self.clients.claim();
});

// Fetch event - strategic caching
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip non-GET requests (but we'll handle POST in message event)
  if (request.method !== 'GET') return;

  // API requests - cache-first with network update (for GET apparatuses/checklists)
  if (url.pathname.includes('/api/public/apparatuses')) {
    event.respondWith(
      caches.open(API_CACHE_NAME).then(async (cache) => {
        const cachedResponse = await cache.match(request);
        
        // Return cached if available, but update in background
        const networkPromise = fetch(request)
          .then((response) => {
            if (response.ok) {
              cache.put(request, response.clone());
            }
            return response;
          })
          .catch(() => null);

        // If we have cache, return it immediately
        if (cachedResponse) {
          networkPromise.catch(() => {}); // Update cache in background
          return cachedResponse;
        }

        // Otherwise wait for network
        const networkResponse = await networkPromise;
        if (networkResponse) {
          return networkResponse;
        }

        // Return a basic offline response if both fail
        return new Response(JSON.stringify({ error: 'Offline' }), {
          status: 503,
          headers: { 'Content-Type': 'application/json' }
        });
      })
    );
    return;
  }

  // Static assets - cache first, fallback to network
  event.respondWith(
    caches.match(request).then((cachedResponse) => {
      if (cachedResponse) {
        return cachedResponse;
      }

      return fetch(request).then((response) => {
        // Cache successful responses
        if (response.ok) {
          const responseClone = response.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(request, responseClone);
          });
        }
        return response;
      });
    })
  );
});

// Message event - handle offline submission queue
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'QUEUE_SUBMISSION') {
    // Store submission in IndexedDB via postMessage back to client
    event.ports[0].postMessage({
      type: 'QUEUE_STORED',
      success: true
    });
  }
  
  if (event.data && event.data.type === 'SYNC_QUEUE') {
    // Notify client to process queue
    event.ports[0].postMessage({
      type: 'SYNC_STARTED',
      success: true
    });
  }
});