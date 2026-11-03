import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import {
    MAX_BACKOFF_MS,
    POLL_INTERVAL_MS,
    describeChanges,
    failureNote,
    isAuthStop,
    nextDelay,
    survivingIds,
    toEntityTag,
    STALE_AFTER_MS,
    bodyHasBoard,
    classifyResponse,
    isStale,
    profileChanged,
    staleNote,
} from '../../resources/js/unit-status/board-state.js';

test('the refresh waits 30 seconds, then backs off by doubling up to five minutes', () => {
    assert.equal(POLL_INTERVAL_MS, 30_000);
    assert.deepEqual([0, 1, 2, 3, 4, 5, 9].map(nextDelay), [30_000, 60_000, 120_000, 240_000, 300_000, 300_000, 300_000]);
    assert.equal(MAX_BACKOFF_MS, 300_000);
    assert.equal(nextDelay(Number.NaN), 30_000);
});

test('401, 403 and 419 stop the refresh; other answers do not', () => {
    assert.deepEqual([401, 403, 419].map(isAuthStop), [true, true, true]);
    assert.deepEqual([200, 304, 404, 429, 500, 503].map(isAuthStop), [false, false, false, false, false, false]);
});

test('an entity tag is quoted once', () => {
    assert.equal(toEntityTag('abc'), '"abc"');
    assert.equal(toEntityTag('"abc"'), '"abc"');
    assert.equal(toEntityTag('W/"abc"'), 'W/"abc"');
    assert.equal(toEntityTag(''), null);
    assert.equal(toEntityTag(null), null);
});

test('only a real status or assignment change is announced', () => {
    const before = [
        { code: 'L1', status: 'Available', summary: 'No active call' },
        { code: 'R2', status: 'Dispatched', summary: 'Medical – 1 Main St' },
    ];
    assert.equal(describeChanges(before, before.map((unit) => ({ ...unit }))), '');
    assert.equal(
        describeChanges(before, [before[0], { code: 'R2', status: 'On Scene', summary: 'Medical – 1 Main St' }]),
        'Unit status updated. R2: On Scene.',
    );
    assert.equal(describeChanges([], before), '', 'the first board is never announced');
});

test('an announcement names at most three units and counts the rest', () => {
    const before = ['A', 'B', 'C', 'D', 'E'].map((code) => ({ code, status: 'Available', summary: '' }));
    const after = before.map((unit) => ({ ...unit, status: 'On Scene' }));
    assert.equal(describeChanges(before, after), 'Unit status updated. A: On Scene. B: On Scene. C: On Scene. And 2 more.');
});

test('rows that vanish in a refresh are not re-expanded', () => {
    assert.deepEqual(survivingIds(['unit-r2', 'unit-gone'], ['unit-l1', 'unit-r2']), ['unit-r2']);
});

test('the failure note names the last good time', () => {
    assert.equal(failureNote('4:58 PM ET'), "Couldn't refresh — showing data from 4:58 PM ET");
});

test('the page script refreshes quietly: no reload, no worker, no innerHTML from JSON', () => {
    const source = readFileSync('resources/js/unit-status.js', 'utf8');
    assert.ok(!/location\.reload/.test(source));
    assert.ok(!/serviceWorker/.test(source));
    assert.ok(!/pwa\/busy/.test(source));
    assert.ok(!/\.innerHTML\s*=/.test(source));
    assert.match(source, /visibilitychange/);
    assert.match(source, /AbortController/);
    assert.match(source, /If-None-Match/);
});

const T0 = Date.parse('2026-11-02T21:58:30Z');

test('a board is stale only after 150 seconds without confirmation', () => {
    assert.equal(STALE_AFTER_MS, 150_000);
    assert.equal(isStale('2026-11-02T21:58:30Z', Number.NaN, T0 + 150_000), false);
    assert.equal(isStale('2026-11-02T21:58:30Z', Number.NaN, T0 + 150_001), true);
    assert.equal(isStale('', Number.NaN, T0), true, 'no generated time means no trust');
    assert.equal(isStale('not a date', Number.NaN, T0), true);
});

test('a 304 confirmation keeps an unchanged board fresh', () => {
    assert.equal(isStale('2026-11-02T21:58:30Z', T0 + 200_000, T0 + 300_000), false);
    assert.equal(isStale('2026-11-02T21:58:30Z', T0 + 200_000, T0 + 351_000), true);
});

test('the stale note names the last good time', () => {
    assert.equal(staleNote('4:58 PM ET'), 'Not updated since 4:58 PM ET — availability not confirmed');
    assert.match(staleNote(''), /availability not confirmed/);
});

test('responses are classified: redirects, 401/403/419 and non-board bodies are auth stops', () => {
    assert.equal(classifyResponse({ status: 0, type: 'opaqueredirect' }), 'auth');
    assert.equal(classifyResponse({ status: 200, redirected: true, ok: true }), 'auth');
    assert.deepEqual([401, 403, 419].map((status) => classifyResponse({ status })), ['auth', 'auth', 'auth']);
    assert.equal(classifyResponse({ status: 304 }), 'not_modified');
    assert.equal(classifyResponse({ status: 200, ok: true }), 'ok');
    assert.deepEqual([404, 429, 500].map((status) => classifyResponse({ status })), ['error', 'error', 'error']);
    assert.equal(bodyHasBoard('<div class="us-board" data-us-board data-etag="x"></div>'), true);
    assert.equal(bodyHasBoard('<html><form action="/login"></form></html>'), false);
    assert.equal(bodyHasBoard(null), false);
});

test('the open profile is only rebuilt when its markup changed', () => {
    assert.equal(profileChanged('<p>a</p>', '<p>a</p>'), false);
    assert.equal(profileChanged('<p>a</p>', '<p>b</p>'), true);
});

test('the page script sends the background header and refuses redirects on its board fetch', () => {
    const source = readFileSync('resources/js/unit-status.js', 'utf8');
    assert.equal((source.match(/redirect: 'manual'/g) ?? []).length, 1);
    assert.equal((source.match(/\.\.\.BACKGROUND_REFRESH/g) ?? []).length, 1);
    assert.match(source, /data-focus-key/);
});
