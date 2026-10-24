import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

/**
 * Daily Checkout's page-side PWA wiring (resources/js/daily-checkout/src/pwa):
 * busy reasons for drafts and the offline queue, and the per-member cache purge.
 * The modules are plain TypeScript with no framework imports, so they run here
 * against small fakes of window, localStorage, the service-worker container and
 * Cache Storage. IndexedDB is replaced by a trap: any access fails the test.
 */

// busy.ts keeps its registry on window; a bare EventTarget is enough.
globalThis.window = new EventTarget();
const documentTarget = new EventTarget();
documentTarget.visibilityState = 'visible';
globalThis.document = documentTarget;
Object.defineProperty(globalThis, 'indexedDB', {
  configurable: true,
  get() {
    throw new Error('IndexedDB must never be touched by the cache purge or the busy bridges');
  },
});

const { busyReasons, isBusy } = await import('../../resources/js/pwa/busy.ts');
const { hasUnsavedWork, resetUnsavedWork, setUnsavedWork } = await import('../../resources/js/daily-checkout/src/pwa/draftBusy.ts');
const { startQueueBusyWatcher } = await import('../../resources/js/daily-checkout/src/pwa/queueBusy.ts');
const {
  API_CACHE_PREFIX,
  MARKER_STORAGE_KEY,
  noteAuthenticatedUser,
  purgeDailyUserCaches,
  purgeOnSessionEnd,
  resetUserCacheState,
} = await import('../../resources/js/daily-checkout/src/pwa/userCaches.ts');

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

// ---------------------------------------------------------------------------
// Unsaved-draft busy reason
// ---------------------------------------------------------------------------

test('unsaved-draft stays busy until every source is clean, so one source finishing never frees another', () => {
  resetUnsavedWork();
  const inspection = Symbol('inspection');
  const composer = Symbol('composer');

  setUnsavedWork(inspection, true);
  setUnsavedWork(composer, true);
  assert.deepEqual(busyReasons(), ['unsaved-draft']);

  setUnsavedWork(inspection, false);
  assert.equal(isBusy(), true, 'the composer still holds a message');
  assert.equal(hasUnsavedWork(), true);

  setUnsavedWork(composer, false);
  assert.equal(isBusy(), false);
  assert.equal(hasUnsavedWork(), false);
});

test('marking the same source dirty twice and clean once leaves the page free', () => {
  resetUnsavedWork();
  const token = Symbol('autosave');
  setUnsavedWork(token, true);
  setUnsavedWork(token, true);
  setUnsavedWork(token, false);

  assert.equal(isBusy(), false);
});

// ---------------------------------------------------------------------------
// Queued-submission busy reason
// ---------------------------------------------------------------------------

function queueHarness(initial = 0) {
  const state = { pending: initial, listeners: new Set(), calls: 0 };
  const stop = startQueueBusyWatcher({
    countPending: async () => {
      state.calls += 1;

      return state.pending;
    },
    subscribe: (listener) => {
      state.listeners.add(listener);

      return () => state.listeners.delete(listener);
    },
  });

  return { state, stop, changed: () => state.listeners.forEach((listener) => listener()) };
}

test('queued-submission is held while records wait and released the moment the queue drains', async () => {
  resetUnsavedWork();
  const harness = queueHarness(2);
  await flush();
  assert.deepEqual(busyReasons(), ['queued-submission']);

  harness.state.pending = 0;
  harness.changed();
  await flush();
  assert.equal(isBusy(), false);

  harness.state.pending = 1;
  window.dispatchEvent(new Event('online'));
  await flush();
  assert.deepEqual(busyReasons(), ['queued-submission']);

  harness.stop();
  assert.equal(isBusy(), false, 'stopping the watcher releases the reason');
});

test('a queue that is already empty never marks the page busy', async () => {
  const harness = queueHarness(0);
  await flush();
  assert.equal(isBusy(), false);
  harness.stop();
});

test('a slow older count cannot overrule a newer one', async () => {
  const resolvers = [];
  const stop = startQueueBusyWatcher({
    countPending: () => new Promise((resolve, reject) => resolvers.push({ resolve, reject })),
    subscribe: () => () => undefined,
  });
  // First (initial) count is still running when a second one starts.
  window.dispatchEvent(new Event('online'));
  assert.equal(resolvers.length, 2);

  resolvers[1].resolve(0); // newer: empty
  await flush();
  resolvers[0].resolve(3); // older and slower: must be ignored
  await flush();
  assert.equal(isBusy(), false);
  stop();
});

test('a count that cannot be read keeps the page busy (the safe answer) until it succeeds, and a run of failures releases it', async () => {
  let attempt = 0;
  const outcomes = [];
  const stop = startQueueBusyWatcher({
    countPending: () => {
      attempt += 1;
      return outcomes.length > 0 ? outcomes.shift()() : Promise.resolve(0);
    },
    subscribe: () => () => undefined,
  });
  await flush();
  assert.equal(isBusy(), false, 'a readable, empty queue is not busy');

  // IndexedDB fails once: unknown, so busy; the next successful count clears it.
  outcomes.push(() => Promise.reject(new Error('IndexedDB unavailable')));
  window.dispatchEvent(new Event('online'));
  await flush();
  assert.deepEqual(busyReasons(), ['queued-submission'], 'unknown is treated as busy');
  window.dispatchEvent(new Event('online'));
  await flush();
  assert.equal(isBusy(), false);

  // IndexedDB stays unavailable: after three failures in a row there is evidently no queue to protect.
  for (let failure = 1; failure <= 4; failure += 1) {
    outcomes.push(() => Promise.reject(new Error('IndexedDB unavailable')));
    window.dispatchEvent(new Event('online'));
    await flush();
    assert.equal(isBusy(), failure <= 3, `failure ${failure}`);
  }
  assert.ok(attempt >= 7);
  stop();
});

test('returning to the app recounts the queue', async () => {
  const harness = queueHarness(0);
  await flush();
  harness.state.pending = 1;
  documentTarget.visibilityState = 'visible';
  documentTarget.dispatchEvent(new Event('visibilitychange'));
  await flush();
  assert.deepEqual(busyReasons(), ['queued-submission']);
  harness.stop();
});

// ---------------------------------------------------------------------------
// Per-member cache purge
// ---------------------------------------------------------------------------

function memoryStorage() {
  const values = new Map();

  return {
    values,
    getItem: (key) => (values.has(key) ? values.get(key) : null),
    setItem: (key, value) => values.set(key, String(value)),
  };
}

/** Cache Storage fake: name -> Set of request paths. */
function fakeCaches(initial = {}) {
  const stores = new Map(Object.entries(initial).map(([name, paths]) => [name, new Set(paths)]));

  return {
    stores,
    keys: async () => [...stores.keys()],
    delete: async (name) => stores.delete(name),
    open: async (name) => ({ delete: async (path) => stores.get(name)?.delete(path) ?? false }),
  };
}

/** A worker that answers the purge message like the real one (or stays silent). */
function fakeContainer({ answers = true, success = true, withController = true } = {}) {
  const received = [];
  const worker = {
    postMessage(message, transfer) {
      received.push(message);
      if (answers) transfer[0].postMessage({ type: 'USER_CACHES_PURGED', success });
    },
  };

  return {
    received,
    controller: withController ? worker : null,
    getRegistration: async () => (withController ? undefined : { active: worker }),
  };
}

function environment({ container = fakeContainer(), caches = fakeCaches(), storage = memoryStorage() } = {}) {
  let counter = 0;

  return {
    storage,
    serviceWorker: container,
    caches,
    randomHex: () => `0a1b2c3d4e5f60${(counter += 1).toString(16).padStart(2, '0')}`,
    digest: async (text) => `d${[...text].reduce((sum, char) => (sum * 31 + char.charCodeAt(0)) % 1_000_003, 7).toString(16).padStart(10, '0')}`,
    workerAnswerTimeoutMs: 40,
  };
}

test('the first member seen on a device is only remembered: nothing is purged, and no id is stored', async () => {
  resetUserCacheState();
  const caches = fakeCaches({ 'mbfd-api-cache-v8': ['/api/public/apparatuses', '/api/public/apparatuses/7/checklist'] });
  const env = environment({ caches });

  assert.equal(await noteAuthenticatedUser(4242, env), 'first');

  // This runs right after the member's first online visit stored the apparatus list and checklists;
  // purging then would leave the offline checkout empty on every upgraded device.
  assert.deepEqual(env.serviceWorker.received, [], 'no purge request on first sighting');
  assert.deepEqual([...caches.stores.get('mbfd-api-cache-v8')], ['/api/public/apparatuses', '/api/public/apparatuses/7/checklist']);
  const stored = env.storage.values.get(MARKER_STORAGE_KEY);
  assert.match(stored, /^[0-9a-f]{8,64}\.[0-9a-f]{8,64}$/);
  assert.ok(![...env.storage.values.values()].some((value) => value.includes('4242')), 'the member id must not appear in storage');
  assert.equal(env.storage.values.size, 1);
});

test('the same member again does not purge; a different member purges first and is remembered afterwards', async () => {
  resetUserCacheState();
  const env = environment();
  await noteAuthenticatedUser(1, env);
  const marker = env.storage.values.get(MARKER_STORAGE_KEY);

  assert.equal(await noteAuthenticatedUser(1, env), 'same');
  assert.equal(env.serviceWorker.received.length, 0, 'neither the first sighting nor the same member purges');
  assert.equal(env.storage.values.get(MARKER_STORAGE_KEY), marker);

  assert.equal(await noteAuthenticatedUser(2, env), 'switched');
  assert.equal(env.serviceWorker.received.length, 1);
  assert.notEqual(env.storage.values.get(MARKER_STORAGE_KEY), marker);

  assert.equal(await noteAuthenticatedUser(2, env), 'same', 'the new member is now the remembered one');
  assert.equal(env.serviceWorker.received.length, 1);
});

test('switching back to the earlier member purges again (the other member cached in between)', async () => {
  resetUserCacheState();
  const env = environment();
  await noteAuthenticatedUser(1, env);
  await noteAuthenticatedUser(2, env);

  assert.equal(await noteAuthenticatedUser(1, env), 'switched');
  assert.equal(env.serviceWorker.received.length, 2, 'one purge for each switch, none for the first sighting');
});

test('a failed purge is retried: the new member is not remembered until the old caches are really gone', async () => {
  resetUserCacheState();
  const caches = fakeCaches();
  const env = environment({ container: fakeContainer({ answers: false }), caches });
  await noteAuthenticatedUser(1, env);
  const marker = env.storage.values.get(MARKER_STORAGE_KEY);
  caches.keys = async () => { throw new Error('storage unavailable'); };

  assert.equal(await noteAuthenticatedUser(2, env), 'switched');
  assert.equal(env.storage.values.get(MARKER_STORAGE_KEY), marker, 'marker unchanged after a failed purge');

  env.caches = fakeCaches();
  assert.equal(await noteAuthenticatedUser(2, env), 'switched', 'the next identity check tries again');
  assert.notEqual(env.storage.values.get(MARKER_STORAGE_KEY), marker);
});

test('two identity checks racing on a switch purge once', async () => {
  resetUserCacheState();
  const env = environment();
  await noteAuthenticatedUser(1, env);

  const results = await Promise.all([noteAuthenticatedUser(2, env), noteAuthenticatedUser(2, env)]);

  assert.deepEqual(results, ['switched', 'same']);
  assert.equal(env.serviceWorker.received.length, 1, 'exactly one switch purge');
});

test('without usable storage nothing throws and nothing is purged', async () => {
  resetUserCacheState();
  const env = environment({ storage: { getItem: () => { throw new Error('denied'); }, setItem: () => { throw new Error('denied'); } } });

  assert.equal(await noteAuthenticatedUser(1, env), 'first');
  assert.equal(await noteAuthenticatedUser(2, env), 'first');
  assert.equal(env.serviceWorker.received.length, 0, 'without a stored marker nothing is purged on every check');
});

test('the worker is asked first; without an answer the page removes the API caches itself and leaves the shell and everything else alone', async () => {
  const caches = fakeCaches({
    'mbfd-api-cache-v8': ['/api/public/stations'],
    'mbfd-api-cache-v7': ['/api/public/stations'],
    'mbfd-checkout-v12': ['/daily/', '/daily/index.html', '/daily/assets/index-abc.js', '/manifest.json'],
    'mbfd-admin-v4-static': ['/build/app.js'],
    'unrelated': ['/x'],
  });

  assert.equal(await purgeDailyUserCaches(environment({ container: fakeContainer({ answers: false }), caches })), true);

  assert.deepEqual([...caches.stores.keys()].sort(), ['mbfd-admin-v4-static', 'mbfd-checkout-v12', 'unrelated']);
  assert.deepEqual([...caches.stores.get('mbfd-checkout-v12')].sort(), ['/daily/', '/daily/assets/index-abc.js', '/daily/index.html', '/manifest.json'],
    'the shell is only ever replaced by the worker, after the server has answered');
  assert.ok(API_CACHE_PREFIX.startsWith('mbfd-api-cache-'));
});

test('an answering worker is trusted and the page leaves Cache Storage alone', async () => {
  const caches = fakeCaches({ 'mbfd-api-cache-v8': ['/api/public/stations'] });
  const container = fakeContainer();

  assert.equal(await purgeDailyUserCaches(environment({ container, caches })), true);

  assert.deepEqual(container.received, [{ type: 'PURGE_USER_CACHES' }]);
  assert.equal(caches.stores.has('mbfd-api-cache-v8'), true, 'the worker does the deleting');
});

test('an installed worker that is not yet controlling the page is found through its /daily/ registration', async () => {
  const container = fakeContainer({ withController: false });

  assert.equal(await purgeDailyUserCaches(environment({ container })), true);
  assert.equal(container.received.length, 1);
});

test('a worker that reports failure falls back to the page, and no worker at all is fine', async () => {
  const caches = fakeCaches({ 'mbfd-api-cache-v8': ['/api/public/stations'] });
  assert.equal(await purgeDailyUserCaches(environment({ container: fakeContainer({ success: false }), caches })), true);
  assert.equal(caches.stores.size, 0);

  const bare = { ...environment(), serviceWorker: null, caches: null };
  assert.equal(await purgeDailyUserCaches(bare), true);
});

test('the controller is messaged synchronously, so the purge survives an immediate navigation to sign-in', () => {
  const container = fakeContainer();
  const pending = purgeDailyUserCaches(environment({ container }));

  assert.equal(container.received.length, 1, 'posted before the first await');
  return pending;
});

test('a lapsed session purges once per signed-out episode, and a confirmed member re-arms it', async () => {
  resetUserCacheState();
  const env = environment();

  await purgeOnSessionEnd(env);
  await purgeOnSessionEnd(env);
  assert.equal(env.serviceWorker.received.length, 1, 'a replay timer that keeps seeing 401 must not purge repeatedly');

  await noteAuthenticatedUser(1, env); // a confirmed member: the signed-out episode is over (and the first sighting purges nothing)
  assert.equal(env.serviceWorker.received.length, 1);
  await purgeOnSessionEnd(env);
  assert.equal(env.serviceWorker.received.length, 2);
});

// ---------------------------------------------------------------------------
// Edits the DOM comparison cannot see: rich-text editors, file pickers (M5)
// ---------------------------------------------------------------------------

const {
  clearFieldEdits,
  hasRecordedFieldEdits,
  hasUnsavedFieldEdits,
  recordFieldEdit,
  startFieldEditTracking,
} = await import('../../resources/js/pwa/busy.ts');

/** A tiny element: attributes drive closest(), parent gives the chain. */
function fakeElement({ tagName = 'DIV', attrs = {}, parent = null, type, files, isConnected = true, isContentEditable = false } = {}) {
  const element = {
    tagName,
    type,
    files,
    isConnected,
    isContentEditable,
    parent,
    attrs,
    getAttribute: (name) => attrs[name] ?? null,
    contains: (other) => {
      for (let node = other; node; node = node.parent) if (node === element) return true;
      return false;
    },
    closest(selector) {
      for (let node = element; node; node = node.parent) {
        const a = node.attrs;
        const ignored = a.role === 'search' || 'data-mbfd-ignore-edits' in a;
        const editable = (a.contenteditable !== undefined && a.contenteditable !== 'false') || a.role === 'textbox';
        if (selector.includes('role="search"') && ignored) return node;
        if (selector.includes('contenteditable') && editable) return node;
      }
      return null;
    },
  };

  return element;
}

const root = (...children) => ({ querySelectorAll: () => [], contains: (element) => children.some((child) => child.contains(element)) });
const trusted = (target) => ({ isTrusted: true, target });

test('typing into a rich-text editor (contenteditable, text mirrored into a hidden input) marks the page as having unsaved edits', () => {
  clearFieldEdits();
  const editor = fakeElement({ tagName: 'TRIX-EDITOR', attrs: { contenteditable: 'true' }, isContentEditable: true });
  const inner = fakeElement({ tagName: 'DIV', parent: editor });

  assert.equal(hasUnsavedFieldEdits(root(editor)), false, 'nothing typed yet');
  recordFieldEdit(trusted(inner));

  assert.equal(hasRecordedFieldEdits(root(editor)), true);
  assert.equal(hasUnsavedFieldEdits(root(editor)), true, 'the DOM comparison alone would have said false: there is no control to compare');
  clearFieldEdits();
});

test('only trusted user input counts: the events a script dispatches (Trix mirroring into its hidden input) never mark the page', () => {
  clearFieldEdits();
  const editor = fakeElement({ tagName: 'DIV', attrs: { contenteditable: 'true' }, isContentEditable: true });
  const hidden = fakeElement({ tagName: 'INPUT', type: 'hidden' });

  recordFieldEdit({ isTrusted: false, target: editor });
  recordFieldEdit({ isTrusted: false, target: hidden });

  assert.equal(hasRecordedFieldEdits(root(editor, hidden)), false);
});

test('a chosen file counts until the input is emptied or leaves the page', () => {
  clearFieldEdits();
  const input = fakeElement({ tagName: 'INPUT', type: 'file', files: { length: 1 } });

  recordFieldEdit(trusted(input));
  assert.equal(hasRecordedFieldEdits(root(input)), true);

  input.files = { length: 0 }; // the form was reset or the app cleared the picker after sending
  assert.equal(hasRecordedFieldEdits(root(input)), false, 'an emptied picker heals itself');

  const second = fakeElement({ tagName: 'INPUT', type: 'file', files: { length: 2 } });
  recordFieldEdit(trusted(second));
  second.isConnected = false; // the screen was replaced
  assert.equal(hasRecordedFieldEdits(root(second)), false, 'an element that left the page stops counting');
});

test('search boxes and opted-out areas are ignored, and ordinary controls stay with the DOM comparison', () => {
  clearFieldEdits();
  const search = fakeElement({ attrs: { role: 'search' } });
  const searchEditor = fakeElement({ attrs: { contenteditable: 'true' }, parent: search, isContentEditable: true });
  const optedOut = fakeElement({ attrs: { 'data-mbfd-ignore-edits': '' } });
  const optedOutFile = fakeElement({ tagName: 'INPUT', type: 'file', files: { length: 1 }, parent: optedOut });
  const textarea = fakeElement({ tagName: 'TEXTAREA' });
  const select = fakeElement({ tagName: 'SELECT' });

  for (const target of [searchEditor, optedOutFile, textarea, select, null, {}]) recordFieldEdit(trusted(target));

  assert.equal(hasRecordedFieldEdits(root(search, optedOut, textarea, select)), false);
});

test('recorded edits can be limited to the page part that is asked about', () => {
  clearFieldEdits();
  const here = fakeElement({ attrs: { contenteditable: 'true' } });
  const elsewhere = fakeElement({ attrs: { contenteditable: 'true' } });
  recordFieldEdit(trusted(here));

  assert.equal(hasRecordedFieldEdits(root(here)), true);
  assert.equal(hasRecordedFieldEdits(root(elsewhere)), false);
  clearFieldEdits();
});

test('tracking listens in the capture phase on the document, clears on Livewire saves (not polls) and on navigation, and is installed once', () => {
  clearFieldEdits();
  const listeners = [];
  const original = documentTarget.addEventListener;
  documentTarget.addEventListener = (type, handler, options) => listeners.push({ type, handler, options });
  const livewireHooks = [];
  window.Livewire = { hook: (name, callback) => livewireHooks.push({ name, callback }) };
  delete window.__mbfdFieldTracking;
  try {
    startFieldEditTracking();
    startFieldEditTracking();
  } finally {
    documentTarget.addEventListener = original;
  }

  const capturing = (type) => listeners.filter((entry) => entry.type === type && entry.options === true);
  assert.equal(capturing('input').length, 1, 'one capturing input listener, however often it is started');
  assert.equal(capturing('change').length, 1);
  assert.equal(livewireHooks.length, 1);
  assert.equal(livewireHooks[0].name, 'commit');

  const editor = fakeElement({ attrs: { contenteditable: 'true' }, isContentEditable: true });
  const markEdited = () => {
    recordFieldEdit(trusted(editor));
    assert.equal(hasRecordedFieldEdits(root(editor)), true);
  };

  // A poll or refresh is not a save: the edits stay.
  markEdited();
  let succeeded;
  livewireHooks[0].callback({ commit: { calls: [{ method: '$refresh' }] }, succeed: (callback) => { succeeded = callback; } });
  assert.equal(succeeded, undefined, 'no success hook is even registered for a refresh');
  assert.equal(hasRecordedFieldEdits(root(editor)), true);

  // A real action that succeeds is a save: the edits are cleared.
  livewireHooks[0].callback({ commit: { calls: [{ method: 'save' }] }, succeed: (callback) => { succeeded = callback; } });
  assert.equal(hasRecordedFieldEdits(root(editor)), true, 'not before the server answered');
  succeeded();
  assert.equal(hasRecordedFieldEdits(root(editor)), false);

  // Livewire navigation clears too.
  markEdited();
  listeners.find((entry) => entry.type === 'livewire:navigated').handler();
  assert.equal(hasRecordedFieldEdits(root(editor)), false);

  // A form handed to the browser (not prevented) clears its own edits only.
  const form = fakeElement({ tagName: 'FORM' });
  const inForm = fakeElement({ attrs: { contenteditable: 'true' }, parent: form, isContentEditable: true });
  const outside = fakeElement({ attrs: { contenteditable: 'true' }, isContentEditable: true });
  recordFieldEdit(trusted(inForm));
  recordFieldEdit(trusted(outside));
  const submit = listeners.find((entry) => entry.type === 'submit').handler;
  submit({ defaultPrevented: true, target: form });
  assert.equal(hasRecordedFieldEdits(root(inForm)), true, 'a script-handled submit says nothing about success');
  submit({ defaultPrevented: false, target: form });
  assert.equal(hasRecordedFieldEdits(root(inForm)), false);
  assert.equal(hasRecordedFieldEdits(root(outside)), true);

  delete window.Livewire;
  clearFieldEdits();
});

test('the explicit busy reasons are untouched by field tracking', () => {
  clearFieldEdits();
  resetUnsavedWork();
  const token = Symbol('draft');
  setUnsavedWork(token, true);
  assert.deepEqual(busyReasons(), ['unsaved-draft']);
  setUnsavedWork(token, false);
  assert.equal(isBusy(), false);
});

// ---------------------------------------------------------------------------
// Lazy-chunk failure recovery (M4)
// ---------------------------------------------------------------------------

const { chunkUrlFromPreloadError, decideChunkRecovery, isMissingStatus } = await import('../../resources/js/pwa/chunk-recovery.ts');
const registrarSource = readFileSync(resolve(process.cwd(), 'resources/js/pwa/update-registrar.ts'), 'utf8');
const A = 'aaaaaaaaaaaa';
const B = 'bbbbbbbbbbbb';

test('a failed chunk never reloads when the release probe did not answer (offline), whatever else is true', () => {
  for (const chunkMissing of [true, false]) {
    for (const baselineRelease of [A, null]) {
      assert.equal(decideChunkRecovery({ latestRelease: null, baselineRelease, chunkMissing, mayReload: true }), 'offline');
    }
  }
});

test('a failed chunk reloads only on positive evidence: a different live release, or a chunk the server says is gone', () => {
  assert.equal(decideChunkRecovery({ latestRelease: B, baselineRelease: A, chunkMissing: false, mayReload: true }), 'reload');
  assert.equal(decideChunkRecovery({ latestRelease: A, baselineRelease: A, chunkMissing: true, mayReload: true }), 'reload');
  assert.equal(decideChunkRecovery({ latestRelease: A, baselineRelease: A, chunkMissing: false, mayReload: true }), 'banner', 'same release, chunk not proven gone: a blip, not a reason to reload');
  assert.equal(decideChunkRecovery({ latestRelease: A, baselineRelease: null, chunkMissing: false, mayReload: true }), 'banner');
});

test('a busy, already-reloaded or unguardable page gets the banner instead of a reload', () => {
  assert.equal(decideChunkRecovery({ latestRelease: B, baselineRelease: A, chunkMissing: true, mayReload: false }), 'banner');
});

test('the chunk URL is read from the browser error message and must be same-origin', () => {
  const origin = 'https://hub.example.test';
  assert.equal(chunkUrlFromPreloadError({ payload: new Error('Failed to fetch dynamically imported module: https://hub.example.test/build/assets/Chart-abc123.js') }, origin),
    'https://hub.example.test/build/assets/Chart-abc123.js');
  assert.equal(chunkUrlFromPreloadError({ payload: { message: 'error loading dynamically imported module: https://hub.example.test/daily/assets/Page-9f.js?x=1' } }, origin),
    'https://hub.example.test/daily/assets/Page-9f.js?x=1');
  assert.equal(chunkUrlFromPreloadError({ payload: new Error('Unable to preload CSS for /build/assets/app-abc.css') }, origin),
    'https://hub.example.test/build/assets/app-abc.css');
  assert.equal(chunkUrlFromPreloadError({ payload: new Error('Importing a module script failed.') }, origin), null, 'Safari names nothing');
  assert.equal(chunkUrlFromPreloadError({ payload: new Error('Failed to fetch dynamically imported module: https://evil.example/x.js') }, origin), null);
  assert.equal(chunkUrlFromPreloadError({ payload: new Error('Failed to fetch dynamically imported module: javascript:alert(1)') }, origin), null);
  assert.equal(chunkUrlFromPreloadError({}, origin), null);
  assert.equal(chunkUrlFromPreloadError(null, origin), null);
  assert.equal(isMissingStatus(404), true);
  assert.equal(isMissingStatus(410), true);
  for (const status of [200, 304, 403, 500, 502, 503]) assert.equal(isMissingStatus(status), false, String(status));
});

test('the registrar reloads for a chunk error only through the decision, and says plainly when the device is offline', () => {
  const recovery = registrarSource.slice(registrarSource.indexOf('async function recoverFromChunkError'), registrarSource.indexOf('async function isChunkMissing'));
  assert.match(registrarSource, /const MESSAGE_OFFLINE = "You're offline\. Some screens will load when you're back online\.";/);
  assert.match(recovery, /decideChunkRecovery\(/);
  assert.equal((recovery.match(/applyAndReload\(/g) ?? []).length, 1, 'one reload site');
  assert.ok(recovery.indexOf("action === 'offline'") < recovery.indexOf('applyAndReload('), 'the offline branch returns before any reload');
  assert.match(recovery, /if \(action === 'reload'\)\s*\{\s*await applyAndReload/);
  assert.ok(!/latest \?\? pending/.test(recovery.slice(recovery.indexOf('decideChunkRecovery(')) ), 'a failed probe is never replaced by a guessed release');
});