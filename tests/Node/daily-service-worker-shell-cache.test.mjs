import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vm from 'node:vm';

const ORIGIN = 'https://hub.test';
const source = readFileSync(resolve(process.cwd(), 'resources/js/daily-checkout/public/service-worker.js'), 'utf8');
const DAILY_SHELL = '<!doctype html><html><head><title>Daily Checkout | MBFD Hub</title></head><body><div id="root"></div></body></html>';
const LOGIN_PAGE = '<!doctype html><html><head><title>Sign in | MBFD Hub</title></head><body><form action="/login"></form></body></html>';

const fakeResponse = ({ body = '', status = 200, url, redirected = false, type = 'basic', contentType = 'text/html; charset=UTF-8' }) => ({
  ok: status >= 200 && status < 300,
  status,
  url,
  redirected,
  type,
  headers: new Headers({ 'Content-Type': contentType }),
  text: async () => body,
  clone() { return fakeResponse({ body, status, url, redirected, type, contentType }); },
});

function loadWorker(networkFor) {
  const listeners = {};
  const stores = new Map();
  const keyOf = (request) => new URL(typeof request === 'string' ? request : request.url, ORIGIN).pathname;
  const open = async (name) => {
    if (!stores.has(name)) stores.set(name, new Map());
    const store = stores.get(name);
    return {
      put: async (request, response) => { store.set(keyOf(request), response); },
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
    fetch: async (request) => networkFor(typeof request === 'string' ? new URL(request, ORIGIN).toString() : request.url),
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

test('the shell cache version is bumped past the release that could store a sign-in page', () => {
  assert.match(source, /const CACHE_NAME = 'mbfd-checkout-v9'/);
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
