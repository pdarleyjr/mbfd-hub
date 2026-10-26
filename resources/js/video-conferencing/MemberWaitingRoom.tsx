import { useEffect, useMemo, useRef, useState } from 'react';
import { CameraOff, CircleAlert, Clock3, LoaderCircle, Radio, RefreshCw, Sparkles, Video } from 'lucide-react';
import lineupStartTone from './lineup-start.mp3';
import { LineupStartAlert } from './session-transition';
import type { ConferenceBootstrap } from './types';
import { useMemberLineupStatus } from './useMemberLineupStatus';
import { ServerClock, formatConferenceTime } from './clock';
import {
    alertObservation,
    canJoin,
    canWaitForLineup,
    decideMediaRelease,
    describeWaitingRoom,
} from './waiting-room';
import './waiting-room.css';

export type MediaReleaseReason = 'idle' | 'ended';

export interface MemberWaitingRoomProps {
    bootstrap: ConferenceBootstrap;
    /** False while joining or connected: no reads, no chime. */
    observing: boolean;
    /** Microphone is ready, the same gate the Join button always had. */
    mediaReady: boolean;
    /** Camera or microphone preview is live on this device. */
    previewActive: boolean;
    /** Why the preview was turned off, if it was. */
    releasedReason: MediaReleaseReason | null;
    /** A join attempt is in flight. */
    busy: boolean;
    /** Lineup session this page was last connected to, for "Rejoin". */
    lastJoinedSessionId: string | null;
    /** Bumped when a join attempt learned the lineup state changed. */
    refreshToken: number;
    /** True only when the server says to keep waiting (not started or no seat). */
    onJoin: () => Promise<boolean>;
    onIdleRelease: (reason: MediaReleaseReason) => void;
    onResumeMedia: () => void;
}

const MEDIA_CHECK_MS = 15_000;

function releaseHint(reason: MediaReleaseReason | null, live: boolean, mediaReady: boolean, full: boolean): string | null {
    if (reason === 'ended') {
        return `Morning Lineup ended, so your camera and microphone were turned off.${live ? ' Turn them back on to join.' : ''}`;
    }
    if (reason === 'idle') {
        return `Your camera and microphone were turned off after 10 minutes of waiting. ${live ? 'Turn them back on to join.' : 'Nothing stays live on this device.'}`;
    }

    return live && !mediaReady && !full ? 'Turn your camera and microphone on to join.' : null;
}

/**
 * The member's waiting room: shows the lineup's real state next to the existing
 * device check. An explicit Join gives consent to wait and join when 300 starts;
 * opening the page alone never admits a member. ConferenceApp still owns admission.
 */
export default function MemberWaitingRoom(props: MemberWaitingRoomProps) {
    const { bootstrap, previewActive } = props;
    const { model, recheck } = useMemberLineupStatus(bootstrap, props.observing, props.refreshToken);
    const [waitingRequested, setWaitingRequested] = useState(false);
    const waitingRef = useRef(false);
    const attemptedModelRef = useRef<typeof model | null>(null);
    const nextAttemptAtRef = useRef(0);
    const waitingSessionRef = useRef<string | null>(null);
    const serverClockRef = useRef(new ServerClock(bootstrap.server_time));
    const alertRef = useRef(new LineupStartAlert());
    const idleReleaseRef = useRef(props.onIdleRelease);
    const modelRef = useRef(model);
    const busyRef = useRef(props.busy);
    const sawLiveRef = useRef(false);
    const evaluateRef = useRef<() => void>(() => undefined);
    useEffect(() => {
        idleReleaseRef.current = props.onIdleRelease;
        modelRef.current = model;
        busyRef.current = props.busy;
        waitingRef.current = waitingRequested;
        if (model.state === 'starting' || model.state === 'active') sawLiveRef.current = true;
    }, [model, props.busy, props.onIdleRelease, waitingRequested]);

    useEffect(() => {
        serverClockRef.current.sync(model.serverTime);
    }, [model.serverTime]);

    useEffect(() => {
        if (['ended', 'signed_out', 'forbidden', 'disabled'].includes(model.state)) {
            setWaitingRequested(false);
            return;
        }
        if (waitingSessionRef.current !== null && (model.state === 'not_started'
            || (model.state === 'active' && model.sessionId !== waitingSessionRef.current))) {
            setWaitingRequested(false);
            return;
        }
        if (waitingRequested && model.state === 'active') waitingSessionRef.current = model.sessionId;
        if (!waitingRequested || !props.observing || !canJoin(model, { mediaReady: props.mediaReady, busy: props.busy })) return;
        if (attemptedModelRef.current === model || performance.now() < nextAttemptAtRef.current) return;
        attemptedModelRef.current = model;
        nextAttemptAtRef.current = performance.now() + 10_000;
        void props.onJoin().then((keepWaiting) => {
            if (!keepWaiting) setWaitingRequested(false);
        }).catch(() => setWaitingRequested(false));
    }, [model, props.busy, props.mediaReady, props.observing, props.onJoin, waitingRequested]);

    const standingBy = props.observing && previewActive && !props.busy;
    useEffect(() => {
        const observation = alertObservation(model);
        if (observation && alertRef.current.observe(observation.active, observation.sessionId, standingBy)) {
            void new Audio(lineupStartTone).play().catch(() => undefined);
        }
    }, [model, standingBy]);

    // The camera and microphone stay on while a join is in flight, while the lineup is starting
    // or live, and from 15 minutes before the scheduled start. They are released only after
    // 10 minutes of not-live idleness, and at once when a lineup this page watched has ended.
    useEffect(() => {
        if (!previewActive) return undefined;
        let idleSince = serverClockRef.current.now() ?? 0;
        const evaluate = () => {
            const decision = decideMediaRelease({
                model: modelRef.current,
                now: serverClockRef.current.now() ?? 0,
                joinInFlight: busyRef.current,
                waitingRequested: waitingRef.current,
                idleSince,
                sawLive: sawLiveRef.current,
            });
            if (decision.action === 'release') idleReleaseRef.current(decision.reason);
            else idleSince = decision.idleSince;
        };
        evaluateRef.current = evaluate;
        const timer = window.setInterval(evaluate, MEDIA_CHECK_MS);
        const onVisible = () => {
            if (!document.hidden) evaluate();
        };
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            evaluateRef.current = () => undefined;
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [previewActive]);

    // A lineup that just ended (or a join that just finished) is acted on now, not at the next tick.
    useEffect(() => {
        evaluateRef.current();
    }, [model.state, props.busy]);

    const rejoin = props.lastJoinedSessionId !== null && props.lastJoinedSessionId === model.sessionId;
    const copy = useMemo(() => describeWaitingRoom(model, {
        rejoin,
        formatTime: (iso) => formatConferenceTime(iso, bootstrap.timezone ?? 'America/New_York'),
    }), [bootstrap.timezone, model, rejoin]);
    const joinable = !waitingRequested && canWaitForLineup(model, { mediaReady: props.mediaReady, busy: props.busy });
    const Icon = model.state === 'checking' || model.state === 'starting'
        ? LoaderCircle
        : copy.tone === 'warn' ? CircleAlert : copy.tone === 'muted' ? Clock3 : Radio;
    const hint = releaseHint(props.releasedReason, model.state === 'active', props.mediaReady, model.full);

    return (
        <div className="vc-self-entry" data-lineup-state={model.state}>
            <div className="vc-room-summary"><Sparkles size={22} /><div><strong>Join as {bootstrap.display_name}</strong><span>This is the name everyone in the call will see.</span></div></div>
            <div className="vc-standby vc-wait" data-ready={copy.tone === 'ready'} data-tone={copy.tone}>
                <Icon size={34} className={Icon === LoaderCircle ? 'vc-spin' : undefined} aria-hidden="true" />
                {/* The only live region in the waiting room: one polite announcement per change. */}
                <div id="vc-wait-status" className="vc-wait__text" role="status" aria-live="polite">
                    <strong>{copy.headline}</strong>
                    <span>{waitingRequested
                        ? model.full
                            ? 'You are waiting for a seat. You will join automatically when one opens.'
                            : 'You are in the lobby. You will join automatically when 300 starts Morning Lineup.'
                        : copy.detail}</span>
                </div>
                <small>{waitingRequested ? 'Your camera and microphone will be shared when you join. You can cancel waiting below.' : copy.footnote}</small>
                {copy.offersRecheck && (model.state === 'signed_out'
                    ? <button type="button" className="vc-button vc-button--secondary" onClick={() => window.location.reload()}><RefreshCw size={18} /> Reload page</button>
                    : <button type="button" className="vc-button vc-button--secondary" onClick={() => void recheck()}><RefreshCw size={18} /> Check again</button>)}
            </div>
            {/* aria-disabled, not disabled: keyboard and screen-reader users can reach the button and hear why it is off. */}
            <button
                className="vc-button vc-button--primary"
                type="button"
                aria-disabled={!joinable}
                aria-busy={props.busy}
                aria-describedby={hint ? 'vc-wait-status vc-join-hint' : 'vc-wait-status'}
                onClick={() => {
                    if (joinable) {
                        waitingSessionRef.current = model.state === 'active' ? model.sessionId : null;
                        setWaitingRequested(true);
                    }
                }}
            >
                {props.busy ? <LoaderCircle size={20} className="vc-spin" /> : <Video size={20} />} {props.busy ? 'Joining Morning Lineup' : waitingRequested ? 'Waiting to join Morning Lineup' : copy.joinLabel}
            </button>
            {waitingRequested && !props.busy && <button type="button" className="vc-button vc-button--secondary" onClick={() => setWaitingRequested(false)}>Cancel waiting</button>}
            {hint && <p id="vc-join-hint" className="vc-wait__hint">{hint}</p>}
            {!props.mediaReady && props.releasedReason === null && <p className="vc-wait__hint">Microphone access is needed to join. Allow microphone access in your browser and try the device check again.</p>}
            {props.releasedReason !== null && <button type="button" className="vc-button vc-button--secondary" onClick={props.onResumeMedia}><CameraOff size={18} /> Turn camera back on</button>}
        </div>
    );
}
