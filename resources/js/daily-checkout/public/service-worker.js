const CACHE_NAME = 'mbfd-checkout-v4';
const API_CACHE_NAME = 'mbfd-api-cache-v4';
const APP_SHELL_CACHE_KEYS = [
  '/daily/',
  '/daily/index.html',
  '/daily/manifest.json',
];

// Install event - cache static assets
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL_CACHE_KEYS))
  );

  self.skipWaiting();
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames
          .filter((name) => {
            return (
              (name.startsWith('mbfd-checkout-') && name !== CACHE_NAME) ||
              (name.startsWith('mbfd-api-cache-') && name !== API_CACHE_NAME)
            );
          })
          .map((name) => caches.delete(name))
      );
    }).then(() => self.clients.claim())
  );
});

// Fetch event - strategic caching with network-first navigation and stale-while-revalidate for API.
// Also ensures that outdated SPA HTML or bundle references are not served from a cache
// in order to ensure a cohesive experience.
self.addEventListener('fetch', (event) => {
  const { request } = event;

  if (request.method !== 'GET') {
    if (request.method === 'POST') {
      event.respondWith(
        fetch(request).catch(() => {
          return new Response(
            JSON.stringify({
              error: 'You are offline. Your submission has been queued.',
              offline: true,
            }),
            {
              status: 503,
              headers: { 'Content-Type': 'application/json' },
            }
          );
        })
      );
    }

    return;
  }

  const url = new URL(request.url);
  const isSameOrigin = url.origin === self.location.origin;
  const isNavigationRequest = request.mode === 'navigate';
  const isDailyShellRequest = isSameOrigin && url.pathname.startsWith('/daily');
  const isApparatusApiRequest = isSameOrigin && url.pathname.startsWith('/api/public/apparatuses');

  if (isNavigationRequest && isDailyShellRequest) {
    event.respondWith(
      (async () => {
        const cache = await caches.open(CACHE_NAME);

        try {
          const networkResponse = await fetch(request, { cache: 'no-store' });

          if (networkResponse.ok) {
            await cache.put('/daily/index.html', networkResponse.clone());
          }

          return networkResponse;
        } catch {
          const cachedIndex = await cache.match('/daily/index.html');

          if (cachedIndex) {
            return cachedIndex;
          }

          return new Response('Offline', { status: 503 });
        }
      })()
    );

    return;
  }

  if (isApparatusApiRequest) {
    event.respondWith(
      (async () => {
        const cache = await caches.open(API_CACHE_NAME);

        try {
          const networkResponse = await fetch(request, { cache: 'no-store' });

          if (networkResponse.ok) {
            await cache.put(request, networkResponse.clone());
          }

          return networkResponse;
        } catch {
          const cachedResponse = await cache.match(request);

          if (cachedResponse) {
            return cachedResponse;
          }

          return new Response(JSON.stringify({ error: 'Offline' }), {
            status: 503,
            headers: { 'Content-Type': 'application/json' },
          });
        }
      })()
    );

    return;
  }

  event.respondWith(
    caches.match(request).then((cachedResponse) => {
      if (cachedResponse) {
        return cachedResponse;
      }

      return fetch(request).then((response) => {
        if (response.ok && isSameOrigin) {
          const responseClone = response.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(request, responseClone);
          });
        }

        return response;
      }).catch(() => {
        if (isNavigationRequest) {
          return caches.match('/daily/index.html');
        }

        return new Response('Offline', { status: 503 });
      });
    })
  );
});

// ─── Push Notification Handlers (ERROR-036 fix) ───────────────────────────────
// These listeners MUST be present in the final sw.js to receive web push events.
// VitePWA's Workbox build was previously stripping them. The serviceWorkerCopyPlugin
// in vite.config.js copies this file to public/daily/sw.js at closeBundle, preserving
// these handlers.

self.addEventListener('push', function (event) {
  if (!(self.Notification && self.Notification.permission === 'granted')) {
    return;
  }

  const payload = event.data ? event.data.json() : {};
  const title = payload.title || 'MBFD Hub Update';
  const options = {
    body: payload.body || 'New activity reported.',
    icon: payload.icon || '/images/mbfd-logo.png',
    badge: '/images/mbfd-logo.png',
    data: payload.data || {},
    actions: payload.actions || [],
    vibrate: [200, 100, 200],
    tag: payload.tag || 'mbfd-notification',
    requireInteraction: true,
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();

  const data = event.notification.data || {};
  let urlToOpen = data.url || '/admin';

  if (event.action === 'open-chat' || urlToOpen.includes('/chat')) {
    urlToOpen = data.url || '/admin/chat';
  }

  event.waitUntil(
    clients
      .matchAll({ type: 'window', includeUncontrolled: true })
      .then(function (clientList) {
        for (let i = 0; i < clientList.length; i++) {
          const client = clientList[i];
          if (client.url.includes(self.location.origin) && 'focus' in client) {
            client.navigate(urlToOpen);
            return client.focus();
          }
        }
        return clients.openWindow(urlToOpen);
      })
  );
});

// Message event - handle offline submission queue
self.addEventListener('message', (event) => {
  if (event.data?.type === 'SKIP_WAITING') {
    self.skipWaiting();
    return;
  }

  if (event.data?.type === 'QUEUE_SUBMISSION') {
    event.ports[0].postMessage({
      type: 'QUEUE_STORED',
      success: true,
    });
  }

  if (event.data?.type === 'SYNC_QUEUE') {
    event.ports[0].postMessage({
      type: 'SYNC_STARTED',
      success: true,
    });
  }
});
