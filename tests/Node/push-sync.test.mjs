import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const { alertSetupFor, snoozeOffer, syncDeviceSubscription } = await import('../../resources/js/pwa/push-sync.ts');
const { TURNED_OFF_KEY, sha256Hex } = await import('../../resources/js/pwa/push-enrollment.ts');

function memoryStorage(seed = {}) {
  const data = new Map(Object.entries(seed));

  return { getItem: (key) => data.get(key) ?? null, setItem: (key, value) => data.set(key, String(value)) };
}

const KEY_BYTES = new Uint8Array(65).fill(7);
const SERVER_KEY = Buffer.from(KEY_BYTES).toString('base64url');
const ENDPOINT = 'https://web.push.apple.com/phone-token';

function subscription(endpoint = ENDPOINT, keyBytes = KEY_BYTES) {
  return {
    endpoint,
    options: { applicationServerKey: keyBytes.buffer.slice(0) },
    getKey: (name) => (name === 'p256dh' ? new Uint8Array(65).fill(4).buffer : new Uint8Array(16).fill(5).buffer),
  };
}

function harness({ subscriptions = [subscription()], permission = 'granted', state = { known: false }, saveStatus = 200, storage = memoryStorage(), now = 1_000_000 } = {}) {
  const calls = [];
  const fetchImpl = async (url, init = {}) => {
    calls.push({ url, method: init.method ?? 'GET', body: init.body ? JSON.parse(init.body) : null, headers: init.headers ?? {} });
    if (url.startsWith('/api/push/vapid-public-key')) {
      return { ok: true, status: 200, json: async () => ({ publicKey: SERVER_KEY }) };
    }
    if (url.startsWith('/api/push/device-state')) {
      return { ok: true, status: 200, json: async () => state };
    }

    return { ok: saveStatus < 300, status: saveStatus, json: async () => ({}) };
  };

  const win = {
    navigator: { userAgent: 'iPhone', standalone: true, serviceWorker: {} },
    isSecureContext: true,
    PushManager: class {},
    Notification: { permission },
  };
  const registrations = {
    getRegistrations: async () => subscriptions.map((held) => ({ pushManager: { getSubscription: async () => held } })),
  };

  return {
    calls,
    run: () => syncDeviceSubscription({ win, registrations, fetch: fetchImpl, now: () => now, storage, csrfHeaders: () => ({ 'X-CSRF-TOKEN': 'token' }) }),
  };
}

test('a working subscription the Hub does not know is saved quietly, with no prompt', async () => {
  const world = harness();

  assert.equal(await world.run(), 'saved');

  const saved = world.calls.find((call) => call.method === 'POST');
  assert.equal(saved.url, '/api/push-subscriptions');
  assert.equal(saved.body.endpoint, ENDPOINT);
  assert.equal(saved.body.sync, true, 'marked as a repair, so it cannot undo a Remove');
  assert.equal(saved.body.content_encoding, 'aes128gcm');
  assert.equal(saved.headers['X-CSRF-TOKEN'], 'token');
});

test('the endpoint itself is never placed in a URL', async () => {
  const world = harness();
  await world.run();

  const stateCall = world.calls.find((call) => call.url.startsWith('/api/push/device-state'));
  assert.equal(stateCall.url, `/api/push/device-state?hash=${await sha256Hex(ENDPOINT)}`);
  assert.ok(world.calls.every((call) => !call.url.includes('phone-token')));
});

test('a device already in step writes nothing, and an old row with no name gets one quiet refresh', async () => {
  const inStep = harness({ state: { known: true, refresh: false } });
  assert.equal(await inStep.run(), 'in-step');
  assert.equal(inStep.calls.filter((call) => call.method === 'POST').length, 0);

  const old = harness({ state: { known: true, refresh: true } });
  assert.equal(await old.run(), 'saved');
  assert.equal(old.calls.filter((call) => call.method === 'POST').length, 1);
});

test('a device the member removed stays removed', async () => {
  const world = harness({ state: { known: false, removed: true } });

  assert.equal(await world.run(), 'removed');
  assert.equal(world.calls.filter((call) => call.method === 'POST').length, 0);
});

test('without permission or a subscription the repair does nothing at all', async () => {
  for (const permission of ['denied', 'default']) {
    const world = harness({ permission });
    assert.equal(await world.run(), 'unavailable');
    assert.equal(world.calls.length, 0);
  }

  const none = harness({ subscriptions: [] });
  assert.equal(await none.run(), 'no-subscription');
  assert.equal(none.calls.length, 0);
});

test('a subscription made with another server key is not saved', async () => {
  const world = harness({ subscriptions: [subscription(ENDPOINT, new Uint8Array(65).fill(9))] });

  await world.run();
  assert.equal(world.calls.filter((call) => call.method === 'POST').length, 0);
});

test('it checks at most once per window, and a failure is quiet', async () => {
  const storage = memoryStorage();
  assert.equal(await harness({ storage, state: { known: true } }).run(), 'in-step');

  const soonAfter = harness({ storage, state: { known: true }, now: 1_000_000 + 60_000 });
  assert.equal(await soonAfter.run(), 'skipped');
  assert.equal(soonAfter.calls.length, 0);

  assert.equal(await harness({ storage, state: { known: true }, now: 1_000_000 + 6 * 60_000 }).run(), 'in-step');
  assert.equal(await harness({ saveStatus: 500 }).run(), 'failed');
});

// ------------------------------------------------------------------ setting up a device that has no subscription

const device = (permission, standalone = true) => ({ secureContext: true, hasServiceWorker: true, hasPushManager: true, permission, standalone, ios: true });
const NOW = 5_000_000_000;

test('an installed app that has never asked gets the one-tap card', () => {
  assert.equal(alertSetupFor(device('default'), 'unavailable', memoryStorage(), NOW), 'offer');
});

test('permission already given and no subscription: set up on the next tap, with no prompt', () => {
  assert.equal(alertSetupFor(device('granted'), 'no-subscription', memoryStorage(), NOW), 'on-next-tap');
});

test('a working device, or one still being checked, is left alone', () => {
  for (const outcome of ['in-step', 'saved', 'removed', 'failed', 'skipped']) {
    assert.equal(alertSetupFor(device('granted'), outcome, memoryStorage(), NOW), 'none', outcome);
  }
});

test('never in a browser tab, and never when the phone has blocked notifications', () => {
  assert.equal(alertSetupFor(device('default', false), 'unavailable', memoryStorage(), NOW), 'none');
  assert.equal(alertSetupFor(device('granted', false), 'no-subscription', memoryStorage(), NOW), 'none');
  assert.equal(alertSetupFor(device('denied'), 'unavailable', memoryStorage(), NOW), 'none');
  assert.equal(alertSetupFor({ ...device('default'), hasPushManager: false }, 'unavailable', memoryStorage(), NOW), 'none');
});

test('a member who turned alerts off here stays off, on both paths', () => {
  const off = memoryStorage({ [TURNED_OFF_KEY]: '1' });

  assert.equal(alertSetupFor(device('default'), 'unavailable', off, NOW), 'none');
  assert.equal(alertSetupFor(device('granted'), 'no-subscription', off, NOW), 'none');
});

test('"Not now" is respected for a day, then the card comes back', () => {
  const storage = memoryStorage();
  snoozeOffer(storage, NOW);

  assert.equal(alertSetupFor(device('default'), 'unavailable', storage, NOW + 60_000), 'none');
  assert.equal(alertSetupFor(device('default'), 'unavailable', storage, NOW + 23 * 60 * 60_000), 'none');
  assert.equal(alertSetupFor(device('default'), 'unavailable', storage, NOW + 25 * 60 * 60_000), 'offer');
});

test('blocked storage fails toward doing nothing', () => {
  const broken = { getItem() { throw new Error('blocked'); } };

  assert.equal(alertSetupFor(device('default'), 'unavailable', broken, NOW), 'none');
});

test('permission is the first thing awaited in the tap, and only the card can ask', () => {
  const source = readFileSync('resources/js/pwa/push-sync.ts', 'utf8');
  const handler = source.slice(source.indexOf("turnOn?.addEventListener('click'"));

  assert.ok(handler.indexOf('await Notification.requestPermission()') < handler.indexOf('await subscribeAndSave'));
  assert.equal(handler.search(/\bawait\s/), handler.indexOf('await Notification.requestPermission()'));
  assert.equal((source.match(/requestPermission\(/g) ?? []).length, 1);
  assert.ok(!source.includes('.unregister('), 'never removes a worker');
});
