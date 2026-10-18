import assert from 'node:assert/strict';
import test from 'node:test';
import { InactiveConfirmation } from '../../resources/js/video-conferencing/inactive-confirmation.ts';

test('a single inactive reading never confirms', () => {
    const confirmation = new InactiveConfirmation();

    assert.equal(confirmation.observe(true, 0), false);
});

test('two time-separated inactive readings confirm', () => {
    const confirmation = new InactiveConfirmation();

    assert.equal(confirmation.observe(true, 0), false);
    assert.equal(confirmation.observe(true, 5_000), true);
});

test('two readings in quick succession are one blip, not a confirmation', () => {
    const confirmation = new InactiveConfirmation();

    assert.equal(confirmation.observe(true, 0), false);
    assert.equal(confirmation.observe(true, 200), false);
    assert.equal(confirmation.observe(true, 2_000), true);
});

test('an active reading in between resets the count', () => {
    const confirmation = new InactiveConfirmation();

    assert.equal(confirmation.observe(true, 0), false);
    assert.equal(confirmation.observe(false, 5_000), false);
    assert.equal(confirmation.observe(true, 10_000), false);
    assert.equal(confirmation.observe(true, 15_000), true);
});

test('reset forgets earlier readings', () => {
    const confirmation = new InactiveConfirmation();

    confirmation.observe(true, 0);
    confirmation.reset();
    assert.equal(confirmation.observe(true, 9_000), false);
});
