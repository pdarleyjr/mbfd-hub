/**
 * Pure state machine and copy for the member (self) Morning Lineup waiting room.
 *
 * Server truth comes from the read-only GET lineup/status endpoint. Nothing in
 * this module touches the network, devices or the DOM, so it is unit-tested in
 * Node. Only erasable TypeScript syntax is used for that reason.
 */
import type { MemberLineupStatus } from './types';

export type LineupWaitState =
    | 'checking'
    | MemberLineupStatus['state']
    | 'offline'
    | 'signed_out'
    | 'forbidden'
    | 'throttled'
    | 'server_error'
    | 'disabled';

export interface WaitingRoomModel {
    state: LineupWaitState;
    sessionId: string | null;
    startedAt: string | null;
    endsAt: string | null;
    endedAt: string | null;
    scheduledFor: string | null;
    /** The live lineup has no member seat free for this member right now. */
    full: boolean;
    /** Server-requested pause after a 429, in milliseconds. */
    retryAfterMs: number | null;
    /** Consecutive failed status reads. */
    failures: number;
}

export const initialWaitingRoom: WaitingRoomModel = {
    state: 'checking',
    sessionId: null,
    startedAt: null,
    endsAt: null,
    endedAt: null,
    scheduledFor: null,
    full: false,
    retryAfterMs: null,
    failures: 0,
};

/** Two missed reads in a row before the member is told the Hub cannot be reached. */
export const OFFLINE_AFTER_FAILURES = 2;
/** The preview camera and microphone are released after this long NOT live and not near the start. */
export const IDLE_PREVIEW_RELEASE_MS = 10 * 60 * 1000;
/** Camera and microphone are never released this close to the scheduled start. */
export const NEAR_START_HOLD_MS = 15 * 60 * 1000;

const POLL_FLOOR_MS = 10_000;
const POLL_CEILING_MS = 15_000;
/** Provisioning a room takes seconds; the status is an inexpensive DB read but 5 s is plenty. */
export const STARTING_POLL_MS = 5_000;
const HIDDEN_POLL_MS = 60_000;
const BACKOFF_CEILING_MS = 60_000;
const THROTTLED_DEFAULT_MS = 30_000;
const THROTTLED_CEILING_MS = 120_000;
/** Polls are only ever delayed further by jitter (up to +25%), never sped up, so every floor holds. */
const JITTER_RATIO = 0.25;

const STATES: ReadonlySet<string> = new Set(['not_started', 'starting', 'active', 'ended', 'unavailable']);

/** True when a parsed response body really is a lineup status (guards against proxy or portal pages). */
export function isMemberLineupStatus(value: unknown): value is MemberLineupStatus {
    if (typeof value !== 'object' || value === null) return false;
    const candidate = value as Record<string, unknown>;

    return typeof candidate.state === 'string' && STATES.has(candidate.state)
        && (candidate.session_id === null || typeof candidate.session_id === 'string' || candidate.session_id === undefined);
}

/** One status read succeeded. The server is authoritative, so it replaces what we knew. */
export function observeStatus(_previous: WaitingRoomModel, status: MemberLineupStatus): WaitingRoomModel {
    return {
        state: status.state,
        sessionId: status.session_id ?? null,
        startedAt: status.started_at ?? null,
        endsAt: status.ends_at ?? null,
        endedAt: status.ended_at ?? null,
        scheduledFor: status.scheduled_for ?? null,
        full: status.state === 'active' && status.full === true,
        retryAfterMs: null,
        failures: 0,
    };
}

export interface FailureDetails {
    /** `code` from the error body, for example `conference_disabled`. */
    code?: string | null;
    /** `Retry-After` header, in seconds. */
    retryAfterSeconds?: number | null;
}

/**
 * One status read failed. A signed-out session, an account that must finish
 * setting up, throttling and a switched-off feature are definite and say so
 * right away; anything else becomes "offline" (or "server problem") only after
 * repeated failures so a single dropped request does not flash an alarming message.
 */
export function observeFailure(previous: WaitingRoomModel, httpStatus: number | null, details: FailureDetails = {}): WaitingRoomModel {
    const failures = previous.failures + 1;
    if (httpStatus === 401 || httpStatus === 419) return { ...previous, state: 'signed_out', full: false, failures };
    if (httpStatus === 403) return { ...previous, state: 'forbidden', full: false, failures };
    if (httpStatus === 429) {
        const seconds = details.retryAfterSeconds;
        const retryAfterMs = seconds === null || seconds === undefined
            ? null
            : Math.min(THROTTLED_CEILING_MS, Math.max(1_000, seconds * 1_000));

        return { ...previous, state: 'throttled', full: false, retryAfterMs, failures };
    }
    if (httpStatus === 503) {
        return { ...previous, state: details.code === 'conference_disabled' ? 'disabled' : 'unavailable', full: false, failures };
    }
    if (failures >= OFFLINE_AFTER_FAILURES) {
        return { ...previous, state: httpStatus !== null && httpStatus >= 500 ? 'server_error' : 'offline', full: false, failures };
    }

    return { ...previous, failures };
}

/** Base poll interval for waiting members: gentler than the stations' own cadence. */
export function memberPollBaseMs(statusPollMs: number): number {
    return Math.min(POLL_CEILING_MS, Math.max(POLL_FLOOR_MS, statusPollMs * 2));
}

/**
 * Delay before the next status read, or null when polling should stop. Jitter spreads members
 * who all load the page at the same moment so they do not hit the Hub in lockstep; a hidden tab,
 * a throttled one and an unreachable Hub all wait longer, never shorter, than their floor.
 */
export function nextPollDelayMs(
    model: WaitingRoomModel,
    baseMs: number,
    hidden: boolean,
    random: () => number = Math.random,
): number | null {
    if (model.state === 'signed_out') return null;
    const jitter = (ms: number) => Math.round(ms * (1 + JITTER_RATIO * random()));
    let delay: number;
    switch (model.state) {
        case 'starting':
            delay = STARTING_POLL_MS;
            break;
        case 'throttled':
            delay = Math.max(model.retryAfterMs ?? THROTTLED_DEFAULT_MS, baseMs);
            break;
        case 'forbidden':
        case 'disabled':
            delay = HIDDEN_POLL_MS;
            break;
        case 'offline':
        case 'unavailable':
        case 'server_error':
            delay = Math.min(BACKOFF_CEILING_MS, baseMs * 2 ** Math.min(3, Math.max(0, model.failures - OFFLINE_AFTER_FAILURES)));
            break;
        default:
            delay = baseMs;
    }
    if (hidden) delay = Math.max(delay, HIDDEN_POLL_MS);

    return jitter(delay);
}

export interface JoinContext {
    mediaReady: boolean;
    busy: boolean;
}

/** Joining is a deliberate action, allowed only while the lineup is live with a seat free and the devices are ready. */
export function canJoin(model: WaitingRoomModel, context: JoinContext): boolean {
    return model.state === 'active' && model.sessionId !== null && !model.full && context.mediaReady && !context.busy;
}

/**
 * What to feed the start-of-lineup chime. Only definite readings count: a failed
 * read must not look like "no lineup", or an already-running lineup would chime.
 */
export function alertObservation(model: WaitingRoomModel): { active: boolean; sessionId: string | null } | null {
    if (model.state === 'active') return { active: true, sessionId: model.sessionId };
    if (model.state === 'not_started' || model.state === 'starting' || model.state === 'ended') {
        return { active: false, sessionId: null };
    }

    return null;
}

/** Milliseconds until the idle preview must be released (0 when due). */
export function idleRemainingMs(idleSince: number, now: number, limitMs = IDLE_PREVIEW_RELEASE_MS): number {
    return Math.max(0, limitMs - Math.max(0, now - idleSince));
}

export interface MediaReleaseInput {
    model: WaitingRoomModel;
    now: number;
    /** A Join (or any token request or connect) is in flight. */
    joinInFlight: boolean;
    /** When the current stretch of idleness began, in epoch milliseconds. */
    idleSince: number;
    /** A starting or live lineup was observed while this page was open. */
    sawLive: boolean;
}

export type MediaReleaseDecision =
    | { action: 'keep'; idleSince: number }
    | { action: 'release'; reason: 'idle' | 'ended' };

/** True within the hold window before today's scheduled start (and never once the time is unknown). */
export function isNearScheduledStart(scheduledFor: string | null, now: number): boolean {
    if (scheduledFor === null) return false;
    const start = Date.parse(scheduledFor);

    return Number.isFinite(start) && now >= start - NEAR_START_HOLD_MS && now <= start;
}

/**
 * Decides whether the preview camera and microphone stay on. They are held (and the idle
 * clock restarts) while a join is in flight, while the lineup is starting or live, and from
 * 15 minutes before the scheduled start. Only after 10 minutes of NOT live and not near the
 * start are they released. When a lineup this page watched go live has ended, they are
 * released at once. Releasing is always followed by an explicit "turn camera back on".
 */
export function decideMediaRelease(input: MediaReleaseInput): MediaReleaseDecision {
    const { model, now, joinInFlight, idleSince, sawLive } = input;
    if (joinInFlight) return { action: 'keep', idleSince: now };
    if (model.state === 'ended' && sawLive) return { action: 'release', reason: 'ended' };
    if (model.state === 'starting' || model.state === 'active' || isNearScheduledStart(model.scheduledFor, now)) {
        return { action: 'keep', idleSince: now };
    }
    if (idleRemainingMs(idleSince, now) === 0) return { action: 'release', reason: 'idle' };

    return { action: 'keep', idleSince };
}

export interface WaitingRoomCopyContext {
    /** True when this member was already in the lineup that is still running. */
    rejoin: boolean;
    formatTime: (iso: string) => string;
}

export interface WaitingRoomCopy {
    headline: string;
    detail: string;
    footnote: string;
    /** Visual tone: ready (live), muted (ended), warn (cannot reach), idle (waiting). */
    tone: 'idle' | 'ready' | 'muted' | 'warn';
    joinLabel: string;
    /** True when a "check again" control is useful (server unreachable or unavailable). */
    offersRecheck: boolean;
}

const NOT_CONNECTED_FOOTNOTE = 'You are not connected to the conference yet. Waiting uses no call minutes.';
export const ROOM_FULL_MESSAGE = 'Morning Lineup is full right now. You can watch for a seat to open; try again in a moment.';
export const FINISH_SETUP_MESSAGE = 'Finish setting up your account first. Change your temporary password or complete onboarding, then come back to join.';

export function describeWaitingRoom(model: WaitingRoomModel, context: WaitingRoomCopyContext): WaitingRoomCopy {
    const joinLabel = context.rejoin && model.state === 'active' ? 'Rejoin Morning Lineup' : 'Join Morning Lineup';
    const base = { joinLabel, offersRecheck: false };
    switch (model.state) {
        case 'not_started':
            return {
                ...base,
                tone: 'idle',
                headline: 'Waiting for Morning Lineup to start',
                detail: (model.scheduledFor ? `It is scheduled for ${context.formatTime(model.scheduledFor)}. ` : '')
                    + 'The Join button appears here as soon as 300 starts it.',
                footnote: NOT_CONNECTED_FOOTNOTE,
            };
        case 'starting':
            return {
                ...base,
                tone: 'idle',
                headline: 'Morning Lineup is starting',
                detail: 'Almost there. The Join button appears in a moment.',
                footnote: NOT_CONNECTED_FOOTNOTE,
            };
        case 'active':
            return {
                ...base,
                tone: model.full ? 'idle' : 'ready',
                headline: context.rejoin ? 'Morning Lineup is still live' : 'Morning Lineup is live',
                detail: model.full
                    ? ROOM_FULL_MESSAGE
                    : (context.rejoin ? 'You can rejoin now. ' : 'Press Join when you are ready. ')
                        + (model.endsAt ? `It ends automatically at ${context.formatTime(model.endsAt)}.` : ''),
                footnote: model.full
                    ? 'You are not connected. We keep checking and the Join button turns on when a seat opens.'
                    : 'Your camera and microphone are shared only after you press Join.',
            };
        case 'ended':
            return {
                ...base,
                tone: 'muted',
                headline: 'Morning Lineup has ended',
                detail: (model.endedAt ? `It ended at ${context.formatTime(model.endedAt)}. ` : '')
                    + 'If 300 starts it again, the Join button appears here.',
                footnote: 'You can safely close this page or go back to the Hub.',
            };
        case 'unavailable':
            return {
                ...base,
                tone: 'warn',
                offersRecheck: true,
                headline: 'Morning Lineup is not available right now',
                detail: 'The video service is not ready. Nothing you did is affected, and we keep checking.',
                footnote: NOT_CONNECTED_FOOTNOTE,
            };
        case 'disabled':
            return {
                ...base,
                tone: 'warn',
                offersRecheck: true,
                headline: 'Video conferencing is turned off right now',
                detail: 'Morning Lineup cannot start until it is turned back on. Nothing you did is affected.',
                footnote: NOT_CONNECTED_FOOTNOTE,
            };
        case 'offline':
            return {
                ...base,
                tone: 'warn',
                offersRecheck: true,
                headline: 'Cannot reach the Hub',
                detail: 'Your Internet connection may be down. We keep trying, and nothing you did is lost.',
                footnote: NOT_CONNECTED_FOOTNOTE,
            };
        case 'server_error':
            return {
                ...base,
                tone: 'warn',
                offersRecheck: true,
                headline: 'The Hub had a problem. Try again.',
                detail: 'Something went wrong on the Hub side, so there is nothing for you to fix. We keep trying.',
                footnote: NOT_CONNECTED_FOOTNOTE,
            };
        case 'throttled':
            return {
                ...base,
                tone: 'idle',
                headline: 'Too many checks. Waiting a moment.',
                detail: 'The Hub asked this page to slow down. We check again by ourselves, and nothing you did is lost.',
                footnote: NOT_CONNECTED_FOOTNOTE,
            };
        case 'forbidden':
            return {
                ...base,
                tone: 'warn',
                offersRecheck: true,
                headline: 'Finish setting up your account first',
                detail: 'Change your temporary password or complete onboarding in the Hub, then come back here to join.',
                footnote: NOT_CONNECTED_FOOTNOTE,
            };
        case 'signed_out':
            return {
                ...base,
                tone: 'warn',
                offersRecheck: true,
                headline: 'Your Hub sign-in expired',
                detail: 'Reload this page and sign in again to see whether Morning Lineup is on.',
                footnote: NOT_CONNECTED_FOOTNOTE,
            };
        default:
            return {
                ...base,
                tone: 'idle',
                headline: 'Checking Morning Lineup',
                detail: 'Looking up whether it has started.',
                footnote: NOT_CONNECTED_FOOTNOTE,
            };
    }
}

export interface JoinFailureInput {
    /** HTTP status of the failed call, or null when the request never reached the Hub. */
    status: number | null;
    code: string | null;
}

export interface JoinFailureOutcome {
    /** "takeover" opens the confirm dialog, "notice" is not an error, "error" is. */
    kind: 'takeover' | 'notice' | 'error';
    message: string;
    /** Re-read the lineup state, because the server just told us it differs from what we showed. */
    refresh: boolean;
}

/** Member-facing words for a failed Join: what happened, whether anything was lost, what to do next. */
export function describeJoinFailure(failure: JoinFailureInput): JoinFailureOutcome {
    if (failure.code === 'endpoint_in_use') {
        return {
            kind: 'takeover',
            message: 'You are already connected to this Morning Lineup from another tab or device.',
            refresh: false,
        };
    }
    if (failure.code === 'room_full') {
        return { kind: 'notice', message: ROOM_FULL_MESSAGE, refresh: true };
    }
    if (failure.code === 'lineup_not_started') {
        return {
            kind: 'notice',
            message: 'Morning Lineup has not started yet. The Join button appears here as soon as it does.',
            refresh: true,
        };
    }
    if (failure.status === 410) {
        return { kind: 'notice', message: 'Morning Lineup has just ended.', refresh: true };
    }
    if (failure.status === 401 || failure.status === 419) {
        return {
            kind: 'error',
            message: 'Your Hub sign-in expired. Reload this page, sign in again, then press Join.',
            refresh: false,
        };
    }
    if (failure.status === 403) {
        return { kind: 'error', message: `${FINISH_SETUP_MESSAGE} Then press Join.`, refresh: true };
    }
    if (failure.status === 429) {
        return { kind: 'error', message: 'Too many attempts in a row. Wait a minute, then press Join again.', refresh: false };
    }
    if (failure.status === 503 && failure.code === 'conference_disabled') {
        return {
            kind: 'error',
            message: 'Video conferencing is turned off right now. Press Join again once it is back on.',
            refresh: true,
        };
    }
    if (failure.status === 503) {
        return {
            kind: 'error',
            message: 'The conference room is still getting ready. Wait a few seconds, then press Join again. Nothing was lost.',
            refresh: true,
        };
    }
    if (failure.status === null) {
        return {
            kind: 'error',
            message: 'We could not reach the Hub. Check your Internet connection, then press Join again.',
            refresh: false,
        };
    }
    if (failure.status >= 500) {
        return { kind: 'error', message: 'The Hub had a problem. Nothing was lost. Press Join to try again.', refresh: true };
    }

    return {
        kind: 'error',
        message: 'Morning Lineup could not be joined. Nothing on your account was changed. Press Join to try again.',
        refresh: true,
    };
}
