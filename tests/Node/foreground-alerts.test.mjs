import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
  CLAIM_FALLBACK_MS,
  MAX_AGE_MS,
  MAX_POLL_BACKOFF_MS,
  MIN_POLL_INTERVAL_MS,
  SHOWN_KEY,
  TOAST_STYLE,
  classifyFeedResponse,
  createAlertTracker,
  createClaimCoordinator,
  createLeaderLease,
  createShownRegistry,
  nextPollDelay,
  parseSnapshot,
  pickClaimWinner,
  planToastStack,
  safeSameOriginUrl,
} from '../../resources/js/pwa/foreground-alerts.ts';
import {
  SOUND_KEY,
  SOUND_NOTE,
  readSoundPreference,
  shouldPlaySound,
  writeSoundPreference,
} from '../../resources/js/pwa/alert-sound.ts';
import { describeTestDevice, describeTestResult } from '../../resources/js/pwa/test-push-result.ts';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (path) => readFileSync(resolve(root, path), 'utf8');

const T0 = Date.UTC(2026, 9, 30, 12, 0, 0);
const alert = (id, created, extra = {}) => ({ id, title: `Alert ${id}`, body: '', url: null, urgent: false, unread: true, created, ...extra });
const snap = (now, alerts) => ({ now, alerts });

class MemoryStorage {
  constructor() { this.map = new Map(); }
  getItem(key) { return this.map.has(key) ? this.map.get(key) : null; }
  setItem(key, value) { this.map.set(key, String(value)); }
  removeItem(key) { this.map.delete(key); }
}

/** BroadcastChannel stand-in: every channel on the same bus hears the others (never itself), asynchronously. */
function bus() {
  const channels = new Set();
  return () => {
    const listeners = [];
    const channel = {
      postMessage(message) {
        for (const other of channels) if (other !== channel) setTimeout(() => other.listeners.forEach((l) => l({ data: structuredClone(message) })), 1);
      },
      addEventListener(_type, listener) { listeners.push(listener); },
      listeners,
    };
    channels.add(channel);
    return channel;
  };
}

// --- baseline: nothing historical is ever announced -------------------------------------------

test('the first snapshot is the baseline: unread alerts already in the bell are never announced', () => {
  const tracker = createAlertTracker();
  assert.deepEqual(tracker.ingest(snap(T0, [alert('b', T0 - 60_000), alert('a', T0 - 3_600_000)])), []);
  assert.equal(tracker.ready, true);
  // The same alerts on the next poll are still not new.
  assert.deepEqual(tracker.ingest(snap(T0 + 30_000, [alert('b', T0 - 60_000), alert('a', T0 - 3_600_000)])), []);
});

test('a genuinely new unread alert is announced once, and only once per page', () => {
  const tracker = createAlertTracker();
  tracker.ingest(snap(T0, [alert('a', T0 - 60_000)]));
  const fresh = tracker.ingest(snap(T0 + 30_000, [alert('c', T0 + 20_000), alert('a', T0 - 60_000)]));
  assert.deepEqual(fresh.map((a) => a.id), ['c']);
  assert.deepEqual(tracker.ingest(snap(T0 + 60_000, [alert('c', T0 + 20_000), alert('a', T0 - 60_000)])), []);
});

test('several new alerts come out oldest first, so the newest ends on top of the stack', () => {
  const tracker = createAlertTracker();
  tracker.ingest(snap(T0, []));
  const fresh = tracker.ingest(snap(T0 + 30_000, [alert('z', T0 + 25_000), alert('y', T0 + 10_000)]));
  assert.deepEqual(fresh.map((a) => a.id), ['y', 'z']);
});

test('an older alert that surfaces later (after deletions, or the next page of the bell) is not new', () => {
  const tracker = createAlertTracker();
  tracker.ingest(snap(T0, [alert('newest', T0 - 1_000)]));
  assert.deepEqual(tracker.ingest(snap(T0 + 30_000, [alert('old-51st', T0 - 86_400_000)])), []);
});

test('a read alert, or one older than 15 minutes when first seen, is left to the bell', () => {
  const tracker = createAlertTracker();
  tracker.ingest(snap(T0, []));
  assert.deepEqual(tracker.ingest(snap(T0 + 30_000, [alert('read', T0 + 10_000, { unread: false })])), []);
  const later = T0 + MAX_AGE_MS + 120_000;
  assert.deepEqual(tracker.ingest(snap(later, [alert('stale', T0 + 1_000)])), []);
});

test('an empty bell baseline allows an alert written moments before the page rendered', () => {
  const tracker = createAlertTracker();
  tracker.ingest(snap(T0, []));
  assert.deepEqual(tracker.ingest(snap(T0 + 30_000, [alert('race', T0 - 2_000)])).map((a) => a.id), ['race']);
  const strict = createAlertTracker();
  strict.ingest(snap(T0, []));
  assert.deepEqual(strict.ingest(snap(T0 + 30_000, [alert('older', T0 - 60_000)])), []);
});

test('stored times are whole seconds: a new alert in the same second as the newest baseline one still counts', () => {
  const tracker = createAlertTracker();
  tracker.ingest(snap(T0, [alert('a', T0)]));
  assert.deepEqual(tracker.ingest(snap(T0 + 30_000, [alert('b', T0), alert('a', T0)])).map((a) => a.id), ['b']);
});

test('marking an alert unread again never re-announces it', () => {
  const tracker = createAlertTracker();
  tracker.ingest(snap(T0, [alert('a', T0 - 5_000, { unread: false })]));
  assert.deepEqual(tracker.ingest(snap(T0 + 30_000, [alert('a', T0 - 5_000, { unread: true })])), []);
});

// --- parsing and link guard ----------------------------------------------------------------------

test('snapshots are validated; malformed entries are dropped and text is trimmed to one line', () => {
  assert.equal(parseSnapshot(null), null);
  assert.equal(parseSnapshot({ alerts: [] }), null);
  const parsed = parseSnapshot({ now: T0, alerts: [{ id: 'x', created: T0, title: 'Hi\nthere', body: 'b', url: '/a', urgent: true }, { id: 7 }, 'junk', { id: 'y' }] });
  assert.equal(parsed.alerts.length, 1);
  assert.equal(parsed.alerts[0].title, 'Hi there');
  assert.equal(parsed.alerts[0].urgent, true);
  assert.equal(parsed.alerts[0].unread, true);
  assert.equal(parseSnapshot({ now: T0, alerts: [{ id: 'q', created: T0 }] }).alerts[0].title, 'New alert');
});

test('"Open" only ever links inside the Hub', () => {
  const origin = 'https://www.mbfdhub.com';
  assert.equal(safeSameOriginUrl('/employee/messages?thread=4#m', origin), '/employee/messages?thread=4#m');
  assert.equal(safeSameOriginUrl('https://www.mbfdhub.com/admin/x', origin), '/admin/x');
  for (const bad of ['https://evil.example/x', '//evil.example/x', 'javascript:alert(1)', 'data:text/html,x', 'https://user:pw@www.mbfdhub.com/', '/\\evil', '', null, undefined, 'http://www.mbfdhub.com/x']) {
    assert.equal(safeSameOriginUrl(bad, origin), null, String(bad));
  }
});

// --- cross-tab: one tab announces ----------------------------------------------------------------

test('the focused tab wins a claim, then the visible one; ties go to the smallest tab id', () => {
  assert.equal(pickClaimWinner([{ tab: 'b', rank: 1 }, { tab: 'a', rank: 1 }]), 'a');
  assert.equal(pickClaimWinner([{ tab: 'a', rank: 1 }, { tab: 'z', rank: 2 }]), 'z');
  assert.equal(pickClaimWinner([]), null);
});

test('two tabs that see the same new alert together announce it exactly once', async () => {
  const channel = bus();
  const storage = new MemoryStorage();
  const tabs = ['tab-b', 'tab-a'].map((tab) => {
    const ch = channel();
    const coordinator = createClaimCoordinator({ channel: ch, registry: createShownRegistry(storage), tab, windowMs: 30, fallbackMs: 60 });
    ch.addEventListener('message', (event) => coordinator.receive(event.data));
    return coordinator;
  });
  const results = await Promise.all(tabs.map((c) => c.claim('alert-1', 1)));
  assert.deepEqual(results, [false, true], 'tab-a (smaller id, same rank) announces; tab-b stays quiet');
  assert.ok(JSON.parse(storage.getItem(SHOWN_KEY))['alert-1']);
});

test('a focused tab takes the alert over a merely visible one', async () => {
  const channel = bus();
  const storage = new MemoryStorage();
  const make = (tab) => {
    const ch = channel();
    const c = createClaimCoordinator({ channel: ch, registry: createShownRegistry(storage), tab, windowMs: 30, fallbackMs: 60 });
    ch.addEventListener('message', (event) => c.receive(event.data));
    return c;
  };
  const visible = make('aaa');
  const focused = make('zzz');
  assert.deepEqual(await Promise.all([visible.claim('x', 1), focused.claim('x', 2)]), [false, true]);
});

test('a tab that sees the alert later (or after a reload) stays quiet because it was already shown', async () => {
  const storage = new MemoryStorage();
  const first = createClaimCoordinator({ channel: null, registry: createShownRegistry(storage), tab: 'one' });
  assert.equal(await first.claim('a', 1), true);
  const reloaded = createClaimCoordinator({ channel: null, registry: createShownRegistry(storage), tab: 'two' });
  assert.equal(await reloaded.claim('a', 2), false);
});

test('if the winning tab closes mid-claim the other tab still announces after a short wait', async () => {
  const storage = new MemoryStorage();
  const ch = { postMessage() {}, addEventListener() {} };
  const coordinator = createClaimCoordinator({ channel: ch, registry: createShownRegistry(storage), tab: 'zzz', windowMs: 10, fallbackMs: 30 });
  coordinator.receive({ type: 'claim', id: 'a', tab: 'aaa', rank: 2 });
  const started = Date.now();
  assert.equal(await coordinator.claim('a', 1), true);
  assert.ok(Date.now() - started >= 30, 'it waited for the winner first');
  assert.ok(CLAIM_FALLBACK_MS <= 2_000);
});

test('the shown list survives broken storage and stays bounded', () => {
  const broken = { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); }, removeItem() {} };
  const registry = createShownRegistry(broken);
  registry.add('a');
  assert.equal(registry.has('a'), true, 'this tab still remembers');

  let now = T0;
  const storage = new MemoryStorage();
  const bounded = createShownRegistry(storage, () => now);
  for (let i = 0; i < 260; i++) { now += 1; bounded.add(`id-${i}`); }
  assert.ok(Object.keys(JSON.parse(storage.getItem(SHOWN_KEY))).length <= 200);
  now += 25 * 60 * 60_000;
  assert.equal(createShownRegistry(storage, () => now).has('id-259'), false, 'entries expire after a day');
});

// --- one poller per device -----------------------------------------------------------------------

test('only one tab holds the polling lease; it frees up when released or expired', () => {
  let now = T0;
  const storage = new MemoryStorage();
  const a = createLeaderLease(storage, 'a', 120_000, () => now);
  const b = createLeaderLease(storage, 'b', 120_000, () => now);
  assert.equal(a.tryAcquire(), true);
  assert.equal(b.tryAcquire(), false);
  assert.equal(a.tryAcquire(), true, 'the holder renews');
  a.release();
  assert.equal(b.tryAcquire(), true);
  now += 120_001;
  assert.equal(a.tryAcquire(), true, 'an abandoned lease expires');
  assert.equal(createLeaderLease(null, 'c', 1_000).tryAcquire(), true, 'without storage each visible tab polls for itself');
});

test('polling is never faster than 30 s, backs off on errors up to 15 minutes, and honours Retry-After', () => {
  const mid = () => 0.5;
  assert.equal(nextPollDelay({ baseMs: 5_000, random: mid }), MIN_POLL_INTERVAL_MS);
  assert.equal(nextPollDelay({ baseMs: 60_000, random: mid }), 60_000);
  assert.equal(nextPollDelay({ baseMs: 60_000, failures: 2, random: mid }), 240_000);
  assert.equal(nextPollDelay({ baseMs: 60_000, failures: 50, random: mid }), MAX_POLL_BACKOFF_MS);
  assert.equal(nextPollDelay({ baseMs: 60_000, failures: 1, retryAfterMs: 600_000, random: mid }), 600_000);
  const low = nextPollDelay({ baseMs: 60_000, random: () => 0 });
  const high = nextPollDelay({ baseMs: 60_000, random: () => 0.999 });
  assert.ok(low >= 54_000 && high <= 66_000 && low < high, 'jittered by about 10%');
});

test('signed out or no feed stops polling; server trouble backs off', () => {
  assert.equal(classifyFeedResponse(200), 'ok');
  for (const status of [401, 403, 404, 410]) assert.equal(classifyFeedResponse(status), 'stop');
  for (const status of [429, 500, 502, 503]) assert.equal(classifyFeedResponse(status), 'backoff');
});

// --- stack ---------------------------------------------------------------------------------------

test('the stack holds three; a duplicate refreshes instead of stacking', () => {
  assert.deepEqual(planToastStack(['a', 'b'], 'c'), { duplicate: false, evict: [] });
  assert.deepEqual(planToastStack(['a', 'b', 'c'], 'd'), { duplicate: false, evict: ['a'] });
  assert.deepEqual(planToastStack(['a', 'b', 'c'], 'b'), { duplicate: true, evict: [] });
});

// --- motion, accessibility and safety of the module itself ---------------------------------------

test('entrance motion only exists under prefers-reduced-motion: no-preference', () => {
  const motionBlock = TOAST_STYLE.match(/@media \(prefers-reduced-motion: no-preference\) \{([\s\S]*?\}\s*\}\s*)\}/);
  assert.ok(motionBlock, 'a no-preference block exists');
  const outside = TOAST_STYLE.replace(motionBlock[0], '');
  assert.doesNotMatch(outside, /animation|transition|@keyframes/);
});

test('every control is at least 44px and the toast never takes focus or marks anything read', () => {
  assert.match(TOAST_STYLE, /min-width: 44px; min-height: 44px/);
  const source = read('resources/js/pwa/foreground-alerts.ts');
  assert.doesNotMatch(source, /\.focus\(/, 'never moves focus');
  assert.doesNotMatch(source, /markAsRead|markNotificationAsRead|read_at|method: 'POST'|method: 'PATCH'/, 'never marks read');
  assert.match(source, /setAttribute\('role', 'status'\)/);
  assert.match(source, /setAttribute\('role', 'alert'\)/);
  assert.match(source, /'mbfd-alerts'/, 'BroadcastChannel name');
  assert.doesNotMatch(source, /serviceWorker|registration|showNotification/, 'no service worker involvement');
});

test('the sound never touches a service worker and the workers never gain a sound', () => {
  assert.doesNotMatch(read('resources/js/pwa/alert-sound.ts'), /serviceWorker|showNotification|registration/);
  assert.doesNotMatch(read('resources/js/pwa/worker-core.js'), /AudioContext|mbfd-alert-sound/);
});

// --- sound gating --------------------------------------------------------------------------------

test('the sound plays only when chosen, visible and unlocked by a gesture', () => {
  assert.equal(shouldPlaySound({ enabled: true, visible: true, audioState: 'running' }), true);
  assert.equal(shouldPlaySound({ enabled: false, visible: true, audioState: 'running' }), false);
  assert.equal(shouldPlaySound({ enabled: true, visible: false, audioState: 'running' }), false);
  assert.equal(shouldPlaySound({ enabled: true, visible: true, audioState: 'suspended' }), false);
  assert.equal(shouldPlaySound({ enabled: true, visible: true, audioState: null }), false);
});

test('the sound choice is per device, off by default, and survives unusable storage', () => {
  const storage = new MemoryStorage();
  assert.equal(readSoundPreference(storage), false, 'off by default');
  assert.equal(writeSoundPreference(true, storage), true);
  assert.equal(storage.getItem(SOUND_KEY), 'on');
  assert.equal(SOUND_KEY, 'mbfd-alert-sound');
  assert.equal(readSoundPreference(storage), true);
  assert.equal(writeSoundPreference(false, storage), true);
  assert.equal(readSoundPreference(storage), false);
  const broken = { getItem() { throw new Error('x'); }, setItem() { throw new Error('x'); }, removeItem() { throw new Error('x'); } };
  assert.equal(readSoundPreference(broken), false);
  assert.equal(writeSoundPreference(true, broken), false);
  assert.equal(writeSoundPreference(true, null), false);
});

test('the settings page offers the toggle, a test sound and the lock-screen note', () => {
  const view = read('resources/views/filament/pages/notification-settings.blade.php');
  assert.match(view, /data-alert-sound-settings/);
  assert.match(view, /Play a sound for new alerts while the Hub is open/);
  assert.match(view, /Play test sound/);
  assert.ok(view.includes(SOUND_NOTE), 'the exact lock-screen note');
  assert.doesNotMatch(view.match(/data-role="sound-toggle"[\s\S]*?>/)[0], /checked/, 'off unless the device says otherwise');
});

// --- honest test-notification wording ------------------------------------------------------------

test('a test result never claims delivery', () => {
  const accepted = describeTestResult(200, { success: true, devices: [{ label: 'iPhone', accepted: true, failure: null, outcome: 'accepted', service: 'Apple' }] });
  assert.match(accepted, /Accepted by Apple's push service/);
  assert.match(accepted, /Check this device/);
  assert.equal(describeTestResult(200, { devices: [{ summary: 'From the server.' }] }), 'From the server.');
  assert.match(describeTestDevice({ outcome: 'rejected', service: 'Google' }), /^Rejected by Google's push service/);
  assert.match(describeTestDevice({ outcome: 'expired' }), /set up again/);
  assert.match(describeTestDevice({ accepted: false, failure: 'push_service_error (503)' }), /Not accepted/);
  assert.match(describeTestDevice({ outcome: 'retrying', service: 'Mozilla' }), /try again on its own/);
  assert.match(describeTestResult(400, null), /set up again/);
  assert.match(describeTestResult(410, null), /set up again/);
  assert.match(describeTestResult(429, null), /wait a minute/);
  const two = describeTestResult(200, { devices: [{ label: 'Phone', accepted: true }, { label: 'Laptop', outcome: 'expired' }] });
  assert.match(two, /^Phone: Accepted/);
  assert.match(two, /Laptop: This device needs to be set up again/);
  for (const sample of [accepted, two, describeTestResult(200, {}), describeTestResult(500, null)]) {
    assert.doesNotMatch(sample, /deliver|sent to your phone|should appear/i);
  }
  for (const file of ['resources/js/pwa/push-enrollment.ts', 'app/Http/Controllers/Api/TestNotificationController.php']) {
    assert.doesNotMatch(read(file), /Test sent\. It should appear|Check your device for the notification|delivered to your phone/i, file);
  }
});
