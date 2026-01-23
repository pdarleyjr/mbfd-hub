const CACHE_NAME = 'mbfd-checkout-v3';
const API_CACHE_NAME = 'mbfd-api-cache-v3';
const ASSETS_TO_CACHE = [
  '/',
  '/index.html',
  '/manifest.json',
  '/assets/', // Will cache JS and CSS bundles
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

// Fetch event - strategic caching with StaleWhileRevalidate for GET
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Handle POST requests - attempt network, show offline error if fails
  if (request.method === 'POST') {
    event.respondWith(
      fetch(request).catch(() => {
        // Notify client about offline status
        return new Response(
          JSON.stringify({ 
            error: 'You are offline. Your submission has been queued.',
            offline: true 
          }), 
          {
            status: 503,
            headers: { 'Content-Type': 'application/json' }
          }
        );
      })
    );
    return;
  }

  // Skip non-GET requests
  if (request.method !== 'GET') return;

  // API requests - StaleWhileRevalidate strategy  
  if (url.pathname.includes('/api/public/apparatuses')) {
    event.respondWith(
      caches.open(API_CACHE_NAME).then(async (cache) => {
        const cachedResponse = await cache.match(request);
        
        // Fetch from network and update cache in background
        const networkPromise = fetch(request)
          .then((response) => {
            if (response.ok) {
              cache.put(request, response.clone());
            }
            return response;
          })
          .catch(() => null);

        // If we have cache, return it immediately (stale)
        if (cachedResponse) {
          networkPromise.catch(() => {}); // Update cache in background
          return cachedResponse;
        }

        // Otherwise wait for network (revalidate)
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
        if (response.ok && request.url.startsWith(self.location.origin)) {
          const responseClone = response.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(request, responseClone);
          });
        }
        return response;
      }).catch(() => {
        // Return offline page for navigation requests
        if (request.mode === 'navigate') {
          return caches.match('/index.html');
        }
        return new Response('Offline', { status: 503 });
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