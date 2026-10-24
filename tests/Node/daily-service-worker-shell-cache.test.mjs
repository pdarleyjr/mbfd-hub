import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vm from 'node:vm';

const ORIGIN = 'https://hub.test';
const source = readFileSync(resolve(process.cwd(), 'resources/js/daily-checkout/public/service-worker.js'), 'utf8');
const CACHE = /const CACHE_NAME = '([^']+)'/.exec(source)[1];
const API_CACHE = /const API_CACHE_NAME = '([^']+)'/.exec(source)[1];
const DAILY_SHELL = '<!doctype html><html><head><title>Daily Checkout | MBFD Hub</title></head><body><div id="root"></div></body></html>';
const LOGIN_PAGE = '<!doctype html><html><head><title>Sign in | MBFD Hub</title></head><body><form action="/login"></form></body></html>';

const fakeResponse = ({ body = '', status = 200, url, redirected = false, type = 'basic', contentType = 'text/html; charset=UTF-8', contentLength }) => ({
  ok: status >= 200 && status < 300,
  status,
  statusText: 'OK',
  url,
  redirected,
  type,
  headers: new Headers({ 'Content-Type': contentType, ...(contentLength === undefined ? {} : { 'Content-Length': String(contentLength) }) }),
  text: async () => body,
  json: async () => JSON.parse(body),
  blob: async () => new Blob([body]),
  clone() { return fakeResponse({ body, status, url, redirected, type, contentType, contentLength }); },
});

// `assets` stands in for the build's asset list (the template ships an empty one); `stores` lets
// several worker generations share one Cache Storage, like successive releases on one device.
function loadWorker(networkFor, { failCachePut = false, assets, stores = new Map() } = {}) {
  // The Daily template inlines the shared core, so several listeners can be registered for one
  // event type (for example "message"). A dispatcher calls every one of them, like the browser.
  const registered = {};
  const listeners = new Proxy({}, {
    get: (_target, type) => (registered[type] ? (event) => registered[type].map((listener) => listener(event))[0] : undefined),
  });
  const calls = { skipWaiting: 0, shown: [] };
  const keyOf = (request) => new URL(typeof request === 'string' ? request : request.url, ORIGIN).pathname;
  // Every operation looks the store up by name, so a late write after the cache was deleted
  // re-creates it. That is the worst case a real browser could exhibit, and the one the
  // worker's purge epoch exists to prevent.
  const storeFor = (name) => {
    if (!stores.has(name)) stores.set(name, new Map());
    return stores.get(name);
  };
  const open = async (name) => {
    storeFor(name);
    return {
      put: async (request, response) => {
        if (failCachePut && name === CACHE && !keyOf(request).startsWith('/daily/')) throw new Error('quota exceeded');
        storeFor(name).set(keyOf(request), response);
      },
      match: async (request) => storeFor(name).get(keyOf(request)),
      delete: async (request) => storeFor(name).delete(keyOf(request)),
      keys: async () => [...storeFor(name).keys()].map((path) => ({ url: `${ORIGIN}${path}` })),
      addAll: async (keys) => { for (const key of keys) storeFor(name).set(keyOf(key), fakeResponse({ url: `${ORIGIN}${key}` })); },
    };
  };
  const sandbox = {
    self: {
      location: { origin: ORIGIN },
      addEventListener: (type, listener) => { (registered[type] ??= []).push(listener); },
      skipWaiting: () => { calls.skipWaiting += 1; },
      clients: { claim: async () => undefined },
      registration: { showNotification: async (title, options) => { calls.shown.push({ title, options }); } },
    },
    caches: {
      open,
      keys: async () => [...stores.keys()],
      delete: async (name) => stores.delete(name),
      match: async (request) => {
        for (const store of stores.values()) if (store.has(keyOf(request))) return store.get(keyOf(request));
        return undefined;
      },
    },
    fetch: async (request, init) => networkFor(typeof request === 'string' ? new URL(request, ORIGIN).toString() : request.url, init),
    Blob,
    URL,
    Headers,
    Response,
    // Node's Request wants absolute URLs; a worker resolves relative ones against its own origin.
    Request: class extends Request {
      constructor(input, init) {
        super(typeof input === 'string' ? new URL(input, ORIGIN).toString() : input, init);
      }
    },
    console,
    MessageChannel,
    setTimeout,
    clearTimeout,
    decodeURIComponent,
  };
  vm.runInNewContext(assets === undefined ? source : source.replace('/* DAILY_BUILD_ASSETS */ []', JSON.stringify(assets)), sandbox);

  return { listeners, registered, calls, stores, caches: sandbox.caches, cacheName: CACHE };
}

const activate = async (worker) => {
  let pending;
  worker.listeners.activate({ waitUntil: (promise) => { pending = promise; } });
  await pending;
};

const seedPreviousCache = async (worker, shellBody) => {
  const previous = await worker.caches.open('mbfd-checkout-v8');
  await previous.put('/daily/index.html', fakeResponse({ body: shellBody, url: `${ORIGIN}/daily/` }));
  await previous.put('/daily/assets/index-old.js', fakeResponse({ body: 'old bundle', url: `${ORIGIN}/daily/assets/index-old.js`, contentType: 'text/javascript' }));
};

const install = async (worker) => {
  let pending;
  worker.listeners.install({ waitUntil: (promise) => { pending = promise; } });
  await pending;
};

const navigate = async (worker, path) => {
  let pending;
  const waiting = [];
  worker.listeners.fetch({
    request: { method: 'GET', mode: 'navigate', url: `${ORIGIN}${path}` },
    respondWith: (promise) => { pending = promise; },
    waitUntil: (promise) => { waiting.push(promise); },
  });
  const response = await pending;
  await Promise.all(waiting);
  return response;
};

const cachedShell = async (worker) => {
  const response = worker.stores.get(worker.cacheName)?.get('/daily/index.html');
  return response ? response.text() : null;
};

test('the shell cache version is bumped past the releases that stored a sign-in page, truncated images and member API data', () => {
  assert.equal(CACHE, 'mbfd-checkout-v12');
  assert.equal(API_CACHE, 'mbfd-api-cache-v8');
});

test('install does not store a redirected sign-in page as the Daily shell', async () => {
  const worker = loadWorker((url) => (url.includes('/daily/')
    ? fakeResponse({ body: LOGIN_PAGE, url: `${ORIGIN}/login`, redirected: true })
    : fakeResponse({ url })));
  await install(worker);

  assert.equal(await cachedShell(worker), null);
  assert.equal(worker.stores.get(worker.cacheName).has('/daily/'), false);
});

test('install stores a genuine Daily shell', async () => {
  const worker = loadWorker((url) => fakeResponse({ body: DAILY_SHELL, url }));
  await install(worker);

  assert.equal(await cachedShell(worker), DAILY_SHELL);
});

test('a navigation answered by a redirect or a non-Daily page never replaces the cached shell', async () => {
  let network = (url) => fakeResponse({ body: DAILY_SHELL, url });
  const worker = loadWorker((url) => network(url));
  await install(worker);

  network = () => fakeResponse({ body: LOGIN_PAGE, url: `${ORIGIN}/login`, redirected: true });
  await navigate(worker, '/daily/stations');
  assert.equal(await cachedShell(worker), DAILY_SHELL);

  network = (url) => fakeResponse({ body: LOGIN_PAGE, url });
  await navigate(worker, '/daily/stations/1');
  assert.equal(await cachedShell(worker), DAILY_SHELL, 'a 200 page without the Daily mount point is not the shell');

  network = (url) => fakeResponse({ body: DAILY_SHELL, url, contentType: 'application/json' });
  await navigate(worker, '/daily/stations/2');
  assert.equal(await cachedShell(worker), DAILY_SHELL);
});

test('a genuine Daily navigation refreshes the cached shell and offline navigation serves it', async () => {
  const updatedShell = DAILY_SHELL.replace('Daily Checkout', 'Daily Checkout v2');
  let network = (url) => fakeResponse({ body: DAILY_SHELL, url });
  const worker = loadWorker((url) => network(url));
  await install(worker);

  network = (url) => fakeResponse({ body: updatedShell, url });
  await navigate(worker, '/daily/vehicle-inspections');
  assert.equal(await cachedShell(worker), updatedShell);

  network = () => { throw new TypeError('offline'); };
  const offline = await navigate(worker, '/daily/vehicle-inspections/engine-1');
  assert.equal(await offline.text(), updatedShell);
});

test('activation after a signed-out install keeps a genuine older shell and its assets for offline launch', async () => {
  const worker = loadWorker((url) => fakeResponse({ body: LOGIN_PAGE, url: `${ORIGIN}/login`, redirected: true }));
  await seedPreviousCache(worker, DAILY_SHELL);
  await install(worker);
  assert.equal(await cachedShell(worker), null);

  await activate(worker);

  assert.equal(await cachedShell(worker), DAILY_SHELL);
  assert.equal(worker.stores.get(worker.cacheName).has('/daily/assets/index-old.js'), true);
  assert.equal(worker.stores.has('mbfd-checkout-v8'), false);
});

test('activation never carries a poisoned older shell forward', async () => {
  const worker = loadWorker((url) => fakeResponse({ body: LOGIN_PAGE, url: `${ORIGIN}/login`, redirected: true }));
  await seedPreviousCache(worker, LOGIN_PAGE);
  await install(worker);

  await activate(worker);

  assert.equal(await cachedShell(worker), null);
  assert.equal(worker.stores.has('mbfd-checkout-v8'), false);
});

test('activation keeps the new shell when the install stored one, and still carries the previous release assets (M3)', async () => {
  const worker = loadWorker((url) => fakeResponse({ body: DAILY_SHELL.replace('Daily Checkout', 'Daily Checkout v9'), url }));
  await seedPreviousCache(worker, DAILY_SHELL);
  await install(worker);

  await activate(worker);

  assert.match(await cachedShell(worker), /Daily Checkout v9/, 'the new shell is not replaced by the old one');
  assert.equal(
    worker.stores.get(worker.cacheName).has('/daily/assets/index-old.js'),
    true,
    'a page still running the previous JavaScript can lazy-load its chunks after the old cache is deleted',
  );
  assert.equal(worker.stores.has('mbfd-checkout-v8'), false);
});

// ---- Static image / asset caching (production bug: truncated station-1.png served forever) ----

const GOOD_IMAGE = 'PNG-FULL-BODY-0123456789';
const IMAGE = '/images/stations/station-1.png';

const staticFetch = async (worker, path, { range = false } = {}) => {
  let responded;
  const waiting = [];
  worker.listeners.fetch({
    request: { method: 'GET', mode: 'no-cors', url: `${ORIGIN}${path}`, headers: new Headers(range ? { Range: 'bytes=0-9' } : {}) },
    respondWith: (promise) => { responded = promise; },
    waitUntil: (promise) => { waiting.push(promise); },
  });
  const response = responded ? await responded : undefined;
  await Promise.all(waiting);
  return response;
};

const cachedBody = async (worker, path) => {
  const entry = worker.stores.get(worker.cacheName)?.get(path);
  return entry ? entry.clone().text() : null;
};

test('activating the new worker deletes the old cache that may hold a corrupt station image', async () => {
  const worker = loadWorker((url) => fakeResponse({ body: DAILY_SHELL, url }));
  const v9 = await worker.caches.open('mbfd-checkout-v9');
  await v9.put(IMAGE, fakeResponse({ body: 'PNG-TRUNC', url: `${ORIGIN}${IMAGE}`, contentType: 'image/png' }));
  await install(worker);

  await activate(worker);

  assert.equal(worker.stores.has('mbfd-checkout-v9'), false);
  assert.equal(await cachedBody(worker, IMAGE), null, 'the corrupt image is not carried over');
});

test('images are network-first and revalidated: a corrupt cached copy is replaced when the network answers', async () => {
  const seen = [];
  const worker = loadWorker((url, init) => {
    seen.push({ url, cache: init?.cache });
    return fakeResponse({ body: GOOD_IMAGE, url, contentType: 'image/png', contentLength: GOOD_IMAGE.length });
  });
  await install(worker);
  await (await worker.caches.open(worker.cacheName)).put(IMAGE, fakeResponse({ body: 'PNG-TRUNC', url: `${ORIGIN}${IMAGE}`, contentType: 'image/png' }));

  const response = await staticFetch(worker, IMAGE);

  assert.equal(await response.text(), GOOD_IMAGE, 'the page gets the network copy, not the stored one');
  assert.deepEqual(seen.filter((entry) => entry.url.endsWith(IMAGE)), [{ url: `${ORIGIN}${IMAGE}`, cache: 'no-cache' }]);
  assert.equal(await cachedBody(worker, IMAGE), GOOD_IMAGE, 'the corrupt entry was overwritten with the verified body');
});

test('an image body shorter than its declared Content-Length is returned but never stored', async () => {
  let network = (url) => fakeResponse({ body: GOOD_IMAGE, url, contentType: 'image/png', contentLength: GOOD_IMAGE.length });
  const worker = loadWorker((url) => network(url));
  await install(worker);
  await staticFetch(worker, IMAGE);
  assert.equal(await cachedBody(worker, IMAGE), GOOD_IMAGE);

  network = (url) => fakeResponse({ body: 'PNG-TRUNC', url, contentType: 'image/png', contentLength: GOOD_IMAGE.length });
  const response = await staticFetch(worker, IMAGE);

  assert.equal(await response.text(), 'PNG-TRUNC');
  assert.equal(await cachedBody(worker, IMAGE), GOOD_IMAGE, 'a truncated body does not replace a good stored copy');
});

test('partial, redirected and failed image responses are not stored', async () => {
  for (const overrides of [{ status: 206 }, { redirected: true }, { status: 404 }, { type: 'opaque' }]) {
    const worker = loadWorker((url) => fakeResponse({ body: GOOD_IMAGE, url, contentType: 'image/png', ...overrides }));
    await install(worker);

    await staticFetch(worker, IMAGE);

    assert.equal(await cachedBody(worker, IMAGE), null, JSON.stringify(overrides));
  }
});

test('the stored image is only an offline fallback; with neither network nor copy the answer is 503', async () => {
  let network = (url) => fakeResponse({ body: GOOD_IMAGE, url, contentType: 'image/png', contentLength: GOOD_IMAGE.length });
  const worker = loadWorker((url) => network(url));
  await install(worker);
  await staticFetch(worker, IMAGE);

  network = () => { throw new TypeError('offline'); };
  assert.equal(await (await staticFetch(worker, IMAGE)).text(), GOOD_IMAGE);
  assert.equal((await staticFetch(worker, '/images/stations/station-9.png')).status, 503);
});

test('a failing cache write never breaks the image response', async () => {
  const worker = loadWorker((url) => fakeResponse({ body: GOOD_IMAGE, url, contentType: 'image/png', contentLength: GOOD_IMAGE.length }), { failCachePut: true });
  await install(worker);

  const response = await staticFetch(worker, IMAGE);

  assert.equal(await response.text(), GOOD_IMAGE);
});

test('hashed build assets and the precache list stay cache-first', async () => {
  let calls = 0;
  const worker = loadWorker((url) => { calls += 1; return fakeResponse({ body: 'network', url, contentType: 'text/javascript' }); });
  const cache = await worker.caches.open(worker.cacheName);
  await cache.put('/daily/assets/index-abc.js', fakeResponse({ body: 'cached', url: `${ORIGIN}/daily/assets/index-abc.js`, contentType: 'text/javascript' }));
  await cache.put('/manifest.json', fakeResponse({ body: 'cached-manifest', url: `${ORIGIN}/manifest.json`, contentType: 'application/json' }));

  assert.equal(await (await staticFetch(worker, '/daily/assets/index-abc.js')).text(), 'cached');
  assert.equal(await (await staticFetch(worker, '/manifest.json')).text(), 'cached-manifest');
  assert.equal(calls, 0);
});

test('byte-range and cross-origin requests are left to the browser', async () => {
  const worker = loadWorker((url) => fakeResponse({ body: GOOD_IMAGE, url }));
  assert.equal(await staticFetch(worker, IMAGE, { range: true }), undefined);

  let responded = false;
  worker.listeners.fetch({
    request: { method: 'GET', mode: 'no-cors', url: 'https://cdn.example/font.woff2', headers: new Headers() },
    respondWith: () => { responded = true; },
  });
  assert.equal(responded, false);
});

// ---- Phase 2: shared core, per-member cache purge, API allowlist ----

const send = async (worker, data, { sourceUrl = `${ORIGIN}/daily/`, port = true } = {}) => {
  const replies = [];
  const waiting = [];
  const ports = port ? [{ postMessage: (message) => replies.push(JSON.parse(JSON.stringify(message))) }] : [];
  worker.listeners.message({ data, source: sourceUrl === null ? undefined : { url: sourceUrl }, ports, waitUntil: (promise) => waiting.push(promise) });
  await Promise.all(waiting);
  return replies;
};

const PURGE = { type: 'PURGE_USER_CACHES' };
const json = (url, body = { ok: true }) => fakeResponse({ body: JSON.stringify(body), url, contentType: 'application/json' });
const memberShell = (name) => DAILY_SHELL.replace('Daily Checkout', `Daily Checkout ${name}`);

const apiGet = (worker, path) => {
  let responded;
  worker.listeners.fetch({
    request: { method: 'GET', mode: 'cors', url: `${ORIGIN}${path}`, headers: new Headers() },
    respondWith: (promise) => { responded = promise; },
    waitUntil: () => undefined,
  });
  return responded;
};

const offline = () => { throw new TypeError('offline'); };
const flushMicrotasks = () => new Promise((resolve) => setTimeout(resolve, 10));
const storedPaths = (worker, name) => [...(worker.stores.get(name)?.keys() ?? [])];

test('the shared worker core is inlined: push, click, subscription refresh and the update protocol are answered by it', async () => {
  const worker = loadWorker((url) => fakeResponse({ body: DAILY_SHELL, url }));

  assert.deepEqual(
    Object.keys(worker.registered).sort(),
    ['activate', 'fetch', 'install', 'message', 'notificationclick', 'push', 'pushsubscriptionchange'],
  );
  assert.match(source, /\/\* MBFD_WORKER_CORE:START \*\//);
  assert.doesNotMatch(source, /client\.navigate\(/, 'the old click handler navigated windows; the core never does');
  assert.doesNotMatch(source, /permission === 'granted'/, 'the old push handler went silent without permission');

  // Every push ends in a visible notification, even an empty one (iOS revokes silent pushes).
  const waiting = [];
  worker.listeners.push({ data: null, waitUntil: (promise) => waiting.push(promise) });
  await Promise.all(waiting);
  assert.equal(worker.calls.shown.length, 1);
  assert.equal(worker.calls.shown[0].title, 'MBFD Hub Update');
});

test('GET_VERSION is answered by the core with the Daily build id, and SKIP_WAITING is processed exactly once', async () => {
  const worker = loadWorker((url) => fakeResponse({ body: DAILY_SHELL, url }));

  const replies = await send(worker, { type: 'GET_VERSION' });
  assert.deepEqual(replies, [{ type: 'MBFD_VERSION', build: CACHE, core: /const MBFD_WORKER_CORE_VERSION = '([^']+)'/.exec(source)[1] }]);

  const before = worker.calls.skipWaiting;
  await send(worker, { type: 'SKIP_WAITING' });
  assert.equal(worker.calls.skipWaiting - before, 1, 'the core and the Daily handler must not both act on one message');
});

test('the offline queue messages are still answered, and messages from other origins or without a port are ignored', async () => {
  const worker = loadWorker((url) => fakeResponse({ body: DAILY_SHELL, url }));

  assert.deepEqual(await send(worker, { type: 'QUEUE_SUBMISSION' }), [{ type: 'QUEUE_STORED', success: true }]);
  assert.deepEqual(await send(worker, { type: 'SYNC_QUEUE' }), [{ type: 'SYNC_STARTED', success: true }]);
  assert.deepEqual(await send(worker, { type: 'QUEUE_SUBMISSION' }, { sourceUrl: 'https://evil.example/daily/' }), []);
  assert.deepEqual(await send(worker, { type: 'QUEUE_SUBMISSION' }, { sourceUrl: null }), []);
  assert.deepEqual(await send(worker, { type: 'SYNC_QUEUE' }, { port: false }), []);
  assert.deepEqual(await send(worker, { type: 'UNKNOWN' }), []);
  assert.deepEqual(await send(worker, 'not an object'), []);
});

test('PURGE_USER_CACHES removes every API cache and the member shell, keeps public assets, and re-stores the shell for whoever is signed in', async () => {
  let network = (url) => fakeResponse({ body: memberShell('A'), url });
  const worker = loadWorker((url, init) => network(url, init));
  await install(worker);
  assert.match(await cachedShell(worker), /Daily Checkout A/);
  await (await worker.caches.open(API_CACHE)).put('/api/public/apparatuses', json(`${ORIGIN}/api/public/apparatuses`, { member: 'A' }));
  await (await worker.caches.open('mbfd-api-cache-v6')).put('/api/public/stations', json(`${ORIGIN}/api/public/stations`, { member: 'A' }));
  await (await worker.caches.open(worker.cacheName)).put('/daily/assets/index-abc.js', fakeResponse({ body: 'bundle', url: `${ORIGIN}/daily/assets/index-abc.js`, contentType: 'text/javascript' }));

  network = (url) => fakeResponse({ body: memberShell('B'), url });
  const replies = await send(worker, PURGE);

  assert.deepEqual(replies, [{ type: 'USER_CACHES_PURGED', success: true }]);
  assert.equal(worker.stores.has(API_CACHE), false);
  assert.equal(worker.stores.has('mbfd-api-cache-v6'), false);
  assert.equal(storedPaths(worker, worker.cacheName).includes('/daily/assets/index-abc.js'), true, 'public build assets are not member data');
  assert.match(await cachedShell(worker), /Daily Checkout B/);
  assert.doesNotMatch(await cachedShell(worker), /Daily Checkout A/);
});

test('after a purge on a signed-out device no shell is stored (the sign-in redirect is never kept)', async () => {
  let network = (url) => fakeResponse({ body: memberShell('A'), url });
  const worker = loadWorker((url) => network(url));
  await install(worker);

  network = () => fakeResponse({ body: LOGIN_PAGE, url: `${ORIGIN}/login`, redirected: true });
  assert.deepEqual(await send(worker, PURGE), [{ type: 'USER_CACHES_PURGED', success: true }]);

  assert.equal(await cachedShell(worker), null);
  assert.equal(worker.stores.get(worker.cacheName).has('/daily/'), false);
});

test('a purge reports failure, instead of throwing, if Cache Storage itself fails', async () => {
  const worker = loadWorker((url) => fakeResponse({ body: memberShell('A'), url }));
  await install(worker);

  worker.caches.keys = async () => { throw new Error('storage unavailable'); };
  assert.deepEqual(await send(worker, PURGE), [{ type: 'USER_CACHES_PURGED', success: false }]);
});

test('a purge request from another origin is ignored', async () => {
  const worker = loadWorker((url) => fakeResponse({ body: memberShell('A'), url }));
  await install(worker);
  await (await worker.caches.open(API_CACHE)).put('/api/public/stations', json(`${ORIGIN}/api/public/stations`));

  assert.deepEqual(await send(worker, PURGE, { sourceUrl: 'https://evil.example/daily/' }), []);
  assert.equal(worker.stores.has(API_CACHE), true);
});

test('only allowlisted read models are cached, and they are served offline; everything else under /api/ reaches the network every time', async () => {
  const seen = [];
  let network = (url, init) => { seen.push({ url, cache: init?.cache }); return json(url, { url }); };
  const worker = loadWorker((url, init) => network(url, init));
  const allowed = [
    '/api/public/apparatuses',
    '/api/public/apparatuses/7/checklist',
    '/api/public/stations',
    '/api/public/stations/3',
    '/api/public/stations/3/rooms',
    '/api/public/stations/3/rooms/9/assets',
    '/api/public/stations/3/apparatus',
    // M2: every station-wide read the Daily screens make, as the baseline (fc35bb83) cached them.
    '/api/public/stations/3/rooms/9/profile',
    '/api/public/stations/3/inspections',
    '/api/public/stations/3/gas-meters',
    '/api/public/stations/3/equipment-requests',
    '/api/public/stations/3/requests',
    '/api/public/stations/3/activity',
  ];
  const denied = [
    '/api/public/stations/3/apparatus-inspections',
    '/api/public/stations/3/projects',
    '/api/public/stations/3/service-tickets',
    '/api/public/apparatuses/7/service-notices',
    '/api/public/apparatuses/7/inspections',
    '/api/public/fleet-vehicles',
    '/api/public/inspection-revisions',
    '/api/me/context',
  ];

  for (const path of [...allowed, ...denied]) {
    const response = await apiGet(worker, path);
    assert.equal(response.status, 200, path);
  }
  assert.deepEqual(storedPaths(worker, API_CACHE).sort(), [...allowed].sort());
  assert.ok(seen.every((entry) => entry.cache === 'no-store'), 'API reads never come from the HTTP cache');
  assert.equal([...worker.stores.values()].some((store) => [...store.keys()].some((key) => denied.includes(key))), false);

  network = offline;
  for (const path of allowed) assert.equal((await apiGet(worker, path)).status, 200, `${path} stays available offline`);
  for (const path of denied) await assert.rejects(apiGet(worker, path), /offline/, `${path} must not be answered from a stored copy`);
});

test('an uncached read model with no network answers a JSON 503 instead of failing the page', async () => {
  const worker = loadWorker(offline);
  const response = await apiGet(worker, '/api/public/stations');

  assert.equal(response.status, 503);
  assert.equal(response.headers.get('Content-Type'), 'application/json');
});

test('an error or redirect answer is returned but never stored as a read model', async () => {
  for (const overrides of [{ status: 500 }, { status: 401 }, { redirected: true }]) {
    const worker = loadWorker((url) => fakeResponse({ body: '{}', url, contentType: 'application/json', ...overrides }));
    await apiGet(worker, '/api/public/stations');
    assert.deepEqual(storedPaths(worker, API_CACHE), [], JSON.stringify(overrides));
  }
});

test('a read model answered after a purge is not stored (it may belong to the previous member)', async () => {
  let release;
  const gate = new Promise((resolveGate) => { release = resolveGate; });
  let apiCalls = 0;
  const worker = loadWorker(async (url) => {
    if (!url.includes('/api/')) return fakeResponse({ body: memberShell('B'), url });
    apiCalls += 1;
    if (apiCalls > 1) return json(url, { member: 'B' }); // the new member's own re-warm
    await gate;
    return json(url, { member: 'A' });
  });

  const inFlight = apiGet(worker, '/api/public/stations');
  await send(worker, PURGE);
  release();
  const response = await inFlight;

  assert.equal(response.status, 200, 'the page still gets its answer');
  assert.equal(await (await response.clone().json()).member, 'A');
  for (const [, stored] of worker.stores.get(API_CACHE) ?? []) {
    assert.equal((await stored.clone().json()).member, 'B', 'only the new member responses are stored');
  }
});

test('the release probe is never intercepted, so a stale answer can never hide a new release', async () => {
  const worker = loadWorker((url) => json(url, { release: 'abcdef012345' }));
  assert.equal(apiGet(worker, '/app-release'), undefined);
  assert.equal([...worker.stores.values()].some((store) => store.has('/app-release')), false);
});

test('activating the new worker deletes the previous shell cache and API cache, which may hold other members data', async () => {
  const worker = loadWorker((url) => fakeResponse({ body: memberShell('B'), url }));
  const previous = await worker.caches.open('mbfd-checkout-v11');
  await previous.put('/daily/index.html', fakeResponse({ body: memberShell('A'), url: `${ORIGIN}/daily/` }));
  await previous.put('/app-release', json(`${ORIGIN}/app-release`, { release: 'old' }));
  await (await worker.caches.open('mbfd-api-cache-v7')).put('/api/public/stations/3/requests', json(`${ORIGIN}/api/public/stations/3/requests`, { member: 'A' }));
  await install(worker);

  await activate(worker);

  assert.deepEqual([...worker.stores.keys()], [worker.cacheName]);
  assert.match(await cachedShell(worker), /Daily Checkout B/);
});

test('the worker never touches IndexedDB or unregisters anything: offline drafts and queued work are not its to delete', () => {
  const code = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

  assert.doesNotMatch(code, /indexedDB|IDBFactory|deleteDatabase|openDatabase|\bDexie\b|mbfd-daily-checkout|mbfd-operational-forms/i);
  assert.doesNotMatch(code, /\.unregister\s*\(|getRegistrations/);
  assert.doesNotMatch(code, /localStorage|sessionStorage/);
  // Cache deletion is limited to names chosen by the activate and purge filters.
  for (const match of code.matchAll(/caches\.delete\(([^)]*)\)/g)) {
    assert.match(match[1], /^name$/);
  }
  assert.doesNotMatch(code, /\?v=/);
});

test('the Daily worker keeps its identity: skipWaiting on install, claim on activate', async () => {
  const worker = loadWorker((url) => fakeResponse({ body: DAILY_SHELL, url }));
  const before = worker.calls.skipWaiting;
  await install(worker);
  assert.equal(worker.calls.skipWaiting - before, 1);
  assert.match(source, /self\.clients\.claim\(\)/);
});

test('a Daily navigation answered with a redirect (signed out) purges the previous member API cache and shell, and a genuine shell does not', async () => {
  let network = (url) => fakeResponse({ body: memberShell('A'), url });
  const worker = loadWorker((url) => network(url));
  await install(worker);
  await (await worker.caches.open(API_CACHE)).put('/api/public/apparatuses', json(`${ORIGIN}/api/public/apparatuses`, { member: 'A' }));

  // Signed in: nothing is purged.
  await navigate(worker, '/daily/stations');
  assert.equal(worker.stores.get(API_CACHE).has('/api/public/apparatuses'), true);

  // A redirect for something that is not a Daily page does not matter to this worker (it never sees it).
  // A redirected Daily navigation (the server sent the member to sign in) purges.
  // The navigation itself is answered with an opaque redirect (the browser follows it to sign-in); the
  // worker's own re-fetch of the shell follows the redirect and sees the sign-in page.
  let calls = 0;
  network = (url) => (calls++ === 0
    ? fakeResponse({ body: '', status: 0, url, type: 'opaqueredirect' })
    : fakeResponse({ body: LOGIN_PAGE, url: `${ORIGIN}/login`, redirected: true }));
  await navigate(worker, '/daily/stations');
  assert.equal(worker.stores.has(API_CACHE), false);
  assert.equal(await cachedShell(worker), null, 'the signed-out device keeps no member shell');
});

test('a purge never deletes the stored shell without a replacement or proof the session is gone', async () => {
  let network = (url) => fakeResponse({ body: memberShell('A'), url });
  const worker = loadWorker((url) => network(url));
  await install(worker);

  // Offline or a flaky network: the stored shell is still the best offline launch.
  network = offline;
  assert.deepEqual(await send(worker, PURGE), [{ type: 'USER_CACHES_PURGED', success: true }]);
  assert.match(await cachedShell(worker), /Daily Checkout A/);

  // A server error says nothing about the session.
  network = (url) => fakeResponse({ body: 'oops', status: 503, url, contentType: 'text/plain' });
  await send(worker, PURGE);
  assert.match(await cachedShell(worker), /Daily Checkout A/);

  // 401 and a redirect to sign-in do: the next person must not get this member shell.
  network = (url) => fakeResponse({ body: '{}', status: 401, url, contentType: 'application/json' });
  await send(worker, PURGE);
  assert.equal(await cachedShell(worker), null);
});

test('the purge answers as soon as the API caches are gone, before the shell refresh finishes', async () => {
  let release;
  const gate = new Promise((resolveGate) => { release = resolveGate; });
  let network = (url) => fakeResponse({ body: memberShell('A'), url });
  const worker = loadWorker(async (url) => network(url));
  await install(worker);
  await (await worker.caches.open(API_CACHE)).put('/api/public/stations', json(`${ORIGIN}/api/public/stations`));

  network = async (url) => { await gate; return fakeResponse({ body: memberShell('B'), url }); };
  const replies = [];
  const waiting = [];
  worker.listeners.message({
    data: PURGE,
    source: { url: `${ORIGIN}/daily/` },
    ports: [{ postMessage: (message) => replies.push(JSON.parse(JSON.stringify(message))) }],
    waitUntil: (promise) => waiting.push(promise),
  });
  await flushMicrotasks();

  assert.deepEqual(replies, [{ type: 'USER_CACHES_PURGED', success: true }], 'answered while the refresh is still waiting for the network');
  assert.equal(worker.stores.has(API_CACHE), false);
  release();
  await Promise.all(waiting);
  assert.deepEqual(replies.length, 1, 'one answer only');
  assert.match(await cachedShell(worker), /Daily Checkout B/);
});

test('a shell that arrives after a purge is not stored (it belongs to the previous member)', async () => {
  let release;
  const gate = new Promise((resolveGate) => { release = resolveGate; });
  let gated = false;
  const worker = loadWorker(async (url) => {
    const body = memberShell(gated ? 'A-late' : 'B'); // decided when the request starts
    if (gated && url.includes('/daily/stations')) await gate;
    return fakeResponse({ body, url });
  });
  await install(worker);

  gated = true;
  const pending = navigate(worker, '/daily/stations');
  await flushMicrotasks();
  gated = false;
  await send(worker, PURGE);
  release();
  await pending;

  assert.doesNotMatch(await cachedShell(worker), /A-late/);
});

// ---- Review follow-ups: M1 re-warm, M2 offline coverage, M3 resident pages, L1 shell, L2 sign-out proof ----

const apiSource = readFileSync(resolve(process.cwd(), 'resources/js/daily-checkout/src/utils/api.ts'), 'utf8');
const RENDERED_SHELL = DAILY_SHELL.replace('<head>', '<head><meta name="mbfd-release" content="abcdef012345">');
const RAW_SHELL = DAILY_SHELL; // what the web server hands out for the real file /daily/index.html
const ASSET = (name) => `/daily/assets/${name}.js`;
const assetResponse = (name) => fakeResponse({ body: `bundle ${name}`, url: `${ORIGIN}${ASSET(name)}`, contentType: 'text/javascript' });

test('every station and apparatus read the Daily app makes is either served offline or deliberately live (audit of utils/api.ts)', async () => {
  const live = [/\/service-tickets$/, /\/service-notices$/];
  // apparatuses/{id}/{endpoint} is the checklist GET plus the inspection-session POSTs; the GET is checked explicitly below.
  const writes =[/^apparatuses\/1\/inspections$/, /^apparatuses\/1\/1$/];
  const paths = new Set();
  for (const match of apiSource.matchAll(/`\$\{API_BASE\}\/public\/((?:stations|apparatuses)[^`]*)`/g)) {
    paths.add(match[1].replace(/\$\{[^}]+\}/g, '1').replace(/\?.*$/, ''));
  }
  assert.ok(paths.size >= 12, `the audit found only ${paths.size} paths: ${[...paths].join(', ')}`);
  paths.add('apparatuses/1/checklist');

  let network = (url) => json(url, { url });
  const worker = loadWorker((url) => network(url));
  const offlineChecked = [];
  for (const path of paths) {
    if (live.some((pattern) => pattern.test(path)) || writes.some((pattern) => pattern.test(path))) continue;
    const full = `/api/public/${path}`;
    assert.equal((await apiGet(worker, full)).status, 200, full);
    offlineChecked.push(full);
  }
  network = offline;
  for (const full of offlineChecked) {
    assert.equal((await apiGet(worker, full)).status, 200, `${full} must still work offline (it did in the baseline)`);
  }
  assert.ok(offlineChecked.includes('/api/public/stations/1/gas-meters'));
  assert.ok(offlineChecked.includes('/api/public/stations/1/rooms/1/profile'));
  assert.ok(offlineChecked.includes('/api/public/stations/1/inspections'));
});

test('query strings do not defeat the allowlist: a paginated request list is served offline', async () => {
  let network = (url) => json(url, { data: [] });
  const worker = loadWorker((url) => network(url));
  const path = '/api/public/stations/3/requests?scope=all&per_page=200&page=1';
  assert.equal((await apiGet(worker, path)).status, 200);

  network = offline;
  assert.equal((await apiGet(worker, path)).status, 200);
});

test('install fetches and stores the Laravel-rendered shell, never the raw static /daily/index.html (L1)', async () => {
  const fetched = [];
  const worker = loadWorker((url) => {
    fetched.push(new URL(url).pathname);
    return fakeResponse({ body: new URL(url).pathname === '/daily/index.html' ? RAW_SHELL : RENDERED_SHELL, url });
  });
  await install(worker);

  assert.equal(fetched.includes('/daily/index.html'), false, 'the raw file carries no release meta tag');
  assert.match(await cachedShell(worker), /name="mbfd-release" content="abcdef012345"/);
  const rootKey = worker.stores.get(worker.cacheName).get('/daily/');
  assert.match(await rootKey.text(), /name="mbfd-release"/, 'stored under both keys');
});

test('the shell refresh after a purge also fetches /daily/ only and keeps the release meta (L1)', async () => {
  const fetched = [];
  let body = RENDERED_SHELL;
  const worker = loadWorker((url) => {
    fetched.push(new URL(url).pathname);
    return fakeResponse({ body: new URL(url).pathname === '/daily/index.html' ? RAW_SHELL : body, url });
  });
  await install(worker);
  fetched.length = 0;
  body = RENDERED_SHELL.replace('abcdef012345', '012345abcdef');

  await send(worker, PURGE);

  assert.deepEqual(fetched.filter((path) => path.startsWith('/daily')), ['/daily/']);
  assert.match(await cachedShell(worker), /content="012345abcdef"/);
});

test('a Daily navigation redirected anywhere but sign-in keeps the caches and the shell (L2)', async () => {
  const redirectedElsewhere = [
    ['forced password change', () => fakeResponse({ body: '<html><body>Set a password</body></html>', url: `${ORIGIN}/employee/set-password`, redirected: true })],
    ['city e-mail review', () => fakeResponse({ body: '<html><body>Review</body></html>', url: `${ORIGIN}/member/city-email`, redirected: true })],
    ['canonical host (cross-origin, unreadable)', () => { throw new TypeError('Failed to fetch'); }],
    ['an edge 403', (url) => fakeResponse({ body: 'Forbidden', status: 403, url, contentType: 'text/plain' })],
    ['a deploy 502', (url) => fakeResponse({ body: 'Bad gateway', status: 502, url, contentType: 'text/plain' })],
  ];

  for (const [label, answer] of redirectedElsewhere) {
    let network = (url) => fakeResponse({ body: memberShell('A'), url });
    const worker = loadWorker((url) => network(url));
    await install(worker);
    await (await worker.caches.open(API_CACHE)).put('/api/public/apparatuses', json(`${ORIGIN}/api/public/apparatuses`, { member: 'A' }));

    let calls = 0;
    network = (url) => (calls++ === 0 ? fakeResponse({ body: '', status: 0, url, type: 'opaqueredirect' }) : answer(url));
    await navigate(worker, '/daily/stations');

    assert.equal(worker.stores.get(API_CACHE).has('/api/public/apparatuses'), true, `${label}: the API cache stays`);
    assert.match(await cachedShell(worker), /Daily Checkout A/, `${label}: the shell stays`);
  }
});

test('a redirect that ends on the sign-in page, or a 401, is the only proof of sign-out (L2)', async () => {
  for (const [label, answer] of [
    ['sign-in page', (url) => fakeResponse({ body: LOGIN_PAGE, url: `${ORIGIN}/login`, redirected: true })],
    ['admin sign-in page', (url) => fakeResponse({ body: LOGIN_PAGE, url: `${ORIGIN}/admin/login`, redirected: true })],
    ['401', (url) => fakeResponse({ body: '{}', status: 401, url, contentType: 'application/json' })],
  ]) {
    let network = (url) => fakeResponse({ body: memberShell('A'), url });
    const worker = loadWorker((url) => network(url));
    await install(worker);
    await (await worker.caches.open(API_CACHE)).put('/api/public/apparatuses', json(`${ORIGIN}/api/public/apparatuses`, { member: 'A' }));

    let calls = 0;
    network = (url) => (calls++ === 0 ? fakeResponse({ body: '', status: 0, url, type: 'opaqueredirect' }) : answer(url));
    await navigate(worker, '/daily/stations');

    assert.equal(worker.stores.has(API_CACHE), false, `${label}: purged`);
    assert.equal(await cachedShell(worker), null, `${label}: the signed-out device keeps no member shell`);
  }
});

test('the shell refresh of an explicit purge never deletes the shell for a 403, a non-sign-in redirect or a 5xx (L2)', async () => {
  for (const answer of [
    (url) => fakeResponse({ body: 'Forbidden', status: 403, url, contentType: 'text/plain' }),
    (url) => fakeResponse({ body: '<html>Set a password</html>', url: `${ORIGIN}/employee/set-password`, redirected: true }),
    (url) => fakeResponse({ body: 'Bad gateway', status: 502, url, contentType: 'text/plain' }),
  ]) {
    let network = (url) => fakeResponse({ body: memberShell('A'), url });
    const worker = loadWorker((url) => network(url));
    await install(worker);

    network = answer;
    await send(worker, PURGE);

    assert.match(await cachedShell(worker), /Daily Checkout A/);
  }
});

// ---- M3: resident pages keep their lazy chunks across a worker upgrade ----

test('activation copies the previous cache assets before deleting it, so a page on the old JavaScript can still lazy-load (M3)', async () => {
  const worker = loadWorker((url) => fakeResponse({ body: memberShell('B'), url }), { assets: [ASSET('new-entry'), ASSET('new-chunk')] });
  const previous = await worker.caches.open('mbfd-checkout-v11');
  await previous.put('/daily/index.html', fakeResponse({ body: memberShell('A'), url: `${ORIGIN}/daily/` }));
  await previous.put(ASSET('old-entry'), assetResponse('old-entry'));
  await previous.put(ASSET('old-lazy-route'), assetResponse('old-lazy-route'));
  await install(worker);
  await activate(worker);

  const paths = storedPaths(worker, worker.cacheName);
  assert.ok(paths.includes(ASSET('old-entry')) && paths.includes(ASSET('old-lazy-route')), 'the old chunks survive');
  assert.ok(paths.includes(ASSET('new-entry')) && paths.includes(ASSET('new-chunk')));
  assert.equal(worker.stores.has('mbfd-checkout-v11'), false);
  // And the old page's lazy import is answered from the cache, offline or not.
  const lazy = await staticFetch(worker, ASSET('old-lazy-route'));
  assert.equal(await lazy.text(), 'bundle old-lazy-route');
});

test('only the newest previous cache is copied, bounded to its newest 300 assets, and never a failed or partial entry (M3)', async () => {
  const worker = loadWorker((url) => fakeResponse({ body: memberShell('B'), url }), { assets: [ASSET('current')] });
  const oldest = await worker.caches.open('mbfd-checkout-v9');
  await oldest.put(ASSET('from-v9'), assetResponse('from-v9'));
  const previous = await worker.caches.open('mbfd-checkout-v11');
  for (let index = 0; index < 320; index += 1) await previous.put(ASSET(`chunk-${index}`), assetResponse(`chunk-${index}`));
  await previous.put(ASSET('truncated'), fakeResponse({ body: 'x', status: 206, url: `${ORIGIN}${ASSET('truncated')}`, contentType: 'text/javascript' }));
  await install(worker);
  await activate(worker);

  const carried = storedPaths(worker, worker.cacheName).filter((path) => path.startsWith('/daily/assets/'));
  assert.equal(carried.includes(ASSET('from-v9')), false, 'older caches than the newest one are not carried');
  assert.equal(carried.includes(ASSET('truncated')), false, 'a partial response is never carried');
  assert.equal(carried.includes(ASSET('chunk-0')), false, 'the oldest entries beyond the bound are dropped');
  assert.equal(carried.includes(ASSET('chunk-319')), true);
  assert.ok(carried.length <= 301, `bounded (carried ${carried.length})`);
});

test('assets older than two releases are pruned; the current and the previous release stay (M3)', async () => {
  const stores = new Map();
  const network = (url) => fakeResponse({ body: memberShell('A'), url });
  const release = async (assets) => {
    const worker = loadWorker(network, { assets, stores });
    await install(worker);
    for (const asset of assets) await (await worker.caches.open(worker.cacheName)).put(asset, assetResponse(asset.split('/').pop()));
    await activate(worker);
    return worker;
  };

  const first = await release([ASSET('r1-entry'), ASSET('shared')]);
  // A font is stored on first use and is on no build list; pruning must never take it.
  await (await first.caches.open(first.cacheName)).put('/daily/assets/inter-0a1b2c3d.woff2', fakeResponse({ body: 'font', url: `/daily/assets/inter-0a1b2c3d.woff2`, contentType: 'font/woff2' }));
  assert.deepEqual(storedPaths(first, first.cacheName).filter((p) => p.startsWith('/daily/assets/') && p.endsWith('.js')).sort(), [ASSET('r1-entry'), ASSET('shared')]);

  const second = await release([ASSET('r2-entry'), ASSET('shared')]);
  assert.deepEqual(
    storedPaths(second, second.cacheName).filter((p) => p.startsWith('/daily/assets/') && p.endsWith('.js')).sort(),
    [ASSET('r1-entry'), ASSET('r2-entry'), ASSET('shared')],
    'the previous release is kept for pages that are still running it',
  );

  // The same build activating again (a restart, a re-check) must not push the previous release out.
  const again = await release([ASSET('r2-entry'), ASSET('shared')]);
  assert.ok(storedPaths(again, again.cacheName).includes(ASSET('r1-entry')));

  const third = await release([ASSET('r3-entry'), ASSET('shared')]);
  assert.deepEqual(
    storedPaths(third, third.cacheName).filter((p) => p.startsWith('/daily/assets/') && p.endsWith('.js')).sort(),
    [ASSET('r2-entry'), ASSET('r3-entry'), ASSET('shared')],
    'r1 is two releases old and goes',
  );
  assert.equal(third.stores.has('mbfd-checkout-v12'), true);
  assert.ok(storedPaths(third, third.cacheName).includes('/daily/assets/inter-0a1b2c3d.woff2'), 'fonts and other files outside the build list are never pruned');
  assert.ok(storedPaths(third, third.cacheName).includes('/manifest.json'), 'non-asset entries are never pruned');
});

test('an unbuilt template (no asset list) never prunes, and pruning never touches the shell or other cached files (M3)', async () => {
  const worker = loadWorker((url) => fakeResponse({ body: memberShell('A'), url }));
  const cache = await worker.caches.open(worker.cacheName);
  await cache.put(ASSET('kept'), assetResponse('kept'));
  await cache.put(IMAGE, fakeResponse({ body: GOOD_IMAGE, url: `${ORIGIN}${IMAGE}`, contentType: 'image/png' }));
  await install(worker);
  await activate(worker);

  assert.ok(storedPaths(worker, worker.cacheName).includes(ASSET('kept')));

  const built = loadWorker((url) => fakeResponse({ body: memberShell('A'), url }), { assets: [ASSET('only')], stores: worker.stores });
  await install(built);
  await activate(built);
  const paths = storedPaths(built, built.cacheName);
  assert.ok(paths.includes(ASSET('kept')), 'an asset of the same-name cache from before the record existed counts as the previous release');
  assert.ok(paths.includes(IMAGE) && paths.includes('/daily/index.html'));
});

// ---- M1: after a user-switch purge the offline read models are fetched again ----

const rewarmNetwork = (log, overrides = {}) => (url, init) => {
  const path = new URL(url).pathname + new URL(url).search;
  log.push(path);
  if (overrides[path]) return overrides[path](url);
  if (path.startsWith('/api/')) return json(url, { path });
  return fakeResponse({ body: memberShell('B'), url });
};

test('a user-switch purge fetches the two lists and the read models the purged cache held, for the new member (M1)', async () => {
  const log = [];
  const worker = loadWorker(rewarmNetwork(log));
  await install(worker);
  const api = await worker.caches.open(API_CACHE);
  await api.put('/api/public/stations/3/requests', json(`${ORIGIN}/api/public/stations/3/requests`, { member: 'A' }));
  await api.put('/api/public/apparatuses/7/checklist', json(`${ORIGIN}/api/public/apparatuses/7/checklist`, { member: 'A' }));
  log.length = 0;

  await send(worker, PURGE);

  const fetchedApi = log.filter((path) => path.startsWith('/api/'));
  assert.deepEqual(fetchedApi.slice(0, 2), ['/api/public/apparatuses', '/api/public/stations'], 'the lists come first');
  assert.ok(fetchedApi.includes('/api/public/apparatuses/7/checklist') && fetchedApi.includes('/api/public/stations/3/requests'));
  assert.deepEqual(storedPaths(worker, API_CACHE).sort(), [
    '/api/public/apparatuses',
    '/api/public/apparatuses/7/checklist',
    '/api/public/stations',
    '/api/public/stations/3/requests',
  ]);
  for (const [, response] of worker.stores.get(API_CACHE)) {
    assert.doesNotMatch(JSON.stringify(await response.clone().json()), /"member":"A"/, 'only the new member responses are stored');
  }
});

test('the re-warm is bounded to ten requests and never stores a non-JSON, failed or redirected answer (M1)', async () => {
  const log = [];
  const worker = loadWorker(rewarmNetwork(log, {
    '/api/public/stations': (url) => fakeResponse({ body: '<html>shell</html>', url }),
    '/api/public/stations/1/gas-meters': (url) => fakeResponse({ body: '{}', status: 500, url, contentType: 'application/json' }),
    '/api/public/stations/2/gas-meters': (url) => fakeResponse({ body: '{}', url: `${ORIGIN}/login`, redirected: true, contentType: 'application/json' }),
  }));
  await install(worker);
  const api = await worker.caches.open(API_CACHE);
  for (let station = 1; station <= 20; station += 1) await api.put(`/api/public/stations/${station}/gas-meters`, json(`${ORIGIN}/api/public/stations/${station}/gas-meters`));
  log.length = 0;

  await send(worker, PURGE);

  assert.equal(log.filter((path) => path.startsWith('/api/')).length, 10);
  const stored = storedPaths(worker, API_CACHE);
  assert.equal(stored.includes('/api/public/stations'), false, 'an HTML answer is not a read model');
  assert.equal(stored.includes('/api/public/stations/1/gas-meters'), false);
  assert.equal(stored.includes('/api/public/stations/2/gas-meters'), false);
});

test('nothing is fetched again when the purge ends in a sign-out, offline, or on a flaky server (M1)', async () => {
  for (const answer of [
    (url) => fakeResponse({ body: LOGIN_PAGE, url: `${ORIGIN}/login`, redirected: true }),
    (url) => fakeResponse({ body: '{}', status: 401, url, contentType: 'application/json' }),
    () => { throw new TypeError('offline'); },
    (url) => fakeResponse({ body: 'oops', status: 503, url, contentType: 'text/plain' }),
  ]) {
    const log = [];
    let network = rewarmNetwork(log);
    const worker = loadWorker((url, init) => network(url, init));
    await install(worker);
    await (await worker.caches.open(API_CACHE)).put('/api/public/stations', json(`${ORIGIN}/api/public/stations`));

    log.length = 0;
    network = (url, init) => { log.push(new URL(url).pathname); return answer(url, init); };
    await send(worker, PURGE);

    assert.deepEqual(log.filter((path) => path.startsWith('/api/')), [], 'no session proof, no API traffic');
    assert.equal(worker.stores.has(API_CACHE), false);
  }
});

test('a second purge or sign-out while the re-warm is running stops it and discards what it fetched (M1)', async () => {
  let release;
  const gate = new Promise((resolveGate) => { release = resolveGate; });
  let gated = false;
  const log = [];
  const base = rewarmNetwork(log);
  const worker = loadWorker(async (url, init) => {
    if (gated && new URL(url).pathname === '/api/public/apparatuses') await gate;
    return base(url, init);
  });
  await install(worker);
  gated = true;

  const first = send(worker, PURGE); // the re-warm is held on the apparatus list
  await flushMicrotasks();
  gated = false;
  await send(worker, PURGE); // a second member change
  release();
  await first;
  await flushMicrotasks();

  // Whatever is stored belongs to the latest purge only (its own re-warm), never to the stale one.
  const apparatusFetches = log.filter((path) => path === '/api/public/apparatuses').length;
  assert.equal(apparatusFetches, 2, 'one request per purge');
  assert.deepEqual(storedPaths(worker, API_CACHE).sort(), ['/api/public/apparatuses', '/api/public/stations']);
});