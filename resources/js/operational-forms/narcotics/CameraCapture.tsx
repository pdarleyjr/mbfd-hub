import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@fluentui/react-components';
import { Camera, X } from 'lucide-react';

/** Why the in-page camera could not start, in words a member can act on. The upload choice always stays available. */
export function cameraProblem(error: unknown): string {
    const name = error instanceof DOMException ? error.name : '';
    if (name === 'NotAllowedError' || name === 'SecurityError' || name === 'PermissionDeniedError') {
        return 'Camera permission was not given. Allow the camera for this site in your browser, or choose a photo from this device instead.';
    }
    if (name === 'NotFoundError' || name === 'DevicesNotFoundError' || name === 'OverconstrainedError') {
        return 'No camera was found on this device. Choose a photo from this device instead.';
    }
    if (name === 'NotReadableError' || name === 'TrackStartError' || name === 'AbortError') {
        return 'The camera is busy in another app. Close it there, or choose a photo from this device instead.';
    }
    return 'The camera could not be started. Choose a photo from this device instead.';
}

/** Whether this page can ask for the camera at all (HTTPS or localhost, and a browser that supports it). */
export function canUseCamera(): boolean {
    return typeof window !== 'undefined' && window.isSecureContext === true
        && typeof navigator !== 'undefined' && typeof navigator.mediaDevices?.getUserMedia === 'function';
}

interface Props {
    onCapture: (file: File) => void;
    onCancel: () => void;
    /** The camera cannot be used: the parent shows the reason and the upload choice. */
    onUnavailable: (reason: string) => void;
}

/**
 * A live camera preview with one Capture button, for computers and tablets that have a camera. Phones use
 * the system camera instead (an <input capture>), which handles permission, focus and rotation natively.
 * The stream is always stopped when this closes.
 */
export function CameraCapture({ onCapture, onCancel, onUnavailable }: Props) {
    const video = useRef<HTMLVideoElement>(null);
    const stream = useRef<MediaStream | null>(null);
    const [ready, setReady] = useState(false);
    const [capturing, setCapturing] = useState(false);
    const unavailable = useRef(onUnavailable);
    unavailable.current = onUnavailable;

    const stop = useCallback(() => {
        stream.current?.getTracks().forEach((track) => track.stop());
        stream.current = null;
    }, []);

    useEffect(() => {
        let cancelled = false;
        navigator.mediaDevices.getUserMedia({
            audio: false,
            video: { facingMode: { ideal: 'environment' }, width: { ideal: 3840 }, height: { ideal: 2160 } },
        }).then((opened) => {
            if (cancelled) { opened.getTracks().forEach((track) => track.stop()); return; }
            stream.current = opened;
            const element = video.current;
            if (!element) return;
            element.srcObject = opened;
            void element.play().then(() => { if (!cancelled) setReady(true); }).catch((error) => { stop(); unavailable.current(cameraProblem(error)); });
        }).catch((error) => { if (!cancelled) unavailable.current(cameraProblem(error)); });
        return () => { cancelled = true; stop(); };
    }, [stop]);

    const capture = () => {
        const element = video.current;
        if (!element || !ready || element.videoWidth < 1) return;
        setCapturing(true);
        const canvas = document.createElement('canvas');
        canvas.width = element.videoWidth;
        canvas.height = element.videoHeight;
        canvas.getContext('2d')?.drawImage(element, 0, 0);
        canvas.toBlob((blob) => {
            canvas.width = 0;
            canvas.height = 0;
            setCapturing(false);
            if (!blob) { onUnavailable('The photo could not be taken. Choose a photo from this device instead.'); return; }
            stop();
            onCapture(new File([blob], 'camera-photo.jpg', { type: 'image/jpeg' }));
        }, 'image/jpeg', 0.95);
    };

    return (
        <div className="nc-camera" data-testid="narcotics-camera">
            <div className="nc-camera-frame">
                <video ref={video} playsInline muted aria-label="Camera preview" />
                {!ready && <p className="nc-camera-wait" role="status">Starting the camera…</p>}
            </div>
            <div className="nc-actions">
                <Button appearance="primary" size="large" icon={<Camera size={20} />} onClick={capture} disabled={!ready || capturing}>Capture photo</Button>
                <Button size="large" icon={<X size={18} />} onClick={() => { stop(); onCancel(); }}>Cancel</Button>
            </div>
        </div>
    );
}
