import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
  MESSAGES,
  ROOT_WORKER_SCOPE,
  ROOT_WORKER_URL,
  arrayBufferToBase64Url,
  canUsePush,
  classifySubscriptions,
  deriveStatus,
  deriveView,
  findDeviceSubscriptions,
  mountPushEnrollment,
  parseHashes,
  readEnvironment,
  sha256Hex,
  preferredContentEncoding,
  urlBase64ToUint8Array,
  usesServerKey,
  whenActive,
} from '../../resources/js/pwa/push-enrollment.ts';

const source = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', 'resources/js/pwa/push-enrollment.ts'), 'utf8');

const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 Version/18.4 Mobile/15E148 Safari/604.1';
const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36';

const win = ({ userAgent = CHROME, platform = 'Win32', standalone, displayMode, permission, push = true, worker = true, secure = true, touch = 0 } = {}) => ({
  navigator: { userAgent, platform, maxTouchPoints: touch, ...(standalone === undefined ? {} : { standalone }), ...(worker ? { serviceWorker: {} } : {}) },
  isSecureContext: secure,
  matchMedia: (query) => ({ matches: displayMode !== undefined && query === `(display-mode: ${displayMode})` }),
  ...(push ? { PushManager: class {} } : {}),
  ...(permission === undefined ? {} : { Notification: { permission } }),
});

test('a desktop browser with push support can offer the button', () => {
  const env = readEnvironment(win({ permission: 'default' }));
  assert.equal(canUsePush(env), true);
  assert.equal(deriveView({ environment: env, subscribed: false, registeredOnServer: false }), 'ready');
});

test('an iPhone in a Safari tab is told to add the Hub to the Home Screen and gets no button', () => {
  const env = readEnvironment(win({ userAgent: IPHONE, platform: 'iPhone', standalone: false }));
  assert.equal(env.ios, true);
  assert.equal(env.standalone, false);
  assert.equal(env.permission, 'unsupported', 'Notification is undefined in a Safari tab and is never touched');
  const view = deriveView({ environment: env, subscribed: false, registeredOnServer: false });
  assert.equal(view, 'ios-install');
  assert.match(MESSAGES[view], /Add MBFD Hub to your Home Screen to get alerts on this iPhone/);
});

test('an installed iPhone Home Screen app can enable alerts', () => {
  const env = readEnvironment(win({ userAgent: IPHONE, platform: 'iPhone', standalone: true, permission: 'default' }));
  assert.equal(env.standalone, true);
  assert.equal(deriveView({ environment: env, subscribed: false, registeredOnServer: false }), 'ready');
});

test('an iPad that reports itself as a Mac is still treated as iOS', () => {
  const env = readEnvironment(win({ userAgent: CHROME.replace('Windows NT 10.0; Win64; x64', 'Macintosh; Intel Mac OS X 10_15_7'), platform: 'MacIntel', touch: 5, standalone: false }));
  assert.equal(env.ios, true);
  assert.equal(deriveView({ environment: env, subscribed: false, registeredOnServer: false }), 'ios-install');
});

test('standalone is detected from display-mode for installed desktop and Android apps', () => {
  assert.equal(readEnvironment(win({ displayMode: 'standalone', permission: 'default' })).standalone, true);
  assert.equal(readEnvironment(win({ displayMode: 'window-controls-overlay', permission: 'default' })).standalone, true);
  assert.equal(readEnvironment(win({ permission: 'default' })).standalone, false);
});

test('a browser missing a required API is reported as unsupported with an honest message', () => {
  for (const options of [{ worker: false, permission: 'default' }, { push: false, permission: 'default' }, { permission: undefined }, { secure: false, permission: 'default' }]) {
    const env = readEnvironment(win(options));
    assert.equal(canUsePush(env), false, JSON.stringify(options));
    assert.equal(deriveView({ environment: env, subscribed: false, registeredOnServer: false }), 'unsupported');
  }
  assert.match(MESSAGES.unsupported, /In-app alerts still arrive/);
});

test('a blocked permission is explained and offers no button', () => {
  const env = readEnvironment(win({ permission: 'denied' }));
  assert.equal(deriveView({ environment: env, subscribed: false, registeredOnServer: false }), 'blocked');
});

test('subscribed and known to the server is enabled; subscribed but unknown needs repair', () => {
  const env = readEnvironment(win({ permission: 'granted' }));
  assert.equal(deriveView({ environment: env, subscribed: true, registeredOnServer: true }), 'enabled');
  assert.equal(deriveView({ environment: env, subscribed: true, registeredOnServer: false }), 'needs-repair');
  assert.equal(deriveView({ environment: env, subscribed: false, registeredOnServer: false }), 'ready');
});

test('permission revoked while a subscription exists never reads as enabled', () => {
  const env = readEnvironment(win({ permission: 'denied' }));
  assert.equal(deriveView({ environment: env, subscribed: true, registeredOnServer: true }), 'blocked');
});

test('the status rows report supported, installed, permission, subscribed and enabled', () => {
  const env = readEnvironment(win({ displayMode: 'standalone', permission: 'granted' }));
  assert.deepEqual(deriveStatus({ environment: env, subscribed: true, registeredOnServer: true }), {
    supported: true,
    installed: true,
    permission: 'granted',
    subscribed: true,
    enabled: true,
  });
});

test('new subscriptions bind to the root worker the Hub already uses', () => {
  assert.equal(ROOT_WORKER_URL, '/sw.js');
  assert.equal(ROOT_WORKER_SCOPE, '/');
  assert.match(source, /register\(ROOT_WORKER_URL, \{ scope: ROOT_WORKER_SCOPE, updateViaCache: 'none' \}\)/, 'identical options to the push widget and the admin page (L6)');
  assert.equal((source.match(/serviceWorker\s*\.register\(/g) ?? []).length, 1, 'one registration call, never a new worker URL');
});

test('the subscription binds to the registration returned by register(), never to serviceWorker.ready', () => {
  assert.ok(!/serviceWorker\.ready/.test(source.replace(/\/\/.*$/gm, '')), 'ready can resolve to a narrower /admin/ worker');
  assert.match(source, /\.then\(whenActive\)/);
});

test('whenActive waits for the worker to activate and returns the same registration', async () => {
  const listeners = [];
  const worker = { state: 'installing', addEventListener: (_, fn) => listeners.push(fn) };
  const registration = { installing: worker, waiting: null, active: null };
  const pending = whenActive(registration);
  worker.state = 'activated';
  listeners.forEach((fn) => fn());
  assert.equal(await pending, registration);

  const ready = { installing: null, waiting: null, active: { state: 'activated' } };
  assert.equal(await whenActive(ready), ready);
});

test('a stalled worker does not leave the page waiting forever', async () => {
  const worker = { state: 'installed', addEventListener: () => {} };
  const registration = { installing: null, waiting: worker, active: null };
  assert.equal(await whenActive(registration, 5), registration);
});

test('a subscription made with another server key is detected', () => {
  const serverKey = urlBase64ToUint8Array('BAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
  const same = { options: { applicationServerKey: serverKey.buffer.slice(0) } };
  const other = { options: { applicationServerKey: new Uint8Array(65).buffer } };
  assert.equal(usesServerKey(same, serverKey), true);
  assert.equal(usesServerKey(other, serverKey), false);
  assert.equal(usesServerKey({}, serverKey), true, 'a browser that hides the key is trusted');
});

test('the standard content encoding is preferred', () => {
  assert.equal(preferredContentEncoding(undefined), 'aes128gcm');
  assert.equal(preferredContentEncoding(['aesgcm', 'aes128gcm']), 'aes128gcm');
  assert.equal(preferredContentEncoding(['aesgcm']), 'aesgcm');
});

test('permission is requested only from the enable button handler, before any network call', () => {
  assert.equal((source.match(/requestPermission\(/g) ?? []).length, 1);
  const handler = source.slice(source.indexOf('private async onEnable'), source.indexOf('private async onDisable'));
  assert.ok(handler.includes('Notification.requestPermission()'));
  assert.ok(handler.indexOf('requestPermission') < handler.indexOf('pushManager.subscribe'));
  assert.ok(handler.indexOf('pushManager.subscribe') < handler.indexOf('this.register('), 'subscribe happens before the server is contacted');
  assert.ok(!/await (fetch|api)\(/.test(handler.slice(0, handler.indexOf('pushManager.subscribe'))), 'no network awaited before subscribe');
  assert.ok(handler.includes('userVisibleOnly: true'));
});

test('VAPID keys and push keys use URL-safe base64', () => {
  const bytes = urlBase64ToUint8Array('BAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
  assert.equal(bytes.length, 65);
  assert.equal(bytes[0], 4);
  assert.equal(arrayBufferToBase64Url(bytes.buffer), 'BAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
  assert.equal(arrayBufferToBase64Url(null), '');
});

test('endpoints are compared by SHA-256, matching the server', async () => {
  assert.equal(await sha256Hex('abc'), 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
});

test('the server hash list tolerates missing or damaged data', () => {
  assert.deepEqual(parseHashes(undefined), []);
  assert.deepEqual(parseHashes('not json'), []);
  assert.deepEqual(parseHashes('{"a":1}'), []);
  assert.deepEqual(parseHashes('["a", 1, "b"]'), ['a', 'b']);
});

test('the module writes status with textContent only, never innerHTML', () => {
  assert.ok(!/innerHTML|insertAdjacentHTML|document\.write/.test(source));
});

// ---------------------------------------------------------------------------
// M6: a subscription on ANY registration is the device's enrollment
// ---------------------------------------------------------------------------

const VAPID = 'BAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
const serverKeyBytes = urlBase64ToUint8Array(VAPID);

function fakeSubscription(endpoint, { key = serverKeyBytes.buffer.slice(0), log = [] } = {}) {
  const keyBytes = (seed) => new Uint8Array(16).fill(seed).buffer;
  return {
    endpoint,
    options: { applicationServerKey: key },
    getKey: (name) => (name === 'p256dh' ? keyBytes(3) : keyBytes(7)),
    unsubscribe: async () => {
      log.push(endpoint);
      return true;
    },
  };
}

function fakeRegistration(scope, subscription = null, calls = { subscribe: [] }) {
  const registration = {
    scope,
    installing: null,
    waiting: null,
    active: { state: 'activated' },
    pushManager: {
      getSubscription: async () => registration.subscription,
      subscribe: async (options) => {
        calls.subscribe.push({ scope, options });
        registration.subscription = fakeSubscription(`https://push.example.test/new-${scope.replaceAll('/', '_')}`);

        return registration.subscription;
      },
    },
    subscription,
  };

  return registration;
}

test('findDeviceSubscriptions looks at every registration, collapses duplicates by endpoint and survives a registration that throws', async () => {
  const root = fakeRegistration('/');
  const admin = fakeRegistration('/admin/', fakeSubscription('https://push.example.test/admin'));
  const daily = fakeRegistration('/daily/', fakeSubscription('https://push.example.test/daily'));
  const rootAgain = Object.create(admin); // the same registration seen through a second object
  const broken = { pushManager: { getSubscription: async () => { throw new Error('no push here'); } } };

  const found = await findDeviceSubscriptions(root, { getRegistrations: async () => [rootAgain, root, admin, daily, broken] });
  assert.deepEqual(found.map((item) => item.endpoint).sort(), ['https://push.example.test/admin', 'https://push.example.test/daily']);

  assert.deepEqual(await findDeviceSubscriptions(null, undefined), []);
  assert.deepEqual(await findDeviceSubscriptions(root, { getRegistrations: async () => { throw new Error('refused'); } }), [], 'listing may be refused');
  const own = fakeSubscription('https://push.example.test/root-only');
  assert.deepEqual((await findDeviceSubscriptions(fakeRegistration('/', own), {})).map((item) => item.endpoint), [own.endpoint]);
});

test('subscriptions made with another server key are separated from the usable ones', () => {
  const good = fakeSubscription('https://push.example.test/good');
  const hidden = { endpoint: 'https://push.example.test/hidden' }; // a browser that hides the key is trusted
  const stale = fakeSubscription('https://push.example.test/stale', { key: new Uint8Array(65).buffer });

  const { usable, stale: rejected } = classifySubscriptions([good, hidden, stale], serverKeyBytes);

  assert.deepEqual(usable.map((item) => item.endpoint), [good.endpoint, hidden.endpoint]);
  assert.deepEqual(rejected.map((item) => item.endpoint), [stale.endpoint]);
});

/** A just-enough browser for the settings block: elements, a service-worker container, fetch and Notification. */
function mountDevice({ registrations, serverEndpoints = [], permission = 'granted', rootSubscribeCalls } = {}) {
  const requests = [];
  const registerCalls = [];
  const elements = {};
  const element = () => {
    const handlers = {};
    return {
      hidden: false,
      disabled: false,
      textContent: '',
      dataset: {},
      addEventListener: (type, handler) => { handlers[type] = handler; },
      click: () => handlers.click(),
    };
  };
  for (const role of ['summary', 'message', 'enable', 'disable', 'test']) elements[role] = element();
  const root = element();
  root.dataset = { vapidKey: VAPID, endpointHashes: '__HASHES__' };
  root.querySelector = (selector) => {
    const role = /data-role="(\w+)"/.exec(selector)?.[1];

    return role ? elements[role] : null;
  };
  const rootRegistration = registrations.find((item) => item.scope === '/');
  const serviceWorker = {
    register: async (url, options) => {
      registerCalls.push({ url, options });

      return Object.create(rootRegistration);
    },
    getRegistrations: async () => registrations,
  };
  const win = {
    navigator: { userAgent: 'Mozilla/5.0 Chrome/130', platform: 'Win32', maxTouchPoints: 0, serviceWorker },
    isSecureContext: true,
    matchMedia: () => ({ matches: false }),
    PushManager: class { static supportedContentEncodings = ['aes128gcm']; },
    Notification: { permission, requestPermission: async () => permission },
  };
  Object.defineProperty(globalThis, 'window', { value: win, configurable: true, writable: true });
  Object.defineProperty(globalThis, 'navigator', { value: win.navigator, configurable: true, writable: true });
  Object.defineProperty(globalThis, 'Notification', { value: win.Notification, configurable: true, writable: true });
  Object.defineProperty(globalThis, 'document', { value: { querySelector: () => null }, configurable: true, writable: true });
  globalThis.fetch = async (url, init) => {
    requests.push({ url, method: init?.method, body: init?.body ? JSON.parse(init.body) : null });

    return { ok: true, status: 200, json: async () => ({ devices: [] }) };
  };

  return { root, elements, requests, registerCalls, serviceWorker, rootSubscribeCalls };
}

const withHashes = async (device, endpoints) => {
  const hashes = [];
  for (const endpoint of endpoints) hashes.push(await sha256Hex(endpoint));
  device.root.dataset.endpointHashes = JSON.stringify(hashes);
};
const settle = () => new Promise((resolveSettle) => setTimeout(resolveSettle, 40));
const mount = (device) => mountPushEnrollment({ querySelector: (selector) => (selector === '[data-push-enrollment]' ? device.root : null) });

test('a device already subscribed through the admin registration reads as enabled, and nothing new is created (M6)', async () => {
  const calls = { subscribe: [] };
  const adminSub = fakeSubscription('https://push.example.test/admin');
  const registrations = [fakeRegistration('/', null, calls), fakeRegistration('/admin/', adminSub, calls)];
  const device = mountDevice({ registrations });
  await withHashes(device, [adminSub.endpoint]);

  mount(device);
  await settle();

  assert.equal(device.root.dataset.view, 'enabled', 'alerts are on for this device; the root registration holding none is irrelevant');
  assert.equal(device.elements.enable.hidden, true);
  assert.equal(device.elements.disable.hidden, false);
  assert.deepEqual(calls.subscribe, []);
  assert.deepEqual(device.requests.filter((request) => request.method), [], 'already known to the server: nothing to sync');
  assert.deepEqual(device.registerCalls, [{ url: '/sw.js', options: { scope: '/', updateViaCache: 'none' } }], 'the root registration is still the one new enrollments use, with identical options (L6)');
});

test('a subscription the server does not know yet is synced, not duplicated (M6)', async () => {
  const calls = { subscribe: [] };
  const dailySub = fakeSubscription('https://push.example.test/daily');
  const registrations = [fakeRegistration('/', null, calls), fakeRegistration('/daily/', dailySub, calls)];
  const device = mountDevice({ registrations });

  mount(device);
  await settle();

  const posts = device.requests.filter((request) => request.method === 'POST');
  assert.equal(posts.length, 1);
  assert.equal(posts[0].url, '/api/push-subscriptions');
  assert.equal(posts[0].body.endpoint, dailySub.endpoint);
  assert.deepEqual(calls.subscribe, [], 'no second subscription');
  assert.equal(device.root.dataset.view, 'enabled');
});

test('"Turn on" with an existing subscription elsewhere syncs it instead of creating another one (M6)', async () => {
  const calls = { subscribe: [] };
  const adminSub = fakeSubscription('https://push.example.test/admin');
  const registrations = [fakeRegistration('/', null, calls), fakeRegistration('/admin/', adminSub, calls)];
  const device = mountDevice({ registrations });
  mount(device);
  await settle();
  device.requests.length = 0;
  // The server forgot this device (for example the member removed it from the list).
  device.root.dataset.endpointHashes = '[]';

  device.elements.enable.click();
  await settle();

  assert.deepEqual(calls.subscribe, [], 'pushManager.subscribe must not run when the device is already enrolled');
  assert.deepEqual(device.requests.filter((request) => request.method === 'POST').map((request) => request.body.endpoint), [adminSub.endpoint]);
});

test('with no subscription anywhere, "Turn on" creates exactly one, on the root registration (M6)', async () => {
  const calls = { subscribe: [] };
  const registrations = [fakeRegistration('/', null, calls), fakeRegistration('/admin/', null, calls), fakeRegistration('/daily/', null, calls)];
  const device = mountDevice({ registrations, permission: 'granted' });
  mount(device);
  await settle();
  assert.equal(device.root.dataset.view, 'ready');

  device.elements.enable.click();
  await settle();

  assert.equal(calls.subscribe.length, 1);
  assert.equal(calls.subscribe[0].scope, '/');
  assert.equal(calls.subscribe[0].options.userVisibleOnly, true);
});

test('"Turn off on this device" unsubscribes every subscription on every registration and deletes each one on the server (M6)', async () => {
  const unsubscribed = [];
  const calls = { subscribe: [] };
  const rootSub = fakeSubscription('https://push.example.test/root', { log: unsubscribed });
  const adminSub = fakeSubscription('https://push.example.test/admin', { log: unsubscribed });
  const dailySub = fakeSubscription('https://push.example.test/daily', { log: unsubscribed });
  const registrations = [fakeRegistration('/', rootSub, calls), fakeRegistration('/admin/', adminSub, calls), fakeRegistration('/daily/', dailySub, calls)];
  const device = mountDevice({ registrations });
  await withHashes(device, [rootSub.endpoint, adminSub.endpoint, dailySub.endpoint]);
  mount(device);
  await settle();
  assert.equal(device.root.dataset.view, 'enabled');

  device.elements.disable.click();
  await settle();

  assert.deepEqual(unsubscribed.sort(), [adminSub.endpoint, dailySub.endpoint, rootSub.endpoint].sort());
  assert.deepEqual(
    device.requests.filter((request) => request.method === 'DELETE').map((request) => request.body.endpoint).sort(),
    [adminSub.endpoint, dailySub.endpoint, rootSub.endpoint].sort(),
  );
});

test('a subscription made with another server key is removed on "Turn on" and replaced by a good one (M6)', async () => {
  const unsubscribed = [];
  const calls = { subscribe: [] };
  const stale = fakeSubscription('https://push.example.test/stale', { key: new Uint8Array(65).buffer, log: unsubscribed });
  const registrations = [fakeRegistration('/', null, calls), fakeRegistration('/admin/', stale, calls)];
  const device = mountDevice({ registrations });
  mount(device);
  await settle();
  assert.equal(device.root.dataset.view, 'needs-repair');

  device.elements.enable.click();
  await settle();

  assert.deepEqual(unsubscribed, [stale.endpoint]);
  assert.equal(calls.subscribe.length, 1);
});

test('withdrawn permission forgets every subscription found, not just the root one (M6)', async () => {
  const unsubscribed = [];
  const adminSub = fakeSubscription('https://push.example.test/admin', { log: unsubscribed });
  const registrations = [fakeRegistration('/'), fakeRegistration('/admin/', adminSub)];
  const device = mountDevice({ registrations, permission: 'denied' });

  mount(device);
  await settle();

  assert.deepEqual(unsubscribed, [adminSub.endpoint]);
  assert.equal(device.root.dataset.view, 'blocked');
});