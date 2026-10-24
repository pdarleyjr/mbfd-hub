export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly payload: Record<string, unknown>,
        /** Seconds from a `Retry-After` header (429 and 503), or null when absent or not a number. */
        public readonly retryAfterSeconds: number | null = null,
    ) {
        super(message);
    }
}

function retryAfterSeconds(response: Response): number | null {
    const seconds = Number.parseInt(response.headers.get('Retry-After') ?? '', 10);

    return Number.isFinite(seconds) && seconds >= 0 ? seconds : null;
}

export async function postJson<T>(url: string, csrfToken: string, payload: unknown): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(payload),
    });
    const body = response.status === 204 ? {} : await response.json().catch(() => ({}));

    if (!response.ok) {
        const message = typeof body.message === 'string' ? body.message : 'The request could not be completed.';
        throw new ApiError(message, response.status, body, retryAfterSeconds(response));
    }

    return body as T;
}

export async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    const body: unknown = await response.json().catch(() => null);
    if (!response.ok) {
        const failure = typeof body === 'object' && body !== null ? body as Record<string, unknown> : {};
        const message = typeof failure.message === 'string' ? failure.message : 'The request could not be completed.';
        throw new ApiError(message, response.status, failure, retryAfterSeconds(response));
    }
    // A 200 that is not a JSON object (captive portal, proxy error page) is not an answer.
    if (typeof body !== 'object' || body === null || Array.isArray(body)) {
        throw new ApiError('The Hub sent an unexpected response.', response.status, { code: 'invalid_response' });
    }

    return body as T;
}
