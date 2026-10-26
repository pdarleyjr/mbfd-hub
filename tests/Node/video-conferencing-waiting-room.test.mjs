import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import {
    alertObservation,
    canJoin,
    canWaitForLineup,
    decideMediaRelease,
    describeJoinFailure,
    describeWaitingRoom,
    IDLE_PREVIEW_RELEASE_MS,
    idleRemainingMs,
    initialWaitingRoom,
    isMemberLineupStatus,
    isNearScheduledStart,
    memberPollBaseMs,
    NEAR_START_HOLD_MS,
    nextPollDelayMs,
    observeFailure,
    observeStatus,
    OFFLINE_AFTER_FAILURES,
    ROOM_FULL_MESSAGE,
    STARTING_POLL_MS,
} from '../../resources/js/video-conferencing/waiting-room.ts';
import { LineupStartAlert } from '../../resources/js/video-conferencing/session-transition.ts';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const read = (path) => readFileSync(resolve(root, path), 'utf8');

function status(state, extra = {}) {
    return {
        state,
        session_id: null,
        started_at: null,
        ends_at: null,
        ended_at: null,
        scheduled_for: null,
        full: false,
        server_time: '2026-08-11T11:00:00+00:00',
        ...extra,
    };
}

const clock = (iso) => `T(${iso})`;
const copyFor = (model, rejoin = false) => describeWaitingRoom(model, { rejoin, formatTime: clock });
const noJitter = () => 0;
const maxJitter = () => 1;

test('a member waits, sees the lineup start, goes live, then sees it end', () => {
    let model = initialWaitingRoom;
    assert.equal(model.state, 'checking');

    model = observeStatus(model, status('not_started', { scheduled_for: '2026-08-11T11:00:00+00:00' }));
    assert.equal(model.state, 'not_started');
    assert.equal(canJoin(model, { mediaReady: true, busy: false }), false);

    model = observeStatus(model, status('starting'));
    assert.equal(model.state, 'starting');
    assert.equal(canJoin(model, { mediaReady: true, busy: false }), false);

    model = observeStatus(model, status('active', { session_id: 'S1', ends_at: '2026-08-11T11:15:00+00:00' }));
    assert.equal(model.state, 'active');
    assert.equal(model.sessionId, 'S1');
    assert.equal(canJoin(model, { mediaReady: true, busy: false }), true);

    model = observeStatus(model, status('ended', { ended_at: '2026-08-11T11:09:00+00:00' }));
    assert.equal(model.state, 'ended');
    assert.equal(model.sessionId, null);
    assert.equal(canJoin(model, { mediaReady: true, busy: false }), false);
});

test('a restart on the same day returns to active with the new session id', () => {
    let model = observeStatus(initialWaitingRoom, status('ended', { ended_at: '2026-08-11T11:09:00+00:00' }));
    model = observeStatus(model, status('active', { session_id: 'S2' }));

    assert.equal(model.state, 'active');
    assert.equal(model.sessionId, 'S2');
    assert.equal(model.endedAt, null);
});

test('joining is allowed only while live, with a seat free, devices ready and no join in flight', () => {
    const live = observeStatus(initialWaitingRoom, status('active', { session_id: 'S1' }));
    const states = ['checking', 'not_started', 'starting', 'ended', 'unavailable'];

    assert.equal(canJoin(live, { mediaReady: true, busy: false }), true);
    assert.equal(canJoin(live, { mediaReady: false, busy: false }), false);
    assert.equal(canJoin(live, { mediaReady: true, busy: true }), false);
    for (const state of states) {
        const model = state === 'checking' ? initialWaitingRoom : observeStatus(initialWaitingRoom, status(state, { session_id: null }));
        assert.equal(canJoin(model, { mediaReady: true, busy: false }), false, state);
    }
    assert.equal(canJoin({ ...live, state: 'offline' }, { mediaReady: true, busy: false }), false);

    const full = observeStatus(initialWaitingRoom, status('active', { session_id: 'S1', full: true }));
    assert.equal(full.full, true);
    assert.equal(canJoin(full, { mediaReady: true, busy: false }), false, 'a full lineup cannot be joined');
    assert.equal(copyFor(full).detail, ROOM_FULL_MESSAGE);
    assert.equal(observeStatus(full, status('active', { session_id: 'S1', full: false })).full, false, 'a seat opening re-enables Join');
    assert.equal(observeStatus(full, status('ended')).full, false);
});

test('a member may deliberately wait before start or for a full room, with devices ready', () => {
    for (const state of ['not_started', 'starting', 'active']) {
        const model = observeStatus(initialWaitingRoom, status(state, { session_id: state === 'active' ? 'S1' : null, full: true }));
        assert.equal(canWaitForLineup(model, { mediaReady: true, busy: false }), true);
        assert.equal(canWaitForLineup(model, { mediaReady: false, busy: false }), false);
        assert.equal(canWaitForLineup(model, { mediaReady: true, busy: true }), false);
    }
    for (const state of ['ended', 'unavailable', 'signed_out', 'forbidden', 'checking']) {
        assert.equal(canWaitForLineup({ ...initialWaitingRoom, state }, { mediaReady: true, busy: false }), false);
    }
});

test('a lost connection becomes offline only after repeated misses and recovers on the next good read', () => {
    let model = observeStatus(initialWaitingRoom, status('active', { session_id: 'S1' }));

    model = observeFailure(model, null);
    assert.equal(model.state, 'active', 'a single missed read is not an outage');
    assert.equal(model.failures, 1);

    for (let i = 1; i < OFFLINE_AFTER_FAILURES; i += 1) model = observeFailure(model, null);
    assert.equal(model.state, 'offline');
    assert.equal(canJoin(model, { mediaReady: true, busy: false }), false);

    model = observeStatus(model, status('active', { session_id: 'S1' }));
    assert.equal(model.state, 'active');
    assert.equal(model.failures, 0);
    assert.equal(canJoin(model, { mediaReady: true, busy: false }), true);
});

test('each kind of failed read gets its own state instead of "your Internet is down"', () => {
    assert.equal(observeFailure(initialWaitingRoom, 401).state, 'signed_out');
    assert.equal(observeFailure(initialWaitingRoom, 419).state, 'signed_out');
    assert.equal(nextPollDelayMs(observeFailure(initialWaitingRoom, 401), 10_000, false), null, 'polling stops when signed out');

    assert.equal(observeFailure(initialWaitingRoom, 403).state, 'forbidden', 'password change or onboarding pending');
    assert.equal(observeFailure(initialWaitingRoom, 429).state, 'throttled');
    assert.equal(observeFailure(initialWaitingRoom, 503, { code: 'conference_disabled' }).state, 'disabled');
    assert.equal(observeFailure(initialWaitingRoom, 503).state, 'unavailable');

    let server = initialWaitingRoom;
    server = observeFailure(server, 500);
    assert.equal(server.state, 'checking', 'one 500 is not shown yet');
    server = observeFailure(server, 500);
    assert.equal(server.state, 'server_error');
    let offline = initialWaitingRoom;
    offline = observeFailure(observeFailure(offline, null), null);
    assert.equal(offline.state, 'offline');
    // A 200 that is not a lineup status (captive portal page) counts as a failed read, not as "no lineup".
    assert.equal(observeFailure(observeFailure(initialWaitingRoom, 200), 200).state, 'offline');
});

test('the member copy for each failure is accurate and free of codes and em dashes', () => {
    const cases = {
        forbidden: [observeFailure(initialWaitingRoom, 403), /Finish setting up your account first/],
        throttled: [observeFailure(initialWaitingRoom, 429), /Too many checks\. Waiting a moment/],
        server_error: [observeFailure(observeFailure(initialWaitingRoom, 500), 500), /The Hub had a problem\. Try again/],
        disabled: [observeFailure(initialWaitingRoom, 503, { code: 'conference_disabled' }), /Video conferencing is turned off right now/],
    };
    for (const [state, [model, headline]] of Object.entries(cases)) {
        const copy = copyFor(model);
        assert.match(copy.headline, headline, state);
        const text = [copy.headline, copy.detail, copy.footnote].join(' ');
        assert.doesNotMatch(text, /Internet/i, `${state} must not blame the Internet connection`);
        assert.doesNotMatch(text, /[a-z]+_[a-z_]+|—/, state);
    }
    assert.equal(copyFor(cases.forbidden[0]).offersRecheck, true);
});

test('polling is gentle while waiting, never faster than 5 s while starting, slow when hidden and backs off when unreachable', () => {
    const base = memberPollBaseMs(5_000);
    assert.equal(base, 10_000);
    assert.equal(memberPollBaseMs(3_000), 10_000);
    assert.equal(memberPollBaseMs(15_000), 15_000, 'never slower than 15 s');
    assert.equal(memberPollBaseMs(60_000), 15_000);

    const waiting = observeStatus(initialWaitingRoom, status('not_started'));
    const starting = observeStatus(initialWaitingRoom, status('starting'));
    assert.equal(nextPollDelayMs(waiting, base, false, noJitter), base);
    assert.equal(nextPollDelayMs(starting, base, false, noJitter), STARTING_POLL_MS);
    assert.ok(STARTING_POLL_MS >= 5_000, 'starting is read no faster than every 5 s');
    assert.equal(nextPollDelayMs(waiting, base, true, noJitter), 60_000);
    assert.equal(nextPollDelayMs(starting, base, true, noJitter), 60_000, 'a hidden tab backs off even while starting');

    let offline = waiting;
    const delays = [];
    for (let i = 0; i < 8; i += 1) {
        offline = observeFailure(offline, null);
        if (offline.state === 'offline') delays.push(nextPollDelayMs(offline, base, false, noJitter));
    }
    assert.deepEqual(delays.slice(0, 3), [10_000, 20_000, 40_000]);
    assert.equal(Math.max(...delays), 60_000);
});

test('poll timers carry jitter that only ever delays, so no floor is broken', () => {
    const base = memberPollBaseMs(5_000);
    const starting = observeStatus(initialWaitingRoom, status('starting'));
    const waiting = observeStatus(initialWaitingRoom, status('not_started'));
    for (const model of [starting, waiting]) {
        const low = nextPollDelayMs(model, base, false, noJitter);
        const high = nextPollDelayMs(model, base, false, maxJitter);
        assert.ok(high > low, 'jitter spreads out members who loaded the page together');
        assert.ok(high <= low * 1.26);
    }
    assert.ok(nextPollDelayMs(starting, base, false, maxJitter) >= 5_000);
    const delays = new Set(Array.from({ length: 20 }, () => nextPollDelayMs(waiting, base, false)));
    assert.ok(delays.size > 1, 'real randomness is used by default');
    for (const delay of delays) assert.ok(delay >= base && delay <= base * 1.25);
});

test('a 429 is honoured: the next read waits at least the Retry-After the Hub asked for', () => {
    const base = memberPollBaseMs(5_000);
    const throttled = observeFailure(initialWaitingRoom, 429, { retryAfterSeconds: 45 });
    assert.equal(throttled.retryAfterMs, 45_000);
    assert.ok(nextPollDelayMs(throttled, base, false, noJitter) >= 45_000);

    assert.ok(nextPollDelayMs(observeFailure(initialWaitingRoom, 429, { retryAfterSeconds: 1 }), base, false, noJitter) >= base, 'never faster than the normal cadence');
    assert.ok(nextPollDelayMs(observeFailure(initialWaitingRoom, 429), base, false, noJitter) >= 30_000, 'a missing header still pauses');
    assert.equal(observeFailure(initialWaitingRoom, 429, { retryAfterSeconds: 9_999 }).retryAfterMs, 120_000, 'a silly value is capped');
    assert.equal(observeStatus(throttled, status('active', { session_id: 'S1' })).retryAfterMs, null, 'a good read clears it');
});

test('only a real lineup status is accepted as a successful read', () => {
    assert.equal(isMemberLineupStatus(status('active', { session_id: 'S1' })), true);
    assert.equal(isMemberLineupStatus(status('not_started')), true);
    for (const bad of [null, undefined, 'ok', 1, [], {}, { state: 'weird' }, { state: undefined }, { state: 'active', session_id: 5 }]) {
        assert.equal(isMemberLineupStatus(bad), false, JSON.stringify(bad));
    }
});

test('the idle camera is released after ten minutes of not being live, and only then', () => {
    assert.equal(IDLE_PREVIEW_RELEASE_MS, 10 * 60 * 1000);
    assert.equal(idleRemainingMs(1_000, 1_000), IDLE_PREVIEW_RELEASE_MS);
    assert.equal(idleRemainingMs(1_000, 1_000 + IDLE_PREVIEW_RELEASE_MS - 1), 1);
    assert.equal(idleRemainingMs(1_000, 1_000 + IDLE_PREVIEW_RELEASE_MS), 0);
    assert.equal(idleRemainingMs(1_000, 1_000 + IDLE_PREVIEW_RELEASE_MS * 3), 0);
    assert.equal(idleRemainingMs(5_000, 1_000), IDLE_PREVIEW_RELEASE_MS, 'a clock that moved backwards never releases early');
});

test('media is never released while the lineup is starting or live, or while a join is in flight', () => {
    const now = 1_000_000_000_000;
    const longIdle = now - 3 * IDLE_PREVIEW_RELEASE_MS;
    const base = { now, idleSince: longIdle, sawLive: false };
    for (const state of ['starting', 'active']) {
        const decision = decideMediaRelease({ ...base, model: observeStatus(initialWaitingRoom, status(state, { session_id: 'S1' })), joinInFlight: false });
        assert.deepEqual(decision, { action: 'keep', idleSince: now }, `${state} holds the camera and restarts the idle clock`);
    }
    // The race: Join pressed as the timer is due. Tokens may already be minted, so nothing is released.
    const waiting = observeStatus(initialWaitingRoom, status('not_started'));
    assert.deepEqual(decideMediaRelease({ ...base, model: waiting, joinInFlight: true }), { action: 'keep', idleSince: now });
    assert.deepEqual(
        decideMediaRelease({ ...base, model: observeStatus(initialWaitingRoom, status('ended')), joinInFlight: true, sawLive: true }),
        { action: 'keep', idleSince: now },
        'not even an ended lineup releases media under a join',
    );
});

test('media is held from 15 minutes before the scheduled start, and the 10 minute clock starts after that', () => {
    const start = Date.parse('2026-08-11T11:00:00Z');
    const model = observeStatus(initialWaitingRoom, status('not_started', { scheduled_for: '2026-08-11T11:00:00Z' }));
    assert.equal(NEAR_START_HOLD_MS, 15 * 60 * 1000);
    assert.equal(isNearScheduledStart('2026-08-11T11:00:00Z', start - NEAR_START_HOLD_MS - 1), false);
    assert.equal(isNearScheduledStart('2026-08-11T11:00:00Z', start - NEAR_START_HOLD_MS), true);
    assert.equal(isNearScheduledStart('2026-08-11T11:00:00Z', start), true);
    assert.equal(isNearScheduledStart('2026-08-11T11:00:00Z', start + 1), false);
    assert.equal(isNearScheduledStart(null, start), false);
    assert.equal(isNearScheduledStart('not a date', start), false);

    // Opened at 10:32, idle since then: at 10:42 the 10 minutes are up, but 10:42 is inside the hold window.
    const opened = start - 28 * 60 * 1000;
    const atTenFortyTwo = decideMediaRelease({ model, now: start - 18 * 60 * 1000, joinInFlight: false, idleSince: opened, sawLive: false });
    assert.deepEqual(atTenFortyTwo, { action: 'release', reason: 'idle' }, 'outside the window, ten idle minutes release');
    const insideWindow = decideMediaRelease({ model, now: start - 10 * 60 * 1000, joinInFlight: false, idleSince: opened, sawLive: false });
    assert.deepEqual(insideWindow, { action: 'keep', idleSince: start - 10 * 60 * 1000 }, 'inside the window the camera stays on');
    // The clock restarted when the window began, so ten more minutes of waiting pass before any release.
    const afterWindowSoon = decideMediaRelease({ model, now: start + 5 * 60 * 1000, joinInFlight: false, idleSince: start, sawLive: false });
    assert.equal(afterWindowSoon.action, 'keep');
    const afterWindowLate = decideMediaRelease({ model, now: start + IDLE_PREVIEW_RELEASE_MS, joinInFlight: false, idleSince: start, sawLive: false });
    assert.deepEqual(afterWindowLate, { action: 'release', reason: 'idle' });
});

test('media is released when a lineup this page watched has ended, but not for one that was already over', () => {
    const now = Date.now();
    const ended = observeStatus(initialWaitingRoom, status('ended', { ended_at: '2026-08-11T11:09:00+00:00' }));

    assert.deepEqual(decideMediaRelease({ model: ended, now, joinInFlight: false, idleSince: now, sawLive: true }), { action: 'release', reason: 'ended' });
    assert.deepEqual(
        decideMediaRelease({ model: ended, now, joinInFlight: false, idleSince: now, sawLive: false }),
        { action: 'keep', idleSince: now },
        'opening the page after the lineup already ended does not flash the camera off at once',
    );
});

test('the start chime fires once per live session, never for an already-running lineup or a failed read', () => {
    const alert = new LineupStartAlert();
    const feed = (model, standingBy = true) => {
        const observation = alertObservation(model);
        return observation === null ? null : alert.observe(observation.active, observation.sessionId, standingBy);
    };
    const live = (id) => observeStatus(initialWaitingRoom, status('active', { session_id: id }));

    assert.equal(feed(initialWaitingRoom), null, 'checking is not a reading');
    assert.equal(feed(live('S0')), false, 'already running when the page opened');
    assert.equal(feed(observeStatus(initialWaitingRoom, status('ended'))), false);
    assert.equal(feed(observeFailure(live('S0'), null)), false, 'one missed read keeps the last known state, so it is a duplicate');
    assert.equal(feed({ ...live('S0'), state: 'offline' }), null);
    assert.equal(feed({ ...live('S0'), state: 'throttled' }), null);
    assert.equal(feed(live('S1'), false), false, 'not standing by, no chime');

    const second = new LineupStartAlert();
    assert.equal(second.observe(...Object.values(alertObservation(observeStatus(initialWaitingRoom, status('not_started')))), true), false);
    assert.equal(second.observe(...Object.values(alertObservation(live('S2'))), true), true, 'waiting member hears it start');
    assert.equal(second.observe(...Object.values(alertObservation(live('S2'))), true), false, 'duplicate delivery stays silent');
});

test('waiting-room copy says what is happening, uses no raw codes, and never promises auto-join', () => {
    const models = {
        checking: initialWaitingRoom,
        not_started: observeStatus(initialWaitingRoom, status('not_started', { scheduled_for: 'SCHED' })),
        starting: observeStatus(initialWaitingRoom, status('starting')),
        active: observeStatus(initialWaitingRoom, status('active', { session_id: 'S1', ends_at: 'END' })),
        full: observeStatus(initialWaitingRoom, status('active', { session_id: 'S1', ends_at: 'END', full: true })),
        ended: observeStatus(initialWaitingRoom, status('ended', { ended_at: 'ENDED' })),
        unavailable: observeStatus(initialWaitingRoom, status('unavailable')),
        offline: { ...initialWaitingRoom, state: 'offline', failures: 2 },
        signed_out: { ...initialWaitingRoom, state: 'signed_out', failures: 1 },
    };
    for (const [state, model] of Object.entries(models)) {
        const copy = copyFor(model);
        const text = [copy.headline, copy.detail, copy.footnote].join(' ');
        assert.ok(copy.headline.length > 0 && copy.detail.length > 0, state);
        assert.doesNotMatch(text, /[a-z]+_[a-z_]+/, `${state} leaks a raw code`);
        assert.doesNotMatch(text, /—/, `${state} uses an em dash`);
        assert.doesNotMatch(text, /automatically join|joining automatically/i, state);
    }
    assert.match(copyFor(models.not_started).detail, /T\(SCHED\)/);
    assert.equal(copyFor(models.active).joinLabel, 'Join Morning Lineup');
    assert.match(copyFor(models.active).detail, /T\(END\)/);
    assert.equal(copyFor(models.active, true).joinLabel, 'Rejoin Morning Lineup');
    assert.equal(copyFor(models.ended, true).joinLabel, 'Join Morning Lineup', 'only a live lineup is rejoined');
    assert.equal(copyFor(models.ended).headline, 'Morning Lineup has ended');
    assert.match(copyFor(models.ended).detail, /T\(ENDED\)/);
    assert.equal(copyFor(models.offline).offersRecheck, true);
    assert.equal(copyFor(models.not_started).offersRecheck, false);
    assert.equal(ROOM_FULL_MESSAGE, 'Morning Lineup is full right now. You can watch for a seat to open; try again in a moment.');
});

test('a failed Join is explained in plain words, and "not started", "ended" and "full" are not errors', () => {
    const notStarted = describeJoinFailure({ status: 409, code: 'lineup_not_started' });
    assert.equal(notStarted.kind, 'notice');
    assert.equal(notStarted.refresh, true);

    const ended = describeJoinFailure({ status: 410, code: null });
    assert.equal(ended.kind, 'notice');
    assert.match(ended.message, /ended/);

    const full = describeJoinFailure({ status: 409, code: 'room_full' });
    assert.equal(full.kind, 'notice');
    assert.equal(full.message, ROOM_FULL_MESSAGE);
    assert.equal(full.refresh, true, 'the waiting room then shows the full state and re-enables Join when a seat opens');

    assert.equal(describeJoinFailure({ status: 409, code: 'endpoint_in_use' }).kind, 'takeover');
    assert.equal(describeJoinFailure({ status: 503, code: 'conference_unavailable' }).kind, 'error');
    assert.match(describeJoinFailure({ status: 403, code: 'password_change_required' }).message, /Finish setting up your account first/);
    assert.match(describeJoinFailure({ status: 503, code: 'conference_disabled' }).message, /turned off right now/);
    assert.match(describeJoinFailure({ status: 500, code: null }).message, /The Hub had a problem/);
    for (const failure of [
        { status: 401, code: null }, { status: 419, code: null }, { status: 429, code: null },
        { status: 403, code: 'password_change_required' }, { status: 503, code: 'conference_disabled' },
        { status: 503, code: 'conference_unavailable' }, { status: null, code: null }, { status: 500, code: null },
        { status: 422, code: 'validation_failed' },
    ]) {
        const outcome = describeJoinFailure(failure);
        assert.doesNotMatch(outcome.message, /[a-z]+_[a-z_]+|\b[45]\d\d\b|—/, JSON.stringify(failure));
        assert.match(outcome.message, /Join|join|sign in|Reload/, 'tells the member what to do next');
    }
});

test('the waiting room requires explicit consent and keeps token admission in the app', () => {
    const app = read('resources/js/video-conferencing/ConferenceApp.tsx');
    const room = read('resources/js/video-conferencing/MemberWaitingRoom.tsx');
    const hook = read('resources/js/video-conferencing/useMemberLineupStatus.ts');
    const pure = read('resources/js/video-conferencing/waiting-room.ts');

    assert.match(app, /const MemberWaitingRoom = lazy\(/, 'the waiting room loads on demand to protect the mount bundle');
    assert.match(app, /onJoin=\{\(\) => joinAsSelf\(false\)\}/);
    assert.doesNotMatch(app, /Join active Morning Lineup/);

    for (const [name, source] of Object.entries({ room, hook, pure })) {
        assert.doesNotMatch(source, /postJson|\/token|command_start|command_status|station_|launch_context|from 'livekit-client'/, `${name} must stay read-only`);
        assert.doesNotMatch(source, /method:\s*'(?:POST|PUT|PATCH|DELETE)'/, name);
    }
    assert.match(hook, /bootstrap\.endpoints\.lineup_status/);
    assert.match(hook, /subscribeToLineupChanges/);
    assert.match(hook, /visibilitychange/);
    assert.match(room, /if \(joinable\) \{[\s\S]*?setWaitingRequested\(true\)/);
    assert.match(room, /if \(!waitingRequested \|\| !props\.observing \|\| !canJoin/);
    assert.match(room, /nextAttemptAtRef\.current = performance\.now\(\) \+ 10_000/);
    assert.match(room, /Cancel waiting/);
});

test('existing reconnect behaviour and the explicit-leave guard are unchanged', () => {
    const app = read('resources/js/video-conferencing/ConferenceApp.tsx');

    assert.match(app, /const MAX_REJOIN_ATTEMPTS = 3;/);
    assert.match(app, /const REJOIN_DELAYS_MS = \[2_000, 6_000, 12_000\];/);
    assert.match(app, /roomMode === 'lineup' && lineupSessionId !== null/);
    assert.match(app, /await prepareMedia\(\{ keepOptedOut: station && superseded \}\)/);
    assert.match(app, /if \(terminatingRef\.current \|\| userLeftRef\.current\) return;/);
    assert.match(app, /bootstrap\.entry_mode === 'command' \? '300 Command' : 'Employee Portal'/);
});

test('the idle release stops devices, never runs under a join, and needs a user action to turn them back on', () => {
    const app = read('resources/js/video-conferencing/ConferenceApp.tsx');
    const room = read('resources/js/video-conferencing/MemberWaitingRoom.tsx');

    assert.match(app, /const releaseIdlePreview = useCallback\(\(reason: MediaReleaseReason\) => \{[\s\S]*?joinInFlightRef\.current \|\| joiningRef\.current \|\| roomRef\.current\) return;[\s\S]*?stopPreview\(\);[\s\S]*?setReleasedReason\(reason\)/);
    assert.match(app, /joinInFlightRef\.current = true;\s*setActionBusy\('join-self'\)/, 'the in-flight flag is set synchronously, before any await');
    assert.match(app, /onResumeMedia=\{\(\) => void prepareMedia\(\)\}/);
    assert.match(room, /Turn camera back on/);
    assert.match(room, /decideMediaRelease/);
    assert.doesNotMatch(room, /IDLE_PREVIEW_RELEASE_MS/, 'the timer rule lives in the tested pure module');
});

test('a join in progress is marked busy for update reloads, next to the active call', () => {
    const app = read('resources/js/video-conferencing/ConferenceApp.tsx');

    assert.match(app, /markBusy\('active-call'\)/);
    assert.match(app, /const joinBusy = phase === 'joining' \|\| actionBusy === 'join-self'/);
    assert.match(app, /if \(joinBusy\) markBusy\('joining'\);\s*else clearBusy\('joining'\)/);
    assert.match(app, /useEffect\(\(\) => \(\) => clearBusy\('joining'\), \[\]\)/, 'cleared on unmount');
});

test('every failed connect after a token was minted gives its seat back', () => {
    const app = read('resources/js/video-conferencing/ConferenceApp.tsx');
    const connect = app.slice(app.indexOf('const connectWithCredentials'), app.indexOf('const requestStationToken'));

    assert.match(connect, /\} catch \(joinError\) \{[\s\S]{0,400}notifyLeave\(credentials\.participation_id\);\s*if \(terminatingRef\.current\) return;/);
    const earlyReturns = connect.slice(0, connect.indexOf('joiningRef.current = true;'));
    assert.equal((earlyReturns.match(/notifyLeave\(credentials\.participation_id\)/g) ?? []).length, 2, 'both early returns release the minted seat');
    assert.match(connect, /\[attachRoomEvents, bootstrap, cameraEnabled, forceRelay, lineup\.session_id, microphoneEnabled, notifyLeave\]/);
});

test('the end time shown after Join comes from the session being joined', () => {
    const app = read('resources/js/video-conferencing/ConferenceApp.tsx');

    assert.match(app, /lineupEndsAtRef\.current = active\.session\.ends_at \?\? null;/);
    assert.doesNotMatch(app, /endsAt: string \| null = null/);
});

test('status reads queue a refresh requested mid-flight, honour Retry-After and reject a non-JSON 200', () => {
    const hook = read('resources/js/video-conferencing/useMemberLineupStatus.ts');
    const api = read('resources/js/video-conferencing/api.ts');

    assert.match(hook, /if \(inFlightRef\.current\) \{\s*queuedRef\.current = true;/);
    assert.match(hook, /\} while \(queuedRef\.current && enabledRef\.current\)/);
    assert.match(hook, /isMemberLineupStatus\(status\)/);
    assert.match(hook, /retryAfterSeconds: known\?\.retryAfterSeconds/);
    assert.match(api, /Retry-After/);
    assert.match(api, /code: 'invalid_response'/);
});

test('the Join button stays focusable, explains itself and is announced through one live region', () => {
    const room = read('resources/js/video-conferencing/MemberWaitingRoom.tsx');
    const css = read('resources/js/video-conferencing/waiting-room.css');

    assert.match(room, /aria-disabled=\{!joinable\}/);
    assert.doesNotMatch(room, /[^-]disabled=\{!joinable\}/, 'a native disabled button would drop out of the tab order');
    assert.match(room, /aria-describedby=\{hint \? 'vc-wait-status vc-join-hint' : 'vc-wait-status'\}/);
    assert.match(room, /aria-busy=\{props\.busy\}/);
    assert.equal((room.match(/role="status"/g) ?? []).length, 1, 'a single polite live region');
    assert.equal((room.match(/aria-live=/g) ?? []).length, 1);
    assert.match(css, /button\[aria-disabled="true"\]/);
});
