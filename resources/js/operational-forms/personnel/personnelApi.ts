import { fileNameFromDisposition, firstErrors, urlFor } from './personnelLogic.js';
import type {
    AccidentDetail, AccidentFormDefinition, AccidentSummary, EmployeeOption, MemoFields, MemoFormDefinition, PersonnelEndpoints, ReportType,
} from './personnelTypes';

/** A refused or failed call. `network` is true when the request never reached the server. */
export class PersonnelApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly fields: Record<string, string> = {},
        public readonly network = false,
        /** The current report, sent with a 409 revision conflict. */
        public readonly current: AccidentDetail | null = null,
    ) {
        super(message);
        this.name = 'PersonnelApiError';
    }
}

const SIGN_IN_MESSAGE = 'Your sign-in has ended. Sign in again to continue. Your entries are kept on this device.';

function statusMessage(status: number): string {
    if (status === 401 || status === 419) return SIGN_IN_MESSAGE;
    if (status === 403) return 'You do not have access to do that.';
    if (status === 404) return 'That report could not be found, or it is not shared with you.';
    if (status === 413) return 'That is too large to send.';
    if (status === 429) return 'That was sent too many times in a row. Wait a minute and try again.';
    if (status >= 500) return 'The server had a problem. Try again in a moment.';
    return `The request could not be completed (${status}).`;
}

interface ErrorBody { message?: string; error?: string; errors?: Record<string, string[]>; data?: AccidentDetail }

export interface SignedPayload { revision: number; signature: string; attest: true }

export function createPersonnelApi(endpoints: PersonnelEndpoints, csrfToken: string) {
    const reportUrl = (template: string, id: string) => urlFor(template, endpoints.placeholder, id);

    async function send(url: string, init: RequestInit = {}): Promise<Response> {
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
            throw new PersonnelApiError('You are offline or the connection dropped.', 0, {}, true);
        }
        if (!response.ok) {
            const body = (await response.json().catch(() => null)) as ErrorBody | null;
            const message = response.status === 401 || response.status === 419
                ? SIGN_IN_MESSAGE
                : (response.status >= 500 && response.status !== 502 ? statusMessage(response.status) : (body?.message || statusMessage(response.status)));
            throw new PersonnelApiError(message, response.status, firstErrors(body?.errors), false, body?.data ?? null);
        }
        return response;
    }

    async function json<T>(url: string, init: RequestInit = {}): Promise<T> {
        const response = await send(url, init);
        if (response.status === 204) return undefined as T;
        const body = (await response.json().catch(() => null)) as { data?: T } | null;
        if (!body || body.data === undefined) throw new PersonnelApiError('The server answer could not be read.', response.status);
        return body.data;
    }

    const post = <T>(url: string, body: unknown, keepalive = false) => json<T>(url, { method: 'POST', body: JSON.stringify(body), keepalive });

    async function pdf(memo: MemoFields, download: boolean): Promise<{ blob: Blob; fileName: string }> {
        const response = await send(endpoints.memorandum_pdf, {
            method: 'POST',
            body: JSON.stringify({ ...memo, download: download ? 1 : 0 }),
            headers: { Accept: 'application/pdf, application/json' },
        });
        const blob = await response.blob();
        return { blob: blob.type === 'application/pdf' ? blob : new Blob([blob], { type: 'application/pdf' }), fileName: fileNameFromDisposition(response.headers.get('Content-Disposition'), 'MBFD Memorandum.pdf') };
    }

    return {
        form: () => json<AccidentFormDefinition>(endpoints.accidents_form),
        list: () => json<AccidentSummary[]>(endpoints.accidents),
        /** The server returns the draft already started with this key (double tap, retry) instead of a second one. */
        create: (type: ReportType, clientKey: string, subjectId: number | null) => post<AccidentDetail>(endpoints.accidents, {
            report_type: type, client_key: clientKey, ...(subjectId ? { subject_employee_id: subjectId } : {}),
        }),
        show: (id: string) => json<AccidentDetail>(reportUrl(endpoints.accident, id)),
        save: (id: string, body: Record<string, unknown>, keepalive = false) => json<AccidentDetail>(reportUrl(endpoints.accident, id), {
            method: 'PATCH', body: JSON.stringify(body), keepalive,
        }),
        remove: (id: string) => json<void>(reportUrl(endpoints.accident, id), { method: 'DELETE' }),
        /** Idempotent on the server: a repeated submit returns the report unchanged and raises no second alert. */
        submit: (id: string, payload: SignedPayload) => post<AccidentDetail>(reportUrl(endpoints.accident_submit, id), payload),
        completeSupervisor: (id: string, payload: SignedPayload) => post<AccidentDetail>(reportUrl(endpoints.accident_supervisor, id), payload),
        previewUrl: (id: string) => reportUrl(endpoints.accident_preview, id),

        searchEmployees: async (query: string): Promise<EmployeeOption[]> => {
            const response = await send(`${endpoints.employee_search}?q=${encodeURIComponent(query)}`);
            const body = (await response.json().catch(() => null)) as { results?: EmployeeOption[] } | null;
            return Array.isArray(body?.results) ? body.results : [];
        },

        memoForm: () => json<MemoFormDefinition>(endpoints.memorandum_form),
        memoPdf: pdf,
        /** Retry-safe: the same client key returns the same conversation and never sends a second one. */
        memoToSupportServices: (memo: MemoFields, clientKey: string) => post<{ delivered: boolean; thread_url: string; message: string }>(
            endpoints.memorandum_support_services, { from: memo.from, date: memo.date, subject: memo.subject, body: memo.body, client_key: clientKey },
        ),
        memoEmailCopy: (memo: MemoFields) => post<{ sent: boolean; message: string }>(endpoints.memorandum_email, memo),
    };
}

export type PersonnelApi = ReturnType<typeof createPersonnelApi>;
