import type {
  Advisories, EmsMeta, Movement, PageMeta, ReconcilePreview, RequestDetail, RequestMessage, RequestSummary, SnapshotDetail,
  SnapshotSummary, StatisticsReport, StorageLocation, SupplyItem, SupplyOrder,
} from '../components/captain5/ems/types';
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

async function call<T>(path: string, init: RequestInit = {}, idempotencyKey?: string): Promise<EmsResult<T>> {
  const method = (init.method ?? 'GET').toUpperCase();
  if (method !== 'GET' && typeof navigator !== 'undefined' && navigator.onLine === false) throw new EmsApiError(OFFLINE_MESSAGE, 0);
  let response: Response;
  try {
    response = await fetchWithTimeout(`${BASE}${path}`, {
      credentials: 'same-origin',
      cache: 'no-store',
      ...init,
      headers: method === 'GET' ? { Accept: 'application/json' } : { ...mutationHeaders(), ...(idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {}) },
    });
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

const send = (method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', body?: unknown): RequestInit => ({ method, body: body === undefined ? '{}' : JSON.stringify(body) });

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

export async function fetchRequests(query: string): Promise<{ requests: RequestSummary[]; meta: PageMeta }> {
  const result = await call<RequestSummary[]>(`/requests?${query}`);
  const meta = result.meta as Partial<PageMeta>;
  return {
    requests: Array.isArray(result.data) ? result.data : [],
    meta: { page: meta.page ?? 1, per_page: meta.per_page ?? 25, total: meta.total ?? 0, last_page: meta.last_page ?? 1 },
  };
}

export async function fetchRequestTotal(query: string): Promise<number> {
  const result = await call<RequestSummary[]>(`/requests?${query}`);
  return Number((result.meta as Partial<PageMeta>).total ?? 0);
}

const normalizeDetail = (raw: RequestDetail): RequestDetail => ({
  ...raw,
  lines: Array.isArray(raw.lines) ? raw.lines : [],
  history: Array.isArray(raw.history) ? raw.history.map((event) => ({ ...event, payload: asRecord(event.payload) })) : [],
  messages: Array.isArray(raw.messages) ? raw.messages : [],
});

export const fetchRequest = async (uuid: string): Promise<RequestDetail> => normalizeDetail((await call<RequestDetail>(`/requests/${encodeURIComponent(uuid)}`)).data);

export type RequestAction = 'review' | 'prepare' | 'issue' | 'not-issued' | 'close' | 'reopen' | 'correct';

export async function postRequestAction(uuid: string, action: RequestAction, body: Record<string, unknown>, key: string): Promise<RequestDetail> {
  return normalizeDetail((await call<RequestDetail>(`/requests/${encodeURIComponent(uuid)}/${action}`, send('POST', body), key)).data);
}

export async function postRequestMessage(uuid: string, body: string, key: string): Promise<RequestMessage> {
  return (await call<RequestMessage>(`/requests/${encodeURIComponent(uuid)}/messages`, send('POST', { body }), key)).data;
}

export const fetchStatistics = async (query: string): Promise<StatisticsReport> => (await call<StatisticsReport>(`/statistics?${query}`)).data;

// -------------------------------------------------------------------- supply room

export async function fetchSupplyItems(params = ''): Promise<{ items: SupplyItem[]; meta: PageMeta }> {
  const result = await call<SupplyItem[]>(`/supply/items?${params}`);
  const meta = result.meta as Partial<PageMeta>;
  return { items: Array.isArray(result.data) ? result.data : [], meta: { page: meta.page ?? 1, per_page: meta.per_page ?? 100, total: meta.total ?? 0, last_page: meta.last_page ?? 1 } };
}

/** Every active item, following the pages (the supply room is a few hundred rows). Bounded so a bad answer cannot loop. */
export async function fetchAllSupplyItems(): Promise<SupplyItem[]> {
  const items: SupplyItem[] = [];
  for (let page = 1; page <= 10; page += 1) {
    const result = await fetchSupplyItems(`per_page=200&page=${page}`);
    items.push(...result.items);
    if (page >= result.meta.last_page) break;
  }
  return items;
}

export const fetchSupplyItem = async (id: number): Promise<SupplyItem> => (await call<SupplyItem>(`/supply/items/${id}`)).data;
export const fetchLocations = async (): Promise<StorageLocation[]> => (await call<StorageLocation[]>('/supply/locations')).data ?? [];
export const fetchCounts = async (): Promise<SnapshotSummary[]> => (await call<SnapshotSummary[]>('/supply/counts')).data ?? [];
export const fetchSnapshots = async (): Promise<SnapshotSummary[]> => (await call<SnapshotSummary[]>('/supply/snapshots')).data ?? [];
export const fetchSnapshot = async (id: string): Promise<SnapshotDetail> => (await call<SnapshotDetail>(`/supply/snapshots/${encodeURIComponent(id)}`)).data;
export const fetchReconciliation = async (): Promise<ReconcilePreview> => (await call<ReconcilePreview>('/supply/reconciliation')).data;
export const fetchAdvisories = async (): Promise<Advisories> => (await call<Advisories>('/supply/advisories')).data;
export const fetchOrders = async (): Promise<SupplyOrder[]> => (await call<SupplyOrder[]>('/supply/orders')).data ?? [];

export async function fetchMovements(page: number, perPage = 25): Promise<{ movements: Movement[]; meta: PageMeta }> {
  const result = await call<Movement[]>(`/supply/movements?page=${page}&per_page=${perPage}`);
  const meta = result.meta as Partial<PageMeta>;
  return { movements: Array.isArray(result.data) ? result.data : [], meta: { page: meta.page ?? 1, per_page: meta.per_page ?? perPage, total: meta.total ?? 0, last_page: meta.last_page ?? 1 } };
}

export const startCount = async (locationCode: string | null, key: string): Promise<SnapshotDetail> => (
  (await call<SnapshotDetail>('/supply/counts', send('POST', { location_code: locationCode }), key)).data
);

export const saveCountLines = async (id: string, lines: unknown[], key: string): Promise<SnapshotDetail> => (
  (await call<SnapshotDetail>(`/supply/counts/${encodeURIComponent(id)}/lines`, send('PUT', { lines }), key)).data
);

export const finalizeCount = async (id: string, key: string): Promise<SnapshotDetail> => (
  (await call<SnapshotDetail>(`/supply/counts/${encodeURIComponent(id)}/finalize`, send('POST'), key)).data
);

export async function discardCount(id: string, key: string): Promise<void> {
  await call(`/supply/counts/${encodeURIComponent(id)}`, send('DELETE'), key);
}

export const confirmReconciliation = async (expectedLastMovementId: number, key: string): Promise<SnapshotDetail> => (
  (await call<SnapshotDetail>('/supply/reconciliation', send('POST', { expected_last_movement_id: expectedLastMovementId }), key)).data
);

export interface PlaceOrderInput { supply_item_id: number; packages: number; supplier_code?: string; item_number?: string; notes?: string }

export const placeOrder = async (input: PlaceOrderInput, key: string): Promise<SupplyOrder> => (await call<SupplyOrder>('/supply/orders', send('POST', input), key)).data;
export const receiveOrder = async (id: number, quantityBase: number | null, key: string): Promise<SupplyOrder> => (
  (await call<SupplyOrder>(`/supply/orders/${id}/receive`, send('POST', quantityBase === null ? {} : { quantity_base: quantityBase }), key)).data
);
export const cancelOrder = async (id: number, key: string): Promise<SupplyOrder> => (await call<SupplyOrder>(`/supply/orders/${id}/cancel`, send('POST'), key)).data;

export const updateSupplyItem = async (id: number, changes: Record<string, unknown>, key: string): Promise<SupplyItem> => (
  (await call<SupplyItem>(`/supply/items/${id}`, send('PATCH', changes), key)).data
);
