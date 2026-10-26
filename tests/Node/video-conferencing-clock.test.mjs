import assert from 'node:assert/strict';
import test from 'node:test';
import { ServerClock, formatConferenceTime } from '../../resources/js/video-conferencing/clock.ts';

test('the conference clock follows server time and monotonic elapsed time, ignoring the client clock', () => {
    const clock = new ServerClock('2026-08-12T23:59:59-04:00', 500);
    const nativeNow = Date.now;
    try {
        Date.now = () => Date.parse('2036-01-01T00:00:00Z');
        assert.equal(clock.now(1500), Date.parse('2026-08-13T00:00:00-04:00'));
        clock.sync('2026-08-13T00:00:01-04:00', 2000);
        assert.equal(clock.now(3000), Date.parse('2026-08-13T00:00:02-04:00'));
    } finally {
        Date.now = nativeNow;
    }
});

test('missing or malformed server time never falls back to the client clock', () => {
    const clock = new ServerClock();
    assert.equal(clock.now(100), null);
    clock.sync('invalid', 100);
    assert.equal(clock.now(200), null);
});

test('conference labels use Eastern time across DST and midnight regardless of browser zone', () => {
    assert.equal(formatConferenceTime('2026-03-08T06:59:00Z', 'America/New_York'), '1:59 AM');
    assert.equal(formatConferenceTime('2026-03-08T07:00:00Z', 'America/New_York'), '3:00 AM');
    assert.equal(formatConferenceTime('2026-08-12T03:59:00Z', 'America/New_York'), '11:59 PM');
    assert.equal(formatConferenceTime('2026-08-12T04:00:00Z', 'America/New_York'), '12:00 AM');
});
