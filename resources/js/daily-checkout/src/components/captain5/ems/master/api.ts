import { READ_TIMEOUT_MS } from '../../../../utils/api';
import { call as emsCall, send } from '../../../../utils/emsApi';
import type {
  CountReviewData, EntryWrite, ItemChanges, MasterData, MasterItem, NewItemInput, SaveEntriesResult, StartResult, SubmitResult,
} from './types';

/**
 * Typed calls for the Master Supply Room Inventory (contract section 6). They ride the shared `call`/`send` in
 * utils/emsApi.ts: same envelope, same EmsApiError and same Idempotency-Key header, so `failureMessage`, `isRetryable`
 * and `useWrite` work on these calls unchanged. Autosave (PUT) uses the shorter read timeout so a dead connection is noticed fast.
 */
async function call<T>(path: string, method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE', body?: unknown, key?: string): Promise<T> {
  const result = await emsCall<T>(path, method === 'GET' ? {} : send(method, body), key, method === 'PUT' ? READ_TIMEOUT_MS : undefined);
  return result.data;
}

const id = (value: string | number): string => encodeURIComponent(String(value));

// ---------------------------------------------------------------- master list

export async function fetchMaster(): Promise<MasterData> {
  const data = await call<Partial<MasterData>>('/supply/master', 'GET');
  return {
    sections: Array.isArray(data.sections) ? data.sections : [],
    items: Array.isArray(data.items) ? data.items : [],
    inactive: Array.isArray(data.inactive) ? data.inactive : [],
    draft: data.draft ?? null,
    last_count: data.last_count ?? null,
    can_edit: data.can_edit === true,
  };
}

export const createMasterItem = (input: NewItemInput, key: string): Promise<MasterItem> => call<MasterItem>('/supply/master/items', 'POST', input, key);

export const updateMasterItem = (itemId: number, changes: ItemChanges, key: string): Promise<MasterItem> => (
  call<MasterItem>(`/supply/master/items/${id(itemId)}`, 'PATCH', changes, key)
);

/** A physical count of one item, in its count unit. */
export const countMasterItem = (itemId: number, onHand: number, key: string): Promise<MasterItem> => (
  call<MasterItem>(`/supply/master/items/${id(itemId)}/count`, 'POST', { on_hand: onHand }, key)
);

// ------------------------------------------------------------------ count form

export async function startCountForm(key: string): Promise<StartResult> {
  const data = await call<StartResult>('/supply/count-form/start', 'POST', {}, key);
  return {
    ...data,
    count: { ...data.count, entries: data.count?.entries && typeof data.count.entries === 'object' ? data.count.entries : {} },
    sections: Array.isArray(data.sections) ? data.sections : [],
    items: Array.isArray(data.items) ? data.items : [],
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

export const submitCountForm = (countId: string, expectedUncounted: number, key: string): Promise<SubmitResult> => (
  call<SubmitResult>(`/supply/count-form/${id(countId)}/submit`, 'POST', { expected_uncounted: expectedUncounted }, key)
);

export async function discardCountForm(countId: string, key: string): Promise<void> {
  await call<unknown>(`/supply/counts/${id(countId)}`, 'DELETE', undefined, key);
}
