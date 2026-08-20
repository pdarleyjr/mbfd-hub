import { MediaDeviceFailure, Room } from 'livekit-client';

export interface MediaDevices {
    cameras: MediaDeviceInfo[];
    microphones: MediaDeviceInfo[];
    speakers: MediaDeviceInfo[];
}

export async function enumerateMediaDevices(requestPermissions = false): Promise<MediaDevices> {
    const devices = await Room.getLocalDevices(undefined, requestPermissions);

    return {
        cameras: devices.filter((device) => device.kind === 'videoinput'),
        microphones: devices.filter((device) => device.kind === 'audioinput'),
        speakers: devices.filter((device) => device.kind === 'audiooutput'),
    };
}

export function mediaErrorMessage(error: unknown): string {
    switch (MediaDeviceFailure.getFailure(error)) {
        case MediaDeviceFailure.PermissionDenied:
            return 'Camera or microphone permission was denied. Allow access in browser settings, then try again.';
        case MediaDeviceFailure.NotFound:
            return 'No matching camera or microphone was found. Reconnect the USB device and try again.';
        case MediaDeviceFailure.DeviceInUse:
            return 'A camera or microphone is already being used by another application.';
        default:
            return error instanceof Error ? error.message : 'The camera or microphone could not be started.';
    }
}

export function isMediaPermissionDenied(error: unknown): boolean {
    return MediaDeviceFailure.getFailure(error) === MediaDeviceFailure.PermissionDenied;
}

export function deviceLabel(device: MediaDeviceInfo, index: number, fallback: string): string {
    return device.label || `${fallback} ${index + 1}`;
}

export async function testSpeakerOutput(deviceId: string): Promise<void> {
    const context = new AudioContext();
    const destination = context.createMediaStreamDestination();
    const oscillator = context.createOscillator();
    const gain = context.createGain();
    const audio = new Audio();

    try {
        await context.resume();
        gain.gain.value = 0.08;
        oscillator.frequency.value = 660;
        oscillator.connect(gain).connect(destination);
        audio.srcObject = destination.stream;
        if (deviceId && 'setSinkId' in audio) {
            await audio.setSinkId(deviceId);
        }
        await audio.play();
        oscillator.start();
        oscillator.stop(context.currentTime + 0.35);
        await new Promise((resolve) => window.setTimeout(resolve, 450));
    } finally {
        audio.pause();
        audio.srcObject = null;
        destination.stream.getTracks().forEach((track) => track.stop());
        await context.close();
    }
}
