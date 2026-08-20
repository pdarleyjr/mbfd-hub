import { MediaDeviceFailure, Room } from 'livekit-client';
import { mediaFailureMessage, type MediaFailureKind } from './media-policy';

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
    const failure: MediaFailureKind = (() => {
        switch (MediaDeviceFailure.getFailure(error)) {
        case MediaDeviceFailure.PermissionDenied:
            return 'permission_denied';
        case MediaDeviceFailure.NotFound:
            return 'not_found';
        case MediaDeviceFailure.DeviceInUse:
            return 'device_in_use';
        default:
            return 'unknown';
        }
    })();

    return mediaFailureMessage(failure, error instanceof Error ? error.message : undefined);
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
