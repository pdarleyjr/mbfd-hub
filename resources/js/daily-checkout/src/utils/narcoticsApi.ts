import type { NarcoticsCheck, NarcoticsCheckDetail, NarcoticsListMeta, NarcoticsMeta } from '../components/captain5/narcotics/types';
import { fetchWithTimeout, mutationHeaders } from './api';
import { EmsApiError, NETWORK_MESSAGE, OFFLINE_MESSAGE } from './emsApi';

/**
 * Captain 5 side of the narcotics check (routes/narcotics.php). Same envelope, error class and retry
 * rules as the EMS API (a failed call is an EmsApiError, so the shared load/write helpers and their
 * sign-in handling apply unchanged). The /api/ prefix keeps the service worker from ever storing a reply.
 */
const BASE = '/api/narcotics-checks';

export class NarcoticsApiError extends EmsApiError {
  constructor(message: string, status: number, code?: string, fields: Record<string, string> = {}) {
    super(message, status, code, fields);
    this.name = 'NarcoticsApiError';
  }
}

const FALLBACKS: Record<number, string> = {
  401: 'Your session has ended. Sign in again.',
  403: 'You do not have access to narcotics checks.',
  404: 'That narcotics check could not be found.',
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

async function call<T>(path: string, init: RequestInit = {}): Promise<{ data: T; meta: Record<string, unknown> }> {
  const method = (init.method ?? 'GET').toUpperCase();
  if (method !== 'GET' && typeof navigator !== 'undefined' && navigator.onLine === false) throw new NarcoticsApiError(OFFLINE_MESSAGE, 0);
  let response: Response;
  try {
    response = await fetchWithTimeout(`${BASE}${path}`, {
      credentials: 'same-origin',
      cache: 'no-store',
      ...init,
      headers: method === 'GET' ? { Accept: 'application/json' } : mutationHeaders(),
    });
  } catch {
    throw new NarcoticsApiError(typeof navigator !== 'undefined' && navigator.onLine === false ? OFFLINE_MESSAGE : NETWORK_MESSAGE, 0);
  }
  const payload = asRecord(await response.json().catch(() => null));
  if (response.ok && payload.ok !== false) return { data: payload.data as T, meta: asRecord(payload.meta) };
  const error = asRecord(payload.error);
  const message = typeof error.message === 'string' && error.message !== ''
    ? error.message
    : FALLBACKS[response.status] ?? (response.status >= 500 ? 'The server had a problem and nothing was saved. Try again in a moment.' : 'That could not be completed.');
  throw new NarcoticsApiError(message, response.status, typeof error.code === 'string' ? error.code : undefined, firstFieldErrors(error.fields));
}

const post = (body?: unknown): RequestInit => ({ method: 'POST', body: JSON.stringify(body ?? {}) });

/** The Captain 5 page asks this to decide whether the tab exists; a person without access gets `review: false`, not an error. */
export const fetchNarcoticsMeta = async (): Promise<NarcoticsMeta> => (await call<NarcoticsMeta>('/staff/meta')).data;

export async function fetchChecks(query: string): Promise<{ checks: NarcoticsCheck[]; meta: NarcoticsListMeta }> {
  const result = await call<NarcoticsCheck[]>(`/staff/checks?${query}`);
  const meta = result.meta as Partial<NarcoticsListMeta>;
  return {
    checks: Array.isArray(result.data) ? result.data : [],
    meta: { page: meta.page ?? 1, per_page: meta.per_page ?? 25, total: meta.total ?? 0, last_page: meta.last_page ?? 1, pending_count: meta.pending_count ?? 0 },
  };
}

export const fetchCheck = async (uuid: string): Promise<NarcoticsCheckDetail> => (await call<NarcoticsCheckDetail>(`/staff/checks/${encodeURIComponent(uuid)}`)).data;

/** One action: the check leaves the inbox and is kept in the archive. A second Captain 5 acting at once gets the first decision back. */
export const approveCheck = async (uuid: string): Promise<{ check: NarcoticsCheckDetail; changed: boolean }> => {
  const result = await call<NarcoticsCheckDetail>(`/staff/checks/${encodeURIComponent(uuid)}/approve`, post());
  return { check: result.data, changed: result.meta.changed !== false };
};

export const rejectCheck = async (uuid: string, explanation: string): Promise<{ check: NarcoticsCheckDetail; changed: boolean }> => {
  const result = await call<NarcoticsCheckDetail>(`/staff/checks/${encodeURIComponent(uuid)}/reject`, post(explanation.trim() === '' ? {} : { explanation: explanation.trim() }));
  return { check: result.data, changed: result.meta.changed !== false };
};
