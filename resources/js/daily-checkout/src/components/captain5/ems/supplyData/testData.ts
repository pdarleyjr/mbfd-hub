import type { ItemReport, Overview, StockRow, UnitReport } from './types.ts';

/** Fixtures for the Supply Data tests. Shapes follow docs/ems/supply-data-contract.md; nothing here ships in the app. */

export const LABEL = 'Distributed from the supply room — not patient use';

const period = { key: '30d' as const, from: '2026-10-04', to: '2026-11-02', days: 30, previous: { from: '2026-09-04', to: '2026-10-03' } };

export const stockRow = (overrides: Partial<StockRow> = {}): StockRow => ({
  item_id: 12, name: 'Gloves, nitrile (L)', category: 'PPE', location: 'Shelf A2', count_unit: 'box',
  on_hand: 14, basis: 'counted', last_counted_at: '2026-10-27T09:00:00-04:00', deductions_since_count: 3,
  minimum: 10, order_level: 20, status: 'ok', needs_reorder: false, recommended_order: null,
  avg_daily_distributed: 0.3, days_of_supply: 46.7, ...overrides,
});

export const overview = (overrides: Partial<Overview> = {}): Overview => ({
  label: LABEL,
  generated_at: '2026-11-02T14:00:00-05:00',
  timezone: 'America/New_York',
  period,
  filters: { unit: null, item: null, category: null },
  attention: { below_minimum: 3, zero_stock: 1, estimated: 44, not_counted_recently: 50, needs_reorder: 4 },
  headline: { distribution_events: 12, previous_distribution_events: 9, change_pct: 33.3, items_distributed: 7, units_supplied: 4, requests_submitted: 6, requests_reconciled: 5 },
  trend: [
    { week_start: '2026-10-04', distribution_events: 3, requests: 2 },
    { week_start: '2026-10-11', distribution_events: 0, requests: 0 },
    { week_start: '2026-10-18', distribution_events: 5, requests: 3 },
    { week_start: '2026-10-25', distribution_events: 4, requests: 1 },
  ],
  top_items: [
    { item_id: 12, name: 'Gloves, nitrile (L)', count_unit: 'box', category: 'PPE', distribution_events: 5, distributed: 9, units_supplied: 3, last_distributed_at: '2026-10-30T10:12:00-04:00', previous_distribution_events: 2, direction: 'up' },
    { item_id: 20, name: 'Gauze 4x4', count_unit: 'each', category: 'Wound care', distribution_events: 2, distributed: 8, units_supplied: 1, last_distributed_at: '2026-10-28T10:12:00-04:00', previous_distribution_events: 0, direction: 'new' },
  ],
  units: [
    { unit_key: 'unit:45', apparatus_id: 45, label: 'RESCUE R2', requests: 2, distribution_events: 6, items_distributed: 5, last_replenished_at: '2026-10-30T10:12:00-04:00' },
    { unit_key: 'unit:46', apparatus_id: 46, label: 'RESCUE R3', requests: 0, distribution_events: 0, items_distributed: 0, last_replenished_at: null },
  ],
  stock: [
    stockRow(),
    stockRow({ item_id: 20, name: 'Gauze 4x4', category: 'Wound care', count_unit: 'each', on_hand: 5, basis: 'estimate', status: 'below_minimum', minimum: 20, days_of_supply: null, needs_reorder: true, recommended_order: 15 }),
    stockRow({ item_id: 31, name: 'Epinephrine cases', category: 'Meds', on_hand: 0, basis: 'counted', status: 'zero', days_of_supply: null }),
    stockRow({ item_id: 40, name: 'Bandage rolls', category: 'Wound care', on_hand: null, basis: 'none', status: 'unknown', last_counted_at: null, deductions_since_count: null, days_of_supply: null, minimum: null }),
  ],
  fulfillment: { submitted: 6, acknowledged: 5, rejected: 1, corrected: 0, reconciled: 5, open: 1, median_hours_to_reconcile: null, qty_requested: 40, qty_distributed: 36 },
  count_accuracy: { comparable_counts: 0, items: [], coverage_pct: null, note: 'No physical count has a verified baseline to compare against yet.' },
  data_quality: { unattributed_movements: 0, estimated_items: 44, unresolved_conversions: 0, notes: ['44 balances are estimates until counted.'] },
  definitions: { distributed: 'Net quantity issued from the supply room to a unit.' },
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
  attention: { below_minimum: 0, zero_stock: 0, estimated: 44, not_counted_recently: 44, needs_reorder: 0 },
  data_quality: {
    unattributed_movements: 0, estimated_items: 44, unresolved_conversions: 0, notes: [],
    insufficient_data: [{ section: 'distribution', message: 'Nothing was distributed from the supply room in this period. This is a real zero, not missing data.' }],
  },
});

export const itemReport = (overrides: Partial<ItemReport> = {}): ItemReport => ({
  item: { item_id: 12, name: 'Gloves, nitrile (L)', category: 'PPE', location: 'Shelf A2', count_unit: 'box', package_unit: 'case', package_size: 10, controlled: false, active: true },
  balance: { on_hand: 14, basis: 'counted', last_counted_at: '2026-10-27T09:00:00-04:00', deductions_since_count: 0, minimum: null, order_level: 20, status: 'ok', needs_reorder: false, recommended_order: null, days_of_supply: null },
  period,
  distribution: { distributed: 9, distribution_events: 5, previous_distribution_events: 2, avg_weekly: 2.1, median_weekly: 2, last_distributed_at: '2026-10-30T10:12:00-04:00' },
  trend: [
    { week_start: '2026-10-04', distribution_events: 2, distributed: 3 },
    { week_start: '2026-10-11', distribution_events: 3, distributed: 6 },
  ],
  units: [
    { unit_key: 'unit:45', label: 'RESCUE R2', distribution_events: 3, distributed: 5, share_pct: 55.6 },
    { unit_key: 'unit:46', label: 'RESCUE R3', distribution_events: 2, distributed: 4, share_pct: 44.4 },
  ],
  replenishment: [{ occurred_at: '2026-10-20T08:00:00-04:00', type: 'received', quantity: 10, reason: 'Order received' }],
  counts: [{ counted_at: '2026-10-27T09:00:00-04:00', verified: 14, expected: null, variance: null, basis_note: 'Compared with an estimate, so no difference is shown.' }],
  movements: [{ id: 99, occurred_at: '2026-10-30T10:12:00-04:00', type: 'issued', quantity: -2, unit_label: 'RESCUE R2', request_number: 'MIR-000004', reason: null, reversed: false }],
  movements_truncated: false,
  ...overrides,
});

export const unitReport = (overrides: Partial<UnitReport> = {}): UnitReport => ({
  unit: { unit_key: 'unit:45', apparatus_id: 45, label: 'RESCUE R2', kind: 'unit' },
  period,
  summary: { requests: 2, requests_reconciled: 2, qty_requested: 12, distributed_events: 6, last_replenished_at: '2026-10-30T10:12:00-04:00' },
  top_requested: [{ item_key: 'als.gauze', label: 'Gauze 4x4', qty_requested: 4, requests: 2 }],
  top_distributed: [{ item_id: 12, name: 'Gloves, nitrile (L)', count_unit: 'box', distributed: 5, distribution_events: 3 }],
  trend: [{ week_start: '2026-10-04', requests: 1, distribution_events: 3 }, { week_start: '2026-10-11', requests: 1, distribution_events: 0 }],
  requests: [{ number: 'MIR-000004', uuid: '6f1f2c1e-1111-4222-8333-444455556666', submitted_at: '2026-10-29T09:00:00-04:00', status: 'reconciled', lines: 6, qty_requested: 12, reconciled_at: '2026-10-30T10:12:00-04:00' }],
  ...overrides,
});
