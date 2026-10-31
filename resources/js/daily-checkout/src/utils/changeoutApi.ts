import { ApiRequestError, fetchWithTimeout, mutationHeaders, parseRetryAfter } from './api';
import { parseAlerts, parseConflictDetails, parseContext, parseSubmitResult, parseSummary } from '../components/changeout/changeoutParse';
import type {
  ChangeoutConflictDetails,
  ChangeoutContext,
  ChangeoutSubmission,
  ChangeoutSubmitResult,
  ChangeoutSummary,
  OperationalAlert,
} from '../components/changeout/types';

const API_BASE = '/api/public';
const READ_HEADERS = { Accept: 'application/json' };
/** A changeout is a small JSON body. On poor signal the member gets "Not sent" sooner rather than a minute of waiting. */
const SUBMIT_TIMEOUT_MS = 30_000;

export const CHANGEOUT_STALE = 'CHANGEOUT_STALE';
export const CHANGEOUT_TRUCK_UNAVAILABLE = 'CHANGEOUT_TRUCK_UNAVAILABLE';
export const CHANGEOUT_ALREADY_PENDING = 'CHANGEOUT_ALREADY_PENDING';
export const CHANGEOUT_REPLAY_CONFLICT = 'CHANGEOUT_REPLAY_CONFLICT';
export const CHANGEOUT_CHECKLIST_CHANGED = 'CHANGEOUT_CHECKLIST_CHANGED';
export const CHANGEOUT_ALREADY_RECORDED = 'CHANGEOUT_ALREADY_RECORDED';

/** A 409 from the changeout service. Nothing was written. */
export class ChangeoutConflictError extends ApiRequestError {
  readonly details: ChangeoutConflictDetails;

  constructor(message: string, code: string | undefined, details: ChangeoutConflictDetails) {
    super(message, 409, code);
    this.name = 'ChangeoutConflictError';
    this.details = details;
  }
}

const refusal = async (response: Response, fallback: string): Promise<ApiRequestError> => {
  const payload = await response.json().catch(() => null) as { message?: unknown; code?: unknown } | null;
  const message = typeof payload?.message === 'string' && payload.message !== '' ? payload.message : fallback;
  const code = typeof payload?.code === 'string' ? payload.code : undefined;
  if (response.status === 409) return new ChangeoutConflictError(message, code, parseConflictDetails(payload));

  return new ApiRequestError(message, response.status, code, parseRetryAfter(response.headers.get('Retry-After')));
};

const read = async (path: string, fallback: string): Promise<unknown> => {
  const response = await fetchWithTimeout(`${API_BASE}/${path}`, { credentials: 'same-origin', headers: READ_HEADERS, cache: 'no-store' });
  if (!response.ok) throw await refusal(response, fallback);

  return response.json();
};

export const getStationOperationalAlerts = async (stationId: number): Promise<OperationalAlert[]> => (
  parseAlerts(await read(`stations/${stationId}/operational-alerts`, 'Unit alerts could not be loaded.'))
);

export const getChangeoutContext = async (apparatusId: number, alertId: number | null): Promise<ChangeoutContext> => {
  const query = alertId === null ? '' : `?alert_id=${alertId}`;

  return parseContext(await read(`apparatuses/${apparatusId}/changeout-context${query}`, 'This changeout could not be loaded.'));
};

/**
 * A response that cannot be read counts as no answer: the caller keeps the work and retries with the same
 * client_submission_id, and the server returns the first result.
 */
export const submitChangeout = async (apparatusId: number, submission: ChangeoutSubmission): Promise<ChangeoutSubmitResult> => {
  const response = await fetchWithTimeout(`${API_BASE}/apparatuses/${apparatusId}/changeouts`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: mutationHeaders(),
    body: JSON.stringify(submission),
  }, SUBMIT_TIMEOUT_MS);
  if (!response.ok) throw await refusal(response, 'The changeout was refused.');

  return parseSubmitResult(await response.json());
};

export const getChangeout = async (changeoutId: number): Promise<ChangeoutSummary> => (
  parseSummary(await read(`changeouts/${changeoutId}`, 'This changeout could not be loaded.'))
);

export const verifyChangeoutLine = async (changeoutId: number, lineId: number, note: string | null): Promise<void> => {
  const response = await fetchWithTimeout(`${API_BASE}/changeouts/${changeoutId}/lines/${lineId}/verify`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: mutationHeaders(),
    body: JSON.stringify({ note }),
  }, SUBMIT_TIMEOUT_MS);
  if (!response.ok) throw await refusal(response, 'The verification could not be saved.');
};
