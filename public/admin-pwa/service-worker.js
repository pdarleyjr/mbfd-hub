/**
 * MBFD Admin PWA Service Worker
 *
 * Scope: /admin/*
 * Strategy:
 *   - Network-first for HTML navigations (always reflect latest server state)
 *   - Stale-while-revalidate for GET /admin/* JSON / Livewire payloads
 *   - Cache-first for static assets (/build/, /admin-pwa/, /images/, /fonts/)
 *   - Bypass entirely for /admin/login, /admin/logout, and any POST/PUT/PATCH/DELETE
 *
 * Mobile/tablet safety:
 *   This SW is ONLY registered when the install-prompt.ts logic decides the
 *   user is on a desktop with a fine pointer. Once installed, it scopes to
 *   /admin/ — it cannot intercept fetches outside /admin even if it wanted
 *   to (per Service Worker spec).
 *
 * Kill-switch:
 *   Returning a 404 for /admin-pwa/service-worker.js + bumping the bust
 *   query param causes browsers to drop the registration on next visit.
 *   Or push a one-line SW that calls self.registration.unregister().
 *
 * Cloned from the proven /daily/ checkout SW pattern, retuned for admin.
 */

const VERSION = 'mbfd-admin-v1';
const STATIC_CACHE = `${VERSION}-static`;
const DATA_CACHE = `${VERSION}-data`;

const PRECACHE_URLS = [
  '/admin-pwa/manifest.webmanifest',
  '/admin-pwa/icons/icon-96.png',
  '/admin-pwa/icons/icon-192.png',
  '/admin-pwa/icons/icon-512.png',
];

const STATIC_HOSTS_OR_PREFIXES = [
  '/build/',
  '/admin-pwa/',
  '/images/',
  '/fonts/',
  '/favicon.ico',
];

const BYPASS_PREFIXES = [
  '/admin/login',
  '/admin/logout',
  '/admin/livewire/upload-file',
  '/livewire/',
  '/admin/_debugbar',
];

// ---------------------------------------------------------------------------
// install — precache and skip waiting
// ---------------------------------------------------------------------------
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then((cache) =>
      cache.addAll(PRECACHE_URLS).catch(() => {
        // Precache is best-effort — never block install on a missing icon.
      })
    ).then(() => self.skipWaiting())
  );
});

// ---------------------------------------------------------------------------
// activate — clean up old versions
// ---------------------------------------------------------------------------
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((k) => k.startsWith('mbfd-admin-') && !k.startsWith(VERSION))
          .map((k) => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

// ---------------------------------------------------------------------------
// fetch — route by request shape
// ---------------------------------------------------------------------------
self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  // Only handle same-origin
  if (url.origin !== self.location.origin) return;

  // Never cache non-GET; let the browser handle them normally
  if (req.method !== 'GET') return;

  // Bypass paths that must always hit the network
  if (BYPASS_PREFIXES.some((p) => url.pathname.startsWith(p))) return;

  // Static asset → cache-first
  if (STATIC_HOSTS_OR_PREFIXES.some((p) => url.pathname.startsWith(p))) {
    event.respondWith(cacheFirst(req));
    return;
  }

  // HTML navigation under /admin → network-first with cached fallback
  if (req.mode === 'navigate' && url.pathname.startsWith('/admin')) {
    event.respondWith(networkFirstWithFallback(req));
    return;
  }

  // /admin/* JSON (e.g. read-only API endpoints) → stale-while-revalidate
  if (url.pathname.startsWith('/admin/') && req.headers.get('Accept')?.includes('application/json')) {
    event.respondWith(staleWhileRevalidate(req));
    return;
  }

  // Everything else: let it pass through
});

// ---------------------------------------------------------------------------
// Strategies
// ---------------------------------------------------------------------------
async function cacheFirst(request) {
  const cache = await caches.open(STATIC_CACHE);
  const hit = await cache.match(request);
  if (hit) return hit;
  try {
    const fresh = await fetch(request);
    if (fresh.ok) cache.put(request, fresh.clone());
    return fresh;
  } catch (err) {
    return new Response('', { status: 504, statusText: 'Offline' });
  }
}

async function networkFirstWithFallback(request) {
  try {
    const fresh = await fetch(request);
    if (fresh.ok) {
      const cache = await caches.open(DATA_CACHE);
      cache.put(request, fresh.clone());
    }
    return fresh;
  } catch (err) {
    const cache = await caches.open(DATA_CACHE);
    const cached = await cache.match(request);
    if (cached) return cached;
    return offlineShell();
  }
}

async function staleWhileRevalidate(request) {
  const cache = await caches.open(DATA_CACHE);
  const cached = await cache.match(request);
  const network = fetch(request)
    .then((res) => {
      if (res.ok) cache.put(request, res.clone());
      return res;
    })
    .catch(() => null);
  return cached || (await network) || new Response(JSON.stringify({ offline: true }), {
    status: 503,
    headers: { 'Content-Type': 'application/json' },
  });
}

function offlineShell() {
  return new Response(
    `<!doctype html><meta charset="utf-8"><title>MBFD Admin – Offline</title>
     <style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;
     padding:2rem;background:#0f172a;color:#e2e8f0;text-align:center}
     h1{font-size:1.5rem;margin-bottom:0.5rem}p{color:#94a3b8}</style>
     <h1>You're offline</h1>
     <p>The MBFD Admin console will reconnect automatically when the network returns.</p>`,
    {
      status: 200,
      headers: { 'Content-Type': 'text/html; charset=utf-8' },
    }
  );
}

// ---------------------------------------------------------------------------
// Push notifications — uses existing webpush VAPID infrastructure
// ---------------------------------------------------------------------------
self.addEventListener('push', (event) => {
  if (!event.data) return;
  let payload;
  try { payload = event.data.json(); } catch (e) { payload = { title: 'MBFD Admin', body: event.data.text() }; }
  const title = payload.title || 'MBFD Admin';
  const options = {
    body: payload.body || '',
    icon: payload.icon || '/admin-pwa/icons/icon-192.png',
    badge: payload.badge || '/admin-pwa/icons/icon-96.png',
    data: payload.data || {},
    tag: payload.tag,
    requireInteraction: !!payload.requireInteraction,
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = event.notification.data?.url || '/admin';
  event.waitUntil(
    self.clients.matchAll({ type: 'window' }).then((wins) => {
      const existing = wins.find((w) => w.url.includes('/admin'));
      if (existing) {
        existing.focus();
        existing.navigate(target);
      } else {
        self.clients.openWindow(target);
      }
    })
  );
});
