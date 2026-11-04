import type { StationRequestSummary } from '../types';
import { fetchWithTimeout, mutationHeaders } from './api';

/**
 * A member marks an open station request resolved (docs/unified-activity-and-ownership.md section 3).
 *
 * Network only: this is never queued for later. `status` 0 means the request got no answer (offline, dropped or
 * timed out), so the outcome is unknown; the caller keeps what the member typed and retries with the SAME client
 * action id, and the server then returns the first result instead of recording it twice.
 */

const PATH = (requestId: number) => `/api/public/station-requests/${requestId}/member-resolve`;

export type ResolveOutcome = 'fixed' | 'no_longer_needed' | 'received' | 'other';

export const RESOLVE_OUTCOMES: ReadonlyArray<{ value: ResolveOutcome; label: string }> = [
  { value: 'fixed', label: 'Fixed' },
  { value: 'no_longer_needed', label: 'No longer needed' },
  { value: 'received', label: 'Received' },
  { value: 'other', label: 'Other' },
];

export const RESOLVE_NOTE_MAX = 1000;
/** Shortest note a member who did not submit the request must give. Mirrors the server. */
export const RESOLVE_OTHER_NOTE_MIN = 5;

export const OFFLINE_RESOLVE_MESSAGE = "You're offline. Try again when connected.";
export const UNREACHABLE_RESOLVE_MESSAGE = 'The server could not be reached. Nothing was changed. Try again.';

/** What the server says this member may do with one request. Absent on payloads that predate it. */
export interface MemberResolution {
  eligible: boolean;
  note_required: boolean;
}

/** The list and by-id payloads carry the field; the shared summary type does not declare it. */
export function memberResolutionOf(request: StationRequestSummary): MemberResolution | null {
  const raw = (request as StationRequestSummary & { member_resolution?: Partial<MemberResolution> | null }).member_resolution;
  if (raw === null || raw === undefined || typeof raw !== 'object') return null;

  return { eligible: raw.eligible === true, note_required: raw.note_required === true };
}

/** True when the member may mark this request resolved and the request is still open. */
export const canMarkResolved = (request: StationRequestSummary): boolean => request.is_open && memberResolutionOf(request)?.eligible === true;

const FALLBACKS: Record<number, string> = {
  401: 'Your session has ended. Sign in again.',
  403: 'Your account cannot mark requests resolved.',
  404: 'That request is no longer available.',
  409: 'This request changed while you were working. Refresh and look again.',
  419: 'Your session expired. Reload the page and try again.',
  422: 'Some of what you entered could not be accepted. Check it and try again.',
  429: 'Too many requests. Wait a moment and try again.',
};

export class StationRequestResolveError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code: string | null = null,
    /** 422: field => first message. */
    readonly fieldErrors: Record<string, string> = {},
    /** 409 REQUEST_CLOSED: where the request stands now, for example "Completed". */
    readonly currentStatusLabel: string | null = null,
  ) {
    super(message);
    this.name = 'StationRequestResolveError';
  }

  /** No answer: the outcome is unknown and the member's text must stay. */
  get isNetwork(): boolean { return this.status === 0; }
  get isClosed(): boolean { return this.status === 409 && this.code === 'REQUEST_CLOSED'; }
  get isSessionEnded(): boolean { return this.status === 401; }
}

export interface ResolveInput {
  /** Generated once when the form opens, reused for every retry of the same action. */
  clientActionId: string;
  outcome: ResolveOutcome;
  note?: string;
}

export interface ResolveResult {
  statusLabel: string;
  message: string;
}

/** Version 4 UUID; crypto.randomUUID needs a secure context, so fall back to getRandomValues. */
export function newResolveActionId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  const bytes = new Uint8Array(16);
  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') crypto.getRandomValues(bytes);
  else bytes.forEach((_, index) => { bytes[index] = Math.floor(Math.random() * 256); });
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

const asRecord = (value: unknown): Record<string, unknown> => (value !== null && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {});

function firstFieldErrors(payload: Record<string, unknown>): Record<string, string> {
  const result: Record<string, string> = {};
  for (const [field, value] of Object.entries(asRecord(payload.errors))) {
    const first = Array.isArray(value) ? value[0] : value;
    if (typeof first === 'string') result[field] = first;
  }

  return result;
}

function failureFor(status: number, payload: Record<string, unknown>): StationRequestResolveError {
  const fields = status === 422 ? firstFieldErrors(payload) : {};
  const serverMessage = typeof payload.message === 'string' && payload.message.trim() !== '' ? payload.message : null;
  const message = serverMessage ?? Object.values(fields)[0] ?? FALLBACKS[status] ?? (status >= 500 ? 'The server had a problem. Try again in a moment.' : 'The request could not be completed.');
  const code = typeof payload.code === 'string' && payload.code !== '' ? payload.code : null;
  const label = typeof payload.status_label === 'string' && payload.status_label !== '' ? payload.status_label : null;

  return new StationRequestResolveError(message, status, code, fields, label);
}

const isOffline = (): boolean => typeof navigator !== 'undefined' && navigator.onLine === false;

export async function resolveStationRequest(requestId: number, input: ResolveInput): Promise<ResolveResult> {
  if (isOffline()) throw new StationRequestResolveError(OFFLINE_RESOLVE_MESSAGE, 0);
  const note = input.note?.trim() ?? '';
  let response: Response;
  try {
    response = await fetchWithTimeout(PATH(requestId), {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: mutationHeaders(),
      body: JSON.stringify({ client_action_id: input.clientActionId, outcome: input.outcome, ...(note !== '' ? { note } : {}) }),
    });
  } catch {
    // A timeout, an abort and a dropped connection all leave the outcome unknown.
    throw new StationRequestResolveError(isOffline() ? OFFLINE_RESOLVE_MESSAGE : UNREACHABLE_RESOLVE_MESSAGE, 0);
  }
  const payload = asRecord(await response.json().catch(() => null));
  if (!response.ok) throw failureFor(response.status, payload);

  const data = asRecord(payload.data);

  return {
    statusLabel: typeof data.status_label === 'string' ? data.status_label : 'Completed',
    message: typeof data.message === 'string' && data.message.trim() !== '' ? data.message : 'Marked resolved. Thank you.',
  };
}
