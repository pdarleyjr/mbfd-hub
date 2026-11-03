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
