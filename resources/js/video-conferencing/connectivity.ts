export type ConferenceConnectivityFailureCode =
    | 'conference_endpoint_misconfigured'
    | 'conference_endpoint_unavailable'
    | 'conference_network_unreachable';

export class ConferenceConnectivityError extends Error {
    public readonly code: ConferenceConnectivityFailureCode;

    constructor(code: ConferenceConnectivityFailureCode) {
        super(code);
        this.code = code;
        this.name = 'ConferenceConnectivityError';
    }
}

type FetchLike = (input: RequestInfo | URL, init?: RequestInit) => Promise<Response>;

function isPermittedProbeUrl(value: string): boolean {
    try {
        const url = new URL(value);
        const loopback = url.hostname === '127.0.0.1' || url.hostname === 'localhost' || url.hostname === '::1';

        return url.protocol === 'https:' || (url.protocol === 'http:' && loopback);
    } catch {
        return false;
    }
}

export async function verifyConferenceConnectivity(
    url: string,
    timeoutMs = 5000,
    request: FetchLike = fetch,
): Promise<void> {
    if (!isPermittedProbeUrl(url)) {
        throw new ConferenceConnectivityError('conference_endpoint_misconfigured');
    }

    const controller = new AbortController();
    const timeout = globalThis.setTimeout(() => controller.abort(), Math.max(1, timeoutMs));

    try {
        const response = await request(url, {
            cache: 'no-store',
            credentials: 'omit',
            method: 'GET',
            mode: 'cors',
            signal: controller.signal,
        });

        if (! response.ok) {
            throw new ConferenceConnectivityError('conference_endpoint_unavailable');
        }
    } catch (error) {
        if (error instanceof ConferenceConnectivityError) throw error;

        throw new ConferenceConnectivityError('conference_network_unreachable');
    } finally {
        globalThis.clearTimeout(timeout);
    }
}
