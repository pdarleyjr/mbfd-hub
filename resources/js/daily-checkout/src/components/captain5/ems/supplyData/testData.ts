import { CONTRACT_ITEM, CONTRACT_OVERVIEW, CONTRACT_UNIT } from './contractExamples.ts';
import type { ItemReport, Overview, StockRow, UnitReport } from './types.ts';

/** Fixtures for the Supply Data tests: the contract's examples (contractExamples.ts) with the particulars each test needs. Nothing here ships. */

export const LABEL = CONTRACT_OVERVIEW.label;
const period = CONTRACT_OVERVIEW.period;

export const stockRow = (overrides: Partial<StockRow> = {}): StockRow => ({ ...CONTRACT_OVERVIEW.stock[0], ...overrides });

export const overview = (overrides: Partial<Overview> = {}): Overview => ({
  ...CONTRACT_OVERVIEW,
  generated_at: '2026-11-02T14:00:00-05:00',
  trend: [
    { week_start: '2026-10-04', distribution_events: 3, requests: 2 },
    { week_start: '2026-10-11', distribution_events: 0, requests: 0 },
    { week_start: '2026-10-18', distribution_events: 5, requests: 3 },
    { week_start: '2026-10-25', distribution_events: 4, requests: 1 },
  ],
  top_items: [
    CONTRACT_OVERVIEW.top_items[0],
    { item_id: 20, name: 'Gauze 4x4', count_unit: 'each', category: 'Wound care', distribution_events: 2, distributed: 8, units_supplied: 1, last_distributed_at: '2026-10-28T10:12:00-04:00', previous_distribution_events: 0, direction: 'new' },
  ],
  units: [
    CONTRACT_OVERVIEW.units[0],
    { unit_key: 'unit:46', apparatus_id: 46, kind: 'unit', label: 'Rescue 3', labels: [], requests: 0, distribution_events: 0, items_distributed: 0, last_replenished_at: null },
  ],
  // The four attention counts below are exactly what these rows add up to (the "not counted" rule: no count in 10 days before generated_at, or never).
  stock: [
    stockRow({ needs_reorder: false, recommended_order: null, recommended_order_unit: null, recommended_order_basis: null }),
    stockRow({
      item_id: 20, name: 'Gauze 4x4', category: 'Wound care', count_unit: 'each', on_hand: 5, basis: 'estimate', baseline_origin: 'estimate', estimated: true, last_counted_at: null,
      status: 'below_minimum', minimum: 20, order_level: 20, days_of_supply: null, needs_reorder: true, recommended_order: 2, recommended_order_unit: 'box',
    }),
    stockRow({ item_id: 31, name: 'Epinephrine cases', category: 'Meds', on_hand: 0, status: 'zero', days_of_supply: null, last_counted_at: '2026-10-01T09:00:00-04:00', needs_reorder: false, recommended_order: null, recommended_order_unit: null, recommended_order_basis: null }),
    stockRow({
      item_id: 40, name: 'Bandage rolls', category: 'Wound care', on_hand: null, basis: 'none', baseline_origin: null, last_counted_at: null, deductions_since_count: null,
      days_of_supply: null, minimum: null, order_level: null, status: 'unknown', needs_reorder: false, recommended_order: null, recommended_order_unit: null, recommended_order_basis: null,
    }),
  ],
  attention: { below_minimum: 1, zero_stock: 1, estimated: 1, not_counted_recently: 3, needs_reorder: 1 },
  fulfillment: { ...CONTRACT_OVERVIEW.fulfillment, median_hours_to_reconcile: null },
  count_accuracy: { counted_lines: 0, comparable_counts: 0, exact_matches: null, items_compared: 0, coverage_pct: null, items: [], note: 'No physical count has a verified baseline to compare against yet.' },
  data_quality: { ...CONTRACT_OVERVIEW.data_quality, estimates_far_above_order_level: [], insufficient_data: [], notes: ['44 balances are estimates until counted.'] },
  ...overrides,
});

/** What production looks like today: almost no history, 44 estimated balances. */
export const sparseOverview = (): Overview => overview({
  headline: { distribution_events: 0, previous_distribution_events: 0, change_pct: null, items_distributed: 0, units_supplied: 0, requests_submitted: 0, requests_reconciled: 0 },
  trend: [
    { week_start: '2026-10-04', distribution_events: 0, requests: 0 },
    { week_start: '2026-10-11', distribution_events: 0, requests: 0 },
  ],
  top_items: [],
  units: [],
  attention: { below_minimum: 0, zero_stock: 0, estimated: 44, not_counted_recently: 44, needs_reorder: 0 },
  data_quality: {
    ...CONTRACT_OVERVIEW.data_quality, estimates_far_above_order_level: [], notes: [],
    insufficient_data: [{ section: 'distribution', message: 'Nothing was distributed from the supply room in this period. This is a real zero, not missing data.' }],
  },
});

export const itemReport = (overrides: Partial<ItemReport> = {}): ItemReport => ({
  ...CONTRACT_ITEM,
  balance: { ...CONTRACT_ITEM.balance, deductions_since_count: 0, minimum: null, order_level: null, days_of_supply: null },
  trend: [
    { week_start: '2026-10-04', distribution_events: 2, distributed: 3 },
    { week_start: '2026-10-11', distribution_events: 3, distributed: 6 },
  ],
  units: [
    { unit_key: 'unit:45', label: 'Rescue 2', distribution_events: 3, distributed: 5, share_pct: 55.6 },
    { unit_key: 'unit:46', label: 'Rescue 3', distribution_events: 2, distributed: 4, share_pct: 44.4 },
  ],
  counts: [{ counted_at: '2026-10-27T09:00:00-04:00', verified: 14, estimated: null, expected: null, variance: null, variance_pct: null, basis_note: 'Compared with an estimate, so no difference is shown.' }],
  period,
  ...overrides,
});

export const unitReport = (overrides: Partial<UnitReport> = {}): UnitReport => ({
  ...CONTRACT_UNIT,
  top_requested: [{ item_key: 'catalog:3|each', label: 'Gauze 4x4', unit: 'each', qty_requested: 4, requests: 2 }],
  trend: [{ week_start: '2026-10-04', requests: 1, distribution_events: 3 }, { week_start: '2026-10-11', requests: 1, distribution_events: 0 }],
  ...overrides,
});
