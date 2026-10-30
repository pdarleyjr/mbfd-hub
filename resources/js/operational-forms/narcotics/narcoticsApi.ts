import { photoFileName, urlFor } from './narcoticsImage.js';
import type {
    NarcoticsCheckDetail, NarcoticsCheckSummary, NarcoticsEndpoints, NarcoticsFormDefinition, SendInput,
} from './narcoticsTypes';

/** A refused or failed call. `network` is true when no answer came back (the outcome is unknown). */
export class NarcoticsApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly code: string | null = null,
        public readonly fields: Record<string, string[]> = {},
        public readonly network = false,
    ) {
        super(message);
        this.name = 'NarcoticsApiError';
    }
}

interface Envelope<T> {
    ok: boolean;
    data?: T;
    meta?: { replayed?: boolean; total?: number; page?: number; per_page?: number; last_page?: number };
    error?: { code?: string; message?: string; fields?: Record<string, string[]> };
    message?: string;
}

const SIGN_IN_MESSAGE = 'Your sign-in has ended. Sign in again to continue.';
/** A photo upload on a weak connection: generous, but not endless. */
const UPLOAD_TIMEOUT_MS = 180_000;

function statusMessage(status: number): string {
    if (status === 401 || status === 419) return SIGN_IN_MESSAGE;
    if (status === 403) return 'The narcotics check is not available to your account.';
    if (status === 404) return 'That could not be found.';
    if (status === 413) return 'That photo is too large to send.';
    if (status === 429) return 'That was sent too many times in a row. Wait a minute and try again.';
    if (status >= 500) return 'The server had a problem. Try again in a moment.';
    return `The request could not be completed (${status}).`;
}

function toError(status: number, body: Envelope<unknown> | null): NarcoticsApiError {
    const error = body?.error;
    const message = status === 401 || status === 419
        ? SIGN_IN_MESSAGE
        : (error?.message || (status >= 500 ? statusMessage(status) : body?.message) || statusMessage(status));
    return new NarcoticsApiError(message, status, error?.code ?? null, (error?.fields as Record<string, string[]>) ?? {});
}

export function createNarcoticsApi(endpoints: NarcoticsEndpoints, csrfToken: string) {
    async function get<T>(url: string): Promise<{ data: T; meta: NonNullable<Envelope<T>['meta']> }> {
        let response: Response;
        try {
            response = await fetch(url, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken },
            });
        } catch {
            throw new NarcoticsApiError('You are offline or the connection dropped.', 0, null, {}, true);
        }
        const body = (await response.json().catch(() => null)) as Envelope<T> | null;
        if (!response.ok || !body || body.ok === false) throw toError(response.status, body);
        return { data: body.data as T, meta: body.meta ?? {} };
    }

    /**
     * The photo goes as multipart form data so the browser streams it and reports progress. The
     * server dedupes on client_submission_id: a retry or a double tap returns the first check.
     */
    function send(input: SendInput, onProgress: (percent: number) => void): Promise<{ check: NarcoticsCheckDetail; replayed: boolean }> {
        return new Promise((resolve, reject) => {
            const data = new FormData();
            data.append('photo', input.photo.blob, input.photo.processed ? photoFileName(input.photo.type) : input.photo.name);
            data.append('client_submission_id', input.clientSubmissionId);
            if (input.note.trim() !== '') data.append('note', input.note.trim());
            if (input.resubmissionOf) data.append('resubmission_of', input.resubmissionOf);

            const request = new XMLHttpRequest();
            request.open('POST', endpoints.store);
            request.withCredentials = true;
            request.timeout = UPLOAD_TIMEOUT_MS;
            request.setRequestHeader('Accept', 'application/json');
            request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            request.setRequestHeader('X-CSRF-TOKEN', csrfToken);
            // Hold at 99 until the server has answered: 100 means "saved", not "uploaded".
            request.upload.onprogress = (event) => {
                if (event.lengthComputable && event.total > 0) onProgress(Math.min(99, Math.round((event.loaded / event.total) * 100)));
            };
            request.onload = () => {
                let body: Envelope<NarcoticsCheckDetail> | null = null;
                try { body = JSON.parse(request.responseText || 'null') as Envelope<NarcoticsCheckDetail> | null; } catch { body = null; }
                if (request.status >= 200 && request.status < 300 && body?.ok === true && body.data) {
                    onProgress(100);
                    resolve({ check: body.data, replayed: body.meta?.replayed === true });
                    return;
                }
                reject(toError(request.status, body));
            };
            request.onerror = () => reject(new NarcoticsApiError('The connection dropped.', 0, null, {}, true));
            request.ontimeout = () => reject(new NarcoticsApiError('The connection was too slow to finish.', 0, null, {}, true));
            request.onabort = () => reject(new NarcoticsApiError('The upload was stopped.', 0, null, {}, true));
            request.send(data);
        });
    }

    return {
        form: async () => (await get<NarcoticsFormDefinition>(endpoints.form)).data,

        list: async (page = 1) => {
            const result = await get<NarcoticsCheckSummary[]>(`${endpoints.mine}?per_page=20&page=${page}`);
            return { checks: Array.isArray(result.data) ? result.data : [], lastPage: result.meta.last_page ?? 1, total: result.meta.total ?? result.data.length };
        },

        show: async (id: string) => (await get<NarcoticsCheckDetail>(urlFor(endpoints.check, endpoints.placeholder, id))).data,

        send,
    };
}

export type NarcoticsApi = ReturnType<typeof createNarcoticsApi>;
