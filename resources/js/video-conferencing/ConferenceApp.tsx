import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    Camera,
    CameraOff,
    Cast,
    Check,
    CircleAlert,
    Clock3,
    Headphones,
    LoaderCircle,
    Mic,
    MicOff,
    PhoneOff,
    Pin,
    Radio,
    RefreshCw,
    ShieldCheck,
    Sparkles,
    Users,
    Video,
    Volume2,
} from 'lucide-react';
import {
    createLocalTracks,
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
import { ApiError, getJson, postJson } from './api';
import { subscribeToLineupChanges } from './conference-events';
import {
    deviceLabel,
    enumerateMediaDevices,
    isMediaPermissionDenied,
    mediaErrorMessage,
    testSpeakerOutput,
    type MediaDevices,
} from './media';
import { ParticipantTile } from './ParticipantTile';
import {
    accumulateInboundRtcStats,
    emptyInboundRtcAccumulator,
    type InboundRtcSample,
} from './rtc-stats';
import { resolveFocusedIdentity, SpeakerFocusTracker } from './speaker-focus';
import type {
    CommandStatusResponse,
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

interface ConferenceAppProps {
    bootstrap: ConferenceBootstrap;
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

function isStationRole(role: JoinRole): role is StationRole {
    return role.startsWith('sta');
}

function stationLabel(role: StationRole): string {
    return stationOptions.find((station) => station.value === role)?.label ?? role;
}

export function ConferenceApp({ bootstrap }: ConferenceAppProps) {
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
    const [automaticFocus, setAutomaticFocus] = useState<string | null>(null);
    const [manualPin, setManualPin] = useState<string | null>(null);
    const [audioBlocked, setAudioBlocked] = useState(false);
    const [forcedStationMic, setForcedStationMic] = useState(false);
    const [speakerTesting, setSpeakerTesting] = useState(false);
    const [actionBusy, setActionBusy] = useState<string | null>(null);
    const [commandPin, setCommandPin] = useState('');
    const [commandAuthorized, setCommandAuthorized] = useState(false);
    const [providerApiHealthy, setProviderApiHealthy] = useState<boolean | null>(null);
    const [lineup, setLineup] = useState<LineupState>(emptyLineup);
    const [stationReadiness, setStationReadiness] = useState<StationReadiness[]>([]);
    const [sessionId, setSessionId] = useState<string | null>(null);
    const [roomMode, setRoomMode] = useState<'lineup' | 'direct'>('lineup');
    const [pendingDirectStation, setPendingDirectStation] = useState<StationRole | null>(null);
    const [room, setRoom] = useState<Room | null>(null);
    const [clock, setClock] = useState(() => Date.now());

    const previewVideoRef = useRef<HTMLVideoElement>(null);
    const previewTracksRef = useRef<LocalTrack[]>([]);
    const roomRef = useRef<Room | null>(null);
    const participationIdRef = useRef<string | null>(null);
    const joiningRef = useRef(false);
    const isLeavingRef = useRef(false);
    const autoMediaStartedRef = useRef(false);
    const activeLineupSeenRef = useRef(false);
    const activeDirectSeenRef = useRef(false);
    const stationOptedOutRef = useRef(false);
    const speakerTimerRef = useRef<number | null>(null);
    const speakerFocusTrackerRef = useRef(new SpeakerFocusTracker());
    const inboundRtcAccumulatorRef = useRef(emptyInboundRtcAccumulator());

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

    const handleActiveSpeakers = useCallback((speakers: Participant[]) => {
        const candidate = speakers[0]?.identity ?? null;
        if (speakerTimerRef.current !== null) window.clearTimeout(speakerTimerRef.current);
        const now = Date.now();
        speakerFocusTrackerRef.current.updateCandidate(candidate, now);
        const delay = speakerFocusTrackerRef.current.remainingDelay(now);
        if (delay === null) return;
        speakerTimerRef.current = window.setTimeout(() => {
            const focused = speakerFocusTrackerRef.current.commit(Date.now());
            if (focused !== undefined) setAutomaticFocus(focused);
        }, delay);
    }, []);

    const attachRoomEvents = useCallback((nextRoom: Room) => {
        const refresh = () => setParticipantRefresh((value) => value + 1);
        nextRoom.on(RoomEvent.ParticipantConnected, refresh);
        nextRoom.on(RoomEvent.ParticipantDisconnected, (participant) => {
            if (speakerFocusTrackerRef.current.participantLeft(participant.identity)) {
                setAutomaticFocus(null);
            }
            setManualPin((identity) => identity === participant.identity ? null : identity);
            refresh();
        });
        nextRoom.on(RoomEvent.TrackPublished, refresh);
        nextRoom.on(RoomEvent.TrackUnpublished, refresh);
        nextRoom.on(RoomEvent.TrackSubscribed, refresh);
        nextRoom.on(RoomEvent.TrackUnsubscribed, refresh);
        nextRoom.on(RoomEvent.TrackMuted, refresh);
        nextRoom.on(RoomEvent.TrackUnmuted, refresh);
        nextRoom.on(RoomEvent.ActiveSpeakersChanged, handleActiveSpeakers);
        nextRoom.on(RoomEvent.ConnectionQualityChanged, refresh);
        nextRoom.on(RoomEvent.MediaDevicesChanged, () => void refreshDevices(false));
        nextRoom.on(RoomEvent.MediaDevicesError, (deviceError) => setError(mediaErrorMessage(deviceError)));
        nextRoom.on(RoomEvent.Reconnecting, () => setPhase('reconnecting'));
        nextRoom.on(RoomEvent.Reconnected, () => setPhase('connected'));
        nextRoom.on(RoomEvent.Disconnected, () => {
            if (!isLeavingRef.current) {
                setError('The conference connection ended. Check your Internet connection and try again.');
                void cleanupRoom(true).finally(() => {
                    setPhase(bootstrap.entry_mode === 'station' ? 'standing_by' : 'ready');
                });
            }
        });
    }, [bootstrap.entry_mode, cleanupRoom, handleActiveSpeakers, refreshDevices]);

    const connectWithCredentials = useCallback(async (credentials: TokenResponse, confirmedTakeover = false) => {
        if (joiningRef.current || roomRef.current) return;
        if (previewTracksRef.current.length === 0) {
            setError('Camera or microphone preparation is required before joining.');
            return;
        }
        joiningRef.current = true;
        setTakeover(false);
        setError(null);
        setNotice('Connecting securely to LiveKit Cloud…');
        setPhase('joining');
        let nextRoom: Room | null = null;
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
                    await nextRoom!.localParticipant.setMicrophoneEnabled(enabled);
                    setMicrophoneEnabled(enabled);
                    setForcedStationMic(!enabled);
                    setParticipantRefresh((value) => value + 1);

                    return JSON.stringify({ enabled });
                });
            }
            roomRef.current = nextRoom;
            await nextRoom.connect(credentials.server_url, credentials.token, {
                autoSubscribe: true,
                rtcConfig: forceRelay ? { iceTransportPolicy: 'relay' } : undefined,
                websocketTimeout: 10_000,
            });

            const preparedTracks = [...previewTracksRef.current];
            previewTracksRef.current = [];
            for (const track of preparedTracks) {
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
            setSessionId(credentials.session?.id ?? lineup.session_id);
            setRoom(nextRoom);
            setAutomaticFocus(nextRoom.localParticipant.identity);
            speakerFocusTrackerRef.current.recordFocus(nextRoom.localParticipant.identity, Date.now());
            setAudioBlocked(!nextRoom.canPlaybackAudio);
            if (nextRoom.canPlaybackAudio) await nextRoom.startAudio().catch(() => setAudioBlocked(true));
            setForcedStationMic(bootstrap.entry_mode === 'station');
            setMicrophoneEnabled(bootstrap.entry_mode !== 'station' && microphoneEnabled);
            setCommandPin('');
            setNotice(null);
            setPhase('connected');
        } catch (joinError) {
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
                setError(joinError instanceof Error
                    ? joinError.message
                    : 'Unable to connect to the video conferencing service. Check your Internet connection and try again.');
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
    }, [attachRoomEvents, bootstrap, cameraEnabled, forceRelay, lineup.session_id, microphoneEnabled]);

    const requestStationToken = useCallback(async (requestedRoom: 'lineup' | 'direct', confirmedTakeover = false) => {
        if (!bootstrap.launch_context || joiningRef.current || roomRef.current) return;
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
        }
    }, [bootstrap, connectWithCredentials]);

    const refreshStationStatus = useCallback(async () => {
        if (!bootstrap.launch_context) return;
        try {
            const status = await getJson<StationStatusResponse>(
                `${bootstrap.endpoints.station_status}?launch_context=${encodeURIComponent(bootstrap.launch_context)}`,
            );
            setLineup(status.lineup);
            if (status.lineup.active) {
                activeLineupSeenRef.current = true;
                if (!stationOptedOutRef.current && !roomRef.current && !joiningRef.current) await requestStationToken('lineup', false);
            } else if (status.direct.active) {
                activeDirectSeenRef.current = true;
                setNotice('300 is calling this station directly. Joining automatically…');
                if (!stationOptedOutRef.current && !roomRef.current && !joiningRef.current) await requestStationToken('direct', false);
            } else if (activeLineupSeenRef.current && roomRef.current && roomMode === 'lineup') {
                await cleanupRoom(false);
                setNotice('Morning Lineup has ended.');
                setStationReadiness([]);
                setPhase('ready');
            } else if (activeDirectSeenRef.current && roomRef.current && roomMode === 'direct') {
                activeDirectSeenRef.current = false;
                await cleanupRoom(false);
                setNotice('The direct call has ended.');
                setPhase('standing_by');
            }
        } catch (statusError) {
            setError(statusError instanceof Error ? statusError.message : 'Unable to refresh lineup status.');
        }
    }, [bootstrap, cleanupRoom, requestStationToken, roomMode]);

    const refreshCommandStatus = useCallback(async () => {
        if (!commandAuthorized) return;
        try {
            const status = await getJson<CommandStatusResponse>(bootstrap.endpoints.command_status);
            setProviderApiHealthy(status.provider_api_healthy);
            setLineup(status.lineup);
            setStationReadiness(status.stations);
            if (status.lineup.active) activeLineupSeenRef.current = true;
            if (!status.lineup.active && activeLineupSeenRef.current && roomRef.current && roomMode === 'lineup') {
                await cleanupRoom(false);
                setNotice('Morning Lineup has ended.');
                setPhase('ready');
            }
        } catch (statusError) {
            if (statusError instanceof ApiError && statusError.status === 403) setCommandAuthorized(false);
            else setError(statusError instanceof Error ? statusError.message : 'Unable to refresh station readiness.');
        }
    }, [bootstrap.endpoints.command_status, cleanupRoom, commandAuthorized, roomMode]);

    const prepareMedia = useCallback(async () => {
        setError(null);
        setNotice('Requesting camera and microphone access…');
        stationOptedOutRef.current = false;
        setPhase('requesting_media');
        stopPreview();
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
    }, [bootstrap, cameraId, microphoneId, refreshDevices, stopPreview]);

    useEffect(() => {
        if (autoMediaStartedRef.current) return;
        autoMediaStartedRef.current = true;
        void prepareMedia();
    }, [prepareMedia]);

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

    useEffect(() => () => {
        if (speakerTimerRef.current !== null) window.clearTimeout(speakerTimerRef.current);
        stopPreview();
        void cleanupRoom(true);
        notifyStandDown();
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
        const timer = window.setInterval(() => setClock(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    const participants = useMemo(
        () => room ? [room.localParticipant, ...Array.from(room.remoteParticipants.values())] : [],
        [participantRefresh, room],
    );
    const screenShareIdentity = useMemo(() => participants.find((participant) =>
        Array.from(participant.trackPublications.values()).some((publication) =>
            publication.source === Track.Source.ScreenShare && publication.isMuted === false,
        ))?.identity ?? null, [participantRefresh, participants]);
    const focusedIdentity = resolveFocusedIdentity(
        screenShareIdentity,
        manualPin,
        automaticFocus,
        participants[0]?.identity ?? null,
    );
    const focusedParticipant = participants.find((participant) => participant.identity === focusedIdentity) ?? participants[0];
    const thumbnailParticipants = participants.filter((participant) => participant.identity !== focusedParticipant?.identity);

    useEffect(() => {
        if (!room) return;
        for (const participant of room.remoteParticipants.values()) {
            for (const publication of participant.videoTrackPublications.values()) {
                if (!(publication instanceof RemoteTrackPublication)) continue;
                const high = publication.source === Track.Source.ScreenShare
                    || participant.identity === focusedIdentity;
                publication.setVideoQuality(high ? VideoQuality.HIGH : VideoQuality.LOW);
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

    const authorizeCommand = async () => {
        setActionBusy('authorize');
        setPhase('authorizing');
        setError(null);
        try {
            await postJson(bootstrap.endpoints.command_authorize, bootstrap.csrf_token, { command_pin: commandPin });
            setCommandAuthorized(true);
            setCommandPin('');
            setPhase('ready');
            setNotice('300 command access confirmed.');
        } catch (authorizationError) {
            setError(authorizationError instanceof Error ? authorizationError.message : 'The 300 PIN could not be verified.');
            setPhase('ready');
        } finally {
            setActionBusy(null);
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
            if (credentials.session) {
                setLineup({
                    active: true,
                    session_id: credentials.session.id,
                    started_at: new Date().toISOString(),
                    ends_at: new Date(Date.now() + bootstrap.lineup_max_minutes * 60_000).toISOString(),
                });
            }
            activeLineupSeenRef.current = true;
            setRoomMode('lineup');
            await connectWithCredentials(credentials, confirmedTakeover);
        } catch (startError) {
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
            if (directError instanceof ApiError && directError.status === 409 && directError.payload.code === 'endpoint_in_use') {
                setTakeover(true);
            }
            setError(directError instanceof Error ? directError.message : 'The direct station call could not be started.');
            setPhase('ready');
        } finally {
            setActionBusy(null);
        }
    };

    const joinAsSelf = async () => {
        setActionBusy('join-self');
        setError(null);
        try {
            const active = await postJson<SessionResponse>(bootstrap.endpoints.sessions, bootstrap.csrf_token, { room: 'lineup' });
            const credentials = await postJson<TokenResponse>(
                `${bootstrap.endpoints.api_base}/sessions/${active.session.id}/token`,
                bootstrap.csrf_token,
                { join_as: 'self' },
            );
            setLineup({ ...emptyLineup, active: true, session_id: active.session.id });
            await connectWithCredentials(credentials);
        } catch (joinError) {
            setError(joinError instanceof Error ? joinError.message : 'No active Morning Lineup is available.');
            setPhase('ready');
        } finally {
            setActionBusy(null);
        }
    };

    const endConference = async () => {
        setActionBusy('end');
        try {
            const endpoint = roomMode === 'direct' && sessionId
                ? `${bootstrap.endpoints.api_base}/sessions/${sessionId}/end`
                : bootstrap.endpoints.command_end;
            await postJson(endpoint, bootstrap.csrf_token, {});
            await cleanupRoom(false);
            setLineup(emptyLineup);
            setStationReadiness([]);
            activeDirectSeenRef.current = false;
            setNotice(roomMode === 'direct' ? 'Direct call ended.' : 'Morning Lineup ended. All stations were disconnected.');
            setPhase('ready');
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
    const remainingSeconds = lineup.ends_at
        ? Math.max(0, Math.floor((new Date(lineup.ends_at).getTime() - clock) / 1000))
        : null;
    const commandPinReady = /^\d{4,8}$/.test(commandPin);

    return (
        <div className="vc-shell" data-phase={phase} data-entry-mode={bootstrap.entry_mode} data-ice-policy={forceRelay ? 'relay' : 'all'}>
            <header className="vc-header">
                <div>
                    <span className="vc-eyebrow"><ShieldCheck size={15} /> MBFD secure conference</span>
                    <h1>{bootstrap.entry_mode === 'command' ? 'Morning Lineup — 300' : bootstrap.display_name}</h1>
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

            {phase === 'reconnecting' && <div className="vc-banner vc-banner--warning" role="status"><RefreshCw size={18} className="vc-spin" /> Network interrupted. LiveKit is reconnecting automatically.</div>}
            {audioBlocked && connected && <button className="vc-banner vc-banner--action" type="button" onClick={async () => {
                await room?.startAudio();
                setAudioBlocked(false);
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
                            {!cameraReady && <div className="vc-preview__empty">{phase === 'requesting_media' ? <LoaderCircle size={32} className="vc-spin" /> : <CameraOff size={32} />}<span>{phase === 'requesting_media' ? 'Waiting for browser permission…' : 'No camera preview — audio-only is available'}</span></div>}
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
                        <div className="vc-section-heading"><div><span>{bootstrap.entry_mode === 'station' ? 'Morning Lineup' : bootstrap.entry_mode === 'command' ? '300 command' : 'Employee Portal'}</span><h2 id="entry-title">{bootstrap.display_name}</h2></div></div>

                        {bootstrap.entry_mode === 'station' && <div className="vc-standby" data-ready={phase === 'standing_by'}>
                            <Radio size={34} />
                            <strong>{phase === 'standing_by' ? 'READY — STANDING BY' : 'Preparing station…'}</strong>
                            <span>{lineup.active ? '300 has started. Joining automatically…' : 'Waiting for 300 to start Morning Lineup.'}</span>
                            <small>No LiveKit connection or participant minutes are used while standing by.</small>
                        </div>}

                        {bootstrap.entry_mode === 'command' && !commandAuthorized && <form className="vc-command-login" onSubmit={(event) => { event.preventDefault(); void authorizeCommand(); }}>
                            <div className="vc-room-summary"><ShieldCheck size={22} /><div><strong>Employee session confirmed</strong><span>Enter the 300 PIN to unlock command controls. Your existing login is reused.</span></div></div>
                            <label className="vc-field">300 command PIN<input type="password" inputMode="numeric" autoComplete="off" pattern="[0-9]{4,8}" minLength={4} maxLength={8} value={commandPin} onChange={(event) => setCommandPin(event.target.value.replace(/\D/g, '').slice(0, 8))} /></label>
                            <button className="vc-button vc-button--primary" type="submit" disabled={!commandPinReady || actionBusy !== null || !microphoneReady}>{actionBusy === 'authorize' ? <LoaderCircle size={19} className="vc-spin" /> : <ShieldCheck size={19} />} Continue as 300</button>
                        </form>}

                        {bootstrap.entry_mode === 'command' && commandAuthorized && <div className="vc-ready-dashboard">
                            <div className={`vc-banner ${providerApiHealthy === false ? 'vc-banner--error' : 'vc-banner--info'}`} role="status">
                                {providerApiHealthy === null ? <LoaderCircle size={18} className="vc-spin" /> : providerApiHealthy ? <Check size={18} /> : <CircleAlert size={18} />}
                                LiveKit Cloud API: {providerApiHealthy === null ? 'Checking…' : providerApiHealthy ? 'Healthy' : 'Unavailable — Start is disabled'}
                            </div>
                            <div className="vc-room-summary"><Users size={22} /><div><strong>Station readiness</strong><span>Start when operations are ready. Late stations can join automatically.</span></div></div>
                            <div className="vc-ready-list">{stationOptions.map((station) => {
                                const status = stationReadiness.find((candidate) => candidate.join_as === station.value);
                                return <div key={station.value}><strong>{station.label}</strong><span className={status?.ready ? 'is-ready' : ''}>{status?.ready ? 'READY' : 'WAITING'}</span><button type="button" onClick={() => void startDirectCall(station.value)} disabled={actionBusy !== null || !microphoneReady || providerApiHealthy !== true}>Direct call</button></div>;
                            })}</div>
                            <button className="vc-button vc-button--start" type="button" onClick={() => void startLineup(false)} disabled={actionBusy !== null || !microphoneReady || providerApiHealthy !== true}>{actionBusy === 'start' ? <LoaderCircle size={20} className="vc-spin" /> : <Video size={20} />} {lineup.active ? 'Reconnect to Morning Lineup' : 'Start Morning Lineup'}</button>
                        </div>}

                        {bootstrap.entry_mode === 'self' && <div className="vc-self-entry">
                            <div className="vc-room-summary"><Sparkles size={22} /><div><strong>Join as {bootstrap.display_name}</strong><span>Your server-derived employee identity and display name will be used.</span></div></div>
                            <button className="vc-button vc-button--primary" type="button" onClick={() => void joinAsSelf()} disabled={actionBusy !== null || !microphoneReady}>{actionBusy === 'join-self' ? <LoaderCircle size={20} className="vc-spin" /> : <Video size={20} />} Join active Morning Lineup</button>
                        </div>}
                    </section>
                </div>
            ) : (
                <div className={`vc-conference ${bootstrap.entry_mode === 'command' ? 'vc-conference--command' : ''}`}>
                    <main className="vc-speaker-layout">
                        <div className="vc-focus-bar"><span>{screenShareIdentity ? 'Screen share' : manualPin ? 'Pinned' : 'Auto speaker'}</span>{manualPin && !screenShareIdentity && <button type="button" onClick={() => setManualPin(null)}>AUTO</button>}</div>
                        <div className="vc-focus-stage">{focusedParticipant && <ParticipantTile participant={focusedParticipant} local={focusedParticipant === room?.localParticipant} refreshKey={participantRefresh} focused onFocus={() => setManualPin(focusedParticipant.identity)} />}</div>
                        {thumbnailParticipants.length > 0 && <div className="vc-thumbnails" aria-label="Other participants">{thumbnailParticipants.map((participant) => <ParticipantTile key={participant.identity} participant={participant} local={participant === room?.localParticipant} refreshKey={participantRefresh} focused={false} onFocus={() => setManualPin(participant.identity)} />)}</div>}
                    </main>

                    {bootstrap.entry_mode === 'command' && <aside className="vc-command" aria-label="300 station microphone controls">
                        <div><span>300 controls</span><h2>Station microphones</h2><p>More than one station may have the floor. Your own microphone is independent.</p></div>
                        <button type="button" className="vc-button vc-button--danger-outline" onClick={() => void muteAllStations()} disabled={actionBusy !== null}><MicOff size={18} /> Mute all stations</button>
                        <div className="vc-command__stations">{stationOptions.map((station) => {
                            const participant = participants.find((candidate) => candidate.identity === `mbfd:${station.value}`);
                            const micLive = participant ? Array.from(participant.audioTrackPublications.values()).some((publication) => !publication.isMuted) : false;
                            return <div key={station.value}><span><strong>{station.label}</strong><small>{participant ? 'Connected' : 'Not connected'}</small></span><button type="button" onClick={() => void stationMicrophone(station.value, !micLive)} disabled={!participant || actionBusy !== null}>{micLive ? <><MicOff size={16} /> Mute</> : <><Mic size={16} /> Give Floor</>}</button></div>;
                        })}</div>
                        <button type="button" className="vc-button vc-button--danger" onClick={() => void endConference()} disabled={actionBusy !== null}><PhoneOff size={18} /> {roomMode === 'direct' ? 'End Direct Call' : 'End Morning Lineup'}</button>
                    </aside>}

                    {bootstrap.entry_mode === 'station' && <div className={`vc-station-mic ${forcedStationMic || !microphoneEnabled ? 'vc-station-mic--muted' : 'vc-station-mic--live'}`} role="status">{forcedStationMic ? <><MicOff size={24} /> MIC MUTED — WAITING FOR 300</> : microphoneEnabled ? <><Mic size={24} /> MIC LIVE</> : <><MicOff size={24} /> MIC MUTED</>}</div>}

                    <nav className="vc-controls" aria-label="Conference controls">
                        <button type="button" onClick={() => void toggleMicrophone()} disabled={forcedStationMic} aria-pressed={!microphoneEnabled}>{microphoneEnabled ? <Mic /> : <MicOff />}<span>{microphoneEnabled ? 'Mute' : 'Unmute'}</span></button>
                        <button type="button" onClick={() => void toggleCamera()} aria-pressed={!cameraEnabled}>{cameraEnabled ? <Camera /> : <CameraOff />}<span>{cameraEnabled ? 'Camera off' : 'Camera on'}</span></button>
                        <button type="button" onClick={() => void toggleScreenShare()} aria-pressed={screenShareEnabled}><Cast /><span>{screenShareEnabled ? 'Stop sharing' : 'Share screen'}</span></button>
                        <button type="button" onClick={async () => { await room?.startAudio(); setAudioBlocked(false); }}><Headphones /><span>Audio</span></button>
                        <button type="button" onClick={() => setManualPin(focusedParticipant?.identity ?? null)}><Pin /><span>Pin</span></button>
                        {bootstrap.entry_mode !== 'command' && <button type="button" className="vc-controls__leave" onClick={async () => {
                            setPhase('leaving');
                            if (bootstrap.entry_mode === 'station') stationOptedOutRef.current = true;
                            await cleanupRoom(true);
                            if (bootstrap.entry_mode === 'station') {
                                notifyStandDown();
                                setStationReadiness([]);
                            }
                            setPhase('ready');
                        }}><PhoneOff /><span>Leave</span></button>}
                    </nav>
                </div>
            )}

            {takeover && <div className="vc-modal-backdrop" role="presentation"><div className="vc-modal" role="alertdialog" aria-modal="true" aria-labelledby="takeover-title" aria-describedby="takeover-description"><CircleAlert size={28} /><h2 id="takeover-title">Endpoint already in use</h2><p id="takeover-description">Taking over will disconnect the existing {isStationRole(bootstrap.join_as) ? stationLabel(bootstrap.join_as) : '300'} connection.</p><div><button type="button" autoFocus className="vc-button vc-button--secondary" onClick={() => setTakeover(false)}>Cancel</button><button type="button" className="vc-button vc-button--danger" onClick={() => bootstrap.entry_mode === 'station' ? void requestStationToken(roomMode, true) : pendingDirectStation ? void startDirectCall(pendingDirectStation, true) : void startLineup(true)}>Confirm takeover</button></div></div></div>}
        </div>
    );
}
