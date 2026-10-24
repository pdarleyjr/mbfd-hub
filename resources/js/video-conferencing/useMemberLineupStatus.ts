import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError, getJson } from './api';
import { subscribeToLineupChanges } from './conference-events';
import type { ConferenceBootstrap } from './types';
import {
    initialWaitingRoom,
    isMemberLineupStatus,
    memberPollBaseMs,
    nextPollDelayMs,
    observeFailure,
    observeStatus,
    type WaitingRoomModel,
} from './waiting-room';

/**
 * Observes today's Morning Lineup for a member who has not joined. It only
 * reads: the status endpoint never creates a session, participation or token.
 * Polling is the authority; the Reverb nudge (state-only payload on the public
 * lineup channel) just makes it prompt. Reads happen only while `enabled`.
 *
 * A read requested while another is in flight is queued and runs right after it,
 * so "the lineup just ended" is never followed by a stale answer for a whole interval.
 */
export function useMemberLineupStatus(
    bootstrap: ConferenceBootstrap,
    enabled: boolean,
    refreshToken: number,
): { model: WaitingRoomModel; recheck: () => Promise<void> } {
    const [model, setModel] = useState<WaitingRoomModel>(initialWaitingRoom);
    const [hidden, setHidden] = useState(() => document.hidden);
    const inFlightRef = useRef(false);
    const queuedRef = useRef(false);
    const enabledRef = useRef(enabled);
    const url = bootstrap.endpoints.lineup_status;
    const baseMs = memberPollBaseMs(bootstrap.status_poll_ms);

    useEffect(() => {
        enabledRef.current = enabled;
    }, [enabled]);

    const recheck = useCallback(async () => {
        if (inFlightRef.current) {
            queuedRef.current = true;

            return;
        }
        inFlightRef.current = true;
        try {
            do {
                queuedRef.current = false;
                try {
                    const status: unknown = await getJson<unknown>(url);
                    // A 200 that is not a lineup status (captive portal, proxy page) is a failed read.
                    if (!isMemberLineupStatus(status)) throw new ApiError('Unexpected response.', 200, { code: 'invalid_response' });
                    setModel((previous) => observeStatus(previous, status));
                } catch (error) {
                    const known = error instanceof ApiError ? error : null;
                    const code = known !== null && typeof known.payload.code === 'string' ? known.payload.code : null;
                    setModel((previous) => observeFailure(previous, known?.status ?? null, {
                        code,
                        retryAfterSeconds: known?.retryAfterSeconds ?? null,
                    }));
                }
            } while (queuedRef.current && enabledRef.current);
        } finally {
            inFlightRef.current = false;
            queuedRef.current = false;
        }
    }, [url]);

    // Read right away when observing starts, resumes, or the join attempt learned something new.
    useEffect(() => {
        if (enabled) void recheck();
    }, [enabled, recheck, refreshToken]);

    // Every completed read produces a new model, which schedules the next one (with jitter,
    // slower when the tab is hidden, and after Retry-After when the Hub asked this page to slow down).
    useEffect(() => {
        if (!enabled) return undefined;
        const delay = nextPollDelayMs(model, baseMs, hidden);
        if (delay === null) return undefined;
        const timer = window.setTimeout(() => void recheck(), delay);

        return () => window.clearTimeout(timer);
    }, [baseMs, enabled, hidden, model, recheck]);

    // Hidden tabs throttle timers, so read straight away when the page returns or the network does.
    useEffect(() => {
        const onVisibility = () => {
            setHidden(document.hidden);
            if (!document.hidden && enabledRef.current) void recheck();
        };
        const onOnline = () => {
            if (enabledRef.current) void recheck();
        };
        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('online', onOnline);

        return () => {
            document.removeEventListener('visibilitychange', onVisibility);
            window.removeEventListener('online', onOnline);
        };
    }, [recheck]);

    useEffect(() => {
        let unsubscribe: () => void = () => undefined;
        let disposed = false;
        void subscribeToLineupChanges(bootstrap, () => {
            if (enabledRef.current) void recheck();
        }).then((subscribed) => {
            if (disposed) subscribed();
            else unsubscribe = subscribed;
        }).catch(() => undefined);

        return () => {
            disposed = true;
            unsubscribe();
        };
    }, [bootstrap, recheck]);

    return { model, recheck };
}
