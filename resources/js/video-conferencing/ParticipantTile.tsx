import { useEffect, useMemo, useRef } from 'react';
import {
    ConnectionQuality,
    Participant,
    Track,
    type TrackPublication,
} from 'livekit-client';

interface ParticipantTileProps {
    participant: Participant;
    local?: boolean;
    refreshKey: number;
    focused: boolean;
    onFocus: () => void;
}

export function ParticipantTile({ participant, local = false, refreshKey, focused, onFocus }: ParticipantTileProps) {
    const videoRef = useRef<HTMLVideoElement>(null);
    const audioRef = useRef<HTMLAudioElement>(null);
    const publications = useMemo(
        () => Array.from(participant.trackPublications.values()),
        [participant, refreshKey],
    );
    const screenPublication = publications.find((publication) =>
        publication.kind === Track.Kind.Video && publication.source === Track.Source.ScreenShare,
    );
    const cameraPublication = publications.find((publication) =>
        publication.kind === Track.Kind.Video && publication.source === Track.Source.Camera,
    );
    const videoPublication = screenPublication ?? cameraPublication;
    const audioPublication = publications.find((publication) => publication.kind === Track.Kind.Audio);

    useEffect(() => {
        const attachments: Array<{ publication: TrackPublication; element: HTMLMediaElement }> = [];
        if (videoPublication?.track && videoRef.current) {
            videoPublication.track.attach(videoRef.current);
            attachments.push({ publication: videoPublication, element: videoRef.current });
        }
        if (!local && audioPublication?.track && audioRef.current) {
            audioPublication.track.attach(audioRef.current);
            attachments.push({ publication: audioPublication, element: audioRef.current });
        }

        return () => attachments.forEach(({ publication, element }) => publication.track?.detach(element));
    }, [audioPublication, local, refreshKey, videoPublication]);

    const quality = participant.connectionQuality ?? ConnectionQuality.Unknown;
    const initials = (participant.name || participant.identity)
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();

    return (
        <article className={`vc-tile ${focused ? 'vc-tile--focused' : ''}`} aria-label={`${participant.name || 'Participant'} video`}>
            <button type="button" className="vc-tile__focus" onClick={onFocus} aria-pressed={focused}>
                {videoPublication?.track && !videoPublication.isMuted ? (
                    <video ref={videoRef} autoPlay playsInline muted={local} />
                ) : (
                    <span className="vc-avatar" aria-hidden="true">{initials}</span>
                )}
                <span className="vc-tile__scrim" />
                <span className="vc-tile__name">{participant.name || 'MBFD participant'}{local ? ' (You)' : ''}</span>
                {screenPublication && <span className="vc-tile__share">Screen</span>}
                <span className={`vc-quality vc-quality--${quality}`} title={`Connection quality: ${quality}`} aria-label={`Connection quality: ${quality}`} />
                <span className={`vc-mic ${audioPublication?.isMuted !== false ? 'vc-mic--muted' : ''}`}>
                    {audioPublication?.isMuted !== false ? 'Mic muted' : participant.isSpeaking ? 'Speaking' : 'Mic live'}
                </span>
            </button>
            {!local && <audio ref={audioRef} autoPlay />}
        </article>
    );
}
