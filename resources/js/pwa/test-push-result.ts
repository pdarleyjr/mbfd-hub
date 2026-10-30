/**
 * Plain, truthful wording for the "Send a test" result (POST /api/push/test).
 *
 * The server can only know what the push service (Apple, Google, Microsoft, Mozilla) said. A push
 * service accepting the test does not prove the phone showed it, so nothing here ever says
 * "delivered" or "sent to your phone".
 */

export interface TestPushDevice {
    label?: string;
    accepted?: boolean;
    failure?: string | null;
    outcome?: string;
    service?: string | null;
    summary?: string;
}

export const TEST_MESSAGES = {
    rateLimited: 'Please wait a minute before sending another test.',
    needsSetup: 'This device needs to be set up again. Turn alerts off and on for this device.',
    failed: 'The test could not be sent. Turn alerts off and on again on this device.',
    offline: 'The test could not be sent. Check your connection and try again.',
} as const;

function serviceWords(service: string | null | undefined): string {
    return typeof service === 'string' && service !== '' ? `${service}'s push service` : 'the push service';
}

/** One sentence for one device; prefers the server's own summary, else derives it from the older keys. */
export function describeTestDevice(device: TestPushDevice): string {
    if (typeof device.summary === 'string' && device.summary.trim() !== '') return device.summary.trim();
    const outcome = device.outcome ?? (device.accepted ? 'accepted' : device.failure ? 'failed' : 'no_answer');
    switch (outcome) {
        case 'accepted':
            return `Accepted by ${serviceWords(device.service)}. Check this device: if nothing appeared, check its notification and Focus settings.`;
        case 'expired':
            return TEST_MESSAGES.needsSetup;
        case 'rejected':
            return `Rejected by ${serviceWords(device.service)}. Turn alerts off and on for this device.`;
        case 'retrying':
            return `Not accepted by ${serviceWords(device.service)} yet. The Hub will try again on its own.`;
        case 'failed':
            return `Not accepted by ${serviceWords(device.service)}. In-app alerts still arrive in the bell.`;
        default:
            return `No answer from ${serviceWords(device.service)} yet. Try again in a minute.`;
    }
}

/** The whole message for a test response. Several devices are listed by name. */
export function describeTestResult(status: number, body: unknown): string {
    if (status === 429) return TEST_MESSAGES.rateLimited;
    if (status === 400 || status === 404 || status === 410) return TEST_MESSAGES.needsSetup;
    if (status < 200 || status >= 300) return TEST_MESSAGES.failed;

    const devices = (typeof body === 'object' && body !== null && Array.isArray((body as { devices?: unknown }).devices)
        ? ((body as { devices: unknown[] }).devices.filter((d) => typeof d === 'object' && d !== null) as TestPushDevice[])
        : []);
    if (devices.length === 0) {
        return 'Test handed to the push service. Check this device to see whether it appeared.';
    }
    if (devices.length === 1) return describeTestDevice(devices[0]);

    return devices.map((device) => `${device.label?.trim() || 'Unknown device'}: ${describeTestDevice(device)}`).join(' ');
}
