import type { OnDuty } from '../components/acting/types';
import { ACTIONS } from '../components/captain5/actionForms';
import type {
  Captain5Summary, Handoff, InspectionView, IssueActionName, IssueActionRequest, IssueDetail, IssueSummary, RescueTruck, RescueTruckChange, Workspace,
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

/** See EnsureCanonicalMemberApiSession: the session is checked as usual but its idle clock does not move. */
const BACKGROUND_REFRESH_HEADER = 'X-Hub-Background-Refresh';
let inBackgroundRefresh = false;

/**
 * Runs `start` so any request it begins synchronously is marked as a timer's, not the member's. An unattended
 * shared screen must still sign out. A member's own Refresh or action calls the same loaders directly.
 */
export function asBackgroundRefresh(start: () => void): void {
  inBackgroundRefresh = true;
  try {
    start();
  } finally {
    inBackgroundRefresh = false;
  }
}

/**
 * Binds the fetch, CSRF and error handling to one API base. The 300 shift API (command300ShiftApi) uses the
 * same handling with its own 403 wording, so both areas fail the same way. `status` 0 always means "no answer".
 */
export function createRequester(base: string, forbiddenMessage: string = FALLBACKS[403]): Requester {
  const fallbacks: Record<number, string> = { ...FALLBACKS, 403: forbiddenMessage };
  return async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
    const method = (init.method ?? 'GET').toUpperCase();
    const background: Record<string, string> = inBackgroundRefresh ? { [BACKGROUND_REFRESH_HEADER]: '1' } : {};
    if (method !== 'GET' && typeof navigator !== 'undefined' && navigator.onLine === false) {
      throw new Captain5ApiError(OFFLINE_MESSAGE, 0);
    }
    let response: Response;
    try {
      response = await fetchWithTimeout(`${base}${path}`, {
        credentials: 'same-origin',
        cache: 'no-store',
        ...init,
        headers: { ...(method === 'GET' ? { Accept: 'application/json' } : mutationHeaders()), ...background },
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
    // A name this build has no form for is left out rather than breaking the sheet.
    actions: [...asArray<IssueActionName>(item.actions), ...asArray<IssueActionName>(item.clearing_actions)].filter((action) => action in ACTIONS),
    origin_inspection_id: item.origin_inspection_id ?? null,
  };
}

const asIso = (value: unknown): string | null => (typeof value === 'string' && value !== '' ? value : null);

export const normalizeOnDuty = (raw: unknown): OnDuty => {
  const duty = asRecord(raw) as unknown as OnDuty;
  return { ...duty, as_of: asIso(duty.as_of) };
};

const asText = (value: unknown): string | null => (typeof value === 'string' && value.trim() !== '' ? value : null);

function normalizeTruck(raw: unknown): RescueTruck {
  const item = asRecord(raw);
  return {
    apparatus_id: typeof item.apparatus_id === 'number' ? item.apparatus_id : 0,
    unit: asText(item.unit) ?? 'Unit',
    vehicle_number: asText(item.vehicle_number),
    station: asText(item.station),
    status: asText(item.status) ?? 'Unknown',
    changed_at: asIso(item.changed_at),
    changed_recently: item.changed_recently === true,
  };
}

function normalizeTruckChange(raw: unknown, index: number): RescueTruckChange {
  const item = asRecord(raw);
  return {
    id: asText(item.id) ?? `change-${index}`,
    unit: asText(item.unit) ?? 'Unit',
    from_vehicle_number: asText(item.from_vehicle_number),
    to_vehicle_number: asText(item.to_vehicle_number),
    at: asIso(item.at),
    by: asText(item.by),
    recent: item.recent === true,
    note: asText(item.note),
    left_behind: asArray(item.left_behind).map((line) => {
      const entry = asRecord(line);
      return { name: asText(entry.name) ?? 'Item', note: asText(entry.note) };
    }),
  };
}

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
    // Absent from an answer sent before these existed: nothing cleared, and the Truck Changes tab shows its empty state.
    cleared_issues: asArray(raw.cleared_issues).map(normalizeSummary),
    rescue_trucks: asArray(raw.rescue_trucks).map(normalizeTruck),
    rescue_truck_changes: asArray(raw.rescue_truck_changes).map(normalizeTruckChange),
    handoff: { duty_date: data.handoff?.duty_date ?? data.duty_date, notes: asArray(data.handoff?.notes) },
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

/** The first message a 422 carries for a field, else the response message. */
export function firstFieldError(error: Captain5ApiError): string {
  return Object.values(error.fieldErrors)[0] ?? error.message;
}

/**
 * What to tell the member when a request that is not a text draft fails.
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
