import { call, EmsApiError } from '../../../../utils/emsApi';
import { periodQuery } from './params.ts';
import type { ItemReport, Overview, PeriodSelection, UnitReport } from './types.ts';

/**
 * Calls for the Supply Data tab. Read only: every one is a GET under `/ems/api/supply-data`, which the service worker
 * keeps network-only like the rest of `/ems/api/*`. They ride the shared `call`, so a 403 is the shared EmsApiError that
 * the other EMS tabs also treat as "no EMS tabs for you".
 */
const enc = encodeURIComponent;
const list = <T,>(value: unknown): T[] => (Array.isArray(value) ? value as T[] : []);
const record = <T extends object>(value: unknown): T => (value && typeof value === 'object' && !Array.isArray(value) ? value as T : {} as T);

function expectObject(value: unknown): Record<string, unknown> {
  if (value && typeof value === 'object' && !Array.isArray(value)) return value as Record<string, unknown>;
  throw new EmsApiError('Supply Data sent an answer this screen cannot read. Try again in a moment.', 502);
}

export async function fetchOverview(selection: PeriodSelection): Promise<Overview> {
  const raw = expectObject((await call<unknown>(`/supply-data?${periodQuery(selection).toString()}`)).data);
  return {
    ...(raw as unknown as Overview),
    trend: list(raw.trend),
    top_items: list(raw.top_items),
    units: list(raw.units),
    stock: list(raw.stock),
    definitions: record(raw.definitions),
    scope_notes: list(raw.scope_notes),
    count_accuracy: { ...record<Overview['count_accuracy']>(raw.count_accuracy), items: list(record<Overview['count_accuracy']>(raw.count_accuracy).items) },
    data_quality: {
      ...record<Overview['data_quality']>(raw.data_quality),
      notes: list(record<Overview['data_quality']>(raw.data_quality).notes),
      insufficient_data: list(record<Overview['data_quality']>(raw.data_quality).insufficient_data),
    },
  };
}

export async function fetchItem(itemId: number, selection: PeriodSelection): Promise<ItemReport> {
  const raw = expectObject((await call<unknown>(`/supply-data/items/${enc(String(itemId))}?${periodQuery(selection).toString()}`)).data);
  return {
    ...(raw as unknown as ItemReport),
    trend: list(raw.trend),
    units: list(raw.units),
    replenishment: list(raw.replenishment),
    counts: list(raw.counts),
    movements: list(raw.movements),
    movements_truncated: raw.movements_truncated === true,
  };
}

export async function fetchUnit(unitKey: string, selection: PeriodSelection): Promise<UnitReport> {
  const raw = expectObject((await call<unknown>(`/supply-data/units/${enc(unitKey)}?${periodQuery(selection).toString()}`)).data);
  return {
    ...(raw as unknown as UnitReport),
    top_requested: list(raw.top_requested),
    top_distributed: list(raw.top_distributed),
    trend: list(raw.trend),
    requests: list(raw.requests),
  };
}
