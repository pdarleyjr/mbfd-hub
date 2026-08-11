export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly payload: Record<string, unknown>,
    ) {
        super(message);
    }
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
        throw new ApiError(message, response.status, body);
    }

    return body as T;
}
