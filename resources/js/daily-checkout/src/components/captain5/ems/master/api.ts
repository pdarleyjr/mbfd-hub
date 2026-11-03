import { READ_TIMEOUT_MS } from '../../../../utils/api';
import { call as emsCall, send } from '../../../../utils/emsApi';
import { DEFAULT_UNITS, normalizeItem, summarize } from './orderLogic';
import type {
  ArrivedInput, BatchChange, CountReviewData, EntryWrite, ItemChanges, MasterData, MasterItem, NewItemInput, OrderInput, OrderUpdate,
  SaveEntriesResult, StartResult, SubmitResult, SupplyScreenData, UnitOption,
} from './types';

/**
 * Typed calls for the Master Supply Room Inventory (contract section 6). They ride the shared `call`/`send` in
 * utils/emsApi.ts: same envelope, same EmsApiError and same Idempotency-Key header, so `failureMessage`, `isRetryable`
 * and `useWrite` work on these calls unchanged. Autosave (PUT) uses the shorter read timeout so a dead connection is noticed fast.
 * Every item that comes back passes through `normalizeItem`, so a row missing the order fields still renders.
 */
async function call<T>(path: string, method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE', body?: unknown, key?: string): Promise<T> {
  const result = await emsCall<T>(path, method === 'GET' ? {} : send(method, body), key, method === 'PUT' ? READ_TIMEOUT_MS : undefined);
  return result.data;
}

const id = (value: string | number): string => encodeURIComponent(String(value));

const itemCall = async (path: string, method: 'POST' | 'PATCH', body: unknown, key: string): Promise<MasterItem> => normalizeItem(await call<MasterItem>(path, method, body, key));

const validUnits = (units: unknown): UnitOption[] => (
  Array.isArray(units) && units.length > 0 ? units.filter((unit): unit is UnitOption => typeof unit?.value === 'string' && typeof unit?.label === 'string') : [...DEFAULT_UNITS]
);

// ---------------------------------------------------------------- master list

export async function fetchMaster(): Promise<MasterData> {
  const data = await call<Partial<MasterData>>('/supply/master', 'GET');
  const items = Array.isArray(data.items) ? data.items.map(normalizeItem) : [];
  return {
    sections: Array.isArray(data.sections) ? data.sections : [],
    items,
    inactive: Array.isArray(data.inactive) ? data.inactive : [],
    draft: data.draft ?? null,
    last_count: data.last_count ?? null,
    can_edit: data.can_edit === true,
    summary: data.summary ?? summarize(items),
    units: validUnits(data.units),
  };
}

/** The supply room screen section: recent kiosk withdrawals, paired screens and search words waiting for review. */
export async function fetchSupplyScreen(): Promise<SupplyScreenData> {
  const data = await call<Partial<SupplyScreenData>>('/supply/screen', 'GET');
  const withdrawals = Array.isArray(data.withdrawals) ? data.withdrawals : [];
  return {
    withdrawals: withdrawals.map((entry) => ({ ...entry, lines: Array.isArray(entry.lines) ? entry.lines : [] })),
    screens: Array.isArray(data.screens) ? data.screens : [],
    search_words_waiting: Number(data.search_words_waiting ?? 0) || 0,
  };
}

export const createMasterItem =(input: NewItemInput, key: string): Promise<MasterItem> => itemCall('/supply/master/items', 'POST', input, key);

export const updateMasterItem = (itemId: number, changes: ItemChanges, key: string): Promise<MasterItem> => (
  itemCall(`/supply/master/items/${id(itemId)}`, 'PATCH', changes, key)
);

/** A physical count of one item, in its count unit. */
export const countMasterItem = (itemId: number, onHand: number, key: string): Promise<MasterItem> => (
  itemCall(`/supply/master/items/${id(itemId)}/count`, 'POST', { on_hand: onHand }, key)
);

/** The Edit mode save: every changed row in one all-or-nothing request. */
export async function saveMasterBatch(changes: readonly BatchChange[], key: string): Promise<MasterItem[]> {
  const data = await call<{ items?: MasterItem[] }>('/supply/master/items', 'PATCH', { changes }, key);
  return Array.isArray(data?.items) ? data.items.map(normalizeItem) : [];
}

// ---------------------------------------------------------------------- orders

export const placeOrder = (itemId: number, input: OrderInput, key: string): Promise<MasterItem> => (
  itemCall(`/supply/master/items/${id(itemId)}/order`, 'POST', input, key)
);

export const updateOrder = (orderId: number, changes: OrderUpdate, key: string): Promise<MasterItem> => (
  itemCall(`/supply/orders/${id(orderId)}`, 'PATCH', changes, key)
);

export const markArrived = (orderId: number, input: ArrivedInput, key: string): Promise<MasterItem> => (
  itemCall(`/supply/orders/${id(orderId)}/arrived`, 'POST', input, key)
);

// ------------------------------------------------------------------ count form

export async function startCountForm(key: string): Promise<StartResult> {
  const data = await call<StartResult>('/supply/count-form/start', 'POST', {}, key);
  return {
    ...data,
    count: { ...data.count, entries: data.count?.entries && typeof data.count.entries === 'object' ? data.count.entries : {} },
    sections: Array.isArray(data.sections) ? data.sections : [],
    items: Array.isArray(data.items) ? data.items.map(normalizeItem) : [],
  };
}

/** Autosave. Send a fresh key for every batch; the same numbers sent twice are harmless. */
export const saveCountEntries = (countId: string, entries: readonly EntryWrite[], key: string): Promise<SaveEntriesResult> => (
  call<SaveEntriesResult>(`/supply/count-form/${id(countId)}/entries`, 'PUT', { entries }, key)
);

export async function fetchCountReview(countId: string): Promise<CountReviewData> {
  const data = await call<Partial<CountReviewData>>(`/supply/count-form/${id(countId)}/review`, 'GET');
  return {
    counted: Number(data.counted ?? 0),
    uncounted: Array.isArray(data.uncounted) ? data.uncounted : [],
    orders: Array.isArray(data.orders) ? data.orders : [],
    config_problems: Array.isArray(data.config_problems) ? data.config_problems : [],
  };
}

/** `expectedUncounted` no longer blocks anything on screen; the server still uses it to spot a list that changed. */
export const submitCountForm = (countId: string, expectedUncounted: number, key: string): Promise<SubmitResult> => (
  call<SubmitResult>(`/supply/count-form/${id(countId)}/submit`, 'POST', { expected_uncounted: expectedUncounted }, key)
);

export async function discardCountForm(countId: string, key: string): Promise<void> {
  await call<unknown>(`/supply/counts/${id(countId)}`, 'DELETE', undefined, key);
}
