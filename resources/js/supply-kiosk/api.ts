// Kiosk API client: EMS envelope, CSRF header, one retry after a 419, plain-words errors.
import type { Bootstrap, WithdrawalBody, WithdrawalResult } from './types.ts';

export const API_BASE = '/supply-kiosk/api';
const TIMEOUT_MS = 15_000;
/** Gateway errors seen while the Hub restarts for a release. A withdrawal that meets one waits in the queue. */
const TRANSIENT = new Set([408, 429, 502, 503, 504, 520, 521, 522, 523, 524, 530]);

let csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
let bootstrapListener: ((data: Bootstrap) => void) | null = null;

export class KioskError extends Error {
    readonly code: string;
    readonly status: number;
    readonly fields: Record<string, string[]>;
    constructor(code: string, message: string, status: number, fields: Record<string, string[]> = {}) {
        super(message);
        this.code = code;
        this.status = status;
        this.fields = fields;
    }
}

type Envelope<T> =
    | { ok: true; data: T; meta?: Record<string, unknown> }
    | { ok: false; error: { code: string; message: string; fields?: Record<string, string[]> } };

/** Called with every bootstrap the client fetches, including the one a 419 retry triggers. */
export function onBootstrap(listener: (data: Bootstrap) => void): void {
    bootstrapListener = listener;
}

async function send(method: 'GET' | 'POST', path: string, body?: unknown): Promise<Response> {
    return sendTo(`${API_BASE}${path}`, method, body);
}

async function sendTo(url: string, method: 'GET' | 'POST', body?: unknown): Promise<Response> {
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), TIMEOUT_MS);
    try {
        return await fetch(url, {
            method,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
                ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
            },
            body: body === undefined ? undefined : JSON.stringify(body),
            signal: controller.signal,
        });
    } catch {
        throw new KioskError('network', 'No connection to the Hub.', 0);
    } finally {
        window.clearTimeout(timer);
    }
}

async function read<T>(response: Response): Promise<{ data: T; meta: Record<string, unknown> }> {
    let payload: Envelope<T> | null = null;
    try {
        payload = (await response.json()) as Envelope<T>;
    } catch {
        payload = null;
    }
    if (response.status === 401) {
        throw new KioskError('kiosk_not_paired', payload && payload.ok === false ? payload.error.message : 'This screen is not paired.', 401);
    }
    if (payload && payload.ok === true && response.ok) return { data: payload.data, meta: payload.meta ?? {} };
    if (payload && payload.ok === false && payload.error) {
        throw new KioskError(payload.error.code || `http_${response.status}`, payload.error.message || '', response.status, payload.error.fields ?? {});
    }
    throw new KioskError(TRANSIENT.has(response.status) ? 'network' : `http_${response.status}`, 'The Hub did not answer as expected.', response.status);
}

async function call<T>(method: 'GET' | 'POST', path: string, body?: unknown): Promise<{ data: T; meta: Record<string, unknown> }> {
    let response = await send(method, path, body);
    if (response.status === 419) {
        // The CSRF token aged out: fetch bootstrap (it carries a fresh token) and try once more.
        await fetchBootstrap();
        response = await send(method, path, body);
    }
    return read<T>(response);
}

export async function fetchBootstrap(): Promise<Bootstrap> {
    const { data } = await read<Bootstrap>(await send('GET', '/bootstrap'));
    if (data.csrf_token) csrf = data.csrf_token;
    bootstrapListener?.(data);
    return data;
}

export async function postWithdrawal(body: WithdrawalBody): Promise<{ result: WithdrawalResult; replayed: boolean }> {
    const { data, meta } = await call<WithdrawalResult>('POST', '/withdrawals', body);
    return { result: data, replayed: meta.replayed === true };
}

export async function postUndo(uuid: string): Promise<void> {
    await call<unknown>('POST', `/withdrawals/${encodeURIComponent(uuid)}/undo`, {});
}

export async function postHeartbeat(): Promise<void> {
    await call<unknown>('POST', '/heartbeat', {});
}

export const PAIR_CODE_URL = '/supply-kiosk/pair-code';
const PAGE_URL = '/supply-kiosk';

/**
 * A fresh CSRF token while the screen is unpaired (bootstrap answers 401 then): read it from a fresh copy of the
 * kiosk page, and fall back to this page's own meta tag.
 */
async function refreshCsrfFromPage(): Promise<void> {
    try {
        const response = await fetch(PAGE_URL, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' } });
        const html = await response.text();
        const token = new DOMParser().parseFromString(html, 'text/html').querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        if (token) {
            csrf = token;
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.setAttribute('content', token);
            return;
        }
    } catch (error) {
        console.warn('Supply kiosk: could not reload the page for a fresh token.', error);
    }
    csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? csrf;
}

export const TOO_MANY_TRIES = 'Too many tries. Wait a minute and try again.';

/** Pair this browser with a one-time code from Admin. The server sets the device cookie on success. */
export async function pairWithCode(code: string): Promise<{ name: string }> {
    let response = await sendTo(PAIR_CODE_URL, 'POST', { code });
    if (response.status === 419) {
        await refreshCsrfFromPage();
        response = await sendTo(PAIR_CODE_URL, 'POST', { code });
    }
    if (response.status === 429) throw new KioskError('too_many_tries', TOO_MANY_TRIES, 429);
    const { data } = await read<{ device?: { name?: string } }>(response);
    return { name: data?.device?.name ?? '' };
}

/** True when the request never got a real answer, so it is safe to keep it and send it again later. */
export function isRetryable(error: unknown): boolean {
    return error instanceof KioskError && (error.code === 'network' || TRANSIENT.has(error.status) || error.status >= 500);
}

export const NOT_PAIRED_MESSAGE = 'An admin makes a pairing code from Admin > Medical Supply Room > Supply room screen > Pair supply room screen.';

/** Plain words for the member. Never a code on its own, never a stack trace. */
export function plainMessage(error: unknown): string {
    if (!(error instanceof KioskError)) return 'Something went wrong. Try again, or tell Captain 5.';
    switch (error.code) {
        case 'employee_not_found':
            return 'We do not have that employee ID. Check the number, or tell Captain 5.';
        case 'kiosk_not_paired':
            return `This screen is not paired. ${NOT_PAIRED_MESSAGE}`;
        case 'network':
            return 'No connection right now. Your items are saved on this screen and will be sent when the connection is back.';
        case 'idempotency_mismatch':
            return 'That order was already sent with different items. Tap Take again to send this one.';
        default: {
            const fieldText = Object.values(error.fields).flat().join(' ');
            if (error.status >= 400 && error.status < 500 && error.message) return error.message;
            return fieldText || 'Something went wrong. Try again, or tell Captain 5.';
        }
    }
}
