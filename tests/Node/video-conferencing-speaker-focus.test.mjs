import assert from 'node:assert/strict';
import test from 'node:test';
import {
    resolveFocusedIdentity,
    SpeakerFocusTracker,
    speakerSwitchDelay,
} from '../../resources/js/video-conferencing/speaker-focus.ts';

test('speaker changes require hysteresis and respect minimum dwell', () => {
    assert.equal(speakerSwitchDelay(2_000, 1_900), 1_300);
    assert.equal(speakerSwitchDelay(4_000, 1_000), 650);
});

test('screen share outranks manual pin and manual pin outranks automatic speaker', () => {
    assert.equal(resolveFocusedIdentity('share', 'pinned', 'speaker', 'fallback'), 'share');
    assert.equal(resolveFocusedIdentity(null, 'pinned', 'speaker', 'fallback'), 'pinned');
    assert.equal(resolveFocusedIdentity(null, null, 'speaker', 'fallback'), 'speaker');
    assert.equal(resolveFocusedIdentity(null, null, null, 'fallback'), 'fallback');
});

test('sustained speakers switch focus while short spikes and silence retain it', () => {
    const tracker = new SpeakerFocusTracker();
    tracker.recordFocus('300', 1_000);
    tracker.updateCandidate('sta1', 1_100);
    assert.equal(tracker.commit(1_749), undefined);
    tracker.updateCandidate(null, 1_750);
    assert.equal(tracker.commit(3_000), undefined);

    tracker.updateCandidate('sta1', 3_000);
    assert.equal(tracker.commit(3_649), undefined);
    assert.equal(tracker.commit(3_650), 'sta1');
    tracker.updateCandidate(null, 4_000);
    assert.equal(tracker.commit(5_000), undefined);

    tracker.updateCandidate('sta3', 5_100);
    assert.equal(tracker.commit(5_749), undefined);
    assert.equal(tracker.commit(5_750), 'sta3');
});

test('leaving participant clears automatic focus and pending focus candidates', () => {
    const tracker = new SpeakerFocusTracker();
    tracker.recordFocus('sta1', 1_000);
    assert.equal(tracker.participantLeft('sta1'), true);
    tracker.updateCandidate('sta4', 2_000);
    assert.equal(tracker.participantLeft('sta4'), false);
    assert.equal(tracker.commit(4_000), undefined);
});
