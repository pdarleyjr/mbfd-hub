import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    Camera,
    CameraOff,
    Cast,
    CircleAlert,
    Headphones,
    LoaderCircle,
    Mic,
    MicOff,
    PhoneOff,
    Radio,
    RefreshCw,
    ShieldCheck,
    Video,
    Volume2,
    Wifi,
    WifiOff,
} from 'lucide-react';
import {
    createLocalTracks,
    LocalTrack,
    LocalVideoTrack,
    Room,
    RoomEvent,
    RpcError,
    supportsAudioOutputSelection,
    Track,
    VideoPresets,
} from 'livekit-client';
import { ApiError, postJson } from './api';
import {
    ConferenceConnectivityError,
    verifyConferenceConnectivity,
    type ConferenceConnectivityFailureCode,
} from './connectivity';
import {
    deviceLabel,
    enumerateMediaDevices,
    mediaErrorMessage,
    testSpeakerOutput,
    type MediaDevices,
} from './media';
import { ParticipantTile } from './ParticipantTile';
import type {
    ConferenceBootstrap,
    ConferencePhase,
    JoinRole,
    RoomMode,
    SessionResponse,
    TokenResponse,
} from './types';

interface ConferenceAppProps {
    bootstrap: ConferenceBootstrap;
}

const emptyDevices: MediaDevices = { cameras: [], microphones: [], speakers: [] };
const stationRoles: JoinRole[] = ['sta1', 'sta2', 'sta3', 'sta4', 'sta6'];
type ConnectivityStatus = 'unchecked' | 'checking' | 'reachable' | 'unreachable';

function initialSelection(bootstrap: ConferenceBootstrap): { mode: RoomMode; role: JoinRole; station: JoinRole } {
    const params = new URLSearchParams(window.location.search);
    const requestedMode = params.get('room');
    const mode: RoomMode = requestedMode === 'direct' ? 'direct' : 'lineup';
    const requestedRole = params.get('join_as') as JoinRole | null;
    const validRole = bootstrap.roles.some((role) => role.value === requestedRole) ? requestedRole! : 'self';
    const station = stationRoles.includes(validRole) ? validRole : 'sta1';

    return { mode, role: mode === 'direct' && validRole === 'self' ? '300' : validRole, station };
}

export function ConferenceApp({ bootstrap }: ConferenceAppProps) {
    const initial = useMemo(() => initialSelection(bootstrap), [bootstrap]);
    const forceRelay = useMemo(
        () => new URLSearchParams(window.location.search).get('force_relay') === '1',
        [],
    );
    const [phase, setPhase] = useState<ConferencePhase>('lobby');
    const [mode, setMode] = useState<RoomMode>(initial.mode);
    const [joinAs, setJoinAs] = useState<JoinRole>(initial.role);
    const [directStation, setDirectStation] = useState<JoinRole>(initial.station);
    const [devices, setDevices] = useState<MediaDevices>(emptyDevices);
    const [cameraId, setCameraId] = useState('');
    const [microphoneId, setMicrophoneId] = useState('');
    const [speakerId, setSpeakerId] = useState('');
    const [cameraEnabled, setCameraEnabled] = useState(true);
    const [microphoneEnabled, setMicrophoneEnabled] = useState(true);
    const [screenShareEnabled, setScreenShareEnabled] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [takeover, setTakeover] = useState(false);
    const [participantRefresh, setParticipantRefresh] = useState(0);
    const [focusedIdentity, setFocusedIdentity] = useState<string | null>(null);
    const [audioBlocked, setAudioBlocked] = useState(false);
    const [forcedStationMic, setForcedStationMic] = useState(false);
    const [speakerTesting, setSpeakerTesting] = useState(false);
    const [actionBusy, setActionBusy] = useState<string | null>(null);
    const [commandPin, setCommandPin] = useState('');
    const [sessionId, setSessionId] = useState<string | null>(null);
    const [connectivityStatus, setConnectivityStatus] = useState<ConnectivityStatus>('unchecked');
    const [room, setRoom] = useState<Room | null>(null);
    const previewVideoRef = useRef<HTMLVideoElement>(null);
    const previewTracksRef = useRef<LocalTrack[]>([]);
    const roomRef = useRef<Room | null>(null);
    const participationIdRef = useRef<string | null>(null);
    const isLeavingRef = useRef(false);

    const refreshDevices = useCallback(async (requestPermissions = false) => {
        try {
            const next = await enumerateMediaDevices(requestPermissions);
            setDevices(next);
            setCameraId((current) => next.cameras.some((device) => device.deviceId === current) ? current : (next.cameras[0]?.deviceId ?? ''));
            setMicrophoneId((current) => next.microphones.some((device) => device.deviceId === current) ? current : (next.microphones[0]?.deviceId ?? ''));
            setSpeakerId((current) => next.speakers.some((device) => device.deviceId === current) ? current : (next.speakers[0]?.deviceId ?? ''));
        } catch (deviceError) {
            setError(mediaErrorMessage(deviceError));
        }
    }, []);

    useEffect(() => {
        void refreshDevices(false);
        const onDeviceChange = () => void refreshDevices(false);
        navigator.mediaDevices?.addEventListener('devicechange', onDeviceChange);

        return () => navigator.mediaDevices?.removeEventListener('devicechange', onDeviceChange);
    }, [refreshDevices]);

    useEffect(() => {
        const conferenceActive = phase === 'connected' || phase === 'reconnecting';
        document.documentElement.classList.toggle('vc-conference-active', conferenceActive);

        return () => document.documentElement.classList.remove('vc-conference-active');
    }, [phase]);

    const stopPreview = useCallback(() => {
        previewTracksRef.current.forEach((track) => {
            track.detach();
            track.stop();
        });
        previewTracksRef.current = [];
        if (previewVideoRef.current) previewVideoRef.current.srcObject = null;
    }, []);

    const notifyLeave = useCallback((participationId: string) => {
        void fetch(`${bootstrap.endpoints.api_base}/participations/${participationId}/leave`, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': bootstrap.csrf_token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: '{}',
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
        isLeavingRef.current = false;
    }, [notifyLeave]);

    useEffect(() => () => {
        stopPreview();
        void cleanupRoom(true);
    }, [cleanupRoom, stopPreview]);

    const prepareMedia = async () => {
        setError(null);
        setPhase('requesting_media');
        stopPreview();
        try {
            const tracks = await createLocalTracks({
                audio: microphoneId ? { deviceId: microphoneId, echoCancellation: true, noiseSuppression: true, autoGainControl: true } : true,
                video: cameraId ? { deviceId: cameraId, resolution: VideoPresets.h720.resolution } : { resolution: VideoPresets.h720.resolution },
            });
            previewTracksRef.current = tracks;
            const video = tracks.find((track): track is LocalVideoTrack => track.kind === Track.Kind.Video);
            const audio = tracks.find((track) => track.kind === Track.Kind.Audio);
            if (video && !cameraEnabled) await video.mute();
            if (audio && !microphoneEnabled) await audio.mute();
            if (video && previewVideoRef.current) video.attach(previewVideoRef.current);
            await refreshDevices(true);
            setPhase('ready');
        } catch (deviceError) {
            stopPreview();
            setError(mediaErrorMessage(deviceError));
            setPhase('failed');
        }
    };

    const togglePreviewTrack = async (kind: Track.Kind) => {
        const track = previewTracksRef.current.find((candidate) => candidate.kind === kind);
        if (kind === Track.Kind.Video) {
            const enabled = !cameraEnabled;
            setCameraEnabled(enabled);
            if (track) await (enabled ? track.unmute() : track.mute());
        } else {
            const enabled = !microphoneEnabled;
            setMicrophoneEnabled(enabled);
            if (track) await (enabled ? track.unmute() : track.mute());
        }
    };

    const attachRoomEvents = (nextRoom: Room) => {
        const refresh = () => setParticipantRefresh((value) => value + 1);
        nextRoom.on(RoomEvent.ParticipantConnected, refresh);
        nextRoom.on(RoomEvent.ParticipantDisconnected, refresh);
        nextRoom.on(RoomEvent.TrackSubscribed, refresh);
        nextRoom.on(RoomEvent.TrackUnsubscribed, refresh);
        nextRoom.on(RoomEvent.TrackMuted, refresh);
        nextRoom.on(RoomEvent.TrackUnmuted, refresh);
        nextRoom.on(RoomEvent.ActiveSpeakersChanged, refresh);
        nextRoom.on(RoomEvent.ConnectionQualityChanged, refresh);
        nextRoom.on(RoomEvent.MediaDevicesChanged, () => void refreshDevices(false));
        nextRoom.on(RoomEvent.MediaDevicesError, (deviceError) => setError(mediaErrorMessage(deviceError)));
        nextRoom.on(RoomEvent.Reconnecting, () => setPhase('reconnecting'));
        nextRoom.on(RoomEvent.Reconnected, () => setPhase('connected'));
        nextRoom.on(RoomEvent.Disconnected, () => {
            if (!isLeavingRef.current) {
                setError('The conference connection ended. Your employee session is still signed in.');
                setPhase('failed');
            }
        });
    };

    const issueToken = async (activeSessionId: string, confirmedTakeover: boolean): Promise<TokenResponse> => {
        return postJson<TokenResponse>(
            `${bootstrap.endpoints.api_base}/sessions/${activeSessionId}/token`,
            bootstrap.csrf_token,
            {
                join_as: joinAs,
                confirmed_takeover: confirmedTakeover,
                ...(joinAs === '300' ? { command_pin: commandPin } : {}),
            },
        );
    };

    const reportClientFailure = (
        stage: 'preflight' | 'signaling' | 'media_publication',
        failureCode: ConferenceConnectivityFailureCode | 'livekit_signaling_failed' | 'media_publication_failed',
        activeSessionId?: string,
    ) => {
        void postJson<void>(bootstrap.endpoints.connectivity_failures, bootstrap.csrf_token, {
            stage,
            room: mode,
            join_as: joinAs,
            failure_code: failureCode,
            session_id: activeSessionId,
        }).catch(() => undefined);
    };

    const joinConference = async (confirmedTakeover = false) => {
        if (previewTracksRef.current.length === 0) {
            setError('Test your camera and microphone before joining.');
            return;
        }
        setTakeover(false);
        setError(null);
        setPhase('joining');

        setConnectivityStatus('checking');
        try {
            await verifyConferenceConnectivity(
                bootstrap.connectivity_url,
                bootstrap.connectivity_timeout_ms,
            );
            setConnectivityStatus('reachable');
        } catch (connectivityError) {
            const failureCode = connectivityError instanceof ConferenceConnectivityError
                ? connectivityError.code
                : 'conference_network_unreachable';
            setConnectivityStatus('unreachable');
            reportClientFailure('preflight', failureCode);
            setError(`This browser cannot reach the conference network. ${bootstrap.connectivity_help} No room was created; your employee session is still signed in.`);
            setPhase('ready');
            return;
        }

        let failureStage: 'session' | 'signaling' | 'media_publication' = 'session';
        let activeSessionId: string | undefined;

        try {
            const sessionResponse = await postJson<SessionResponse>(bootstrap.endpoints.sessions, bootstrap.csrf_token, {
                room: mode,
                station: mode === 'direct' ? directStation : undefined,
                join_as: joinAs,
                ...(joinAs === '300' ? { command_pin: commandPin } : {}),
            });
            activeSessionId = sessionResponse.session.id;
            setSessionId(activeSessionId);
            const credentials = await issueToken(activeSessionId, confirmedTakeover);
            participationIdRef.current = credentials.participation_id;
            const nextRoom = new Room({
                adaptiveStream: true,
                dynacast: true,
                videoCaptureDefaults: { resolution: VideoPresets.h720.resolution },
                publishDefaults: { simulcast: true },
            });
            attachRoomEvents(nextRoom);
            if (stationRoles.includes(joinAs)) {
                nextRoom.registerRpcMethod('mbfd.stationMic', async (invocation) => {
                    if (invocation.callerIdentity !== 'mbfd:300') {
                        throw new RpcError(2001, 'Only 300 may manage a station microphone.');
                    }
                    const payload = JSON.parse(invocation.payload) as { enabled?: boolean };
                    const enabled = payload.enabled === true;
                    await nextRoom.localParticipant.setMicrophoneEnabled(enabled);
                    setMicrophoneEnabled(enabled);
                    setForcedStationMic(!enabled);
                    setParticipantRefresh((value) => value + 1);
                    return JSON.stringify({ enabled });
                });
            }

            roomRef.current = nextRoom;
            failureStage = 'signaling';
            await nextRoom.connect(credentials.server_url, credentials.token, {
                autoSubscribe: true,
                rtcConfig: forceRelay ? { iceTransportPolicy: 'relay' } : undefined,
                websocketTimeout: 8000,
            });

            const preparedTracks = [...previewTracksRef.current];
            previewTracksRef.current = [];
            failureStage = 'media_publication';
            for (const track of preparedTracks) {
                if (track.kind === Track.Kind.Audio && (stationRoles.includes(joinAs) || !microphoneEnabled)) {
                    await track.mute();
                }
                if (track.kind === Track.Kind.Video && !cameraEnabled) await track.mute();
                await nextRoom.localParticipant.publishTrack(track, {
                    simulcast: track.kind === Track.Kind.Video,
                    videoCodec: 'vp8',
                });
            }

            setRoom(nextRoom);
            setFocusedIdentity(nextRoom.localParticipant.identity);
            setAudioBlocked(!nextRoom.canPlaybackAudio);
            if (nextRoom.canPlaybackAudio) await nextRoom.startAudio().catch(() => setAudioBlocked(true));
            setForcedStationMic(stationRoles.includes(joinAs));
            setMicrophoneEnabled(!stationRoles.includes(joinAs) && microphoneEnabled);
            setCommandPin('');
            setPhase('connected');
        } catch (joinError) {
            await cleanupRoom(true);
            if (joinError instanceof ApiError && joinError.status === 409 && joinError.payload.code === 'endpoint_in_use') {
                setTakeover(true);
                setError(joinError.message);
                setPhase('ready');
                return;
            }
            if (failureStage === 'signaling') {
                reportClientFailure('signaling', 'livekit_signaling_failed', activeSessionId);
            } else if (failureStage === 'media_publication') {
                reportClientFailure('media_publication', 'media_publication_failed', activeSessionId);
            }
            setError(failureStage === 'signaling'
                ? 'The secure media service was reachable, but the conference connection could not be established. Check Tailscale and Local Network Access, then retry.'
                : failureStage === 'media_publication'
                    ? 'The call connected, but this browser could not publish the selected camera or microphone. Retest the devices and try again.'
                    : joinError instanceof ApiError && joinError.status === 404 && mode === 'direct'
                        ? 'No active direct call from 300 was found for that station.'
                        : joinError instanceof Error ? joinError.message : 'The conference could not be joined.');
            if (failureStage === 'media_publication') {
                stopPreview();
                setPhase('failed');
            } else {
                setPhase('ready');
            }
        }
    };

    const leaveConference = async () => {
        setPhase('leaving');
        await cleanupRoom(true);
        setPhase('lobby');
        setError(null);
        await refreshDevices(false);
    };

    const toggleCamera = async () => {
        if (!room) return;
        const enabled = !cameraEnabled;
        try {
            await room.localParticipant.setCameraEnabled(enabled, cameraId ? { deviceId: cameraId } : undefined);
            setCameraEnabled(enabled);
        } catch (deviceError) {
            setError(mediaErrorMessage(deviceError));
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

    const changeDevice = async (kind: MediaDeviceKind, value: string) => {
        if (kind === 'videoinput') setCameraId(value);
        if (kind === 'audioinput') setMicrophoneId(value);
        if (kind === 'audiooutput') setSpeakerId(value);
        if (!value) return;

        if (room) {
            try {
                await room.switchActiveDevice(kind, value, true);
            } catch (deviceError) {
                setError(mediaErrorMessage(deviceError));
            }
            return;
        }

        const previewKind = kind === 'videoinput'
            ? Track.Kind.Video
            : kind === 'audioinput' ? Track.Kind.Audio : null;
        const previewTrack = previewTracksRef.current.find((track) => track.kind === previewKind);
        if (previewTrack) {
            try {
                await previewTrack.restartTrack({ deviceId: value });
            } catch (deviceError) {
                setError(mediaErrorMessage(deviceError));
            }
        }
    };

    const testSpeaker = async () => {
        setSpeakerTesting(true);
        setError(null);
        try {
            await testSpeakerOutput(speakerId);
        } catch (speakerError) {
            setError(speakerError instanceof Error
                ? speakerError.message
                : 'The selected speaker could not play the test tone.');
        } finally {
            setSpeakerTesting(false);
        }
    };

    const stationMicrophone = async (station: JoinRole, enabled: boolean) => {
        if (!room || !sessionId) return;
        setActionBusy(`${station}:${enabled}`);
        setError(null);
        try {
            const result = await postJson<{
                identity: string;
                rpc_required: boolean;
                method: string;
                payload: { enabled: boolean };
            }>(
                `${bootstrap.endpoints.api_base}/sessions/${sessionId}/moderation/stations/${station}/microphone`,
                bootstrap.csrf_token,
                { enabled },
            );
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
        if (!sessionId) return;
        setActionBusy('mute-all');
        try {
            const result = await postJson<{ muted: JoinRole[] }>(
                `${bootstrap.endpoints.api_base}/sessions/${sessionId}/moderation/mute-stations`,
                bootstrap.csrf_token,
                {},
            );
            await Promise.allSettled(result.muted.map((station) => room?.localParticipant.performRpc({
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

    const participants = room
        ? [room.localParticipant, ...Array.from(room.remoteParticipants.values())]
        : [];
    const connected = phase === 'connected' || phase === 'reconnecting';
    const commandPinReady = joinAs !== '300' || /^\d{4,8}$/.test(commandPin);
    const canJoin = phase === 'ready' && commandPinReady;
    const filteredRoles = mode === 'direct'
        ? bootstrap.roles.filter((role) => role.value === '300' || role.value === directStation)
        : bootstrap.roles;

    useEffect(() => {
        if (mode === 'direct' && joinAs !== '300' && joinAs !== directStation) setJoinAs('300');
    }, [directStation, joinAs, mode]);

    return (
        <div className="vc-shell" data-phase={phase} data-ice-policy={forceRelay ? 'relay' : 'all'}>
            <header className="vc-header">
                <div>
                    <span className="vc-eyebrow"><ShieldCheck size={15} /> MBFD secure conference</span>
                    <h1>Video Conferencing</h1>
                    <p>Morning Lineup and direct station calls, inside your employee session.</p>
                </div>
                <span className={`vc-status vc-status--${phase}`} aria-live="polite">
                    {phase === 'reconnecting' ? 'Reconnecting…' : phase === 'connected' ? 'Connected' : phase.replace('_', ' ')}
                </span>
            </header>

            {phase === 'reconnecting' && (
                <div className="vc-banner vc-banner--warning" role="status"><RefreshCw size={18} className="vc-spin" /> Network interrupted. Reconnecting automatically…</div>
            )}
            {audioBlocked && connected && (
                <button className="vc-banner vc-banner--action" type="button" onClick={async () => {
                    await room?.startAudio();
                    setAudioBlocked(false);
                }}><Volume2 size={18} /> Tap to enable conference audio</button>
            )}
            {error && <div className="vc-banner vc-banner--error" role="alert"><CircleAlert size={19} /><span>{error}</span></div>}

            {!connected ? (
                <div className="vc-lobby-grid">
                    <section className="vc-card vc-preview" aria-labelledby="preview-title">
                        <div className="vc-section-heading">
                            <div><span>Step 1</span><h2 id="preview-title">Check camera and microphone</h2></div>
                            <button type="button" className="vc-icon-button" onClick={() => void refreshDevices(false)} aria-label="Refresh device list"><RefreshCw size={19} /></button>
                        </div>
                        <div className="vc-preview__video">
                            <video ref={previewVideoRef} autoPlay playsInline muted />
                            {previewTracksRef.current.length === 0 && <div className="vc-preview__empty"><Video size={32} /><span>Preview starts only when you choose “Test devices”</span></div>}
                        </div>
                        <div className="vc-preview__toggles" role="group" aria-label="Prejoin media controls">
                            <button type="button" aria-pressed={!microphoneEnabled} onClick={() => void togglePreviewTrack(Track.Kind.Audio)}>
                                {microphoneEnabled ? <Mic size={18} /> : <MicOff size={18} />} {microphoneEnabled ? 'Mute preview mic' : 'Enable preview mic'}
                            </button>
                            <button type="button" aria-pressed={!cameraEnabled} onClick={() => void togglePreviewTrack(Track.Kind.Video)}>
                                {cameraEnabled ? <Camera size={18} /> : <CameraOff size={18} />} {cameraEnabled ? 'Turn preview camera off' : 'Turn preview camera on'}
                            </button>
                            {supportsAudioOutputSelection() && devices.speakers.length > 0 && (
                                <button type="button" onClick={() => void testSpeaker()} disabled={speakerTesting}>
                                    {speakerTesting ? <LoaderCircle size={18} className="vc-spin" /> : <Volume2 size={18} />}
                                    {speakerTesting ? 'Playing tone…' : 'Test selected speaker'}
                                </button>
                            )}
                        </div>
                        <div className="vc-device-grid">
                            <label>Camera<select value={cameraId} onChange={(event) => void changeDevice('videoinput', event.target.value)}>
                                {devices.cameras.map((device, index) => <option key={device.deviceId} value={device.deviceId}>{deviceLabel(device, index, 'Camera')}</option>)}
                            </select></label>
                            <label>Microphone<select value={microphoneId} onChange={(event) => void changeDevice('audioinput', event.target.value)}>
                                {devices.microphones.map((device, index) => <option key={device.deviceId} value={device.deviceId}>{deviceLabel(device, index, 'Microphone')}</option>)}
                            </select></label>
                            {supportsAudioOutputSelection() && <label>Speaker<select value={speakerId} onChange={(event) => void changeDevice('audiooutput', event.target.value)}>
                                {devices.speakers.map((device, index) => <option key={device.deviceId} value={device.deviceId}>{deviceLabel(device, index, 'Speaker')}</option>)}
                            </select></label>}
                        </div>
                        <button className="vc-button vc-button--secondary" type="button" onClick={() => void prepareMedia()} disabled={phase === 'requesting_media' || phase === 'joining'}>
                            {phase === 'requesting_media' ? <LoaderCircle size={19} className="vc-spin" /> : <Camera size={19} />} Test devices
                        </button>
                    </section>

                    <section className="vc-card" aria-labelledby="join-title">
                        <div className="vc-section-heading"><div><span>Step 2</span><h2 id="join-title">Choose how to join</h2></div></div>
                        <div className="vc-mode-switch" role="group" aria-label="Conference type">
                            <button type="button" aria-pressed={mode === 'lineup'} onClick={() => { setMode('lineup'); setJoinAs(initial.role); }}>Morning Lineup</button>
                            <button type="button" aria-pressed={mode === 'direct'} onClick={() => { setMode('direct'); setJoinAs('300'); }}>Direct station call</button>
                        </div>
                        <div className="vc-room-summary">
                            {mode === 'lineup' ? <><Radio size={22} /><div><strong>Today’s Morning Lineup</strong><span>{bootstrap.lineup_time ? `Configured for ${bootstrap.lineup_time} America/New_York` : 'Lineup time is not configured'}</span></div></> : <><Cast size={22} /><div><strong>Private call with one station</strong><span>300 starts the call; only 300 and the selected station may enter.</span></div></>}
                        </div>
                        <div className={`vc-room-summary vc-network-check vc-network-check--${connectivityStatus}`} role="status" aria-live="polite">
                            {connectivityStatus === 'unreachable' ? <WifiOff size={20} /> : connectivityStatus === 'checking' ? <LoaderCircle size={20} className="vc-spin" /> : <Wifi size={20} />}
                            <span>
                                <strong>{connectivityStatus === 'reachable' ? 'Conference network ready' : connectivityStatus === 'unreachable' ? 'Conference network unavailable' : connectivityStatus === 'checking' ? 'Checking conference network…' : 'Conference network check pending'}</strong>
                                <small>{connectivityStatus === 'unchecked' ? 'Connect MBFD Tailscale first. A reachability check runs before any room is created and may ask for Local Network Access.' : connectivityStatus === 'reachable' ? 'This browser can reach the secure media service.' : connectivityStatus === 'unreachable' ? bootstrap.connectivity_help : 'Allow Local Network Access if your browser asks. This normally takes less than five seconds.'}</small>
                            </span>
                        </div>
                        {mode === 'direct' && <label className="vc-field">Station<select value={directStation} onChange={(event) => setDirectStation(event.target.value as JoinRole)}>
                            {bootstrap.roles.filter((role) => role.station).map((role) => <option key={role.value} value={role.value}>{role.label}</option>)}
                        </select></label>}
                        <fieldset className="vc-role-list">
                            <legend>Join as</legend>
                            {filteredRoles.map((role) => <label key={role.value} className={joinAs === role.value ? 'is-selected' : ''}>
                                <input type="radio" name="join-role" value={role.value} checked={joinAs === role.value} onChange={() => setJoinAs(role.value)} />
                                <span><strong>{role.label}</strong>{role.station && <small>Fixed station endpoint</small>}</span>
                            </label>)}
                        </fieldset>
                        {joinAs === '300' && <label className="vc-field vc-command-pin">
                            300 command PIN
                            <input
                                type="password"
                                inputMode="numeric"
                                autoComplete="off"
                                pattern="[0-9]{4,8}"
                                minLength={4}
                                maxLength={8}
                                value={commandPin}
                                onChange={(event) => setCommandPin(event.target.value.replace(/\D/g, '').slice(0, 8))}
                                aria-describedby="vc-command-pin-help"
                            />
                            <small id="vc-command-pin-help">Required to join or start a call as 300.</small>
                        </label>}
                        <button className="vc-button vc-button--primary" type="button" onClick={() => void joinConference(false)} disabled={!canJoin}>
                            {phase === 'joining' ? <LoaderCircle size={20} className="vc-spin" /> : <Video size={20} />} Join conference
                        </button>
                        {phase !== 'ready' && <p className="vc-help">Test devices first. Camera and microphone permission is requested only after you press the button.</p>}
                    </section>
                </div>
            ) : (
                <div className={`vc-conference ${joinAs === '300' ? 'vc-conference--command' : ''}`}>
                    <main className={`vc-stage ${focusedIdentity ? 'vc-stage--focused' : ''}`}>
                        {participants.map((participant) => <ParticipantTile
                            key={participant.identity}
                            participant={participant}
                            local={participant === room?.localParticipant}
                            refreshKey={participantRefresh}
                            focused={focusedIdentity === participant.identity}
                            onFocus={() => setFocusedIdentity((current) => current === participant.identity ? null : participant.identity)}
                        />)}
                    </main>

                    {joinAs === '300' && <aside className="vc-command" aria-label="300 station microphone controls">
                        <div><span>300 controls</span><h2>Station microphones</h2><p>Enable sends a verified request to the station. Mute is enforced by the server.</p></div>
                        <button type="button" className="vc-button vc-button--danger-outline" onClick={() => void muteAllStations()} disabled={actionBusy !== null}><MicOff size={18} /> Mute all stations</button>
                        <div className="vc-command__stations">
                            {bootstrap.roles.filter((role) => role.station).map((role) => <div key={role.value}>
                                <strong>{role.label}</strong>
                                <span>
                                    <button type="button" onClick={() => void stationMicrophone(role.value, true)} disabled={actionBusy !== null}>Request mic on</button>
                                    <button type="button" onClick={() => void stationMicrophone(role.value, false)} disabled={actionBusy !== null}>Mute</button>
                                </span>
                            </div>)}
                        </div>
                    </aside>}

                    {stationRoles.includes(joinAs) && <div className={`vc-station-mic ${forcedStationMic || !microphoneEnabled ? 'vc-station-mic--muted' : 'vc-station-mic--live'}`} role="status">
                        {forcedStationMic ? <><MicOff size={24} /> MUTED BY 300</> : microphoneEnabled ? <><Mic size={24} /> MIC LIVE</> : <><MicOff size={24} /> MIC MUTED</>}
                    </div>}

                    <nav className="vc-controls" aria-label="Conference controls">
                        <button type="button" onClick={() => void toggleMicrophone()} disabled={forcedStationMic} aria-pressed={!microphoneEnabled} title={forcedStationMic ? '300 has muted this station microphone' : undefined}>{microphoneEnabled ? <Mic /> : <MicOff />}<span>{microphoneEnabled ? 'Mute' : 'Unmute'}</span></button>
                        <button type="button" onClick={() => void toggleCamera()} aria-pressed={!cameraEnabled}>{cameraEnabled ? <Camera /> : <CameraOff />}<span>{cameraEnabled ? 'Camera off' : 'Camera on'}</span></button>
                        <button type="button" onClick={() => void toggleScreenShare()} aria-pressed={screenShareEnabled}><Cast /><span>{screenShareEnabled ? 'Stop sharing' : 'Share screen'}</span></button>
                        <button type="button" onClick={async () => { await room?.startAudio(); setAudioBlocked(false); }}><Headphones /><span>Enable audio</span></button>
                        <button type="button" className="vc-controls__leave" onClick={() => void leaveConference()}><PhoneOff /><span>Leave</span></button>
                    </nav>
                </div>
            )}

            {takeover && <div className="vc-modal-backdrop" role="presentation">
                <div className="vc-modal" role="alertdialog" aria-modal="true" aria-labelledby="takeover-title" aria-describedby="takeover-description">
                    <CircleAlert size={28} />
                    <h2 id="takeover-title">Endpoint already in use</h2>
                    <p id="takeover-description">Taking over will disconnect the existing {bootstrap.roles.find((role) => role.value === joinAs)?.label} connection. Continue only if you are at that endpoint.</p>
                    <div><button type="button" autoFocus className="vc-button vc-button--secondary" onClick={() => setTakeover(false)}>Cancel</button><button type="button" className="vc-button vc-button--danger" onClick={() => void joinConference(true)}>Confirm takeover</button></div>
                </div>
            </div>}
        </div>
    );
}
