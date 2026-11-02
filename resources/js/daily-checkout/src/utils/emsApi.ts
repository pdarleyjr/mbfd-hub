import type { EmsMeta, PageMeta, RequestMessage, RequestSummary } from '../components/captain5/ems/types';
import { ApiRequestError, fetchWithTimeout, mutationHeaders } from './api';

const BASE = '/ems/api';

/**
 * A failed EMS call. `status` 0 means no answer came back (offline, dropped, timed out): the outcome is unknown, so the
 * caller keeps what the person typed and retries with the SAME idempotency key. `code` is the API's stable error code.
 */
export class EmsApiError extends ApiRequestError {
  constructor(message: string, status: number, code?: string, readonly fields: Record<string, string> = {}) {
    super(message, status, code);
    this.name = 'EmsApiError';
  }

  get isNetwork(): boolean { return this.status === 0; }
  get isConflict(): boolean { return this.status === 409; }
  get isStalePreview(): boolean { return this.status === 409 && this.code === 'stale_preview'; }
}

export const OFFLINE_MESSAGE = "You're offline. Nothing was sent; what you typed is kept.";
export const NETWORK_MESSAGE = 'The server could not be reached, so nothing is confirmed. What you typed is kept. Try again.';

const FALLBACKS: Record<number, string> = {
  401: 'Your session has ended. Sign in again.',
  403: 'Your account does not have access to do that.',
  404: 'That record could not be found.',
  419: 'Your session expired. Reload the page and try again.',
  429: 'Too many requests. Wait a moment and try again.',
};

const asRecord = (value: unknown): Record<string, unknown> => (value && typeof value === 'object' ? value as Record<string, unknown> : {});

function firstFieldErrors(fields: unknown): Record<string, string> {
  const result: Record<string, string> = {};
  for (const [name, value] of Object.entries(asRecord(fields))) {
    const first = Array.isArray(value) ? value[0] : value;
    if (typeof first === 'string') result[name] = first;
  }
  return result;
}

export interface EmsResult<T> { data: T; meta: Record<string, unknown> }

/**
 * The one request helper for every EMS staff call. `timeoutMs` overrides the default for the method (autosave uses the
 * shorter read timeout so a dead connection is noticed quickly and the same batch is retried).
 */
export async function call<T>(path: string, init: RequestInit = {}, idempotencyKey?: string, timeoutMs?: number): Promise<EmsResult<T>> {
  const method = (init.method ?? 'GET').toUpperCase();
  if (method !== 'GET' && typeof navigator !== 'undefined' && navigator.onLine === false) throw new EmsApiError(OFFLINE_MESSAGE, 0);
  let response: Response;
  try {
    response = await fetchWithTimeout(`${BASE}${path}`, {
      credentials: 'same-origin',
      cache: 'no-store',
      ...init,
      headers: method === 'GET' ? { Accept: 'application/json' } : { ...mutationHeaders(), ...(idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {}) },
    }, timeoutMs);
  } catch {
    throw new EmsApiError(typeof navigator !== 'undefined' && navigator.onLine === false ? OFFLINE_MESSAGE : NETWORK_MESSAGE, 0);
  }
  const payload = asRecord(await response.json().catch(() => null));
  if (response.ok && payload.ok !== false) return { data: payload.data as T, meta: asRecord(payload.meta) };
  const error = asRecord(payload.error);
  const message = typeof error.message === 'string' && error.message !== ''
    ? error.message
    : typeof payload.message === 'string' && payload.message !== ''
      ? payload.message
      : FALLBACKS[response.status] ?? (response.status >= 500 ? 'The server had a problem and nothing was saved. Try again in a moment.' : 'That could not be completed.');
  throw new EmsApiError(message, response.status, typeof error.code === 'string' ? error.code : undefined, firstFieldErrors(error.fields));
}

export const send = (method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', body?: unknown): RequestInit => ({ method, body: body === undefined ? '{}' : JSON.stringify(body) });

/** The outcome is unknown or the server was busy: the same request may be sent again unchanged. */
export const isRetryable = (error: unknown): boolean => error instanceof EmsApiError && (error.status === 0 || error.status === 429 || error.status >= 500);

/** What to tell the person: a 422 shows the first field message, everything else the API's own words. */
export function failureMessage(error: unknown, fallback = 'That could not be completed. Try again.'): string {
  if (error instanceof EmsApiError) return error.status === 422 ? (Object.values(error.fields)[0] ?? error.message) : error.message;
  return error instanceof Error && error.message ? error.message : fallback;
}

export const isForbidden = (error: unknown): boolean => error instanceof EmsApiError && error.status === 403;

// ------------------------------------------------------------------ meta + requests

export const fetchEmsMeta = async (): Promise<EmsMeta> => (await call<EmsMeta>('/meta')).data;

export async function fetchRequestTotal(query: string): Promise<number> {
  const result = await call<RequestSummary[]>(`/requests?${query}`);
  return Number((result.meta as Partial<PageMeta>).total ?? 0);
}

export async function postRequestMessage(uuid: string, body: string, key: string): Promise<RequestMessage> {
  return (await call<RequestMessage>(`/requests/${encodeURIComponent(uuid)}/messages`, send('POST', { body }), key)).data;
}
