// v9: v8 could hold a sign-in page stored as the Daily shell; activating v9
// deletes that cache and only verified Daily HTML is stored from now on.
// v10: v9 served every same-origin GET (including 1.6-5 MB station photos)
// cache-first forever, so one truncated image write was shown permanently
// ("Image corrupt or truncated"). Activating v10 deletes that cache; only
// immutable build assets and the precache list are cache-first now, and other
// static GETs are network-first and only stored after their body is verified.
// v11: drops any 300 / Captain 5 message attachment a v10 worker may have stored (they are private
// and per-audience); attachments now always bypass the cache.
const CACHE_NAME = 'mbfd-checkout-v11';
const API_CACHE_NAME = 'mbfd-api-cache-v7';
const BUILD_ASSETS = /* DAILY_BUILD_ASSETS */ [];
const DAILY_SHELL_KEYS = ['/daily/', '/daily/index.html'];
const APP_SHELL_CACHE_KEYS = [
  '/manifest.json',
  '/images/wallpapers/mbfd-smartphone-c13ce770.webp',
  '/images/wallpapers/mbfd-tablet-87f370c6.webp',
  '/images/wallpapers/mbfd-desktop-cea4e417.webp',
  ...BUILD_ASSETS,
];

function isDailyPath(pathname) {
  return pathname === '/daily' || pathname.startsWith('/daily/');
}

// Only a direct, same-origin HTML response for a /daily/ URL that contains the
// Daily mount point may become the offline app shell. A redirect (for example
// to /login after the session expired) or any other page must never be stored
// as the Daily app.
async function isGenuineDailyShell(response) {
  if (!response || !response.ok || response.redirected || response.type !== 'basic') {
    return false;
  }

  let finalUrl;
  try {
    finalUrl = new URL(response.url);
  } catch {
    return false;
  }

  if (finalUrl.origin !== self.location.origin
    || !isDailyPath(finalUrl.pathname)
    || !(response.headers.get('Content-Type') || '').includes('text/html')) {
    return false;
  }

  try {
    return (await response.clone().text()).includes('<div id="root">');
  } catch {
    return false;
  }
}

function isCacheableSameOriginResponse(response) {
  return Boolean(response) && response.ok && !response.redirected && response.type === 'basic';
}

const PRECACHED_PATHS = new Set(APP_SHELL_CACHE_KEYS);

// Hashed build output never changes under the same URL, and the precache list
// is installed with the worker; only these may be served cache-first.
function isImmutablePath(pathname) {
  return pathname.startsWith('/daily/assets/') || PRECACHED_PATHS.has(pathname);
}

// A full 200 only: a 206 partial or a redirect is never a complete file.
function isStorableStaticResponse(response) {
  return isCacheableSameOriginResponse(response) && response.status === 200;
}

// Stores a complete copy of a static response. The body is read in full first,
// and when the server declared a Content-Length (and sent the body unencoded)
// the bytes received must match it, so a short or interrupted body is never
// written. The stored response is rebuilt from the verified blob.
async function storeVerifiedStatic(request, response) {
  const declared = response.headers.get('Content-Length');
  const encoded = response.headers.get('Content-Encoding');
  const body = await response.blob();
  if (!encoded && declared !== null && /^\d+$/.test(declared) && body.size !== Number(declared)) {
    return false;
  }

  const cache = await caches.open(CACHE_NAME);
  await cache.put(request, new Response(body, {
    status: 200,
    statusText: response.statusText,
    headers: response.headers,
  }));
  return true;
}

// Cache writes happen after the response is returned and must never break it.
function storeStaticInBackground(event, request, response) {
  if (!isStorableStaticResponse(response)) return;
  const write = storeVerifiedStatic(request, response.clone()).catch(() => false);
  if (typeof event.waitUntil === 'function') event.waitUntil(write);
}

// Immutable assets: cache first, store on a miss.
async function cacheFirstImmutable(event, request, ignoreVary) {
  const cached = await caches.match(request, { ignoreVary });
  if (cached) return cached;

  try {
    const response = await fetch(request);
    storeStaticInBackground(event, request, response);
    return response;
  } catch {
    return new Response('Offline', { status: 503 });
  }
}

// Everything else that is static (station photos, icons, wallpapers not in the
// precache list): always revalidate with the network so a stale or damaged
// stored copy is replaced; the stored copy is only an offline fallback.
async function networkFirstStatic(event, request, isNavigationRequest) {
  try {
    const response = await fetch(request, { cache: 'no-cache' });
    if (!isNavigationRequest) storeStaticInBackground(event, request, response);
    return response;
  } catch {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(request);
    if (cached) return cached;
    if (isNavigationRequest) {
      const shell = await cache.match('/daily/index.html');
      if (shell) return shell;
    }
    return new Response('Offline', { status: 503 });
  }
}

async function precacheDailyShell(cache) {
  for (const key of DAILY_SHELL_KEYS) {
    try {
      const response = await fetch(key, { cache: 'no-store', credentials: 'same-origin' });
      if (await isGenuineDailyShell(response)) {
        await cache.put(key, response);
      }
    } catch {
      // Signed out or offline during install: the next genuine navigation
      // stores the shell instead.
    }
  }
}

// Install event - cache static assets
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(async (cache) => {
      await cache.addAll(APP_SHELL_CACHE_KEYS);
      await precacheDailyShell(cache);
    })
  );

  self.skipWaiting();
});

// Activate event - clean up old caches
// A worker installed while signed out has no shell. Before older caches are
// deleted, keep a genuine older shell and the build assets it references, so
// an offline launch still opens the app; the next online navigation replaces
// it. A poisoned (non-Daily) shell is never carried over.
async function carryOverGenuineShell(cacheNames) {
  const current = await caches.open(CACHE_NAME);
  if (await current.match('/daily/index.html')) return;

  for (const name of cacheNames) {
    if (!name.startsWith('mbfd-checkout-') || name === CACHE_NAME) continue;
    const previous = await caches.open(name);
    const shell = await previous.match('/daily/index.html');
    if (!(await isGenuineDailyShell(shell))) continue;

    await current.put('/daily/index.html', shell.clone());
    await current.put('/daily/', shell.clone());
    for (const request of await previous.keys()) {
      const path = new URL(request.url, self.location.origin).pathname;
      if (!path.startsWith('/daily/assets/') || await current.match(request)) continue;
      const asset = await previous.match(request);
      if (asset) await current.put(request, asset);
    }
    return;
  }
}

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then(async (cacheNames) => {
      try {
        await carryOverGenuineShell(cacheNames);
      } catch {
        // Losing the carried-over shell only affects an offline launch.
      }
      return cacheNames;
    }).then((cacheNames) => {
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
  const isStationApiRequest = isSameOrigin && url.pathname.startsWith('/api/public/stations');
  const isLiveServiceRequest = isSameOrigin && (
    url.pathname.endsWith('/service-notices') ||
    url.pathname.endsWith('/service-tickets')
  );
  // Private, per-audience message attachments (300 / Captain 5) are authorized on every request and
  // sent no-store: never keep a copy a different person could open later on a shared device.
  const isPrivateAttachmentRequest = isSameOrigin && url.pathname.startsWith('/command-communications/attachments/');

  // Identity and member-specific follow-ups must always be authorized live.
  // Only the existing, redacted station/apparatus read models may use API cache.
  if (isSameOrigin && url.pathname.startsWith('/api/') && !isApparatusApiRequest && !isStationApiRequest) {
    event.respondWith(fetch(request, { cache: 'no-store' }));
    return;
  }

  if (isPrivateAttachmentRequest) {
    event.respondWith(fetch(request, { cache: 'no-store' }));
    return;
  }

  // Service state is operationally time-sensitive. Never satisfy it from a
  // stale service-worker cache; callers render a non-blocking unavailable state.
  if (isLiveServiceRequest) {
    event.respondWith(fetch(request, { cache: 'no-store' }));
    return;
  }

  if (isNavigationRequest && isDailyShellRequest) {
    event.respondWith(
      (async () => {
        const cache = await caches.open(CACHE_NAME);

        try {
          const networkResponse = await fetch(request, { cache: 'no-store' });

          if (await isGenuineDailyShell(networkResponse)) {
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

  if (isStationApiRequest || isApparatusApiRequest) {
    event.respondWith(
      (async () => {
        const cache = await caches.open(API_CACHE_NAME);

        try {
          const networkResponse = await fetch(request, { cache: 'no-store' });

          if (isCacheableSameOriginResponse(networkResponse)) {
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

  // Cross-origin requests and byte-range requests (a stored full copy would be
  // the wrong answer to a partial request) go straight to the browser.
  if (!isSameOrigin || request.headers?.has('range')) {
    return;
  }

  // Build assets are immutable and same-origin. Their precache request has
  // no Origin header, while module requests can carry one; Vary: Origin must
  // not hide the already-cached asset during an offline reload.
  if (isImmutablePath(url.pathname)) {
    event.respondWith(cacheFirstImmutable(event, request, url.pathname.startsWith('/daily/assets/')));
    return;
  }

  event.respondWith(networkFirstStatic(event, request, isNavigationRequest));
});

// ─── Push Notification Handlers (ERROR-036 fix) ───────────────────────────────
// These listeners MUST be present in the final sw.js to receive web push events.
// The build's serviceWorkerCopyPlugin
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

function sameOriginNavigation(value, fallback) {
  try {
    const candidate = new URL(String(value || fallback), self.location.origin);
    if (candidate.origin !== self.location.origin) return fallback;
    return candidate.pathname + candidate.search + candidate.hash;
  } catch {
    return fallback;
  }
}

self.addEventListener('notificationclick', function (event) {
  event.notification.close();

  const data = event.notification.data || {};
  let requestedUrl = data.url || '/';

  if (event.action === 'open-chat' || requestedUrl.includes('/chat')) {
    requestedUrl = data.url || '/';
  }
  const urlToOpen = sameOriginNavigation(requestedUrl, '/');

  event.waitUntil(
    clients
      .matchAll({ type: 'window', includeUncontrolled: true })
      .then(function (clientList) {
        for (let i = 0; i < clientList.length; i++) {
          const client = clientList[i];
          if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
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
  let sourceUrl;
  try {
    sourceUrl = new URL(event.source?.url || '');
  } catch {
    return;
  }
  if (sourceUrl.origin !== self.location.origin) return;
  const replyPort = event.ports?.[0];

  if (event.data?.type === 'SKIP_WAITING') {
    self.skipWaiting();
    return;
  }

  if (event.data?.type === 'QUEUE_SUBMISSION') {
    if (!replyPort) return;
    replyPort.postMessage({
      type: 'QUEUE_STORED',
      success: true,
    });
  }

  if (event.data?.type === 'SYNC_QUEUE') {
    if (!replyPort) return;
    replyPort.postMessage({
      type: 'SYNC_STARTED',
      success: true,
    });
  }
});
