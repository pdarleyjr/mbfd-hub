import type { ExploreView, PeriodKey, PeriodSelection, StockFilter } from './types.ts';

/**
 * The Supply Data screen lives in the address bar (`?tab=supply-data&period=90d&explore=unit&item=12`), so Back, a
 * refresh and a shared link all return to the same place. Pure helpers: no React, no fetch.
 */

/** Keys this tab owns; the page clears them when the person leaves for another tab. */
export const SUPPLY_DATA_PARAM_KEYS = ['period', 'from', 'to', 'explore', 'stock', 'item', 'unit'] as const;
export const SUPPLY_DATA_CLEARED: Record<(typeof SUPPLY_DATA_PARAM_KEYS)[number], null> = {
  period: null, from: null, to: null, explore: null, stock: null, item: null, unit: null,
};

export const PERIOD_OPTIONS: Array<{ id: PeriodKey; label: string }> = [
  { id: '30d', label: 'Last 30 days' },
  { id: '90d', label: 'Last 90 days' },
  { id: '12m', label: 'Last 12 months' },
  { id: 'custom', label: 'Custom' },
];

export const EXPLORE_OPTIONS: Array<{ id: ExploreView; label: string }> = [
  { id: 'supply', label: 'By supply' },
  { id: 'unit', label: 'By unit' },
  { id: 'stock', label: 'Stock' },
];

const STOCK_FILTERS: StockFilter[] = ['all', 'zero', 'below_minimum', 'ok', 'estimated', 'reorder', 'not_counted'];
const DATE = /^\d{4}-\d{2}-\d{2}$/;
const UNIT_KEY = /^(unit:\d{1,18}|detail|unassigned)$/;

/** How many rows the ranked lists ask for. The API's default is 10 and its ceiling 50, which the admin page and the export use. */
export const LIST_LIMIT = 50;

/** The API refuses ranges longer than 400 days; stay one day inside the limit so a range is never refused. */
export const MAX_RANGE_DAYS = 399;

export interface SupplyDataView {
  selection: PeriodSelection;
  explore: ExploreView;
  stock: StockFilter;
  itemId: number | null;
  unitKey: string | null;
}

const dayNumber = (value: string): number | null => {
  if (!DATE.test(value)) return null;
  const [y, m, d] = value.split('-').map(Number);
  const time = Date.UTC(y, m - 1, d);
  const check = new Date(time);
  return check.getUTCFullYear() === y && check.getUTCMonth() === m - 1 && check.getUTCDate() === d ? Math.round(time / 86_400_000) : null;
};

/** A reason a custom range cannot be shown, in words for the person; null when it is fine. `today` is Eastern Y-m-d. */
export function customRangeProblem(from: string | null, to: string | null, today: string): string | null {
  if (!from || !to) return 'Choose both a start date and an end date.';
  const start = dayNumber(from);
  const end = dayNumber(to);
  const now = dayNumber(today);
  if (start === null || end === null) return 'Enter both dates as real calendar dates.';
  if (end < start) return 'The end date cannot be before the start date.';
  if (now !== null && end > now) return 'The end date cannot be in the future.';
  if (end - start > MAX_RANGE_DAYS) return 'Choose a range of 400 days or fewer.';
  return null;
}

export function parseView(params: URLSearchParams): SupplyDataView {
  const rawPeriod = params.get('period');
  const period: PeriodKey = rawPeriod === '90d' || rawPeriod === '12m' || rawPeriod === 'custom' ? rawPeriod : '30d';
  const from = params.get('from');
  const to = params.get('to');
  const explore = params.get('explore');
  const stock = params.get('stock');
  const item = params.get('item');
  const unit = params.get('unit');
  return {
    selection: { period, from: period === 'custom' && from && dayNumber(from) !== null ? from : null, to: period === 'custom' && to && dayNumber(to) !== null ? to : null },
    explore: explore === 'unit' || explore === 'stock' ? explore : 'supply',
    stock: STOCK_FILTERS.includes(stock as StockFilter) ? stock as StockFilter : 'all',
    itemId: item !== null && /^\d{1,12}$/.test(item) && Number(item) > 0 ? Number(item) : null,
    unitKey: unit !== null && UNIT_KEY.test(unit) ? unit : null,
  };
}

/** Query-string pieces that the API understands (period, and the dates for a custom range). */
export function periodQuery(selection: PeriodSelection): URLSearchParams {
  const query = new URLSearchParams({ period: selection.period });
  if (selection.period === 'custom') {
    if (selection.from) query.set('from', selection.from);
    if (selection.to) query.set('to', selection.to);
  }
  return query;
}

/** The address-bar changes that select a period; the default 30 days leaves the address clean. */
export function periodParams(selection: PeriodSelection): Record<string, string | null> {
  return {
    period: selection.period === '30d' ? null : selection.period,
    from: selection.period === 'custom' ? selection.from : null,
    to: selection.period === 'custom' ? selection.to : null,
  };
}

/** The stable key that tells a loader its answer belongs to this period. */
export const selectionKey = (selection: PeriodSelection): string => `${selection.period}|${selection.from ?? ''}|${selection.to ?? ''}`;

/** A custom range is ready to ask for only once both dates are set and valid. */
export function selectionReady(selection: PeriodSelection, today: string): boolean {
  return selection.period !== 'custom' || customRangeProblem(selection.from, selection.to, today) === null;
}

/** `/ems/api/supply-data/export.xlsx` with the filters the person is looking at. A plain link: the browser downloads it. */
export function exportHref(selection: PeriodSelection, scope: { itemId?: number | null; unitKey?: string | null } = {}): string {
  const query = periodQuery(selection);
  if (scope.itemId) query.set('item', String(scope.itemId));
  if (scope.unitKey) query.set('unit', scope.unitKey);
  return `/ems/api/supply-data/export.xlsx?${query.toString()}`;
}

/** The earliest allowed start for a custom range ending on `to` (or today). */
export function earliestStart(anchor: string): string {
  const end = dayNumber(anchor);
  if (end === null) return '';
  const date = new Date((end - MAX_RANGE_DAYS) * 86_400_000);
  return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`;
}
