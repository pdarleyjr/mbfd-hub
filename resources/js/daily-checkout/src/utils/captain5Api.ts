import type { ActingApi, ActingRequest, ActingResult, ActingState, OnDuty } from '../components/acting/types';
import type {
  Captain5Summary, Handoff, InspectionView, IssueActionRequest, IssueDetail, IssueSummary, Workspace,
} from '../components/captain5/types';
import { ApiRequestError, fetchWithTimeout, mutationHeaders } from './api';
import { parseVehicleIdentification } from './vehicleIdentification';

const BASE = '/api/captain5';

/**
 * A failed Captain 5 call. `status` 0 means the request never got an answer (offline, dropped or timed
 * out): the outcome is unknown, so the caller keeps the member's text and retries with the SAME key.
 */
export class Captain5ApiError extends ApiRequestError {
  constructor(
    message: string,
    status: number,
    /** 409: the issue as it is now on the server. */
    readonly issue?: IssueDetail,
    /** 422: field => message. */
    readonly fieldErrors: Record<string, string> = {},
  ) {
    super(message, status);
    this.name = 'Captain5ApiError';
  }

  get isNetwork(): boolean { return this.status === 0; }
  get isConflict(): boolean { return this.status === 409; }
}

export const OFFLINE_MESSAGE = "You're offline. Nothing was sent; your text is kept.";
export const NETWORK_MESSAGE = 'The server could not be reached. Nothing was confirmed; your text is kept. Try again.';

const asRecord = (value: unknown): Record<string, unknown> => (value && typeof value === 'object' ? value as Record<string, unknown> : {});
const asArray = <T>(value: unknown): T[] => (Array.isArray(value) ? value as T[] : []);

function fieldErrors(payload: Record<string, unknown>): Record<string, string> {
  const result: Record<string, string> = {};
  for (const [field, value] of Object.entries(asRecord(payload.errors))) {
    const first = Array.isArray(value) ? value[0] : value;
    if (typeof first === 'string') result[field] = first;
  }
  return result;
}

const FALLBACKS: Record<number, string> = {
  401: 'Your session has ended. Sign in again.',
  403: 'Your account does not have access to this part of Captain 5.',
  404: 'That item is no longer available.',
  419: 'Your session expired. Reload the page and try again.',
  429: 'Too many requests. Wait a moment and try again.',
};

export type Requester = <T>(path: string, init?: RequestInit) => Promise<T>;

/**
 * Binds the fetch, CSRF and error handling to one API base. The 300 shift API (command300ShiftApi) uses the
 * same handling with its own 403 wording, so both areas fail the same way. `status` 0 always means "no answer".
 */
export function createRequester(base: string, forbiddenMessage: string = FALLBACKS[403]): Requester {
  const fallbacks: Record<number, string> = { ...FALLBACKS, 403: forbiddenMessage };
  return async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
    const method = (init.method ?? 'GET').toUpperCase();
    if (method !== 'GET' && typeof navigator !== 'undefined' && navigator.onLine === false) {
      throw new Captain5ApiError(OFFLINE_MESSAGE, 0);
    }
    let response: Response;
    try {
      response = await fetchWithTimeout(`${base}${path}`, {
        credentials: 'same-origin',
        cache: 'no-store',
        ...init,
        headers: method === 'GET' ? { Accept: 'application/json' } : mutationHeaders(),
      });
    } catch {
      // A timeout, an abort and a dropped connection all leave the outcome unknown.
      throw new Captain5ApiError(typeof navigator !== 'undefined' && navigator.onLine === false ? OFFLINE_MESSAGE : NETWORK_MESSAGE, 0);
    }
    const payload = asRecord(await response.json().catch(() => null));
    if (response.ok) return payload as T;
    const message = typeof payload.message === 'string' && payload.message !== ''
      ? payload.message
      : fallbacks[response.status] ?? (response.status >= 500 ? 'The server had a problem. Try again in a moment.' : 'The request could not be completed.');
    throw new Captain5ApiError(
      message,
      response.status,
      response.status === 409 ? normalizeDetail(payload.issue) : undefined,
      response.status === 422 ? fieldErrors(payload) : {},
    );
  };
}

const request = createRequester(BASE);

const json = (body: unknown): RequestInit => ({ method: 'POST', body: JSON.stringify(body) });

export function normalizeSummary(raw: unknown): IssueSummary {
  const item = asRecord(raw) as unknown as IssueSummary;
  return { ...item, details: asRecord(item.details) as IssueSummary['details'] };
}

export function normalizeDetail(raw: unknown): IssueDetail {
  const item = asRecord(raw) as unknown as IssueDetail;
  return {
    ...normalizeSummary(raw),
    resolution_notes: item.resolution_notes ?? null,
    observations: asArray(item.observations),
    events: asArray<IssueDetail['events'][number]>(item.events).map((event) => ({ ...event, details: asRecord(event.details) })),
    messages: asArray(item.messages),
    can_post: item.can_post === true,
    can_post_internal: item.can_post_internal === true,
    actions: asArray(item.actions),
    origin_inspection_id: item.origin_inspection_id ?? null,
  };
}

const asIso = (value: unknown): string | null => (typeof value === 'string' && value !== '' ? value : null);

function normalizeActingRequest(raw: unknown): ActingRequest {
  const item = asRecord(raw);
  return {
    id: typeof item.id === 'number' ? item.id : 0,
    ...(typeof item.role === 'string' ? { role: item.role } : {}),
    ...(typeof item.role_label === 'string' ? { role_label: item.role_label } : {}),
    kind: item.kind === 'end' ? 'end' : 'act',
    requested_by: typeof item.requested_by === 'string' ? item.requested_by : 'A member',
    name: typeof item.name === 'string' && item.name !== '' ? item.name : null,
    rank: typeof item.rank === 'string' && item.rank !== '' ? item.rank : null,
    duration: item.duration === '12h' || item.duration === '24h' || item.duration === 'custom' ? item.duration : null,
    shift: item.shift === 'A' || item.shift === 'B' || item.shift === 'C' ? item.shift : null,
    ends_at: asIso(item.ends_at),
    requested_at: asIso(item.requested_at),
    own: item.own === true,
  };
}

/** Never trust the shape: a missing block becomes "nothing pending" so the page cannot throw. */
export function normalizeActing(raw: unknown): ActingState {
  const acting = asRecord(raw);
  const choices = asRecord(acting.choices);
  return {
    ...(typeof acting.role === 'string' ? { role: acting.role } : {}),
    // Older answers omit it; only an explicit false hides the "Click here" control.
    can_request: acting.can_request !== false,
    can_approve: acting.can_approve === true,
    mine: acting.mine ? normalizeActingRequest(acting.mine) : null,
    pending: asArray(acting.pending).map(normalizeActingRequest),
    choices: { '12h': asIso(choices['12h']), '24h': asIso(choices['24h']) },
  };
}

export const normalizeOnDuty = (raw: unknown): OnDuty => {
  const duty = asRecord(raw) as unknown as OnDuty;
  return { ...duty, acting_until: asIso(duty.acting_until), acting_request_id: typeof duty.acting_request_id === 'number' ? duty.acting_request_id : null, as_of: asIso(duty.as_of) };
};

function normalizeWorkspace(raw: Record<string, unknown>): Workspace {
  const data = raw as unknown as Workspace;
  const vehicleIdentification = parseVehicleIdentification(raw.vehicle_identification);
  return {
    ...data,
    // The one-time vehicle questions are rebuilt field by field; an absent or empty block means none are open.
    apparatus_id: typeof raw.apparatus_id === 'number' && Number.isInteger(raw.apparatus_id) && raw.apparatus_id > 0 ? raw.apparatus_id : undefined,
    vehicle_identification: vehicleIdentification,
    on_duty: normalizeOnDuty(data.on_duty),
    rescue_dailies: asArray(data.rescue_dailies),
    issues: asArray(data.issues).map(normalizeSummary),
    handoff: { duty_date: data.handoff?.duty_date ?? data.duty_date, notes: asArray(data.handoff?.notes) },
    acting: normalizeActing(raw.acting),
  };
}

/** The station-list card appears only for a 200 with `access.view`; anything else hides it. */
export async function fetchCaptain5Summary(): Promise<Captain5Summary> {
  const summary = await request<Captain5Summary>('/summary');
  if (summary.access?.view !== true) throw new Captain5ApiError(FALLBACKS[403], 403);
  return { ...summary, on_duty: normalizeOnDuty(summary.on_duty) };
}

export const fetchWorkspace = async (): Promise<Workspace> => normalizeWorkspace(await request('/workspace'));

export async function fetchIssues(status: 'open' | 'closed', query = ''): Promise<IssueSummary[]> {
  const params = new URLSearchParams({ status });
  if (query.trim() !== '') params.set('q', query.trim());
  const payload = await request<{ issues?: unknown }>(`/issues?${params.toString()}`);
  return asArray(payload.issues).map(normalizeSummary);
}

export async function fetchIssue(id: number): Promise<IssueDetail> {
  const payload = await request<{ issue?: unknown }>(`/issues/${id}`);
  return normalizeDetail(payload.issue);
}

export async function performIssueAction(id: number, input: IssueActionRequest): Promise<IssueDetail> {
  const { fields, ...core } = input;
  const payload = await request<{ issue?: unknown }>(`/issues/${id}/actions`, json({ ...core, ...fields }));
  return normalizeDetail(payload.issue);
}

export async function postIssueMessage(id: number, body: string, visibility: 'member' | 'internal', clientMessageId: string): Promise<IssueDetail> {
  const payload = await request<{ issue?: unknown }>(`/issues/${id}/messages`, json({ body, visibility, client_message_id: clientMessageId }));
  return normalizeDetail(payload.issue);
}

export async function fetchInspection(id: number): Promise<InspectionView> {
  const payload = await request<{ inspection?: InspectionView }>(`/inspections/${id}`);
  const inspection = asRecord(payload.inspection) as unknown as InspectionView;
  return { ...inspection, compartments: asArray<InspectionView['compartments'][number]>(inspection.compartments).map((c) => ({ ...c, items: asArray(c.items) })) };
}

export async function postClarification(inspectionId: number, body: string, clientMessageId: string): Promise<void> {
  await request(`/inspections/${inspectionId}/clarification`, json({ body, client_message_id: clientMessageId }));
}

const handoffOf = (payload: { handoff?: Handoff }): Handoff => ({ duty_date: payload.handoff?.duty_date ?? '', notes: asArray(payload.handoff?.notes) });

export async function writeHandoff(body: string, clientRequestId: string): Promise<Handoff> {
  return handoffOf(await request('/handoff', json({ body, client_request_id: clientRequestId })));
}

export async function acknowledgeHandoff(noteId: number): Promise<Handoff> {
  return handoffOf(await request(`/handoff/${noteId}/acknowledge`, { method: 'POST', body: '{}' }));
}

export const actingResult = (payload: { on_duty?: unknown; acting?: unknown }): ActingResult => ({
  on_duty: normalizeOnDuty(payload.on_duty),
  acting: normalizeActing(payload.acting),
});

export type { ActingRequestInput } from '../components/acting/types';

/**
 * The four acting calls against one API base. `kind: 'act'` is "Acting as ... today?" (also a takeover) and
 * `kind: 'end'` is "End the current acting ...". The Captain 5 workspace and the 300 page each bind their own.
 */
export function buildActingApi(call: Requester): ActingApi {
  return {
    requestActing: async (input) => actingResult(await call('/acting-requests', json(input))),
    approveActing: async (id, note = '') => actingResult(await call(`/acting-requests/${id}/approve`, json(note.trim() === '' ? {} : { note: note.trim() }))),
    rejectActing: async (id, note) => actingResult(await call(`/acting-requests/${id}/reject`, json({ note: note.trim() }))),
    withdrawActing: async (id) => actingResult(await call(`/acting-requests/${id}/withdraw`, { method: 'POST', body: '{}' })),
  };
}

export const captain5ActingApi: ActingApi = buildActingApi(request);
export const { requestActing, approveActing, rejectActing, withdrawActing } = captain5ActingApi;

/** The first message a 422 carries for a field, else the response message. */
export function firstFieldError(error: Captain5ApiError): string {
  return Object.values(error.fieldErrors)[0] ?? error.message;
}

/**
 * What to tell the member when a request that is not a text draft (acting requests, decisions) fails.
 * Offline and unreachable are different: offline is certain, unreachable is not.
 */
export function requestFailureMessage(error: unknown, fallback: string): string {
  if (!(error instanceof Captain5ApiError)) return error instanceof Error && error.message ? error.message : fallback;
  if (error.status === 0) {
    return typeof navigator !== 'undefined' && navigator.onLine === false
      ? "You're offline. Nothing was sent. Reconnect, then try again."
      : 'The server could not be reached, so nothing is confirmed. Try again.';
  }
  return error.status === 422 ? firstFieldError(error) : error.message;
}

export const isCaptain5Forbidden = (error: unknown): boolean => error instanceof Captain5ApiError && error.status === 403;

/** Unread count for the Messages tab badge; the feed itself is served by the shared communications panel. */
export async function fetchMessagesUnread(): Promise<number> {
  const payload = await request<{ unread_count?: unknown }>('/communications/summary');
  return typeof payload.unread_count === 'number' && Number.isFinite(payload.unread_count) ? payload.unread_count : 0;
}

/** The outcome is unknown or the server was busy: the same request may be sent again unchanged. */
export const isRetryable = (error: unknown): boolean => error instanceof Captain5ApiError
  && (error.status === 0 || error.status === 429 || error.status >= 500);
