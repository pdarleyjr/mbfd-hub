import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

import {
  CORE_PATH,
  END_MARKER,
  START_MARKER,
  TARGETS,
  findDrift,
  renderWorker,
} from '../../scripts/pwa/build-service-workers.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (path) => readFileSync(resolve(root, path), 'utf8');
const coreSource = read(CORE_PATH);
const ORIGIN = 'https://hub.example.test';

/** Cross-realm values fail deepStrictEqual on prototypes, so compare plain JSON. */
const plain = (value) => JSON.parse(JSON.stringify(value));

class FakeClient {
  constructor(url, { focused = false, visibilityState = 'hidden', acknowledges = true, controlled = true, focusDelayMs = 0 } = {}) {
    this.url = url;
    this.focused = focused;
    this.visibilityState = visibilityState;
    this.acknowledges = acknowledges;
    this.controlled = controlled;
    this.focusDelayMs = focusDelayMs;
    this.focusCalls = 0;
    this.messages = [];
  }

  async focus() {
    this.focusCalls += 1;
    if (this.focusDelayMs > 0) {
      await new Promise((resolveDelay) => setTimeout(resolveDelay, this.focusDelayMs));
    }

    return this;
  }

  postMessage(message, transfer = []) {
    this.messages.push(plain(message));
    if (this.acknowledges && transfer[0]) {
      transfer[0].postMessage({ type: 'MBFD_NAVIGATE_ACK' });
    }
  }

  navigate() {
    throw new Error('WindowClient.navigate must never be used');
  }
}

/**
 * Evaluate a worker script, or the bare core, in a fake ServiceWorkerGlobalScope.
 * Timers are shortened so the acknowledgement timeout does not slow the suite.
 */
function createWorld({ source = coreSource, clients = [], fetchImpl, cookies = {}, withCookieStore = true, buildId = 'test-build' } = {}) {
  const listeners = {};
  const shown = [];
  const opened = [];
  const fetchCalls = [];
  const subscribeCalls = [];
  const world = { skipWaitingCalls: 0, showFailures: 0 };

  const self = {
    location: { origin: ORIGIN },
    addEventListener(type, handler) {
      (listeners[type] ??= []).push(handler);
    },
    skipWaiting() {
      world.skipWaitingCalls += 1;
    },
    registration: {
      async showNotification(title, options) {
        if (world.showFailures > 0) {
          world.showFailures -= 1;
          throw new TypeError('bad notification options');
        }
        shown.push({ title, options: plain(options) });
      },
      pushManager: {
        async subscribe(options) {
          subscribeCalls.push(options);

          return {
            toJSON: () => ({ endpoint: 'https://push.example.test/new', keys: { p256dh: 'p', auth: 'a' } }),
          };
        },
      },
    },
    clients: {
      async matchAll(options) {
        world.matchAllOptions = plain(options);

        return clients;
      },
      async openWindow(url) {
        opened.push(url);
      },
      async claim() {},
    },
  };
  if (withCookieStore) {
    self.cookieStore = { get: async (name) => (cookies[name] ? { value: cookies[name] } : null) };
  }

  const sandbox = {
    self,
    URL,
    MessageChannel,
    setTimeout: (fn, ms) => setTimeout(fn, Math.min(ms, 250)),
    clearTimeout,
    fetch: async (url, init) => {
      fetchCalls.push({ url, init: plain(init) });

      return fetchImpl ? fetchImpl(url, init) : { ok: true, status: 201 };
    },
    decodeURIComponent,
    caches: { keys: async () => [], open: async () => ({ addAll: async () => {} }) },
  };
  sandbox.globalThis = sandbox;
  vm.createContext(sandbox);
  // Shipped workers declare their own build id; the bare core gets a stand-in.
  const prelude = buildId !== null && !source.includes('const MBFD_WORKER_BUILD_ID')
    ? `const MBFD_WORKER_BUILD_ID = '${buildId}';\n`
    : '';
  vm.runInContext(`${prelude}${source}`, sandbox);

  return {
    listeners,
    shown,
    opened,
    fetchCalls,
    subscribeCalls,
    world,
    sandbox,
    /** Dispatch an event to every listener of a type and wait for waitUntil promises. */
    async dispatch(type, event = {}) {
      const pending = [];
      const full = { ...event, waitUntil: (promise) => pending.push(promise) };
      for (const handler of listeners[type] ?? []) {
        handler(full);
      }
      await Promise.all(pending);

      return { event: full, waited: pending.length };
    },
  };
}

function pushEvent(text) {
  return {
    data: text === undefined
      ? null
      : {
          text: () => {
            if (text instanceof Error) {
              throw text;
            }

            return text;
          },
          json: () => JSON.parse(text),
        },
  };
}

function clickEvent({ data = { url: '/employee/forms' }, action = '' } = {}) {
  const notification = {
    data,
    closed: 0,
    close() {
      this.closed += 1;
    },
  };

  return { notification, action };
}

// --------------------------------------------------------------------------
// Core: structure
// --------------------------------------------------------------------------

test('the core is classic-script safe and exposes its version', () => {
  assert.doesNotMatch(coreSource, /^\s*(import|export)\s/m);
  assert.doesNotMatch(coreSource.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, ''), /importScripts\s*\(/);
  assert.match(coreSource, /const MBFD_WORKER_CORE_VERSION = '\d{4}-\d{2}-\d{2}\.\d+';/);
  assert.ok(coreSource.includes('use strict'));
  new vm.Script(coreSource);
});

test('the core wires exactly push, notificationclick, pushsubscriptionchange and message', () => {
  const { listeners } = createWorld();

  assert.deepEqual(Object.keys(listeners).sort(), ['message', 'notificationclick', 'push', 'pushsubscriptionchange']);
  for (const handlers of Object.values(listeners)) {
    assert.equal(handlers.length, 1);
  }
});

// --------------------------------------------------------------------------
// Core: push never goes silent
// --------------------------------------------------------------------------

const SILENT_RISK_PAYLOADS = {
  'no data at all': undefined,
  'empty string': '',
  'whitespace': '   \n ',
  'garbled json': '{"title": "Half a payload',
  'plain text': 'Engine 5 is out of service',
  'json null': 'null',
  'json number': '42',
  'json array': '[1,2,3]',
  'empty object': '{}',
  'unreadable data': new Error('data.text() blew up'),
  'declarative without navigate': '{"web_push":8030,"notification":{"title":"Declarative only"}}',
  'declarative with garbage notification': '{"web_push":8030,"notification":"nope"}',
  'wrong field types': '{"title":{"a":1},"body":[1],"data":"x","actions":"no","tag":7}',
};

for (const [label, payload] of Object.entries(SILENT_RISK_PAYLOADS)) {
  test(`a push with ${label} still shows exactly one notification inside waitUntil`, async () => {
    const world = createWorld();
    const { waited } = await world.dispatch('push', pushEvent(payload));

    assert.equal(waited, 1, 'showNotification must be wrapped in event.waitUntil');
    assert.equal(world.shown.length, 1);
    assert.ok(world.shown[0].title.length > 0);
    assert.ok(world.shown[0].options.tag.length > 0);
    assert.equal(world.shown[0].options.data.url.startsWith('/'), true);
  });
}

test('a rejected option set falls back to a minimal visible notification', async () => {
  const world = createWorld();
  world.world.showFailures = 1;
  await world.dispatch('push', pushEvent(JSON.stringify({ title: 'Alert', body: 'Body', icon: '/images/x.png' })));

  assert.equal(world.shown.length, 1);
  assert.equal(world.shown[0].title, 'Alert');
  assert.equal(world.shown[0].options.body, 'Body');
});

test('a push is shown even when Notification permission is unknown to the worker', async () => {
  const world = createWorld();
  assert.equal(world.sandbox.Notification, undefined);
  await world.dispatch('push', pushEvent(JSON.stringify({ title: 'No permission API in scope' })));

  assert.equal(world.shown.length, 1);
});

// --------------------------------------------------------------------------
// Core: payload formats
// --------------------------------------------------------------------------

test('the current app payload format is preserved', async () => {
  const world = createWorld();
  await world.dispatch('push', pushEvent(JSON.stringify({
    title: 'New request',
    body: 'Station 3 needs supplies',
    icon: '/images/mbfd-logo.png',
    badge: '/images/mbfd-logo.png',
    tag: 'mbfd-submission-supply',
    data: { url: `${ORIGIN}/admin/station-inspections/9?tab=x`, extra: 'kept' },
    actions: [{ action: 'view_alert', title: 'View Alert' }],
    requireInteraction: false,
  })));

  const { title, options } = world.shown[0];
  assert.equal(title, 'New request');
  assert.equal(options.body, 'Station 3 needs supplies');
  assert.equal(options.icon, '/images/mbfd-logo.png');
  assert.equal(options.badge, '/images/mbfd-logo.png');
  assert.equal(options.tag, 'mbfd-submission-supply');
  assert.equal(options.requireInteraction, false);
  assert.equal(options.data.url, '/admin/station-inspections/9?tab=x');
  assert.equal(options.data.extra, 'kept');
  assert.deepEqual(options.actions, [{ action: 'view_alert', title: 'View Alert' }]);
});

test('Declarative Web Push payloads are rendered through the same path', async () => {
  const world = createWorld();
  await world.dispatch('push', pushEvent(JSON.stringify({
    web_push: 8030,
    notification: { title: 'Declarative', body: 'From Safari format', navigate: `${ORIGIN}/updates/12`, tag: 'dwp-1' },
    mutable: false,
  })));

  const { title, options } = world.shown[0];
  assert.equal(title, 'Declarative');
  assert.equal(options.body, 'From Safari format');
  assert.equal(options.tag, 'dwp-1');
  assert.equal(options.data.url, '/updates/12');
});

test('plain text payloads become the body with defaults for everything else', async () => {
  const world = createWorld();
  await world.dispatch('push', pushEvent('Engine 5 is out of service'));

  assert.equal(world.shown[0].title, 'MBFD Hub Update');
  assert.equal(world.shown[0].options.body, 'Engine 5 is out of service');
  assert.equal(world.shown[0].options.icon, '/images/mbfd-logo.png');
  assert.equal(world.shown[0].options.badge, '/images/mbfd-logo.png');
  assert.equal(world.shown[0].options.data.url, '/');
});

test('untagged pushes dedupe by content while different alerts stack; explicit tags always win', async () => {
  const world = createWorld();
  const alert = (body) => pushEvent(JSON.stringify({ title: 'Alert', body, data: { url: '/a' } }));
  await world.dispatch('push', alert('same'));
  await world.dispatch('push', alert('same'));
  await world.dispatch('push', alert('different'));
  await world.dispatch('push', pushEvent(JSON.stringify({ title: 'Alert', body: 'same', tag: 'fixed' })));

  assert.equal(world.shown[0].options.tag, world.shown[1].options.tag);
  assert.notEqual(world.shown[0].options.tag, world.shown[2].options.tag);
  assert.equal(world.shown[3].options.tag, 'fixed');
});

test('cross-origin and scripted destinations and icons are neutralised', async () => {
  const world = createWorld();
  for (const url of ['https://evil.example/phish', '//evil.example/x', 'javascript:alert(1)', 'data:text/html,hi', '/\\evil.example']) {
    await world.dispatch('push', pushEvent(JSON.stringify({ title: 't', icon: 'https://evil.example/i.png', data: { url } })));
  }

  for (const { options } of world.shown) {
    assert.equal(options.data.url, '/', 'destination must fall back to the Hub home');
    assert.equal(options.icon, '/images/mbfd-logo.png', 'cross-origin icon must fall back');
  }
});

test('per-action destinations are carried in notification data', async () => {
  const world = createWorld();
  await world.dispatch('push', pushEvent(JSON.stringify({
    title: 'Inspection',
    data: { url: '/admin/inspections/1' },
    actions: [
      { action: 'review', title: 'Review', url: '/admin/inspections/1/review' },
      { action: 'evil', title: 'Evil', url: 'https://evil.example/' },
      { action: 'third', title: 'Dropped over the action limit' },
    ],
  })));

  const { options } = world.shown[0];
  assert.equal(options.actions.length, 2);
  assert.deepEqual(options.data.mbfdActionUrls, { review: '/admin/inspections/1/review', evil: '/' });
});

// --------------------------------------------------------------------------
// Core: notificationclick
// --------------------------------------------------------------------------

test('click focuses a window that already shows exactly the destination', async () => {
  const exact = new FakeClient(`${ORIGIN}/employee/forms?tab=ics#section`);
  const other = new FakeClient(`${ORIGIN}/daily/`, { focused: true, visibilityState: 'visible' });
  const world = createWorld({ clients: [other, exact] });
  const click = clickEvent({ data: { url: '/employee/forms?tab=ics' } });
  await world.dispatch('notificationclick', click);

  assert.equal(click.notification.closed, 1);
  assert.equal(exact.focusCalls, 1);
  assert.equal(other.focusCalls, 0);
  assert.deepEqual(exact.messages, []);
  assert.deepEqual(world.opened, []);
  assert.deepEqual(world.world.matchAllOptions, { type: 'window', includeUncontrolled: true });
});

test('click on another Hub window focuses it and asks the page to navigate, never navigating itself', async () => {
  const hidden = new FakeClient(`${ORIGIN}/admin/users`);
  const visible = new FakeClient(`${ORIGIN}/daily/`, { focused: true, visibilityState: 'visible', controlled: false });
  const world = createWorld({ clients: [hidden, visible] });
  await world.dispatch('notificationclick', clickEvent({ data: { url: `${ORIGIN}/employee/my-requests?x=1` } }));

  assert.equal(visible.focusCalls, 1, 'the focused, visible window is preferred');
  assert.equal(hidden.focusCalls, 0);
  assert.deepEqual(visible.messages, [{ type: 'MBFD_NAVIGATE', url: '/employee/my-requests?x=1' }]);
  assert.deepEqual(world.opened, []);
});

test('a page without an MBFD_NAVIGATE listener gets a new window instead of a dropped deep link', async () => {
  const silent = new FakeClient(`${ORIGIN}/daily/`, { acknowledges: false });
  const world = createWorld({ clients: [silent] });
  await world.dispatch('notificationclick', clickEvent({ data: { url: '/employee/forms' } }));

  assert.equal(silent.focusCalls, 1);
  assert.deepEqual(silent.messages, [{ type: 'MBFD_NAVIGATE', url: '/employee/forms' }]);
  assert.deepEqual(world.opened, ['/employee/forms']);
});

test('click opens a window when no Hub window exists', async () => {
  const foreign = new FakeClient('https://evil.example/hub', { focused: true, visibilityState: 'visible' });
  const world = createWorld({ clients: [foreign] });
  await world.dispatch('notificationclick', clickEvent({ data: { url: '/admin/chat' } }));

  assert.deepEqual(world.opened, ['/admin/chat']);
  assert.equal(foreign.focusCalls, 0);
  assert.deepEqual(foreign.messages, []);
});

test('click rejects cross-origin destinations', async () => {
  for (const url of ['https://evil.example/steal', '//evil.example/x', 'javascript:alert(1)', undefined, '']) {
    const world = createWorld({ clients: [] });
    await world.dispatch('notificationclick', clickEvent({ data: url === undefined ? {} : { url } }));

    assert.deepEqual(world.opened, ['/'], `destination ${String(url)} must collapse to the Hub home`);
  }
});

test('click survives missing notification data and a failing openWindow', async () => {
  const world = createWorld({ clients: [] });
  world.sandbox.self.clients.openWindow = async () => {
    throw new Error('InvalidAccessError');
  };
  const click = { notification: { data: null, close() {} }, action: '' };

  await assert.doesNotReject(world.dispatch('notificationclick', click));
});

test('action buttons use their own destination, fall back to the notification url, and dismiss does nothing', async () => {
  const data = { url: '/admin/inspections/1', mbfdActionUrls: { review: '/admin/inspections/1/review' } };

  const review = createWorld();
  await review.dispatch('notificationclick', clickEvent({ data, action: 'review' }));
  assert.deepEqual(review.opened, ['/admin/inspections/1/review']);

  const unknown = createWorld();
  await unknown.dispatch('notificationclick', clickEvent({ data, action: 'view_alert' }));
  assert.deepEqual(unknown.opened, ['/admin/inspections/1']);

  const dismissed = createWorld();
  const click = clickEvent({ data, action: 'dismiss' });
  await dismissed.dispatch('notificationclick', click);
  assert.equal(click.notification.closed, 1);
  assert.deepEqual(dismissed.opened, []);
});

// --------------------------------------------------------------------------
// Core: pushsubscriptionchange
// --------------------------------------------------------------------------

test('pushsubscriptionchange re-subscribes with the old key and posts the new subscription with credentials', async () => {
  const key = new Uint8Array([4, 1, 2, 3]).buffer;
  const world = createWorld({ cookies: { 'XSRF-TOKEN': 'abc%3D' } });
  await world.dispatch('pushsubscriptionchange', { oldSubscription: { options: { applicationServerKey: key } } });

  assert.equal(world.subscribeCalls.length, 1);
  assert.equal(world.subscribeCalls[0].userVisibleOnly, true);
  assert.equal(world.subscribeCalls[0].applicationServerKey, key);
  assert.equal(world.fetchCalls.length, 1);
  const [{ url, init }] = world.fetchCalls;
  assert.equal(url, '/api/push-subscriptions');
  assert.equal(init.method, 'POST');
  assert.equal(init.credentials, 'same-origin');
  assert.equal(init.headers['X-XSRF-TOKEN'], 'abc=');
  assert.deepEqual(JSON.parse(init.body), {
    endpoint: 'https://push.example.test/new',
    keys: { p256dh: 'p', auth: 'a' },
  });
});

test('pushsubscriptionchange posts a browser-provided newSubscription without subscribing again', async () => {
  const world = createWorld({ withCookieStore: false });
  const newSubscription = { toJSON: () => ({ endpoint: 'https://push.example.test/fresh', keys: { p256dh: 'p2', auth: 'a2' } }) };
  await world.dispatch('pushsubscriptionchange', { newSubscription });

  assert.equal(world.subscribeCalls.length, 0);
  assert.equal(world.fetchCalls.length, 1);
  assert.equal(JSON.parse(world.fetchCalls[0].init.body).endpoint, 'https://push.example.test/fresh');
});

test('pushsubscriptionchange never throws, whatever fails', async () => {
  const noKey = createWorld();
  await assert.doesNotReject(noKey.dispatch('pushsubscriptionchange', { oldSubscription: { options: {} } }));
  assert.equal(noKey.fetchCalls.length, 0);

  const noEvents = createWorld();
  await assert.doesNotReject(noEvents.dispatch('pushsubscriptionchange', {}));

  const offline = createWorld({ fetchImpl: async () => { throw new TypeError('offline'); } });
  await assert.doesNotReject(offline.dispatch('pushsubscriptionchange', {
    newSubscription: { toJSON: () => ({ endpoint: 'https://push.example.test/x', keys: { p256dh: 'p', auth: 'a' } }) },
  }));

  const rejectedSubscribe = createWorld();
  rejectedSubscribe.sandbox.self.registration.pushManager.subscribe = async () => { throw new Error('InvalidStateError'); };
  await assert.doesNotReject(rejectedSubscribe.dispatch('pushsubscriptionchange', {
    oldSubscription: { options: { applicationServerKey: new ArrayBuffer(4) } },
  }));
});

// --------------------------------------------------------------------------
// Core: message protocol
// --------------------------------------------------------------------------

test('SKIP_WAITING activates the waiting worker and GET_VERSION answers on the port', async () => {
  const world = createWorld();
  await world.dispatch('message', { source: { url: `${ORIGIN}/admin` }, data: { type: 'SKIP_WAITING' } });
  assert.equal(world.world.skipWaitingCalls, 1);

  const replies = [];
  await world.dispatch('message', { source: { url: `${ORIGIN}/admin` }, data: { type: 'GET_VERSION' }, ports: [{ postMessage: (reply) => replies.push(plain(reply)) }] });
  assert.deepEqual(replies, [{ type: 'MBFD_VERSION', build: 'test-build', core: '2026-10-23.2' }]);

  await assert.doesNotReject(world.dispatch('message', { source: { url: `${ORIGIN}/admin` }, data: 'not an object' }));
  await assert.doesNotReject(world.dispatch('message', { source: { url: `${ORIGIN}/admin` }, data: { type: 'SOMETHING_ELSE' } }));
  assert.equal(world.world.skipWaitingCalls, 1);
});

test('messages from another origin, or with no client source, are ignored', async () => {
  const world = createWorld();
  const replies = [];
  const port = { postMessage: (reply) => replies.push(plain(reply)) };

  for (const source of [undefined, null, {}, { url: 'https://evil.example/admin' }, { url: 'not a url' }]) {
    await world.dispatch('message', { source, data: { type: 'SKIP_WAITING' } });
    await world.dispatch('message', { source, data: { type: 'GET_VERSION' }, ports: [port] });
  }

  // A foreign event origin is refused even when the source looks local.
  await world.dispatch('message', { origin: 'https://evil.example', source: { url: `${ORIGIN}/admin` }, data: { type: 'SKIP_WAITING' } });

  assert.equal(world.world.skipWaitingCalls, 0);
  assert.deepEqual(replies, []);

  await world.dispatch('message', { origin: ORIGIN, source: { url: `${ORIGIN}/daily/` }, data: { type: 'SKIP_WAITING' } });
  assert.equal(world.world.skipWaitingCalls, 1, 'a same-origin client with a matching event origin drives the update');
  await world.dispatch('message', { source: { url: `${ORIGIN}/daily/` }, data: { type: 'SKIP_WAITING' } });
  assert.equal(world.world.skipWaitingCalls, 2, 'an engine that leaves event.origin empty falls back to the client URL');
});

test('GET_VERSION reports unknown when the worker declares no build id', async () => {
  const world = createWorld({ buildId: null });
  const replies = [];
  await world.dispatch('message', { source: { url: `${ORIGIN}/admin` }, data: { type: 'GET_VERSION' }, ports: [{ postMessage: (reply) => replies.push(plain(reply)) }] });

  assert.equal(replies[0].build, 'unknown');
});

// --------------------------------------------------------------------------
// Shipped workers: inlining, stable identity and preserved behavior
// --------------------------------------------------------------------------

test('every worker (root, admin, Daily template) contains exactly the current core between the markers', () => {
  assert.deepEqual(findDrift(root), []);
  for (const target of TARGETS) {
    const worker = read(target);
    assert.equal(worker.split(START_MARKER).length - 1, 1, `${target} start marker`);
    assert.equal(worker.split(END_MARKER).length - 1, 1, `${target} end marker`);
    assert.ok(worker.includes(coreSource.trimEnd()), `${target} must embed the core verbatim`);
    new vm.Script(worker);
  }
});

test('rendering is deterministic and idempotent, and refuses a worker without markers', () => {
  for (const target of TARGETS) {
    const worker = read(target);
    const once = renderWorker(worker, coreSource, target);
    assert.equal(once, worker.replace(/\r\n/g, '\n'));
    assert.equal(renderWorker(once, coreSource, target), once);
  }
  assert.throws(() => renderWorker('const x = 1;\n', coreSource, 'bare.js'), /exactly one/);
  assert.throws(() => renderWorker(`${END_MARKER}\n${START_MARKER}\n`, coreSource, 'swapped.js'), /wrong order/);
  assert.throws(() => renderWorker(`${START_MARKER}\n${END_MARKER}\n`, 'export const x = 1;\n', 'esm.js'), /import\/export|MBFD_WORKER_CORE_VERSION/);
});

test('the root worker keeps its identity: no fetch handler, skipWaiting on install, claim on activate', async () => {
  const worker = read('public/sw.js');
  assert.doesNotMatch(worker, /\?v=/);
  assert.doesNotMatch(worker, /addEventListener\(\s*['"]fetch['"]/);

  const world = createWorld({ source: worker });
  assert.deepEqual(
    Object.keys(world.listeners).sort(),
    ['activate', 'install', 'message', 'notificationclick', 'push', 'pushsubscriptionchange'],
  );
  await world.dispatch('install');
  assert.equal(world.world.skipWaitingCalls, 1);
  let claimed = 0;
  world.sandbox.self.clients.claim = async () => { claimed += 1; };
  await world.dispatch('activate');
  assert.equal(claimed, 1);

  await world.dispatch('push', pushEvent(undefined));
  assert.equal(world.shown.length, 1, 'the shipped root worker must also never go silent');
});

test('the admin worker keeps its caching strategy and cleans only mbfd-admin caches', async () => {
  const worker = read('public/admin-pwa/service-worker.js');
  assert.match(worker, /const VERSION = 'mbfd-admin-v4';/);
  assert.doesNotMatch(worker, /\?v=/);
  for (const prefix of ["'/build/'", "'/admin-pwa/'", "'/images/'", "'/fonts/'", "'/favicon.ico'"]) {
    assert.ok(worker.includes(prefix), `static prefix ${prefix} must remain`);
  }
  assert.ok(worker.includes("'/admin/login'") && worker.includes("'/livewire/'"), 'bypass list must remain');
  assert.ok(worker.includes("req.mode === 'navigate'"), 'navigations must stay unproxied');

  const deleted = [];
  const world = createWorld({ source: worker });
  assert.deepEqual(
    Object.keys(world.listeners).sort(),
    ['activate', 'fetch', 'install', 'message', 'notificationclick', 'push', 'pushsubscriptionchange'],
  );
  world.sandbox.caches.keys = async () => ['mbfd-admin-v3-static', 'mbfd-admin-v4-static', 'mbfd-checkout-v10', 'other'];
  world.sandbox.caches.delete = async (name) => { deleted.push(name); return true; };
  world.sandbox.self.clients.claim = async () => {};
  await world.dispatch('activate');
  assert.deepEqual(deleted, ['mbfd-admin-v3-static']);

  await world.dispatch('push', pushEvent('{"title":"Admin push"}'));
  assert.equal(world.shown[0].title, 'Admin push');
});

test('shipped workers answer GET_VERSION with their own build id', async () => {
  const expected = {
    'public/sw.js': 'mbfd-root-push-2026-10-23.1',
    'public/admin-pwa/service-worker.js': 'mbfd-admin-v4',
    'resources/js/daily-checkout/public/service-worker.js': 'mbfd-checkout-v12',
  };
  for (const [target, build] of Object.entries(expected)) {
    const world = createWorld({ source: read(target) });
    const replies = [];
    await world.dispatch('message', { source: { url: `${ORIGIN}/admin` }, data: { type: 'GET_VERSION' }, ports: [{ postMessage: (reply) => replies.push(plain(reply)) }] });

    assert.deepEqual(replies, [{ type: 'MBFD_VERSION', build, core: '2026-10-23.2' }], target);
  }
});

test('a slow focus() cannot push openWindow past the browser user-activation window: the ack timer starts before focus and the total wait is capped (L4)', async () => {
  assert.match(coreSource, /var NAVIGATE_ACK_TIMEOUT_MS = 600;/, 'the wait is capped at 600 ms (Firefox allows ~1 s)');
  // Test timers are capped at 250 ms (see createWorld); focus takes 400 ms.
  const slow = new FakeClient(`${ORIGIN}/daily/`, { acknowledges: false, focusDelayMs: 400, visibilityState: 'visible', focused: true });
  const world = createWorld({ clients: [slow] });
  const started = Date.now();
  let openedAfterMs = null;
  world.sandbox.self.clients.openWindow = async (url) => {
    openedAfterMs = Date.now() - started;
    world.opened.push(url);
  };

  await world.dispatch('notificationclick', clickEvent({ data: { url: '/employee/forms' } }));

  assert.deepEqual(world.opened, ['/employee/forms']);
  assert.deepEqual(slow.messages, [{ type: 'MBFD_NAVIGATE', url: '/employee/forms' }], 'the page was asked without waiting for focus');
  assert.ok(openedAfterMs < 400, `openWindow ran ${openedAfterMs} ms after the click; waiting for a 400 ms focus first would be ~650 ms`);
});

test('a page that acknowledges while focus is still slow ends the click handling without opening a window (L4)', async () => {
  const slow = new FakeClient(`${ORIGIN}/daily/`, { acknowledges: true, focusDelayMs: 50 });
  const world = createWorld({ clients: [slow] });

  await world.dispatch('notificationclick', clickEvent({ data: { url: '/employee/forms' } }));

  assert.equal(slow.focusCalls, 1);
  assert.deepEqual(world.opened, []);
});

test('a focus() that never settles cannot hold the click handler open for long', async () => {
  const hanging = new FakeClient(`${ORIGIN}/daily/`, { acknowledges: true });
  hanging.focus = function () {
    this.focusCalls += 1;

    return new Promise(() => {});
  };
  const world = createWorld({ clients: [hanging] });
  const started = Date.now();

  await world.dispatch('notificationclick', clickEvent({ data: { url: '/employee/forms' } }));

  assert.ok(Date.now() - started < 1000);
  assert.deepEqual(world.opened, []);
});

test('the legacy admin worker URL is served with the scope header its /admin/ registration needs (L7)', () => {
  const server = read('server.php');
  assert.match(server, /\$uri === '\/admin-pwa\/service-worker\.js'[\s\S]{0,80}header\('Service-Worker-Allowed: \/admin\/'\)/);
  assert.match(server, /\$serviceWorkers = \['\/sw\.js', '\/admin-pwa\/service-worker\.js', '\/daily\/sw\.js', '\/daily\/service-worker\.js'\];/);
  // The same header on the Laravel routes for both admin worker URLs stays.
  const routes = read('routes/web.php');
  assert.equal((routes.match(/'Service-Worker-Allowed' => '\/admin\/'/g) ?? []).length, 2);
});