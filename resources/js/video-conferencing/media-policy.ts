export type MediaFailureKind = 'permission_denied' | 'not_found' | 'device_in_use' | 'unknown';

export function mediaFailureMessage(kind: MediaFailureKind, fallback?: string): string {
    switch (kind) {
        case 'permission_denied':
            return 'Camera or microphone permission was denied. Allow access in browser settings, then try again.';
        case 'not_found':
            return 'No matching camera or microphone was found. Reconnect the USB device and try again.';
        case 'device_in_use':
            return 'A camera or microphone is already being used by another application.';
        default:
            return fallback || 'The camera or microphone could not be started.';
    }
}
