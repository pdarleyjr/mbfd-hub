import type {
    EmsEndpoints, EmsFormDefinition, EmsMessage, EmsRequestDetail, EmsRequestSummary, EmsSubmitPayload,
} from './emsTypes';
import { newSubmissionId } from './emsLogic.js';

/** A refused or failed call. `network` is true when the request never reached the server. */
export class EmsApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly code: string | null = null,
        public readonly fields: Record<string, string[]> = {},
        public readonly network = false,
    ) {
        super(message);
        this.name = 'EmsApiError';
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

function statusMessage(status: number): string {
    if (status === 401 || status === 419) return SIGN_IN_MESSAGE;
    if (status === 403) return 'You do not have access to do that.';
    if (status === 404) return 'That could not be found.';
    if (status === 429) return 'That was sent too many times in a row. Wait a minute and try again.';
    if (status >= 500) return 'The server had a problem. Try again in a moment.';
    return `The request could not be completed (${status}).`;
}

export function createEmsApi(endpoints: EmsEndpoints, csrfToken: string) {
    const urlFor = (template: string, id: string) => template.split(endpoints.placeholder).join(id);

    async function call<T>(url: string, init: RequestInit = {}): Promise<{ data: T; meta: NonNullable<Envelope<T>['meta']>; status: number }> {
        let response: Response;
        try {
            response = await fetch(url, {
                credentials: 'same-origin',
                ...init,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                    ...init.headers,
                },
            });
        } catch {
            throw new EmsApiError('You are offline or the connection dropped.', 0, null, {}, true);
        }

        const body = (await response.json().catch(() => null)) as Envelope<T> | null;
        if (!response.ok || !body || body.ok === false) {
            const error = body?.error;
            const friendly = response.status === 401 || response.status === 419
                ? SIGN_IN_MESSAGE
                : (error?.message || (response.status >= 500 ? statusMessage(response.status) : body?.message) || statusMessage(response.status));
            throw new EmsApiError(friendly, response.status, error?.code ?? null, (error?.fields as Record<string, string[]>) ?? {});
        }

        return { data: body.data as T, meta: body.meta ?? {}, status: response.status };
    }

    const post = (url: string, body: unknown, idempotencyKey?: string) => call<unknown>(url, {
        method: 'POST',
        body: JSON.stringify(body),
        headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : undefined,
    });

    return {
        form: async () => (await call<EmsFormDefinition>(endpoints.form)).data,

        list: async (page = 1) => {
            const result = await call<EmsRequestSummary[]>(`${endpoints.requests}?per_page=25&page=${page}`);
            return { requests: result.data, lastPage: result.meta.last_page ?? 1, total: result.meta.total ?? result.data.length };
        },

        show: async (id: string) => (await call<EmsRequestDetail>(urlFor(endpoints.request, id))).data,

        /** The server dedupes on client_submission_id, so a retry or a double tap returns the same request. */
        submit: async (payload: EmsSubmitPayload) => {
            const result = await call<EmsRequestDetail>(endpoints.requests, { method: 'POST', body: JSON.stringify(payload) });
            return { request: result.data, replayed: result.meta.replayed === true };
        },

        message: async (id: string, body: string, idempotencyKey: string = newSubmissionId()) => (
            (await post(urlFor(endpoints.messages, id), { body }, idempotencyKey)).data as EmsMessage & { request_id: string }
        ),

        cancel: async (id: string, reason: string, idempotencyKey: string = newSubmissionId()) => (
            (await post(urlFor(endpoints.cancel, id), reason.trim() === '' ? {} : { reason: reason.trim() }, idempotencyKey)).data as EmsRequestDetail
        ),
    };
}

export type EmsApi = ReturnType<typeof createEmsApi>;
