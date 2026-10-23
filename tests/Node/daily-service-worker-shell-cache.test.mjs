import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vm from 'node:vm';

const ORIGIN = 'https://hub.test';
const source = readFileSync(resolve(process.cwd(), 'resources/js/daily-checkout/public/service-worker.js'), 'utf8');
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
  blob: async () => new Blob([body]),
  clone() { return fakeResponse({ body, status, url, redirected, type, contentType, contentLength }); },
});

function loadWorker(networkFor, { failCachePut = false } = {}) {
  const listeners = {};
  const stores = new Map();
  const keyOf = (request) => new URL(typeof request === 'string' ? request : request.url, ORIGIN).pathname;
  const open = async (name) => {
    if (!stores.has(name)) stores.set(name, new Map());
    const store = stores.get(name);
    return {
      put: async (request, response) => {
        if (failCachePut && name === mbfd-checkout-v11 && !keyOf(request).startsWith('/daily/')) throw new Error('quota exceeded');
        store.set(keyOf(request), response);
      },
      match: async (request) => store.get(keyOf(request)),
      keys: async () => [...store.keys()].map((path) => ({ url: `${ORIGIN}${path}` })),
      addAll: async (keys) => { for (const key of keys) store.set(keyOf(key), fakeResponse({ url: `${ORIGIN}${key}` })); },
    };
  };
  const sandbox = {
    self: {
      location: { origin: ORIGIN },
      addEventListener: (type, listener) => { listeners[type] = listener; },
      skipWaiting: () => undefined,
      clients: { claim: async () => undefined },
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
    console,
  };
  vm.runInNewContext(source, sandbox);

  return { listeners, stores, caches: sandbox.caches, cacheName: [...source.matchAll(/const CACHE_NAME = '([^']+)'/g)][0][1] };
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
  worker.listeners.fetch({
    request: { method: 'GET', mode: 'navigate', url: `${ORIGIN}${path}` },
    respondWith: (promise) => { pending = promise; },
  });
  return pending;
};

const cachedShell = async (worker) => {
  const response = worker.stores.get(worker.cacheName)?.get('/daily/index.html');
  return response ? response.text() : null;
};

test('the shell cache version is bumped past the releases that stored a sign-in page and truncated images', () => {
  assert.match(source, /const CACHE_NAME = 'mbfd-checkout-v11'/);
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

test('activation keeps the new shell when the install stored one', async () => {
  const worker = loadWorker((url) => fakeResponse({ body: DAILY_SHELL.replace('Daily Checkout', 'Daily Checkout v9'), url }));
  await seedPreviousCache(worker, DAILY_SHELL);
  await install(worker);

  await activate(worker);

  assert.match(await cachedShell(worker), /Daily Checkout v9/);
  assert.equal(worker.stores.get(worker.cacheName).has('/daily/assets/index-old.js'), false);
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
