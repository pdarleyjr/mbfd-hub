import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    Camera,
    CameraOff,
    ArrowLeft,
    Cast,
    Check,
    CircleAlert,
    Clock3,
    Headphones,
    House,
    LoaderCircle,
    Mic,
    MicOff,
    PhoneOff,
    Pin,
    Radio,
    RefreshCw,
    ShieldCheck,
    Users,
    Video,
    Volume2,
} from 'lucide-react';
import {
    createLocalTracks,
    DisconnectReason,
    setLogLevel,
    LocalTrack,
    LocalVideoTrack,
    Participant,
    RemoteTrackPublication,
    Room,
    RoomEvent,
    RpcError,
    supportsAudioOutputSelection,
    Track,
    VideoPresets,
    VideoQuality,
} from 'livekit-client';
import { clearBusy, markBusy } from '../pwa/busy';
import { ApiError, getJson, postJson } from './api';
import { subscribeToLineupChanges } from './conference-events';
import { ServerClock } from './clock';
import {
    deviceLabel,
    enumerateMediaDevices,
    isMediaPermissionDenied,
    mediaErrorMessage,
    testSpeakerOutput,
    type MediaDevices,
} from './media';
import { ConferenceStage } from './ConferenceStage';
import { ConferenceDialog } from './ConferenceDialog';
import { buildGallerySlots, expectedEndpointIdentities } from './gallery-order';
import { InactiveConfirmation } from './inactive-confirmation';
import { LineupStartAlert } from './session-transition';
import type { MediaReleaseReason, MemberWaitingRoomProps } from './MemberWaitingRoom';
import lineupStartTone from './lineup-start.mp3';
import {
    accumulateInboundRtcStats,
    emptyInboundRtcAccumulator,
    type InboundRtcSample,
} from './rtc-stats';
import type {
    CommandStatusResponse,
    ConferenceUsage,
    ConferenceBootstrap,
    ConferencePhase,
    JoinRole,
    LineupState,
    SessionResponse,
    StationReadiness,
    StationRole,
    StationStatusResponse,
    TokenResponse,
} from './types';

// The member waiting room (status polling, copy, idle release) loads on demand so the
// always-loaded bundle stays small. If it cannot load, a plain Join button still works.
const MemberWaitingRoom = lazy(() => import('./MemberWaitingRoom').catch(() => ({
    default: (fallback: MemberWaitingRoomProps) => <button className="vc-button vc-button--primary" type="button" disabled={fallback.busy || !fallback.mediaReady} onClick={() => fallback.onJoin()}>Join Morning Lineup</button>,
})));

interface ConferenceAppProps {
    bootstrap: ConferenceBootstrap;
    /**
     * True when the app is hosted inside a Filament page that already renders the
     * shared Back / Hub Home group. The app then omits its own navigation so the
     * page shows exactly one set. Standalone pages keep the built-in navigation.
     */
    embedded?: boolean;
}

const emptyDevices: MediaDevices = { cameras: [], microphones: [], speakers: [] };
const stationOptions: Array<{ value: StationRole; label: string }> = [
    { value: 'sta1', label: 'Station 1' },
    { value: 'sta2', label: 'Station 2' },
    { value: 'sta3', label: 'Station 3' },
    { value: 'sta4', label: 'Station 4' },
    { value: 'sta6', label: 'Station 6' },
];
const emptyLineup: LineupState = { active: false, session_id: null, started_at: null, ends_at: null };

const MAX_REJOIN_ATTEMPTS = 3;
const REJOIN_DELAYS_MS = [2_000, 6_000, 12_000];
/** A connection this old is considered healthy, so the next drop gets a fresh set of attempts. */
const STABLE_CONNECTION_MS = 60_000;
const DROPPED_MESSAGE = 'You were disconnected from Morning Lineup and your camera and microphone were turned off. If it is still running, you can rejoin below.';
const SUPERSEDED_MESSAGE = 'This endpoint connected from another device or browser tab, so this connection was closed.';

// SDK debug/info output includes signaling URLs. Never emit it in production.
if (import.meta.env.PROD) setLogLevel('warn');

function isStationRole(role: JoinRole): role is StationRole {
    return role.startsWith('sta');
}

function stationLabel(role: StationRole): string {
    return stationOptions.find((station) => station.value === role)?.label ?? role;
}

export function ConferenceApp({ bootstrap, embedded = false }: ConferenceAppProps) {
    const forceRelay = useMemo(
        () => new URLSearchParams(window.location.search).get('force_relay') === '1',
        [],
    );
    const [phase, setPhase] = useState<ConferencePhase>('requesting_media');
    const [devices, setDevices] = useState<MediaDevices>(emptyDevices);
    const [cameraId, setCameraId] = useState('');
    const [microphoneId, setMicrophoneId] = useState('');
    const [speakerId, setSpeakerId] = useState('');
    const [cameraReady, setCameraReady] = useState(false);
    const [microphoneReady, setMicrophoneReady] = useState(false);
    const [cameraEnabled, setCameraEnabled] = useState(true);
    const [microphoneEnabled, setMicrophoneEnabled] = useState(true);
    const [screenShareEnabled, setScreenShareEnabled] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [takeover, setTakeover] = useState(false);
    const [participantRefresh, setParticipantRefresh] = useState(0);
    const [manualPin, setManualPin] = useState<string | null>(null);
    const [controlsOpen, setControlsOpen] = useState(false);
    const [confirmEnd, setConfirmEnd] = useState(false);
    const [usage, setUsage] = useState<ConferenceUsage | null>(null);
    const [audioBlocked, setAudioBlocked] = useState(false);
    const [forcedStationMic, setForcedStationMic] = useState(false);
    const [speakerTesting, setSpeakerTesting] = useState(false);
    const [actionBusy, setActionBusy] = useState<string | null>(null);
    // Server-side policy decision from the bootstrap; every command endpoint re-checks it live.
    const [commandAuthorized, setCommandAuthorized] = useState(bootstrap.command_access === true);
    const [commandDeniedMessage, setCommandDeniedMessage] = useState<string | null>(null);
    const [providerApiHealthy, setProviderApiHealthy] = useState<boolean | null>(null);
    const [lineup, setLineup] = useState<LineupState>(emptyLineup);
    const [stationReadiness, setStationReadiness] = useState<StationReadiness[]>([]);
    const [sessionId, setSessionId] = useState<string | null>(null);
    const [roomMode, setRoomMode] = useState<'lineup' | 'direct'>('lineup');
    const [pendingDirectStation, setPendingDirectStation] = useState<StationRole | null>(null);
    const [room, setRoom] = useState<Room | null>(null);
    const serverClockRef = useRef(new ServerClock(bootstrap.server_time));
    const [clock, setClock] = useState(() => serverClockRef.current.now());
    // Member waiting room: preview released while idle, the lineup last joined, and a nudge to re-read its state.
    const [releasedReason, setReleasedReason] = useState<MediaReleaseReason | null>(null);
    const idleReleased = releasedReason !== null;
    const [lastJoinedSessionId, setLastJoinedSessionId] = useState<string | null>(null);
    const [waitRefresh, setWaitRefresh] = useState(0);

    const previewVideoRef = useRef<HTMLVideoElement>(null);
    const previewTracksRef = useRef<LocalTrack[]>([]);
    const roomRef = useRef<Room | null>(null);
    const participationIdRef = useRef<string | null>(null);
    const joiningRef = useRef(false);
    // True from the instant Join is pressed until the attempt ends, set synchronously (state would lag a timer).
    const joinInFlightRef = useRef(false);
    const stationTokenRequestRef = useRef(false);
    const stationStatusRequestRef = useRef(false);
    const commandStatusRequestRef = useRef(false);
    const isLeavingRef = useRef(false);
    const autoMediaStartedRef = useRef(false);
    const activeLineupSeenRef = useRef(false);
    const activeDirectSeenRef = useRef(false);
    const stationOptedOutRef = useRef(false);
    const terminatingRef = useRef(false);
    const connectedSessionRef = useRef<string | null>(null);
    const alertRef = useRef(new LineupStartAlert());
    const inboundRtcAccumulatorRef = useRef(emptyInboundRtcAccumulator());
    const stationInactiveRef = useRef(new InactiveConfirmation());
    const commandInactiveRef = useRef(new InactiveConfirmation());
    const selfInactiveRef = useRef(new InactiveConfirmation());
    const lineupEndsAtRef = useRef<string | null>(null);
    const rejoinAttemptsRef = useRef(0);
    const connectedAtRef = useRef(0);
    const userLeftRef = useRef(false);
    const firstSeenRef = useRef(new Map<string, number>());
    const recoverRef = useRef<(superseded: boolean, isRetry?: boolean) => Promise<void>>(async () => undefined);

    const refreshDevices = useCallback(async (requestPermissions = false) => {
        try {
            const next = await enumerateMediaDevices(requestPermissions);
            setDevices(next);
            setCameraId((current) => next.cameras.some((device) => device.deviceId === current)
                ? current : (next.cameras[0]?.deviceId ?? ''));
            setMicrophoneId((current) => next.microphones.some((device) => device.deviceId === current)
                ? current : (next.microphones[0]?.deviceId ?? ''));
            setSpeakerId((current) => next.speakers.some((device) => device.deviceId === current)
                ? current : (next.speakers[0]?.deviceId ?? ''));
        } catch (deviceError) {
            setError(mediaErrorMessage(deviceError));
        }
    }, []);

    const stopPreview = useCallback(() => {
        previewTracksRef.current.forEach((track) => {
            track.detach();
            track.stop();
        });
        previewTracksRef.current = [];
        if (previewVideoRef.current) previewVideoRef.current.srcObject = null;
    }, []);

    const notifyLeave = useCallback((participationId: string) => {
        const station = bootstrap.entry_mode === 'station';
        const base = station ? bootstrap.endpoints.station_participation_base : `${bootstrap.endpoints.api_base}/participations`;
        void fetch(`${base}/${participationId}/leave`, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': bootstrap.csrf_token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(station ? { launch_context: bootstrap.launch_context } : {}),
        }).catch(() => undefined);
    }, [bootstrap]);

    const notifyStandDown = useCallback(() => {
        if (bootstrap.entry_mode !== 'station' || !bootstrap.launch_context) return;
        void fetch(bootstrap.endpoints.station_stand_down, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': bootstrap.csrf_token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ launch_context: bootstrap.launch_context }),
        }).catch(() => undefined);
    }, [bootstrap]);

    const cleanupRoom = useCallback(async (notifyServer = true) => {
        if (isLeavingRef.current) return;
        isLeavingRef.current = true;
        const activeRoom = roomRef.current;
        roomRef.current = null;
        setRoom(null);
        if (activeRoom) {
            activeRoom.unregisterRpcMethod('mbfd.stationMic');
            activeRoom.removeAllListeners();
            await activeRoom.disconnect(true).catch(() => undefined);
        }
        if (notifyServer && participationIdRef.current) notifyLeave(participationIdRef.current);
        participationIdRef.current = null;
        setSessionId(null);
        setForcedStationMic(false);
        setScreenShareEnabled(false);
        setManualPin(null);
        joiningRef.current = false;
        isLeavingRef.current = false;
    }, [notifyLeave]);

    const returnFromConference = useCallback(async (target = bootstrap.navigation.back, ended = true) => {
        if (terminatingRef.current) return;
        terminatingRef.current = true;
        stationOptedOutRef.current = true;
        setError(null);
        setPhase('leaving');
        stopPreview();
        await cleanupRoom(!ended);
        if (!ended) notifyStandDown();
        window.location.replace(target);
    }, [bootstrap.navigation.back, cleanupRoom, notifyStandDown, stopPreview]);

    const attachRoomEvents = useCallback((nextRoom: Room) => {
        const refresh = () => setParticipantRefresh((value) => value + 1);
        nextRoom.on(RoomEvent.ParticipantConnected, refresh);
        nextRoom.on(RoomEvent.ParticipantDisconnected, (participant) => {
            setManualPin((identity) => identity === participant.identity ? null : identity);
            refresh();
        });
        nextRoom.on(RoomEvent.TrackPublished, refresh);
        nextRoom.on(RoomEvent.TrackUnpublished, refresh);
        nextRoom.on(RoomEvent.TrackSubscribed, refresh);
        nextRoom.on(RoomEvent.TrackUnsubscribed, refresh);
        nextRoom.on(RoomEvent.TrackMuted, refresh);
        nextRoom.on(RoomEvent.TrackUnmuted, refresh);
        nextRoom.on(RoomEvent.ActiveSpeakersChanged, refresh);
        nextRoom.on(RoomEvent.LocalTrackPublished, refresh);
        nextRoom.on(RoomEvent.LocalTrackUnpublished, (publication) => {
            // The browser's own "Stop sharing" bar ends the track without going through our button.
            if (publication.source === Track.Source.ScreenShare) setScreenShareEnabled(false);
            refresh();
        });
        nextRoom.on(RoomEvent.ConnectionQualityChanged, refresh);
        nextRoom.on(RoomEvent.MediaDevicesChanged, () => void refreshDevices(false));
        nextRoom.on(RoomEvent.MediaDevicesError, (deviceError) => setError(mediaErrorMessage(deviceError)));
        nextRoom.on(RoomEvent.Reconnecting, () => setPhase('reconnecting'));
        nextRoom.on(RoomEvent.Reconnected, () => setPhase('connected'));
        nextRoom.on(RoomEvent.Disconnected, (reason) => {
            if (reason === DisconnectReason.ROOM_DELETED) {
                void returnFromConference();
                return;
            }
            if (isLeavingRef.current) return;
            // Another device taking over this endpoint must not trigger a rejoin,
            // or the two devices would keep evicting each other.
            const superseded = reason === DisconnectReason.DUPLICATE_IDENTITY
                || reason === DisconnectReason.PARTICIPANT_REMOVED;
            void cleanupRoom(true).finally(() => void recoverRef.current(superseded));
        });
    }, [cleanupRoom, refreshDevices, returnFromConference]);

    const connectWithCredentials = useCallback(async (credentials: TokenResponse, confirmedTakeover = false) => {
        if (terminatingRef.current || joiningRef.current || roomRef.current) {
            // A token was minted but will not be used: give its seat back.
            notifyLeave(credentials.participation_id);
            return;
        }
        if (previewTracksRef.current.length === 0) {
            notifyLeave(credentials.participation_id);
            setError('Your camera and microphone are off. Turn them on, then press Join again.');
            return;
        }
        joiningRef.current = true;
        setTakeover(false);
        setError(null);
        setNotice('Connecting securely…');
        setPhase('joining');
        let nextRoom: Room | null = null;
        let preparedTracks: LocalTrack[] = [];
        try {
            nextRoom = new Room({
                adaptiveStream: true,
                dynacast: true,
                videoCaptureDefaults: { resolution: VideoPresets.h720.resolution },
                publishDefaults: { simulcast: true, videoCodec: 'vp8' },
            });
            attachRoomEvents(nextRoom);
            if (bootstrap.entry_mode === 'station') {
                nextRoom.registerRpcMethod('mbfd.stationMic', async (invocation) => {
                    if (invocation.callerIdentity !== 'mbfd:300') {
                        throw new RpcError(2001, 'Only 300 may manage a station microphone.');
                    }
                    const payload = JSON.parse(invocation.payload) as { enabled?: boolean };
                    const enabled = payload.enabled === true;
                    // Applying the browser track change can wait on WebRTC
                    // renegotiation. Reflect 300's command immediately so a
                    // station never appears muted while its live track is
                    // already reaching the room.
                    setMicrophoneEnabled(enabled);
                    setForcedStationMic(!enabled);
                    setParticipantRefresh((value) => value + 1);
                    try {
                        await nextRoom!.localParticipant.setMicrophoneEnabled(enabled);
                    } catch (microphoneError) {
                        // A failed remote unmute must remain visibly muted.
                        // Remote mute is also enforced by the server, so its
                        // safe UI state remains valid if the local call fails.
                        if (enabled) {
                            setMicrophoneEnabled(false);
                            setForcedStationMic(true);
                            setParticipantRefresh((value) => value + 1);
                        }
                        throw microphoneError;
                    }

                    return JSON.stringify({ enabled });
                });
            }
            roomRef.current = nextRoom;
            await nextRoom.connect(credentials.server_url, credentials.token, {
                autoSubscribe: true,
                rtcConfig: forceRelay ? { iceTransportPolicy: 'relay' } : undefined,
                websocketTimeout: 10_000,
            });
            if (terminatingRef.current) {
                notifyLeave(credentials.participation_id);
                return;
            }

            preparedTracks = [...previewTracksRef.current];
            previewTracksRef.current = [];
            for (const track of preparedTracks) {
                if (terminatingRef.current) {
                    preparedTracks.forEach((prepared) => prepared.stop());
                    notifyLeave(credentials.participation_id);
                    return;
                }
                if (track.kind === Track.Kind.Audio && (bootstrap.entry_mode === 'station' || !microphoneEnabled)) {
                    await track.mute();
                }
                if (track.kind === Track.Kind.Video && !cameraEnabled) await track.mute();
                await nextRoom.localParticipant.publishTrack(track, {
                    simulcast: track.kind === Track.Kind.Video,
                    videoCodec: 'vp8',
                });
            }
            participationIdRef.current = credentials.participation_id;
            inboundRtcAccumulatorRef.current = emptyInboundRtcAccumulator();
            connectedSessionRef.current = credentials.session?.id ?? lineup.session_id;
            setSessionId(connectedSessionRef.current);
            setLastJoinedSessionId(connectedSessionRef.current);
            setRoom(nextRoom);
            setAudioBlocked(!nextRoom.canPlaybackAudio);
            if (nextRoom.canPlaybackAudio) await nextRoom.startAudio().catch(() => setAudioBlocked(true));
            setForcedStationMic(bootstrap.entry_mode === 'station');
            setMicrophoneEnabled(bootstrap.entry_mode !== 'station' && microphoneEnabled);
            setNotice(null);
            connectedAtRef.current = performance.now();
            userLeftRef.current = false;
            [stationInactiveRef, commandInactiveRef, selfInactiveRef].forEach((ref) => ref.current.reset());
            setPhase('connected');
        } catch (joinError) {
            // The token was minted, so a participation holds a seat (and, for a fixed endpoint, its
            // identity) until it is released. Any failed connect gives it back, or the next try would
            // meet "already connected" or a falsely full room.
            notifyLeave(credentials.participation_id);
            if (terminatingRef.current) return;
            // Tracks already handed to the room were taken from the preview list; stop any
            // that were never published so the camera light does not stay on.
            preparedTracks.forEach((track) => track.stop());
            if (nextRoom) {
                nextRoom.removeAllListeners();
                await nextRoom.disconnect(true).catch(() => undefined);
            }
            roomRef.current = null;
            setRoom(null);
            joiningRef.current = false;
            if (joinError instanceof ApiError && joinError.status === 409 && joinError.payload.code === 'endpoint_in_use') {
                setTakeover(true);
                setError(joinError.message);
            } else {
                setError('Unable to connect to the video conferencing service. Check your Internet connection and try again.');
            }
            setPhase(bootstrap.entry_mode === 'station' ? 'standing_by' : 'ready');
            if (!confirmedTakeover && bootstrap.entry_mode !== 'station') {
                void postJson(bootstrap.endpoints.connectivity_failures, bootstrap.csrf_token, {
                    stage: 'signaling',
                    room: 'lineup',
                    join_as: bootstrap.join_as,
                    failure_code: 'livekit_signaling_failed',
                    session_id: lineup.session_id,
                }).catch(() => undefined);
            }
        } finally {
            if (!roomRef.current) joiningRef.current = false;
        }
    }, [attachRoomEvents, bootstrap, cameraEnabled, forceRelay, lineup.session_id, microphoneEnabled, notifyLeave]);

    const requestStationToken = useCallback(async (requestedRoom: 'lineup' | 'direct', confirmedTakeover = false) => {
        if (terminatingRef.current || !bootstrap.launch_context || stationTokenRequestRef.current || joiningRef.current || roomRef.current) return;
        stationTokenRequestRef.current = true;
        try {
            const credentials = await postJson<TokenResponse>(
                bootstrap.endpoints.station_token,
                bootstrap.csrf_token,
                {
                    launch_context: bootstrap.launch_context,
                    room: requestedRoom,
                    confirmed_takeover: confirmedTakeover,
                },
            );
            setRoomMode(requestedRoom);
            await connectWithCredentials(credentials, confirmedTakeover);
        } catch (tokenError) {
            if (tokenError instanceof ApiError && tokenError.status === 409 && tokenError.payload.code === 'endpoint_in_use') {
                setTakeover(true);
            }
            setError(tokenError instanceof Error ? tokenError.message : 'The station could not join Morning Lineup.');
            setPhase('standing_by');
        } finally {
            stationTokenRequestRef.current = false;
        }
    }, [bootstrap, connectWithCredentials]);

    const refreshStationStatus = useCallback(async () => {
        if (!bootstrap.launch_context || stationStatusRequestRef.current || terminatingRef.current) return;
        stationStatusRequestRef.current = true;
        try {
            const status = await getJson<StationStatusResponse>(
                `${bootstrap.endpoints.station_status}?launch_context=${encodeURIComponent(bootstrap.launch_context)}`,
            );
            if (terminatingRef.current) return;
            serverClockRef.current.sync(status.lineup.server_time);
            setLineup(status.lineup);
            const current = roomMode === 'direct' ? status.direct : status.lineup;
            if (connectedSessionRef.current && (!current.active || current.session_id !== connectedSessionRef.current)) {
                // A single inactive reading may be a transient blip; only a
                // repeated one sends the station away. ROOM_DELETED stays immediate.
                if (stationInactiveRef.current.observe(true, performance.now())) await returnFromConference();
                return;
            }
            if (alertRef.current.observe(status.lineup.active, status.lineup.session_id,
                !stationOptedOutRef.current && previewTracksRef.current.length > 0 && !roomRef.current && !joiningRef.current)) {
                setNotice('Morning Lineup is starting…');
                void new Audio(lineupStartTone).play().catch(() => {
                    if (!terminatingRef.current) setNotice('Morning Lineup is starting — alert sound was blocked by your browser.');
                });
            }
            if (status.lineup.active) {
                stationInactiveRef.current.reset();
                activeLineupSeenRef.current = true;
                if (!stationOptedOutRef.current && !roomRef.current && !joiningRef.current) await requestStationToken('lineup', false);
            } else if (status.direct.active) {
                stationInactiveRef.current.reset();
                activeDirectSeenRef.current = true;
                setNotice('300 is calling this station directly. Joining automatically…');
                if (!stationOptedOutRef.current && !roomRef.current && !joiningRef.current) await requestStationToken('direct', false);
            } else if (activeLineupSeenRef.current && roomRef.current && roomMode === 'lineup') {
                if (stationInactiveRef.current.observe(true, performance.now())) await returnFromConference();
            } else if (activeDirectSeenRef.current && roomRef.current && roomMode === 'direct') {
                if (stationInactiveRef.current.observe(true, performance.now())) {
                    activeDirectSeenRef.current = false;
                    await returnFromConference();
                }
            }
        } catch (statusError) {
            setError(statusError instanceof Error ? statusError.message : 'Unable to refresh lineup status.');
        } finally {
            stationStatusRequestRef.current = false;
        }
    }, [bootstrap, returnFromConference, requestStationToken, roomMode]);

    // A 403 from any command endpoint means the live 300 policy no longer allows
    // this account (for example after a demotion); show the access-denied panel.
    const denyCommandAccess = useCallback((commandError: unknown): boolean => {
        if (!(commandError instanceof ApiError) || commandError.status !== 403) return false;
        setCommandAuthorized(false);
        setCommandDeniedMessage(commandError.message || null);
        setError(null);
        setPhase('ready');

        return true;
    }, []);

    const refreshCommandStatus = useCallback(async () => {
        if (!commandAuthorized || commandStatusRequestRef.current || terminatingRef.current) return;
        commandStatusRequestRef.current = true;
        try {
            const status = await getJson<CommandStatusResponse>(bootstrap.endpoints.command_status);
            serverClockRef.current.sync(status.lineup.server_time);
            setProviderApiHealthy(status.provider_api_healthy);
            setLineup(status.lineup);
            setStationReadiness(status.stations);
            setUsage(status.usage);
            if (status.lineup.active) activeLineupSeenRef.current = true;
            const lineupEnded = !status.lineup.active && activeLineupSeenRef.current && roomRef.current !== null && roomMode === 'lineup';
            if (commandInactiveRef.current.observe(lineupEnded, performance.now())) await returnFromConference();
        } catch (statusError) {
            if (denyCommandAccess(statusError)) return;
            else setError(statusError instanceof Error ? statusError.message : 'Unable to refresh station readiness.');
        } finally {
            commandStatusRequestRef.current = false;
        }
    }, [bootstrap.endpoints.command_status, returnFromConference, commandAuthorized, denyCommandAccess, roomMode]);

    const acquireTracks = useCallback(async (): Promise<{ tracks: LocalTrack[]; primaryError: unknown }> => {
        let tracks: LocalTrack[] = [];
        let primaryError: unknown = null;
        try {
            tracks = await createLocalTracks({
                audio: microphoneId
                    ? { deviceId: microphoneId, echoCancellation: true, noiseSuppression: true, autoGainControl: true }
                    : { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
                video: cameraId
                    ? { deviceId: cameraId, resolution: VideoPresets.h720.resolution }
                    : { resolution: VideoPresets.h720.resolution },
            });
        } catch (deviceError) {
            primaryError = deviceError;
            if (!isMediaPermissionDenied(deviceError)) {
                const partialTracks = await Promise.allSettled([
                    createLocalTracks({ video: { resolution: VideoPresets.h720.resolution }, audio: false }),
                    createLocalTracks({ audio: true, video: false }),
                ]);
                tracks = partialTracks.flatMap((result) => result.status === 'fulfilled' ? result.value : []);
            }
        }

        return { tracks, primaryError };
    }, [cameraId, microphoneId]);

    /** `keepOptedOut` re-arms the camera but leaves a superseded station from auto-rejoining. */
    const prepareMedia = useCallback(async (options: { keepOptedOut?: boolean } = {}) => {
        setError(null);
        setReleasedReason(null);
        setNotice('Requesting camera and microphone access…');
        if (!options.keepOptedOut) stationOptedOutRef.current = false;
        setPhase('requesting_media');
        stopPreview();
        const { tracks, primaryError } = await acquireTracks();
        if (tracks.length === 0) {
            setNotice(null);
            setError(mediaErrorMessage(primaryError));
            setPhase('failed');
            return;
        }

        previewTracksRef.current = tracks;
        const video = tracks.find((track): track is LocalVideoTrack => track.kind === Track.Kind.Video);
        const audio = tracks.find((track) => track.kind === Track.Kind.Audio);
        if (video && previewVideoRef.current) video.attach(previewVideoRef.current);
        setCameraReady(video !== undefined);
        setMicrophoneReady(audio !== undefined);
        setCameraEnabled(video !== undefined);
        setMicrophoneEnabled(audio !== undefined);
        await refreshDevices(false);
        setNotice(video && audio
            ? 'Camera and microphone are ready.'
            : video ? 'Camera is ready. No microphone is available.' : 'Microphone is ready. Joining audio-only.');

        if (bootstrap.entry_mode === 'station' && bootstrap.launch_context) {
            try {
                const ready = await postJson<{ station: StationReadiness }>(
                    bootstrap.endpoints.station_ready,
                    bootstrap.csrf_token,
                    {
                        launch_context: bootstrap.launch_context,
                        camera_ready: video !== undefined,
                        microphone_ready: audio !== undefined,
                    },
                );
                setStationReadiness([ready.station]);
                setPhase('standing_by');
            } catch (readyError) {
                setError(readyError instanceof Error ? readyError.message : 'The station could not be marked Ready.');
                setPhase('failed');
            }
        } else {
            setPhase('ready');
        }
    }, [acquireTracks, bootstrap, refreshDevices, stopPreview]);

    useEffect(() => {
        if (autoMediaStartedRef.current) return;
        // An account without 300 access sees only the access-denied panel and is never prompted for devices.
        if (bootstrap.entry_mode === 'command' && !bootstrap.command_access) return;
        autoMediaStartedRef.current = true;
        void prepareMedia();
    }, [bootstrap.command_access, bootstrap.entry_mode, prepareMedia]);

    useEffect(() => {
        const onDeviceChange = () => void refreshDevices(false);
        navigator.mediaDevices?.addEventListener('devicechange', onDeviceChange);

        return () => navigator.mediaDevices?.removeEventListener('devicechange', onDeviceChange);
    }, [refreshDevices]);

    useEffect(() => {
        const connected = phase === 'connected' || phase === 'reconnecting';
        document.documentElement.classList.toggle('vc-conference-active', connected);

        return () => document.documentElement.classList.remove('vc-conference-active');
    }, [phase]);

    // A call in progress (including a reconnect) must never be interrupted by an app
    // update reload. Keyed on the boolean, not the phase, so connected <-> reconnecting
    // never flashes "idle"; leaving, ending the call or unmounting releases it.
    const inCall = phase === 'connected' || phase === 'reconnecting';
    useEffect(() => {
        if (inCall) markBusy('active-call');
        else clearBusy('active-call');
    }, [inCall]);
    useEffect(() => () => clearBusy('active-call'), []);

    // A join in progress (token requests and connecting) is as unsafe to reload as a call: an update
    // reload here would orphan the participation. Cleared the moment the join settles or unmounts.
    const joinBusy = phase === 'joining' || actionBusy === 'join-self' || actionBusy === 'start' || (actionBusy?.startsWith('direct:') ?? false);
    useEffect(() => {
        if (joinBusy) markBusy('joining');
        else clearBusy('joining');
    }, [joinBusy]);
    useEffect(() => () => clearBusy('joining'), []);

    useEffect(() => () => {
        stopPreview();
        if (!terminatingRef.current) {
            void cleanupRoom(true);
            notifyStandDown();
        }
    }, [cleanupRoom, notifyStandDown, stopPreview]);

    useEffect(() => {
        if (bootstrap.entry_mode !== 'station'
            || !bootstrap.launch_context
            || !['standing_by', 'joining', 'connected', 'reconnecting'].includes(phase)) return;
        void refreshStationStatus();
        const statusTimer = window.setInterval(() => void refreshStationStatus(), bootstrap.status_poll_ms);
        const heartbeatTimer = window.setInterval(() => {
            void postJson(bootstrap.endpoints.station_heartbeat, bootstrap.csrf_token, {
                launch_context: bootstrap.launch_context,
            }).catch(() => undefined);
        }, bootstrap.heartbeat_ms);

        return () => {
            window.clearInterval(statusTimer);
            window.clearInterval(heartbeatTimer);
        };
    }, [bootstrap, phase, refreshStationStatus]);

    useEffect(() => {
        if (bootstrap.entry_mode !== 'command' || !commandAuthorized) return;
        void refreshCommandStatus();
        const timer = window.setInterval(() => void refreshCommandStatus(), bootstrap.status_poll_ms);

        return () => window.clearInterval(timer);
    }, [bootstrap.entry_mode, bootstrap.status_poll_ms, commandAuthorized, refreshCommandStatus]);

    useEffect(() => {
        const shouldSubscribe = bootstrap.entry_mode === 'station'
            || (bootstrap.entry_mode === 'command' && commandAuthorized);
        if (!shouldSubscribe) return;

        let unsubscribe = () => undefined;
        let disposed = false;
        void subscribeToLineupChanges(bootstrap, () => {
            if (bootstrap.entry_mode === 'station') void refreshStationStatus();
            else void refreshCommandStatus();
        }).then((subscribed) => {
            if (disposed) subscribed();
            else unsubscribe = subscribed;
        }).catch(() => undefined);

        return () => {
            disposed = true;
            unsubscribe();
        };
    }, [bootstrap, commandAuthorized, refreshCommandStatus, refreshStationStatus]);

    useEffect(() => {
        const timer = window.setInterval(() => setClock(serverClockRef.current.now()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    // Exact-session status also covers self joins, direct calls and a missed
    // ROOM_DELETED event after a network interruption.
    // Keyed on the connected session, not the phase, so reconnect blips do not
    // re-register the timer.
    useEffect(() => {
        if (bootstrap.entry_mode === 'station' || !sessionId) return;
        const poll = async () => {
            if (terminatingRef.current) return;
            try {
                const status = await getJson<{ active: boolean; server_time?: string }>(`${bootstrap.endpoints.api_base}/sessions/${sessionId}/status`);
                serverClockRef.current.sync(status.server_time);
                if (selfInactiveRef.current.observe(!status.active, performance.now())) await returnFromConference();
            } catch { /* Transient status failures leave LiveKit reconnect in control. */ }
        };
        const timer = window.setInterval(() => void poll(), bootstrap.status_poll_ms);
        return () => window.clearInterval(timer);
    }, [bootstrap, returnFromConference, sessionId]);

    const participants = useMemo(
        () => room ? [room.localParticipant, ...Array.from(room.remoteParticipants.values())] : [],
        [participantRefresh, room],
    );
    const screenShareIdentity = useMemo(() => participants.find((participant) =>
        Array.from(participant.trackPublications.values()).some((publication) =>
            publication.source === Track.Source.ScreenShare && publication.isMuted === false,
        ))?.identity ?? null, [participantRefresh, participants]);
    const focusedIdentity = screenShareIdentity ?? manualPin;
    const focusedParticipant = participants.find((participant) => participant.identity === focusedIdentity);
    const directStation = roomMode === 'direct'
        ? (bootstrap.entry_mode === 'station' ? bootstrap.join_as : pendingDirectStation)
        : null;
    const gallerySlots = useMemo(() => buildGallerySlots(
        participants,
        expectedEndpointIdentities(roomMode, directStation),
        (participant) => {
            const joined = participant.joinedAt?.getTime();
            if (joined !== undefined) return joined;
            const known = firstSeenRef.current.get(participant.identity);
            if (known !== undefined) return known;
            firstSeenRef.current.set(participant.identity, serverClockRef.current.now() ?? 0);

            return firstSeenRef.current.get(participant.identity);
        },
    ), [directStation, participants, roomMode]);

    useEffect(() => {
        if (!room) return;
        for (const participant of room.remoteParticipants.values()) {
            for (const publication of participant.videoTrackPublications.values()) {
                if (!(publication instanceof RemoteTrackPublication)) continue;
                const high = publication.source === Track.Source.ScreenShare
                    || participant.identity === focusedIdentity;
                // Adaptive stream follows the attached element's actual dimensions.
                // Only presentation/pinned tracks need an explicit high preference.
                if (high) publication.setVideoQuality(VideoQuality.HIGH);
            }
        }
    }, [focusedIdentity, participantRefresh, room]);

    useEffect(() => {
        if (!room || !participationIdRef.current) return;
        const sample = async () => {
            const samples: InboundRtcSample[] = [];
            for (const participant of room.remoteParticipants.values()) {
                for (const publication of participant.trackPublications.values()) {
                    const report = await publication.track?.getRTCStatsReport();
                    report?.forEach((stat) => {
                        if (stat.type !== 'inbound-rtp') return;
                        const inbound = stat as RTCInboundRtpStreamStats;
                        samples.push({
                            id: inbound.id,
                            bytesReceived: inbound.bytesReceived ?? 0,
                            packetsReceived: inbound.packetsReceived ?? 0,
                            packetsLost: Math.max(0, inbound.packetsLost ?? 0),
                            jitterMs: Math.round((inbound.jitter ?? 0) * 1000),
                        });
                    });
                }
            }
            inboundRtcAccumulatorRef.current = accumulateInboundRtcStats(
                inboundRtcAccumulatorRef.current,
                samples,
            );
            const totals = inboundRtcAccumulatorRef.current.totals;
            const station = bootstrap.entry_mode === 'station';
            const base = station ? bootstrap.endpoints.station_participation_base : `${bootstrap.endpoints.api_base}/participations`;
            await postJson(`${base}/${participationIdRef.current}/stats`, bootstrap.csrf_token, {
                ...(station ? { launch_context: bootstrap.launch_context } : {}),
                downstream_bytes: totals.downstreamBytes,
                packets_received: totals.packetsReceived,
                packets_lost: totals.packetsLost,
                jitter_ms: totals.jitterMs,
            });
        };
        const timer = window.setInterval(() => void sample().catch(() => undefined), 15_000);

        return () => window.clearInterval(timer);
    }, [bootstrap, room]);

    const togglePreviewTrack = async (kind: Track.Kind) => {
        const track = previewTracksRef.current.find((candidate) => candidate.kind === kind);
        if (!track) return;
        if (kind === Track.Kind.Video) {
            const enabled = !cameraEnabled;
            setCameraEnabled(enabled);
            await (enabled ? track.unmute() : track.mute());
        } else {
            const enabled = !microphoneEnabled;
            setMicrophoneEnabled(enabled);
            await (enabled ? track.unmute() : track.mute());
        }
    };

    const changeDevice = async (kind: MediaDeviceKind, value: string) => {
        if (kind === 'videoinput') setCameraId(value);
        if (kind === 'audioinput') setMicrophoneId(value);
        if (kind === 'audiooutput') setSpeakerId(value);
        if (!value) return;
        try {
            if (room) await room.switchActiveDevice(kind, value, true);
            else {
                const trackKind = kind === 'videoinput' ? Track.Kind.Video
                    : kind === 'audioinput' ? Track.Kind.Audio : null;
                const track = previewTracksRef.current.find((candidate) => candidate.kind === trackKind);
                if (track) await track.restartTrack({ deviceId: value });
            }
        } catch (deviceError) {
            setError(mediaErrorMessage(deviceError));
        }
    };

    const startLineup = async (confirmedTakeover = false) => {
        setActionBusy('start');
        setError(null);
        try {
            setPendingDirectStation(null);
            const credentials = await postJson<TokenResponse>(
                bootstrap.endpoints.command_start,
                bootstrap.csrf_token,
                { confirmed_takeover: confirmedTakeover },
            );
            await refreshCommandStatus();
            activeLineupSeenRef.current = true;
            setRoomMode('lineup');
            await connectWithCredentials(credentials, confirmedTakeover);
        } catch (startError) {
            if (denyCommandAccess(startError)) return;
            if (startError instanceof ApiError && startError.status === 409 && startError.payload.code === 'endpoint_in_use') {
                setTakeover(true);
            }
            setError(startError instanceof Error ? startError.message : 'Morning Lineup could not be started.');
            setPhase('ready');
        } finally {
            setActionBusy(null);
        }
    };

    const startDirectCall = async (station: StationRole, confirmedTakeover = false) => {
        setActionBusy(`direct:${station}`);
        setPendingDirectStation(station);
        setError(null);
        try {
            const credentials = await postJson<TokenResponse>(
                bootstrap.endpoints.command_direct,
                bootstrap.csrf_token,
                { station, confirmed_takeover: confirmedTakeover },
            );
            setRoomMode('direct');
            activeDirectSeenRef.current = true;
            setNotice(`Calling ${stationLabel(station)}…`);
            await connectWithCredentials(credentials, confirmedTakeover);
        } catch (directError) {
            if (denyCommandAccess(directError)) return;
            if (directError instanceof ApiError && directError.status === 409 && directError.payload.code === 'endpoint_in_use') {
                setTakeover(true);
            }
            setError(directError instanceof Error ? directError.message : 'The direct station call could not be started.');
            setPhase('ready');
        } finally {
            setActionBusy(null);
        }
    };

    const joinAsSelf = async (confirmedTakeover = false): Promise<boolean> => {
        if (joinInFlightRef.current || joiningRef.current || roomRef.current || terminatingRef.current) return false;
        joinInFlightRef.current = true;
        setActionBusy('join-self');
        setError(null);
        try {
            const active = await postJson<SessionResponse>(bootstrap.endpoints.sessions, bootstrap.csrf_token, { room: 'lineup' });
            serverClockRef.current.sync(active.session.server_time);
            // The end time of the lineup actually being joined, from the server: a restart between
            // polls can never leave the previous lineup's end time on the countdown.
            lineupEndsAtRef.current = active.session.ends_at ?? null;
            const credentials = await postJson<TokenResponse>(
                `${bootstrap.endpoints.api_base}/sessions/${active.session.id}/token`,
                bootstrap.csrf_token,
                { join_as: 'self', confirmed_takeover: confirmedTakeover },
            );
            setLineup({ ...emptyLineup, active: true, session_id: active.session.id, ends_at: lineupEndsAtRef.current });
            await connectWithCredentials({ ...credentials, session: active.session }, confirmedTakeover);
            return false;
        } catch (joinError) {
            const failure = {
                status: joinError instanceof ApiError ? joinError.status : null,
                code: joinError instanceof ApiError && typeof joinError.payload.code === 'string' ? joinError.payload.code : null,
            };
            const outcome = await import('./waiting-room').then((words) => words.describeJoinFailure(failure)).catch(() => ({
                kind: failure.code === 'endpoint_in_use' ? 'takeover' as const : 'error' as const,
                message: 'Morning Lineup could not be joined. Press Join to try again.',
                refresh: true,
            }));
            // Not starting yet, or already over, is a state the waiting room shows, not an error.
            if (outcome.kind === 'takeover') setTakeover(true);
            if (outcome.kind === 'notice') {
                // The waiting room already shows the real state; keep this reassurance only briefly.
                setNotice(outcome.message);
                window.setTimeout(() => setNotice((current) => current === outcome.message ? null : current), 8_000);
            } else setError(outcome.message);
            if (outcome.refresh) setWaitRefresh((value) => value + 1);
            setPhase('ready');
            return failure.code === 'room_full' || failure.code === 'lineup_not_started';
        } finally {
            joinInFlightRef.current = false;
            setActionBusy(null);
        }
    };

    /** Turns off a camera and microphone that sat unused in the waiting room, or after the lineup ended. */
    const releaseIdlePreview = useCallback((reason: MediaReleaseReason) => {
        // Never while a join is starting (even before its first request returns) or connected.
        if (previewTracksRef.current.length === 0 || joinInFlightRef.current || joiningRef.current || roomRef.current) return;
        stopPreview();
        setCameraReady(false);
        setMicrophoneReady(false);
        setReleasedReason(reason);
        setNotice(null);
    }, [stopPreview]);

    /**
     * Credentials for rejoining the lineup this endpoint was in, or null when the
     * lineup has ended in the meantime (never starts a new one).
     */
    const requestRejoinCredentials = async (lineupSessionId: string): Promise<TokenResponse | null> => {
        const session: SessionResponse['session'] = {
            id: lineupSessionId,
            type: 'lineup',
            target_station: null,
            scheduled_for: null,
            lineup_time_configured: false,
        };
        if (bootstrap.entry_mode === 'command') {
            const status = await getJson<CommandStatusResponse>(bootstrap.endpoints.command_status);
            setLineup(status.lineup);
            if (!status.lineup.active || status.lineup.session_id !== lineupSessionId) return null;
            const credentials = await postJson<TokenResponse>(
                bootstrap.endpoints.command_start,
                bootstrap.csrf_token,
                { confirmed_takeover: false },
            );
            if (credentials.session?.id !== lineupSessionId) {
                // The lineup ended between the check and the start call, so a new one was
                // created. Do not silently continue in it: release the seat and stop.
                notifyLeave(credentials.participation_id);

                return null;
            }

            return credentials;
        }
        const status = await getJson<{ active: boolean }>(`${bootstrap.endpoints.api_base}/sessions/${lineupSessionId}/status`);
        if (!status.active) return null;
        const credentials = await postJson<TokenResponse>(
            `${bootstrap.endpoints.api_base}/sessions/${lineupSessionId}/token`,
            bootstrap.csrf_token,
            { join_as: 'self' },
        );

        return { ...credentials, session };
    };

    /**
     * Runs after an unexpected disconnect (never after an explicit leave).
     * Stations re-arm their media and let the status poll rejoin. Command and
     * self retry a bounded number of times with backoff, and only while the same
     * lineup is still active. A superseded endpoint never rejoins on its own.
     */
    const recoverFromDisconnect = async (superseded: boolean, isRetry = false) => {
        if (terminatingRef.current || userLeftRef.current) return;
        const station = bootstrap.entry_mode === 'station';
        const lineupSessionId = connectedSessionRef.current;
        // Only a fresh drop after a stable connection earns new attempts; our own retries must not.
        if (!isRetry && performance.now() - connectedAtRef.current > STABLE_CONNECTION_MS) rejoinAttemptsRef.current = 0;
        const canRejoin = !superseded && !station && roomMode === 'lineup' && lineupSessionId !== null
            && rejoinAttemptsRef.current < MAX_REJOIN_ATTEMPTS;
        if (!canRejoin || lineupSessionId === null) {
            if (station && superseded) stationOptedOutRef.current = true;
            await prepareMedia({ keepOptedOut: station && superseded });
            setError(superseded ? SUPERSEDED_MESSAGE : DROPPED_MESSAGE);

            return;
        }

        const delay = REJOIN_DELAYS_MS[Math.min(rejoinAttemptsRef.current, REJOIN_DELAYS_MS.length - 1)];
        rejoinAttemptsRef.current += 1;
        setError(null);
        setNotice('Connection lost. Rejoining Morning Lineup…');
        setPhase('reconnecting');
        await new Promise((resolve) => window.setTimeout(resolve, delay));
        if (terminatingRef.current || userLeftRef.current) return;

        // The dropped room stopped its tracks, so capture fresh ones.
        stopPreview();
        const { tracks } = await acquireTracks();
        if (terminatingRef.current || userLeftRef.current) {
            tracks.forEach((track) => track.stop());

            return;
        }
        previewTracksRef.current = tracks;
        try {
            const credentials = tracks.length > 0 ? await requestRejoinCredentials(lineupSessionId) : undefined;
            if (credentials === null) {
                await returnFromConference();

                return;
            }
            if (credentials) {
                await connectWithCredentials(credentials);
                if (roomRef.current) return;
            }
        } catch { /* Counted as a failed attempt; the next one follows. */ }
        if (!terminatingRef.current && !userLeftRef.current) {
            setPhase('reconnecting');
            await recoverRef.current(false, true);
        }
    };
    useEffect(() => {
        recoverRef.current = recoverFromDisconnect;
    });

    const endConference = async () => {
        setActionBusy('end');
        try {
            const endpoint = sessionId
                ? `${bootstrap.endpoints.api_base}/sessions/${sessionId}/end`
                : bootstrap.endpoints.command_end;
            await postJson(endpoint, bootstrap.csrf_token, {});
            await returnFromConference();
        } catch (endError) {
            setError(endError instanceof Error ? endError.message : 'Morning Lineup could not be ended.');
        } finally {
            setActionBusy(null);
        }
    };

    const toggleMicrophone = async () => {
        if (!room || forcedStationMic) return;
        const enabled = !microphoneEnabled;
        try {
            await room.localParticipant.setMicrophoneEnabled(enabled, microphoneId ? { deviceId: microphoneId } : undefined);
            setMicrophoneEnabled(enabled);
        } catch (deviceError) {
            setError(mediaErrorMessage(deviceError));
        }
    };

    const toggleCamera = async () => {
        if (!room) return;
        const enabled = !cameraEnabled;
        try {
            await room.localParticipant.setCameraEnabled(enabled, cameraId ? {
                deviceId: cameraId,
                resolution: VideoPresets.h720.resolution,
            } : { resolution: VideoPresets.h720.resolution });
            setCameraEnabled(enabled);
        } catch (deviceError) {
            setError(mediaErrorMessage(deviceError));
        }
    };

    const toggleScreenShare = async () => {
        if (!room) return;
        const enabled = !screenShareEnabled;
        try {
            await room.localParticipant.setScreenShareEnabled(enabled);
            setScreenShareEnabled(enabled);
        } catch (shareError) {
            // Closing the browser's screen picker is a choice, not a failure.
            // Chrome reports "Permission denied by system" when the OS blocks capture; that one is worth telling the member about.
            if (enabled && shareError instanceof DOMException && (shareError.name === 'AbortError' || (shareError.name === 'NotAllowedError' && !/by system/i.test(shareError.message)))) return;
            setError(shareError instanceof Error ? shareError.message : 'Screen sharing could not be started.');
        }
    };

    const stationMicrophone = async (station: StationRole, enabled: boolean) => {
        if (!room || !sessionId) return;
        setActionBusy(`${station}:${enabled}`);
        try {
            const result = await postJson<{
                identity: string;
                rpc_required: boolean;
                method: string;
                payload: { enabled: boolean };
            }>(`${bootstrap.endpoints.api_base}/sessions/${sessionId}/moderation/stations/${station}/microphone`, bootstrap.csrf_token, { enabled });
            if (result.rpc_required) {
                await room.localParticipant.performRpc({
                    destinationIdentity: result.identity,
                    method: result.method,
                    payload: JSON.stringify(result.payload),
                });
            }
        } catch (actionError) {
            setError(actionError instanceof Error ? actionError.message : 'The station microphone could not be updated.');
        } finally {
            setActionBusy(null);
        }
    };

    const muteAllStations = async () => {
        if (!room || !sessionId) return;
        setActionBusy('mute-all');
        try {
            const result = await postJson<{ muted: StationRole[] }>(
                `${bootstrap.endpoints.api_base}/sessions/${sessionId}/moderation/mute-stations`,
                bootstrap.csrf_token,
                {},
            );
            await Promise.allSettled(result.muted.map((station) => room.localParticipant.performRpc({
                destinationIdentity: `mbfd:${station}`,
                method: 'mbfd.stationMic',
                payload: JSON.stringify({ enabled: false }),
            })));
        } catch (actionError) {
            setError(actionError instanceof Error ? actionError.message : 'Station microphones could not be muted.');
        } finally {
            setActionBusy(null);
        }
    };

    const connected = phase === 'connected' || phase === 'reconnecting';
    const remainingSeconds = lineup.ends_at && clock !== null
        ? Math.max(0, Math.floor((new Date(lineup.ends_at).getTime() - clock) / 1000))
        : null;
    const endLabel = roomMode === 'direct' ? 'End Direct Call' : 'End Conference';

    return (
        <div className="vc-shell" data-phase={phase} data-entry-mode={bootstrap.entry_mode} data-ice-policy={forceRelay ? 'relay' : 'all'}>
            <header className="vc-header">
                {!embedded && <nav className="vc-navigation" aria-label="Conference navigation">
                    <button type="button" data-nav="back" aria-label="Back to previous page" onClick={() => void returnFromConference(bootstrap.navigation.back, false)}><ArrowLeft size={18} aria-hidden="true" /><span>Back</span></button>
                    <button type="button" data-nav="home" aria-label="Go to Hub Home" onClick={() => void returnFromConference(bootstrap.navigation.home, false)}><House size={18} aria-hidden="true" /><span>Hub Home</span></button>
                </nav>}
                <div>
                    <span className="vc-eyebrow"><ShieldCheck size={15} /> MBFD secure conference</span>
                    {/* Embedded in an Employee page, the page already has its H1. */}
                    {embedded
                        ? <h2>{bootstrap.entry_mode === 'command' ? 'Morning Lineup — 300' : bootstrap.display_name}</h2>
                        : <h1>{bootstrap.entry_mode === 'command' ? 'Morning Lineup — 300' : bootstrap.display_name}</h1>}
                    <p>{bootstrap.entry_mode === 'station'
                        ? 'Camera and microphone check, then stand by for 300.'
                        : bootstrap.entry_mode === 'command'
                            ? 'See station readiness, start lineup, and manage the floor.'
                            : 'Join as yourself through your authenticated Employee Portal session.'}</p>
                </div>
                <span className={`vc-status vc-status--${phase}`} aria-live="polite">
                    {phase === 'standing_by' ? 'Ready — Standing By' : phase === 'reconnecting' ? 'Reconnecting…' : phase.replace('_', ' ')}
                </span>
            </header>

            {phase === 'reconnecting' && <div className="vc-banner vc-banner--warning" role="status"><RefreshCw size={18} className="vc-spin" /> Network interrupted. Reconnecting automatically…</div>}
            {audioBlocked && connected && <button className="vc-banner vc-banner--action" type="button" onClick={async () => {
                await room?.startAudio().then(() => setAudioBlocked(false)).catch(() => setAudioBlocked(true));
            }}><Volume2 size={18} /> Tap to enable conference audio</button>}
            {error && <div className="vc-banner vc-banner--error" role="alert"><CircleAlert size={19} /><span>{error}</span></div>}
            {notice && !error && <div className="vc-banner vc-banner--info" role="status"><Check size={19} /><span>{notice}</span></div>}
            {remainingSeconds !== null && connected && remainingSeconds <= 120 && <div className="vc-banner vc-banner--warning" role="status"><Clock3 size={18} /> Morning Lineup ends automatically in {Math.ceil(remainingSeconds / 60)} minute{remainingSeconds > 60 ? 's' : ''}.</div>}

            {!connected ? (
                <div className="vc-lobby-grid">
                    <section className="vc-card vc-preview" aria-labelledby="preview-title">
                        <div className="vc-section-heading">
                            <div><span>Device check</span><h2 id="preview-title">Camera and microphone</h2></div>
                            <button type="button" className="vc-icon-button" onClick={() => void refreshDevices(false)} aria-label="Refresh device list"><RefreshCw size={19} /></button>
                        </div>
                        <div className="vc-preview__video">
                            <video ref={previewVideoRef} autoPlay playsInline muted />
                            {!cameraReady && <div className="vc-preview__empty">{phase === 'requesting_media' ? <LoaderCircle size={32} className="vc-spin" /> : <CameraOff size={32} />}<span>{phase === 'requesting_media' ? 'Waiting for browser permission…' : idleReleased ? 'Camera turned off while waiting' : 'No camera preview — audio-only is available'}</span></div>}
                        </div>
                        <div className="vc-readiness" aria-label="Media readiness">
                            <span className={cameraReady ? 'is-ready' : 'is-warning'}>{cameraReady ? <Check size={16} /> : <CameraOff size={16} />} Camera {cameraReady ? 'Ready' : 'Unavailable'}</span>
                            <span className={microphoneReady ? 'is-ready' : 'is-warning'}>{microphoneReady ? <Check size={16} /> : <MicOff size={16} />} Microphone {microphoneReady ? 'Ready' : 'Unavailable'}</span>
                        </div>
                        <div className="vc-preview__toggles" role="group" aria-label="Prejoin media controls">
                            <button type="button" disabled={!microphoneReady} aria-pressed={!microphoneEnabled} onClick={() => void togglePreviewTrack(Track.Kind.Audio)}>{microphoneEnabled ? <Mic size={18} /> : <MicOff size={18} />} {microphoneEnabled ? 'Mute preview mic' : 'Enable preview mic'}</button>
                            <button type="button" disabled={!cameraReady} aria-pressed={!cameraEnabled} onClick={() => void togglePreviewTrack(Track.Kind.Video)}>{cameraEnabled ? <Camera size={18} /> : <CameraOff size={18} />} {cameraEnabled ? 'Turn preview camera off' : 'Turn preview camera on'}</button>
                            {supportsAudioOutputSelection() && devices.speakers.length > 0 && <button type="button" onClick={async () => {
                                setSpeakerTesting(true);
                                try { await testSpeakerOutput(speakerId); } catch (speakerError) { setError(speakerError instanceof Error ? speakerError.message : 'Speaker test failed.'); } finally { setSpeakerTesting(false); }
                            }} disabled={speakerTesting}>{speakerTesting ? <LoaderCircle size={18} className="vc-spin" /> : <Volume2 size={18} />} {speakerTesting ? 'Playing tone…' : 'Test speaker'}</button>}
                        </div>
                        <div className="vc-device-grid">
                            <label>Camera<select value={cameraId} onChange={(event) => void changeDevice('videoinput', event.target.value)}>{devices.cameras.map((device, index) => <option key={device.deviceId} value={device.deviceId}>{deviceLabel(device, index, 'Camera')}</option>)}</select></label>
                            <label>Microphone<select value={microphoneId} onChange={(event) => void changeDevice('audioinput', event.target.value)}>{devices.microphones.map((device, index) => <option key={device.deviceId} value={device.deviceId}>{deviceLabel(device, index, 'Microphone')}</option>)}</select></label>
                            {supportsAudioOutputSelection() && <label>Speaker<select value={speakerId} onChange={(event) => void changeDevice('audiooutput', event.target.value)}>{devices.speakers.map((device, index) => <option key={device.deviceId} value={device.deviceId}>{deviceLabel(device, index, 'Speaker')}</option>)}</select></label>}
                        </div>
                        <button className="vc-button vc-button--secondary" type="button" onClick={() => void prepareMedia()} disabled={phase === 'requesting_media'}>{phase === 'requesting_media' ? <LoaderCircle size={19} className="vc-spin" /> : <RefreshCw size={19} />} Test Camera &amp; Microphone</button>
                    </section>

                    <section className="vc-card vc-entry-card" aria-labelledby="entry-title">
                        <div className="vc-section-heading"><div><span>{bootstrap.entry_mode === 'station' ? 'Morning Lineup' : bootstrap.entry_mode === 'command' ? '300 Command' : 'Employee Portal'}</span><h2 id="entry-title">{bootstrap.display_name}</h2></div></div>

                        {bootstrap.entry_mode === 'station' && <div className="vc-standby" data-ready={phase === 'standing_by'}>
                            <Radio size={34} />
                            <strong>{phase === 'standing_by' ? 'READY — STANDING BY' : 'Preparing station…'}</strong>
                            <span>{lineup.active ? '300 has started. Joining automatically…' : 'Waiting for 300 to start Morning Lineup.'}</span>
                            <small>Not connected to the conference yet. Standing by uses no call minutes.</small>
                        </div>}

                        {bootstrap.entry_mode === 'command' && !commandAuthorized && <div className="vc-command-denied" role="alert">
                            <div className="vc-room-summary"><ShieldCheck size={22} /><div><strong>300 Command access required</strong><span>{commandDeniedMessage ?? '300 Command video controls are available to Captains, Division Chiefs, the 300 command officer, administrators, and members granted 300 Command Access.'}</span></div></div>
                            <a className="vc-button vc-button--secondary" href={bootstrap.navigation.back}>Return</a>
                        </div>}

                        {bootstrap.entry_mode === 'command' && commandAuthorized && <div className="vc-ready-dashboard">
                            <div className={`vc-banner ${providerApiHealthy === false ? 'vc-banner--error' : 'vc-banner--info'}`} role="status">
                                {providerApiHealthy === null ? <LoaderCircle size={18} className="vc-spin" /> : providerApiHealthy ? <Check size={18} /> : <CircleAlert size={18} />}
                                Conference service: {providerApiHealthy === null ? 'Checking…' : providerApiHealthy ? 'Healthy' : 'Unavailable — Start is disabled'}
                            </div>
                            <div className="vc-room-summary"><Users size={22} /><div><strong>Station readiness</strong><span>Start when operations are ready. Late stations can join automatically.</span></div></div>
                            <div className="vc-ready-list">{stationOptions.map((station) => {
                                const status = stationReadiness.find((candidate) => candidate.join_as === station.value);
                                return <div key={station.value}><strong>{station.label}</strong><span className={status?.ready ? 'is-ready' : ''}>{status?.ready ? 'READY' : 'WAITING'}</span><button type="button" onClick={() => void startDirectCall(station.value)} disabled={actionBusy !== null || !microphoneReady || providerApiHealthy !== true}>Direct call</button></div>;
                            })}</div>
                            <button className="vc-button vc-button--start" type="button" onClick={() => void startLineup(false)} disabled={actionBusy !== null || !microphoneReady || providerApiHealthy !== true}>{actionBusy === 'start' ? <LoaderCircle size={20} className="vc-spin" /> : <Video size={20} />} {lineup.active ? 'Reconnect to Morning Lineup' : 'Start Morning Lineup'}</button>
                        </div>}

                        {bootstrap.entry_mode === 'self' && <Suspense fallback={<p role="status">Checking Morning Lineup…</p>}>
                            <MemberWaitingRoom
                                bootstrap={bootstrap}
                                observing={['requesting_media', 'ready', 'failed'].includes(phase) && actionBusy === null}
                                mediaReady={microphoneReady}
                                previewActive={phase === 'ready' && (cameraReady || microphoneReady)}
                                releasedReason={releasedReason}
                                busy={actionBusy !== null}
                                lastJoinedSessionId={lastJoinedSessionId}
                                refreshToken={waitRefresh}
                                onJoin={() => joinAsSelf(false)}
                                onIdleRelease={releaseIdlePreview}
                                onResumeMedia={() => void prepareMedia()}
                            />
                        </Suspense>}
                    </section>
                </div>
            ) : (
                <div className={`vc-conference ${bootstrap.entry_mode === 'command' ? 'vc-conference--command' : ''}`}>
                    <main className={`vc-meeting ${focusedParticipant ? 'vc-meeting--presentation' : ''}`}>
                        <div className="vc-focus-bar"><span>{screenShareIdentity ? 'Screen share' : manualPin ? 'Pinned' : 'Gallery'} · {participants.length} participants</span>{manualPin && <button type="button" onClick={() => setManualPin(null)}>Return to Gallery</button>}</div>
                        <ConferenceStage
                            slots={gallerySlots}
                            focusedIdentity={focusedParticipant?.identity ?? null}
                            localIdentity={room?.localParticipant.identity}
                            refreshKey={participantRefresh}
                            liveCount={participants.length}
                            onToggleFocus={(identity) => setManualPin((current) => current === identity ? null : identity)}
                        />
                    </main>

                    {bootstrap.entry_mode === 'command' && controlsOpen && <ConferenceDialog title="300 Controls" onClose={() => setControlsOpen(false)} drawer><aside className="vc-command" aria-label="300 station microphone controls">
                        <div><span>300 controls</span><h2>Station microphones</h2><p>More than one station may have the floor. Your own microphone is independent.</p></div>
                        <button type="button" className="vc-button vc-button--danger-outline" onClick={() => void muteAllStations()} disabled={actionBusy !== null}><MicOff size={18} /> Mute all stations</button>
                        <div className="vc-command__stations">{stationOptions.map((station) => {
                            const participant = participants.find((candidate) => candidate.identity === `mbfd:${station.value}`);
                            const micLive = participant ? Array.from(participant.audioTrackPublications.values()).some((publication) => !publication.isMuted) : false;
                            return <div key={station.value}><span><strong>{station.label}</strong><small>{participant ? 'Connected' : 'Not connected'}</small></span><button type="button" onClick={() => void stationMicrophone(station.value, !micLive)} disabled={!participant || actionBusy !== null}>{micLive ? <><MicOff size={16} /> Mute</> : <><Mic size={16} /> Give Floor</>}</button></div>;
                        })}</div>
                    </aside></ConferenceDialog>}

                    {bootstrap.entry_mode === 'station' && <div className={`vc-station-mic ${forcedStationMic || !microphoneEnabled ? 'vc-station-mic--muted' : 'vc-station-mic--live'}`} role="status">{forcedStationMic ? <><MicOff size={24} /> MIC MUTED — WAITING FOR 300</> : microphoneEnabled ? <><Mic size={24} /> MIC LIVE</> : <><MicOff size={24} /> MIC MUTED</>}</div>}

                    <nav className="vc-controls" aria-label="Conference controls">
                        <button type="button" onClick={() => void toggleMicrophone()} disabled={forcedStationMic} aria-pressed={!microphoneEnabled}>{microphoneEnabled ? <Mic /> : <MicOff />}<span>{microphoneEnabled ? 'Mute' : 'Unmute'}</span></button>
                        <button type="button" onClick={() => void toggleCamera()} aria-pressed={!cameraEnabled}>{cameraEnabled ? <Camera /> : <CameraOff />}<span>{cameraEnabled ? 'Camera off' : 'Camera on'}</span></button>
                        <button type="button" onClick={() => void toggleScreenShare()} aria-pressed={screenShareEnabled}><Cast /><span>{screenShareEnabled ? 'Stop sharing' : 'Share screen'}</span></button>
                        <button type="button" onClick={async () => { await room?.startAudio().then(() => setAudioBlocked(false)).catch(() => setAudioBlocked(true)); }}><Headphones /><span>Audio</span></button>
                        {manualPin && <button type="button" onClick={() => setManualPin(null)}><Pin /><span>Gallery</span></button>}
                        {bootstrap.entry_mode === 'command' && <>
                            <button type="button" onClick={() => setControlsOpen(true)} aria-haspopup="dialog" aria-expanded={controlsOpen}><Users /><span>300 Controls</span></button>
                            <button type="button" className="vc-controls__leave" onClick={() => setConfirmEnd(true)} disabled={actionBusy !== null}><PhoneOff /><span>{endLabel}</span></button>
                        </>}
                        {bootstrap.entry_mode !== 'command' && <button type="button" className="vc-controls__leave" onClick={async () => {
                            userLeftRef.current = true;
                            setPhase('leaving');
                            if (bootstrap.entry_mode === 'station') stationOptedOutRef.current = true;
                            await cleanupRoom(true);
                            connectedSessionRef.current = null;
                            if (bootstrap.entry_mode === 'station') {
                                notifyStandDown();
                                setStationReadiness([]);
                            }
                            // Leaving stops the camera and microphone; joining again needs a fresh device check.
                            setCameraReady(false);
                            setMicrophoneReady(false);
                            setNotice('You left Morning Lineup and your camera and microphone are off. You can rejoin below while it is running.');
                            setPhase('ready');
                        }}><PhoneOff /><span>Leave</span></button>}
                    </nav>
                    {bootstrap.entry_mode === 'command' && <div className="vc-meeting-status">
                        {roomMode === 'lineup' && remainingSeconds !== null && <span><Clock3 size={14} /> Lineup · {Math.floor(remainingSeconds / 60)}:{String(remainingSeconds % 60).padStart(2, '0')} remaining</span>}
                        {usage && <details className="vc-usage"><summary>LiveKit · ~{Math.floor(usage.participant_minutes_remaining).toLocaleString()} participant-min left · resets {new Date(usage.resets_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric', timeZone: 'UTC' })}</summary><div>
                            <p>Participant-min: ~{usage.participant_minutes_estimated.toLocaleString()} / {usage.participant_minutes_allowance.toLocaleString()} used · ~{usage.participant_minutes_remaining.toLocaleString()} remaining</p>
                            <p>Downstream: ~{usage.downstream_gb_estimated} / {usage.downstream_allowance_gb} GB used · ~{usage.downstream_gb_remaining} GB remaining</p>
                            <p>Resets {new Date(usage.resets_at).toLocaleDateString(undefined, { timeZone: 'UTC' })} (UTC)</p>
                            <p>MBFD estimate — LiveKit dashboard is authoritative.</p>
                        </div></details>}
                    </div>}
                </div>
            )}

            {confirmEnd && <ConferenceDialog title={roomMode === 'direct' ? 'End Direct Call?' : 'End Morning Lineup?'} onClose={() => setConfirmEnd(false)}>
                <p>This will disconnect all connected stations.</p><div className="vc-dialog__actions">
                    <button type="button" className="vc-button vc-button--secondary" onClick={() => setConfirmEnd(false)}>Cancel</button>
                    <button type="button" className="vc-button vc-button--danger" disabled={actionBusy !== null} onClick={() => void endConference()}>{endLabel}</button>
                </div>
            </ConferenceDialog>}
            {takeover && <div className="vc-modal-backdrop" role="presentation"><div className="vc-modal" role="alertdialog" aria-modal="true" aria-labelledby="takeover-title" aria-describedby="takeover-description"><CircleAlert size={28} /><h2 id="takeover-title">{bootstrap.entry_mode === 'self' ? 'Already connected' : 'Endpoint already in use'}</h2><p id="takeover-description">{bootstrap.entry_mode === 'self' ? 'You are already in this Morning Lineup from another tab or device. Joining here will disconnect that connection.' : `Taking over will disconnect the existing ${isStationRole(bootstrap.join_as) ? stationLabel(bootstrap.join_as) : '300'} connection.`}</p><div><button type="button" autoFocus className="vc-button vc-button--secondary" onClick={() => setTakeover(false)}>Cancel</button><button type="button" className="vc-button vc-button--danger" onClick={() => bootstrap.entry_mode === 'station' ? void requestStationToken(roomMode, true) : bootstrap.entry_mode === 'self' ? void joinAsSelf(true) : pendingDirectStation ? void startDirectCall(pendingDirectStation, true) : void startLineup(true)}>Confirm takeover</button></div></div></div>}
        </div>
    );
}
