import { fetchWithTimeout, mutationHeaders } from './api';

/**
 * Member actions on equipment issues (docs/unified-activity-and-ownership.md sections 3 and 4):
 *  - report that a vehicle issue was fixed (JSON), and
 *  - report a stretcher problem on a unit (multipart, optional photo).
 *
 * Same session, CSRF and timeout handling as the other member writes. `status` 0 means the request got no answer
 * (offline, dropped or timed out): the outcome is unknown, so the caller keeps what the member typed and retries
 * with the SAME client id; the server then returns the first result instead of recording it twice.
 */

const BASE = '/api/public';

export type CorrectionOutcome = 'found' | 'replaced' | 'repaired' | 'corrected' | 'no_longer_deficient';
export type StretcherProblem = 'loading' | 'armrest' | 'battery' | 'charger' | 'restraint' | 'mechanical' | 'other';

export const NOTE_MAX = 1000;
export const STRETCHER_TEXT_MIN = 3;
export const STRETCHER_TEXT_MAX = 1000;
export const STRETCHER_PHOTO_MAX_BYTES = 10 * 1024 * 1024;

export const OFFLINE_ACTION_MESSAGE = "You're offline. Nothing was sent. What you typed is kept; try again when you have a connection.";
export const UNREACHABLE_ACTION_MESSAGE = 'The server could not be reached. Nothing was confirmed. What you typed is kept; try again.';

const FALLBACKS: Record<number, string> = {
  401: 'Your session has ended. Sign in again.',
  403: 'Your account cannot do this.',
  404: 'That item is no longer available.',
  409: 'This changed while you were working. Refresh and look again.',
  419: 'Your session expired. Reload the page and try again.',
  422: 'Some of what you entered could not be accepted. Check it and try again.',
  429: 'Too many requests. Wait a moment and try again.',
};

/** A failed member action. `code` is the server's machine code (for example ISSUE_CLOSED), when it sent one. */
export class MemberIssueActionError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code: string | null = null,
    /** 422: field => first message. */
    readonly fieldErrors: Record<string, string> = {},
  ) {
    super(message);
    this.name = 'MemberIssueActionError';
  }

  /** No answer: the outcome is unknown and the member's text must stay. */
  get isNetwork(): boolean { return this.status === 0; }
  get isIssueClosed(): boolean { return this.status === 409 && this.code === 'ISSUE_CLOSED'; }
  get isForbidden(): boolean { return this.status === 403; }
}

/** Version 4 UUID; crypto.randomUUID needs a secure context, so fall back to getRandomValues. */
export function newClientId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  const bytes = new Uint8Array(16);
  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') crypto.getRandomValues(bytes);
  else bytes.forEach((_, index) => { bytes[index] = Math.floor(Math.random() * 256); });
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');

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

function failureFor(status: number, payload: Record<string, unknown>): MemberIssueActionError {
  const fields = status === 422 ? firstFieldErrors(payload) : {};
  const serverMessage = typeof payload.message === 'string' && payload.message.trim() !== '' ? payload.message : null;
  const message = serverMessage ?? Object.values(fields)[0] ?? FALLBACKS[status] ?? (status >= 500 ? 'The server had a problem. Try again in a moment.' : 'The request could not be completed.');
  const code = typeof payload.code === 'string' && payload.code !== '' ? payload.code : null;

  return new MemberIssueActionError(message, status, code, fields);
}

async function send(path: string, init: RequestInit): Promise<Record<string, unknown>> {
  if (typeof navigator !== 'undefined' && navigator.onLine === false) throw new MemberIssueActionError(OFFLINE_ACTION_MESSAGE, 0);
  let response: Response;
  try {
    response = await fetchWithTimeout(`${BASE}${path}`, { credentials: 'same-origin', cache: 'no-store', method: 'POST', ...init });
  } catch {
    // A timeout, an abort and a dropped connection all leave the outcome unknown.
    throw new MemberIssueActionError(typeof navigator !== 'undefined' && navigator.onLine === false ? OFFLINE_ACTION_MESSAGE : UNREACHABLE_ACTION_MESSAGE, 0);
  }
  const payload = asRecord(await response.json().catch(() => null));
  // The service worker answers a dropped POST with 503 { offline: true }: no answer from the server, outcome unknown.
  if (payload.offline === true) throw new MemberIssueActionError(UNREACHABLE_ACTION_MESSAGE, 0);
  if (!response.ok) throw failureFor(response.status, payload);

  return payload;
}

export interface CorrectionRequest {
  clientActionId: string;
  outcome: CorrectionOutcome;
  note?: string;
}

export interface CorrectionResult {
  result: 'resolved' | 'awaiting_verification';
  statusLabel: string;
  message: string;
}

export async function submitMemberCorrection(defectId: number, input: CorrectionRequest): Promise<CorrectionResult> {
  const note = input.note?.trim() ?? '';
  const payload = await send(`/apparatus-issues/${defectId}/member-correction`, {
    headers: mutationHeaders(),
    body: JSON.stringify({ client_action_id: input.clientActionId, outcome: input.outcome, ...(note !== '' ? { note } : {}) }),
  });
  const data = asRecord(payload.data);
  const result = data.result === 'resolved' ? 'resolved' : 'awaiting_verification';
  const statusLabel = typeof data.status_label === 'string' ? data.status_label : '';
  const message = typeof data.message === 'string' && data.message.trim() !== ''
    ? data.message
    : result === 'resolved' ? 'Thanks. This issue is resolved.' : 'Thanks. Your report was recorded and is waiting to be verified.';

  return { result, statusLabel, message };
}

export interface StretcherIssueRequest {
  clientSubmissionId: string;
  problem: StretcherProblem;
  description: string;
  unusable: boolean;
  photo?: File | null;
}

export interface StretcherIssueResult {
  issueId: number | null;
  attached: boolean;
  message: string;
}

/** The multipart body, exported so tests can read exactly what is sent. */
export function buildStretcherForm(input: StretcherIssueRequest): FormData {
  const form = new FormData();
  form.append('client_submission_id', input.clientSubmissionId);
  form.append('problem', input.problem);
  form.append('description', input.description.trim());
  form.append('unusable', input.unusable ? '1' : '0');
  if (input.photo) form.append('photo', input.photo, input.photo.name);

  return form;
}

export async function submitStretcherIssue(apparatusId: number, input: StretcherIssueRequest): Promise<StretcherIssueResult> {
  // No Content-Type here: the browser adds the multipart boundary.
  const { 'Content-Type': _contentType, ...headers } = mutationHeaders();
  const payload = await send(`/apparatuses/${apparatusId}/stretcher-issues`, { headers, body: buildStretcherForm(input) });
  const data = asRecord(payload.data);

  return {
    issueId: Number.isInteger(data.issue_id) ? data.issue_id as number : null,
    attached: data.attached === true,
    message: typeof data.message === 'string' && data.message.trim() !== '' ? data.message : 'Your stretcher report was sent to Captain 5.',
  };
}
