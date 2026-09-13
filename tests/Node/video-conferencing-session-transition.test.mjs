import assert from 'node:assert/strict';
import test from 'node:test';
import { LineupStartAlert } from '../../resources/js/video-conferencing/session-transition.ts';

test('standing-by stations alert once per new session despite duplicate delivery and reconnect', () => {
    const alert = new LineupStartAlert();
    assert.equal(alert.observe(false, null, true), false);
    assert.equal(alert.observe(true, 'first', true), true);
    assert.equal(alert.observe(true, 'first', true), false);
    assert.equal(alert.observe(false, null, true), false);
    assert.equal(alert.observe(true, 'first', true), false);
    assert.equal(alert.observe(true, 'second', true), true);
});

test('an existing session, opted-out station or connected station does not sound an alert', () => {
    const alert = new LineupStartAlert();
    assert.equal(alert.observe(true, 'existing', true), false);
    alert.observe(false, null, true);
    assert.equal(alert.observe(true, 'not-standing-by', false), false);
    assert.equal(alert.observe(true, 'not-standing-by', true), false);
});
