// Script URL /daily/sw.js, scope /daily/ (frozen: installed devices only look for
// updates at exactly this URL; never rename, redirect, version-suffix or
// unregister it). /daily/service-worker.js is emitted as a byte-identical copy of the
// built worker, see vite.config.js.
//
// Push display, notification routing, subscription refresh and the update
// message protocol are the shared worker core, inlined from
// resources/js/pwa/worker-core.js by scripts/pwa/build-service-workers.mjs.
// Edit the core, not the block between the markers.
//
// v9: v8 could hold a sign-in page stored as the Daily shell; activating v9
// deletes that cache and only verified Daily HTML is stored from now on.
// v10: v9 served every same-origin GET (including 1.6-5 MB station photos)
// cache-first forever, so one truncated image write was shown permanently
// ("Image corrupt or truncated"). Activating v10 deletes that cache; only
// immutable build assets and the precache list are cache-first now, and other
// static GETs are network-first and only stored after their body is verified.
// v11: drops any 300 / Captain 5 message attachment a v10 worker may have stored (they are private
// and per-audience); attachments now always bypass the cache.
// v12 (cache contract change): the authenticated station/apparatus API cache was
// never purged on logout or user switch and kept any /api/public/stations* and
// /api/public/apparatuses* GET (including per-member request and activity data).
// Activating v12 deletes the v11 shell cache and the v7 API cache, only an explicit
// allowlist of read models is cached from now on (the lists and detail the offline
// checkout needs), and the page can send {type: 'PURGE_USER_CACHES'} on logout or
// user switch. The purge touches Cache Storage only: IndexedDB (offline drafts and the
// queued submissions) is never opened, read, changed or deleted by this worker.
// /app-release (the update registrar's release probe) is never intercepted.
// v12 (same cache names, review follow-ups; no bump needed because v12 has not shipped):
//  - the offline allowlist again covers every station-wide read the Daily screens use (inspection
//    history, gas meters, room profile, requests, equipment requests, activity). All of them are
//    redacted and station-scoped, not member-private, and every API cache is purged on sign-out and
//    user switch, so the baseline (fc35bb83) offline behavior is restored;
//  - activation copies the previous cache's /daily/assets/* into this cache before that cache is
//    deleted, so a page still running the old JavaScript can lazy-load its chunks; assets older than
//    two releases are pruned (see settleAssetGenerations);
//  - install and shell refresh store the Laravel-rendered /daily/ shell (it carries the release
//    meta tag), never the raw static /daily/index.html;
//  - only a redirect to the sign-in page or a 401 counts as "signed out"; canonical-host redirects,
//    forced-password-change redirects and 403s never purge or delete the shell;
//  - after a user-switch purge the worker re-fetches the two lists and the few read models the
//    previous cache held (bounded, session proven first) so the offline checkout is not left empty.
const CACHE_NAME = 'mbfd-checkout-v12';
const API_CACHE_NAME = 'mbfd-api-cache-v8';
const API_CACHE_PREFIX = 'mbfd-api-cache-';
const MBFD_WORKER_BUILD_ID = CACHE_NAME;
const BUILD_ASSETS = /* DAILY_BUILD_ASSETS */ [];
// The shell is fetched from /daily/ only: Laravel renders it (release meta tag, canonical origin,
// hub navigation). /daily/index.html is a real file the web server hands out raw, so it is only
// ever a storage key, never something this worker fetches.
const DAILY_SHELL_URL = '/daily/';
const DAILY_SHELL_KEYS = ['/daily/', '/daily/index.html'];
const DAILY_ASSET_PREFIX = '/daily/assets/';
// The file types the Daily build puts in BUILD_ASSETS (see vite.config.js); the only ones pruned.
const PRECACHED_ASSET_TYPES = /\.(?:js|css|png)$/;
// Where activation remembers which hashed assets belong to the current and the previous release.
// A synthetic key that no page ever requests.
const ASSET_GENERATIONS_KEY = '/__mbfd/daily-asset-generations';
// First rollout from an older cache name: carry over at most this many of that cache's newest assets.
const CARRY_OVER_ASSET_LIMIT = 300;
// Read models fetched again after a user-switch purge (/api/public is throttled to 60 a minute per member).
const REWARM_LIMIT = 10;
const SIGN_IN_PATH = /^\/(?:admin\/)?login\/?$/;
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

// Proof that the session is gone: a 401, or a redirect whose final stop is the sign-in page. A
// redirect anywhere else (the canonical host, forced password change, city e-mail review), a 403
// (an edge or role decision) and a 5xx are NOT sign-out: the stored shell and caches must stay.
function isSignedOutResponse(response) {
  if (!response) return false;
  if (response.status === 401) return true;
  if (!response.redirected) return false;

  try {
    const finalUrl = new URL(response.url);
    return finalUrl.origin === self.location.origin && SIGN_IN_PATH.test(finalUrl.pathname);
  } catch {
    return false;
  }
}

async function storeDailyShell(cache, response) {
  await cache.put('/daily/index.html', response.clone());
  await cache.put('/daily/', response);
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
  try {
    const response = await fetch(DAILY_SHELL_URL, { cache: 'no-store', credentials: 'same-origin' });
    if (await isGenuineDailyShell(response)) {
      await storeDailyShell(cache, response);
    }
  } catch {
    // Signed out or offline during install: the next genuine navigation
    // stores the shell instead.
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
// Before older caches are deleted:
//  - a worker installed while signed out has no shell, so keep a genuine older shell (a poisoned,
//    non-Daily shell is never carried over; the next online navigation replaces it);
//  - always copy the newest older cache's /daily/assets/* into this cache (bounded by
//    CARRY_OVER_ASSET_LIMIT). A page that is still running the previous JavaScript (an installed app
//    stays resident for days) lazy-loads chunks by their old hashed names; without this copy those
//    requests would 404 against the new release and the page would break until relaunched.
function cacheVersion(name) {
  const match = /-v(\d+)$/.exec(name);
  return match ? Number(match[1]) : -1;
}

function assetPathOf(request) {
  try {
    return new URL(request.url, self.location.origin).pathname;
  } catch {
    return '';
  }
}

// Paths of every stored hashed asset, oldest first.
async function storedAssetPaths(cache) {
  return (await cache.keys()).map(assetPathOf).filter((path) => path.startsWith(DAILY_ASSET_PREFIX));
}

async function carryOverPreviousGeneration(cacheNames) {
  const current = await caches.open(CACHE_NAME);
  const previousNames = cacheNames
    .filter((name) => name.startsWith('mbfd-checkout-') && name !== CACHE_NAME)
    .sort((a, b) => cacheVersion(b) - cacheVersion(a));
  const carried = [];
  let needShell = !(await current.match('/daily/index.html'));
  let assetsCarried = false;

  for (const name of previousNames) {
    const previous = await caches.open(name);

    if (needShell) {
      const shell = await previous.match('/daily/index.html');
      if (await isGenuineDailyShell(shell)) {
        await storeDailyShell(current, shell);
        needShell = false;
      }
    }

    if (!assetsCarried) {
      assetsCarried = true;
      const candidates = [];
      for (const request of await previous.keys()) {
        const path = assetPathOf(request);
        if (path.startsWith(DAILY_ASSET_PREFIX) && !(await current.match(request))) candidates.push(request);
      }
      for (const request of candidates.slice(-CARRY_OVER_ASSET_LIMIT)) {
        const asset = await previous.match(request);
        if (!isStorableStaticResponse(asset)) continue;
        await current.put(request, asset);
        carried.push(assetPathOf(request));
      }
    }

    if (assetsCarried && !needShell) break;
  }

  return carried;
}

async function readAssetGenerations(cache) {
  try {
    const stored = await cache.match(ASSET_GENERATIONS_KEY);
    const record = stored ? await stored.clone().json() : null;
    const paths = (value) => (Array.isArray(value) ? value.filter((path) => typeof path === 'string') : null);
    const current = paths(record?.current);
    const previous = paths(record?.previous);

    return current && previous ? { current, previous } : null;
  } catch {
    return null;
  }
}

// Keeps this release's hashed assets and the previous release's, and deletes older ones. The
// cache name does not change between releases, so without this every deploy's chunks would pile
// up. `carried` are the assets copied from an older cache name (the first activation under this
// scheme), which stand in for "the previous release" until the next activation moves on.
// A build without an asset list (the unbuilt template) never prunes.
async function settleAssetGenerations(carried) {
  const cache = await caches.open(CACHE_NAME);
  const current = BUILD_ASSETS.filter((path) => path.startsWith(DAILY_ASSET_PREFIX));
  if (current.length === 0) return;

  const currentSet = new Set(current);
  const stored = await storedAssetPaths(cache);
  const record = await readAssetGenerations(cache);
  let previous;
  if (record && record.current.length === currentSet.size && record.current.every((path) => currentSet.has(path))) {
    previous = record.previous;
  } else if (record) {
    previous = record.current;
  } else {
    previous = [...new Set([...carried, ...stored.filter((path) => !currentSet.has(path))])].slice(-CARRY_OVER_ASSET_LIMIT);
  }
  previous = previous.filter((path) => !currentSet.has(path));

  // Only the file types the build lists (and therefore the record knows about) are pruned. Fonts and
  // other hashed files are stored on first use, belong to no list, and must not disappear.
  const keep = new Set([...current, ...previous]);
  for (const path of new Set(stored)) {
    if (PRECACHED_ASSET_TYPES.test(path) && !keep.has(path)) await cache.delete(path);
  }
  await cache.put(ASSET_GENERATIONS_KEY, new Response(JSON.stringify({ current, previous }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  }));
}

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then(async (cacheNames) => {
      let carried = [];
      try {
        carried = await carryOverPreviousGeneration(cacheNames);
      } catch {
        // Losing the carried-over shell or assets only affects an offline launch or an old page.
      }
      try {
        await settleAssetGenerations(carried);
      } catch {
        // Pruning is housekeeping; a failure must never block activation.
      }
      return cacheNames;
    }).then((cacheNames) => {
      return Promise.all(
        cacheNames
          .filter((name) => {
            return (
              (name.startsWith('mbfd-checkout-') && name !== CACHE_NAME) ||
              (name.startsWith(API_CACHE_PREFIX) && name !== API_CACHE_NAME)
            );
          })
          .map((name) => caches.delete(name))
      );
    }).then(() => self.clients.claim())
  );
});

// Authenticated read models the Daily screens need offline: the apparatus and station lists, a
// checklist, station detail, rooms and room assets/profile, station inspection history, gas meters,
// and the station's request/equipment-request/activity feeds. This is every station-wide read the
// Daily app makes (audited against resources/js/daily-checkout/src/utils/api.ts), and the same set
// the baseline (fc35bb83) cached by prefix. They are the only API responses ever stored.
//  - Not member-private: the station endpoints return station-scoped data with requester identity
//    redacted (PublicStationRequestResource), so two members at one station see the same thing.
//  - Not entirely member-neutral: a checklist carries the viewer's own pending inventory
//    corrections. Hence every API cache is purged on sign-out and on a user switch (see
//    purgeUserCaches), so requests/activity, like the checklist, never outlive the member.
// Everything else under /api/ (service tickets and notices, identity, captain/command feeds,
// anything per member such as /api/me/*, fleet vehicles, revisions) always goes to the network.
const OFFLINE_READ_MODELS = [
  /^\/api\/public\/apparatuses$/,
  /^\/api\/public\/apparatuses\/[^/]+\/checklist$/,
  /^\/api\/public\/stations$/,
  /^\/api\/public\/stations\/[^/]+$/,
  /^\/api\/public\/stations\/[^/]+\/rooms$/,
  /^\/api\/public\/stations\/[^/]+\/rooms\/[^/]+\/assets$/,
  /^\/api\/public\/stations\/[^/]+\/rooms\/[^/]+\/profile$/,
  /^\/api\/public\/stations\/[^/]+\/apparatus$/,
  /^\/api\/public\/stations\/[^/]+\/inspections$/,
  /^\/api\/public\/stations\/[^/]+\/gas-meters$/,
  /^\/api\/public\/stations\/[^/]+\/equipment-requests$/,
  /^\/api\/public\/stations\/[^/]+\/requests$/,
  /^\/api\/public\/stations\/[^/]+\/activity$/,
];

function isOfflineReadModel(pathname) {
  return OFFLINE_READ_MODELS.some((pattern) => pattern.test(pathname));
}

// Bumped by every purge so a response that was already in flight when the member
// changed cannot be stored afterwards.
let userCachePurgeEpoch = 0;

// Re-fetch the member's Daily shell after a purge, fetch first: a genuine shell replaces the
// stored one; proof that the session is gone (a 401, or a redirect that ended on the sign-in
// page) removes the old member's shell; anything else (offline, a flaky network, a 5xx, a
// canonical-host or forced-password-change redirect, a 403) leaves the stored shell alone, so an
// offline launch keeps working. Nothing is ever deleted without a replacement or proof.
// Resolves true when a genuine shell came back, which also proves there is a live session.
async function refreshDailyShell(cache, epoch) {
  let response;
  try {
    response = await fetch(DAILY_SHELL_URL, { cache: 'no-store', credentials: 'same-origin' });
  } catch {
    return false;
  }

  if (await isGenuineDailyShell(response)) {
    if (epoch === userCachePurgeEpoch) await storeDailyShell(cache, response);

    return true;
  }
  if (isSignedOutResponse(response)) {
    for (const key of DAILY_SHELL_KEYS) await cache.delete(key);
  }

  return false;
}

// Read models the purged caches held, most recently stored first, as path + query.
async function rememberedReadModels() {
  const remembered = [];
  try {
    for (const name of (await caches.keys()).filter((cacheName) => cacheName.startsWith(API_CACHE_PREFIX))) {
      for (const request of await (await caches.open(name)).keys()) {
        const url = new URL(request.url, self.location.origin);
        if (isOfflineReadModel(url.pathname)) remembered.push(url.pathname + url.search);
      }
    }
  } catch {
    // Remembering is a convenience; the two lists below are always fetched.
  }

  return remembered.reverse();
}

// Fetch one read model and store it, unless a purge happened meanwhile. JSON answers only.
async function warmReadModel(path, epoch) {
  if (epoch !== userCachePurgeEpoch) return;
  const request = new Request(path, { headers: { Accept: 'application/json', 'Content-Type': 'application/json' } });

  try {
    const response = await fetch(request, { cache: 'no-store' });
    if (!isCacheableSameOriginResponse(response) || !(response.headers.get('Content-Type') || '').includes('json')) return;
    await response.clone().json();
    if (epoch !== userCachePurgeEpoch) return;
    const cache = await caches.open(API_CACHE_NAME);
    await cache.put(request, response);
    if (epoch !== userCachePurgeEpoch) await cache.delete(request);
  } catch {
    // Offline, a bad body or a full cache: the page simply fetches it when it needs it.
  }
}

// After a user-switch purge the offline checkout would otherwise be empty until each screen was
// opened online again. So, once the session is proven, fetch again what the purged caches held
// plus the two lists, bounded to REWARM_LIMIT (the API is throttled per member) and one at a time.
async function warmOfflineReadModels(epoch, remembered) {
  const plan = [...new Set(['/api/public/apparatuses', '/api/public/stations', ...remembered])].slice(0, REWARM_LIMIT);
  for (const path of plan) {
    if (epoch !== userCachePurgeEpoch) return;
    await warmReadModel(path, epoch);
  }
}

// Logout / user switch. Removes everything this worker stored that belongs to one
// member: every API cache (the privacy-critical part, reported through onCachesDeleted as
// soon as it is done) and, once the server says so, the per-member Daily shell (it embeds
// the member's name and app list). Public build assets and images stay. When the refreshed shell
// proves a live session (a user switch, not a sign-out), the offline read models are fetched again
// for that member. Cache Storage only; IndexedDB is never touched.
async function purgeUserCaches(onCachesDeleted) {
  userCachePurgeEpoch += 1;
  const epoch = userCachePurgeEpoch;
  const remembered = await rememberedReadModels();
  const names = await caches.keys();
  await Promise.all(
    names.filter((name) => name.startsWith(API_CACHE_PREFIX)).map((name) => caches.delete(name))
  );
  if (typeof onCachesDeleted === 'function') onCachesDeleted();

  if (await refreshDailyShell(await caches.open(CACHE_NAME), epoch)) {
    await warmOfflineReadModels(epoch, remembered);
  }
}

// A Daily navigation was redirected. The browser hides where to, so ask again with redirects
// followed: only when that ends on the sign-in page (or answers 401) has whoever used this
// device last signed out, and only then is their cached member data removed. A redirect to the
// canonical host, to forced password change or to city e-mail review keeps everything.
async function purgeIfSignedOut() {
  let response;
  try {
    response = await fetch(DAILY_SHELL_URL, { cache: 'no-store', credentials: 'same-origin' });
  } catch {
    return;
  }
  if (isSignedOutResponse(response)) await purgeUserCaches();
}

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
  const isOfflineReadModelRequest = isSameOrigin && isOfflineReadModel(url.pathname);
  const isLiveServiceRequest = isSameOrigin && (
    url.pathname.endsWith('/service-notices') ||
    url.pathname.endsWith('/service-tickets')
  );
  // Private, per-audience message attachments (300 / Captain 5) are authorized on every request and
  // sent no-store: never keep a copy a different person could open later on a shared device.
  const isPrivateAttachmentRequest = isSameOrigin && url.pathname.startsWith('/command-communications/attachments/');

  // The update registrar's release probe must always reach the network and must
  // never be stored: a stale answer would hide a new release.
  if (isSameOrigin && url.pathname === '/app-release') {
    return;
  }

  // Identity and member-specific follow-ups must always be authorized live.
  // Only the allowlisted station/apparatus read models may use the API cache.
  // /ems/api/* (Captain 5 Rescue Inventory and Supply Room) carries member names, messages
  // and stock, so it is never stored either.
  const isLiveApiPath = url.pathname.startsWith('/api/') || url.pathname.startsWith('/ems/api/');
  if (isSameOrigin && isLiveApiPath && !isOfflineReadModelRequest) {
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
        // Read before the first await: a shell that arrives after a purge belongs to the previous member.
        const epoch = userCachePurgeEpoch;
        const cache = await caches.open(CACHE_NAME);

        try {
          const networkResponse = await fetch(request, { cache: 'no-store' });

          if (await isGenuineDailyShell(networkResponse)) {
            if (epoch === userCachePurgeEpoch) await storeDailyShell(cache, networkResponse.clone());
          } else if (networkResponse.type === 'opaqueredirect' && typeof event.waitUntil === 'function') {
            // The server redirected a Daily navigation. For a member who is not signed in that
            // means "go to sign-in": whoever used this device last has signed out, and their
            // cached member data must not stay readable for the next person. But the same kind
            // of redirect also serves the canonical host, forced password change and city
            // e-mail review, which are not sign-out, so the destination is checked first.
            event.waitUntil(purgeIfSignedOut().catch(() => undefined));
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

  if (isOfflineReadModelRequest) {
    event.respondWith(
      (async () => {
        // Read before the first await: the request belongs to whoever was signed in when it began.
        const epoch = userCachePurgeEpoch;
        const cache = await caches.open(API_CACHE_NAME);

        try {
          const networkResponse = await fetch(request, { cache: 'no-store' });

          if (isCacheableSameOriginResponse(networkResponse) && epoch === userCachePurgeEpoch) {
            // A full or failing cache must never replace a good network answer.
            await cache.put(request, networkResponse.clone()).catch(() => undefined);
            // A purge that ran while the write was in progress wins.
            if (epoch !== userCachePurgeEpoch) await cache.delete(request).catch(() => undefined);
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

/* MBFD_WORKER_CORE:START */
// GENERATED from resources/js/pwa/worker-core.js by scripts/pwa/build-service-workers.mjs.
// Do not edit between the markers; edit the core and run `npm run build:service-workers`.
/**
 * MBFD Hub shared service-worker core (classic script, no module syntax).
 *
 * One authoritative home for push display, notification routing, push
 * subscription refresh and the update message protocol. It is inlined, by
 * scripts/pwa/build-service-workers.mjs, between the MBFD_WORKER_CORE markers
 * of every worker script that ships it (public/sw.js and
 * public/admin-pwa/service-worker.js). Edit this file, then run
 * `npm run build:service-workers`; CI fails if an inlined copy drifts.
 *
 * Rules this file must keep:
 *  - Classic-script safe: no import/export, no importScripts, no top-level
 *    await. Workers are fetched as classic scripts at stable URLs.
 *  - Every push event ends in showNotification inside event.waitUntil. Safari
 *    and iOS revoke subscriptions that receive a silent push, so there is no
 *    early return, not even when the payload is empty, garbled or the
 *    permission looks wrong.
 *  - Nothing here opens, closes or removes a cache, a registration or an
 *    IndexedDB database. Caching strategy belongs to each worker.
 *  - Never use WindowClient.navigate(): Safari throws, and Chromium rejects it
 *    for windows controlled by a different registration. The page decides.
 *
 * Messages understood by the page side (resources/js/pwa/update-registrar.ts):
 *   worker -> page  { type: 'MBFD_NAVIGATE', url }   (+ one MessagePort for an ack)
 *   page -> worker  { type: 'SKIP_WAITING' }
 *   page -> worker  { type: 'GET_VERSION' }  (reply on ports[0])
 */

// .2: the page-acknowledgement wait is a 600 ms total budget that starts before focus() (was 800 ms after it).
const MBFD_WORKER_CORE_VERSION = '2026-10-23.2';

(function mbfdWorkerCore(scope) {
  'use strict';

  var DEFAULT_TITLE = 'MBFD Hub Update';
  var DEFAULT_BODY = 'New activity reported.';
  var DEFAULT_ICON = '/images/mbfd-logo.png';
  var DEFAULT_BADGE = '/images/mbfd-logo.png';
  var DEFAULT_VIBRATE = [200, 100, 200];
  var DEFAULT_URL = '/';
  var DECLARATIVE_PUSH_MARKER = 8030;
  var MAX_ACTIONS = 2;
  var DISMISS_ACTIONS = ['dismiss', 'close'];
  // Total budget, counted from the moment the page is asked (not from when focus() returns), for the
  // page to acknowledge. Firefox and WebKit only allow openWindow for ~1s after a notification click,
  // so the wait must end well inside that even when focusing the window is slow.
  var NAVIGATE_ACK_TIMEOUT_MS = 600;
  var SUBSCRIPTION_ENDPOINT = '/api/push-subscriptions';

  function origin() {
    return scope.location.origin;
  }

  function isPlainObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
  }

  function asText(value) {
    return typeof value === 'string' ? value : '';
  }

  /**
   * Resolve a destination against this origin. Returns path + search + hash,
   * or the fallback for anything cross-origin, malformed or non-http(s).
   */
  function sameOriginNavigation(value, fallback) {
    var safeFallback = typeof fallback === 'string' ? fallback : DEFAULT_URL;
    try {
      var candidate = new URL(String(value || safeFallback), origin());

      return candidate.origin === origin()
        ? candidate.pathname + candidate.search + candidate.hash
        : safeFallback;
    } catch (_) {
      return safeFallback;
    }
  }

  /** Same-origin asset path for icons, or the default when the payload value is unusable. */
  function sameOriginAsset(value, fallback) {
    if (typeof value !== 'string' || value === '') {
      return fallback;
    }
    try {
      var candidate = new URL(value, origin());

      return candidate.origin === origin() ? candidate.pathname + candidate.search : fallback;
    } catch (_) {
      return fallback;
    }
  }

  function pathAndSearch(value) {
    try {
      var candidate = new URL(String(value), origin());

      return candidate.origin === origin() ? candidate.pathname + candidate.search : null;
    } catch (_) {
      return null;
    }
  }

  function hashString(value) {
    var hash = 5381;
    for (var i = 0; i < value.length; i += 1) {
      hash = ((hash << 5) + hash + value.charCodeAt(i)) | 0;
    }

    return (hash >>> 0).toString(36);
  }

  // -------------------------------------------------------------------------
  // Payload parsing: current app format, Declarative Web Push, plain text.
  // -------------------------------------------------------------------------

  function readRawPayload(event) {
    var data = event && event.data;
    if (!data) {
      return {};
    }

    var text = '';
    try {
      text = data.text();
    } catch (_) {
      return {};
    }
    if (typeof text !== 'string' || text.trim() === '') {
      return {};
    }

    try {
      var parsed = JSON.parse(text);

      return isPlainObject(parsed) ? parsed : { body: String(parsed) };
    } catch (_) {
      return { body: text };
    }
  }

  function normalizeActions(list, urls) {
    var actions = [];
    if (!Array.isArray(list)) {
      return actions;
    }

    for (var i = 0; i < list.length && actions.length < MAX_ACTIONS; i += 1) {
      var item = list[i];
      if (!isPlainObject(item) || !asText(item.action) || !asText(item.title)) {
        continue;
      }

      var action = { action: item.action, title: item.title };
      var icon = sameOriginAsset(item.icon, '');
      if (icon) {
        action.icon = icon;
      }
      actions.push(action);

      var destination = asText(item.url) || asText(item.navigate);
      if (destination) {
        urls[item.action] = sameOriginNavigation(destination, DEFAULT_URL);
      }
    }

    return actions;
  }

  /** Map a Declarative Web Push message onto the current app payload shape. */
  function fromDeclarative(raw) {
    var n = raw.notification;
    var mapped = {
      title: n.title,
      body: n.body,
      icon: n.icon,
      badge: n.badge,
      tag: n.tag,
      actions: n.actions,
      requireInteraction: n.require_interaction !== undefined ? n.require_interaction : n.requireInteraction,
      renotify: n.renotify,
      silent: n.silent,
      vibrate: n.vibrate,
      data: isPlainObject(n.data) ? Object.assign({}, n.data) : {},
    };
    if (n.navigate && !mapped.data.url) {
      mapped.data.url = n.navigate;
    }

    return mapped;
  }

  function buildNotification(raw) {
    var payload = isPlainObject(raw) ? raw : {};
    if (payload.web_push === DECLARATIVE_PUSH_MARKER && isPlainObject(payload.notification)) {
      payload = fromDeclarative(payload);
    }

    var data = isPlainObject(payload.data) ? Object.assign({}, payload.data) : {};
    var title = asText(payload.title) || DEFAULT_TITLE;
    var body = asText(payload.body) || DEFAULT_BODY;
    var url = sameOriginNavigation(data.url || payload.url || payload.navigate, DEFAULT_URL);
    var actionUrls = {};
    var actions = normalizeActions(payload.actions, actionUrls);

    data.url = url;
    if (Object.keys(actionUrls).length > 0) {
      data.mbfdActionUrls = actionUrls;
    }

    // Same tag replaces. Without a tag, identical alerts (for example one push
    // delivered through two registrations) collapse while different alerts stack.
    var tag = asText(payload.tag) || 'mbfd-' + hashString(title + '\n' + body + '\n' + url);

    var options = {
      body: body,
      icon: sameOriginAsset(payload.icon, DEFAULT_ICON),
      badge: sameOriginAsset(payload.badge, DEFAULT_BADGE),
      data: data,
      tag: tag,
      requireInteraction: typeof payload.requireInteraction === 'boolean' ? payload.requireInteraction : true,
      vibrate: Array.isArray(payload.vibrate) ? payload.vibrate : DEFAULT_VIBRATE,
    };
    if (actions.length > 0) {
      options.actions = actions;
    }
    if (payload.renotify === true) {
      options.renotify = true;
    }

    return { title: title, options: options };
  }

  // -------------------------------------------------------------------------
  // push
  // -------------------------------------------------------------------------

  async function showPush(event) {
    var built;
    try {
      built = buildNotification(readRawPayload(event));
    } catch (_) {
      built = buildNotification({});
    }

    try {
      await scope.registration.showNotification(built.title, built.options);
    } catch (_) {
      // A rejected option set (bad icon URL, unsupported action) must still end
      // in a visible notification, otherwise the push counts as silent.
      try {
        await scope.registration.showNotification(built.title, {
          body: built.options.body,
          tag: built.options.tag,
          data: built.options.data,
        });
      } catch (_ignored) {
        // Nothing more can be shown; do not turn this into an unhandled rejection.
      }
    }
  }

  function onPush(event) {
    event.waitUntil(showPush(event));
  }

  // -------------------------------------------------------------------------
  // notificationclick
  // -------------------------------------------------------------------------

  function resolveClickTarget(notification, action) {
    var data = isPlainObject(notification && notification.data) ? notification.data : {};
    var byAction = isPlainObject(data.mbfdActionUrls) ? data.mbfdActionUrls : {};

    if (action && typeof byAction[action] === 'string') {
      return sameOriginNavigation(byAction[action], DEFAULT_URL);
    }

    return sameOriginNavigation(data.url, DEFAULT_URL);
  }

  function isSameOriginClient(client) {
    return pathAndSearch(client && client.url) !== null;
  }

  function rankClient(client) {
    return (client.focused ? 2 : 0) + (client.visibilityState === 'visible' ? 1 : 0);
  }

  async function focusClient(client) {
    try {
      if (client && typeof client.focus === 'function') {
        await client.focus();
      }
    } catch (_) {
      // Focus can be refused by the platform; routing continues.
    }
  }

  /**
   * Ask a page to navigate itself. Resolves true when the page acknowledged
   * (even if it deferred the navigation because the member has unsaved work),
   * false when it never answered, for example because that page has no
   * MBFD_NAVIGATE listener.
   */
  function askPageToNavigate(client, target) {
    return new Promise(function (resolve) {
      var settled = false;
      var channel = null;
      var timer = null;

      function finish(value) {
        if (settled) {
          return;
        }
        settled = true;
        if (timer !== null) {
          clearTimeout(timer);
        }
        try {
          if (channel) {
            channel.port1.onmessage = null;
            channel.port1.close();
          }
        } catch (_) {
          // Port already closed.
        }
        resolve(value);
      }

      try {
        channel = new MessageChannel();
        channel.port1.onmessage = function () {
          finish(true);
        };
        client.postMessage({ type: 'MBFD_NAVIGATE', url: target }, [channel.port2]);
        timer = setTimeout(function () {
          finish(false);
        }, NAVIGATE_ACK_TIMEOUT_MS);
      } catch (_) {
        finish(false);
      }
    });
  }

  /** Resolves when the promise settles or after ms, whichever comes first. Never rejects. */
  function settleWithin(promise, ms) {
    return new Promise(function (resolve) {
      var timer = setTimeout(resolve, ms);
      function done() {
        clearTimeout(timer);
        resolve();
      }
      promise.then(done, done);
    });
  }

  async function openTarget(target) {
    try {
      await scope.clients.openWindow(target);
    } catch (_) {
      // openWindow can be refused outside a user-initiated click; nothing else to try.
    }
  }

  async function routeToTarget(target) {
    var clientList = [];
    try {
      clientList = await scope.clients.matchAll({ type: 'window', includeUncontrolled: true });
    } catch (_) {
      clientList = [];
    }

    var candidates = clientList.filter(isSameOriginClient);
    var wanted = pathAndSearch(target);

    // (a) A window already shows exactly this destination: just bring it forward.
    var exact = candidates.filter(function (client) {
      return pathAndSearch(client.url) === wanted;
    })[0];
    if (exact) {
      await focusClient(exact);

      return;
    }

    // (b) Another Hub window exists: focus it and let the page decide, because it
    // may hold unsaved work. A page that never answers gets a new window instead
    // of a silently dropped deep link.
    if (candidates.length > 0) {
      var best = candidates.slice().sort(function (a, b) {
        return rankClient(b) - rankClient(a);
      })[0];
      // Focus and ask at the same time, and start the acknowledgement timer immediately: a slow
      // focus() must not eat into the time openWindow still has before the browser stops
      // treating this as a user-initiated click.
      var focusing = focusClient(best);
      var acknowledged = await askPageToNavigate(best, target);
      if (acknowledged) {
        await settleWithin(focusing, NAVIGATE_ACK_TIMEOUT_MS);

        return;
      }
    }

    // (c) No usable window.
    await openTarget(target);
  }

  function onNotificationClick(event) {
    var notification = event.notification;
    try {
      notification.close();
    } catch (_) {
      // Already closed.
    }

    if (event.action && DISMISS_ACTIONS.indexOf(event.action) !== -1) {
      return;
    }

    event.waitUntil(routeToTarget(resolveClickTarget(notification, event.action)));
  }

  // -------------------------------------------------------------------------
  // pushsubscriptionchange (best effort, never throws)
  // -------------------------------------------------------------------------

  async function readCsrfHeaders() {
    var headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };
    try {
      if (scope.cookieStore && typeof scope.cookieStore.get === 'function') {
        var cookie = await scope.cookieStore.get('XSRF-TOKEN');
        if (cookie && cookie.value) {
          headers['X-XSRF-TOKEN'] = decodeURIComponent(cookie.value);
        }
      }
    } catch (_) {
      // cookieStore is not available in every browser; the request is still attempted.
    }

    return headers;
  }

  async function postSubscription(subscription) {
    var json = subscription.toJSON();
    var keys = isPlainObject(json.keys) ? json.keys : {};
    if (!json.endpoint || !keys.p256dh || !keys.auth) {
      return;
    }

    await fetch(SUBSCRIPTION_ENDPOINT, {
      method: 'POST',
      headers: await readCsrfHeaders(),
      credentials: 'same-origin',
      body: JSON.stringify({ endpoint: json.endpoint, keys: { p256dh: keys.p256dh, auth: keys.auth } }),
    });
  }

  async function refreshSubscription(event) {
    try {
      var subscription = event.newSubscription || null;

      if (!subscription) {
        var previous = event.oldSubscription;
        var key = previous && previous.options ? previous.options.applicationServerKey : null;
        if (!key) {
          return;
        }
        subscription = await scope.registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: key,
        });
      }

      await postSubscription(subscription);
    } catch (_) {
      // Best effort. The Hub re-syncs the subscription the next time the member
      // opens notification settings; the browser may also have no session here.
    }
  }

  function onPushSubscriptionChange(event) {
    event.waitUntil(refreshSubscription(event));
  }

  // -------------------------------------------------------------------------
  // message: update protocol
  // -------------------------------------------------------------------------

  function workerBuildId() {
    return typeof MBFD_WORKER_BUILD_ID === 'string' ? MBFD_WORKER_BUILD_ID : 'unknown';
  }

  /** Only a window or worker of this Hub origin may drive the update protocol. */
  function isSameOriginSource(event) {
    var source = event && event.source;
    if (!source || typeof source.url !== 'string') {
      return false;
    }
    try {
      return new URL(source.url).origin === origin();
    } catch (_) {
      return false;
    }
  }

  function onMessage(event) {
    if (!event) {
      return;
    }
    // ExtendableMessageEvent.origin is the sending client's origin. A few older engines leave it
    // empty; there the client's own URL decides. Either way the sender must be a Hub client.
    if (event.origin !== scope.location.origin && !(event.origin === '' || event.origin === undefined)) {
      return;
    }
    if (!isSameOriginSource(event)) {
      return;
    }
    var message = event.data;
    if (!isPlainObject(message)) {
      return;
    }

    if (message.type === 'SKIP_WAITING') {
      scope.skipWaiting();

      return;
    }

    if (message.type === 'GET_VERSION') {
      var reply = { type: 'MBFD_VERSION', build: workerBuildId(), core: MBFD_WORKER_CORE_VERSION };
      try {
        if (event.ports && event.ports[0]) {
          event.ports[0].postMessage(reply);
        } else if (event.source && typeof event.source.postMessage === 'function') {
          event.source.postMessage(reply);
        }
      } catch (_) {
        // The asking page went away.
      }
    }
  }

  scope.addEventListener('push', onPush);
  scope.addEventListener('notificationclick', onNotificationClick);
  scope.addEventListener('pushsubscriptionchange', onPushSubscriptionChange);
  scope.addEventListener('message', onMessage);
})(self);
/* MBFD_WORKER_CORE:END */

// Message event - offline submission queue, per-member cache purge.
// SKIP_WAITING and GET_VERSION are answered by the shared core above; they are
// deliberately not handled here, so a single message is never processed twice.
self.addEventListener('message', (event) => {
  let sourceUrl;
  try {
    sourceUrl = new URL(event.source?.url || '');
  } catch {
    return;
  }
  if (sourceUrl.origin !== self.location.origin) return;
  const replyPort = event.ports?.[0];
  const type = event.data?.type;

  if (type === 'PURGE_USER_CACHES') {
    let replied = false;
    const reply = (success) => {
      if (replied) return;
      replied = true;
      try {
        replyPort?.postMessage({ type: 'USER_CACHES_PURGED', success });
      } catch {
        // The asking page went away (for example it was navigating to sign in).
      }
    };
    // Answer as soon as the API caches are gone; the shell refresh may need the network.
    const purge = purgeUserCaches(() => reply(true)).then(() => reply(true), () => reply(false));
    if (typeof event.waitUntil === 'function') event.waitUntil(purge);
    return;
  }

  if (type === 'QUEUE_SUBMISSION') {
    if (!replyPort) return;
    replyPort.postMessage({
      type: 'QUEUE_STORED',
      success: true,
    });
  }

  if (type === 'SYNC_QUEUE') {
    if (!replyPort) return;
    replyPort.postMessage({
      type: 'SYNC_STARTED',
      success: true,
    });
  }
});
