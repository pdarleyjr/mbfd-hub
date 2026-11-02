import type { ItemReport, Overview, UnitReport } from './types.ts';

/**
 * The example payloads of docs/ems/supply-data-contract.md (v2), section 3, copied member for member. Where the contract shows a choice
 * ("counted|estimate") or an ellipsis, one real value stands in. Each is declared with its type, so `tsc` fails the moment `types.ts`
 * and the contract disagree: a member missing from the type is an excess-property error here, a member the contract lacks is a missing one.
 */

const PERIOD = { key: '30d', from: '2026-10-04', to: '2026-11-02', days: 30, previous: { from: '2026-09-04', to: '2026-10-03' } } as const;
const FILTERS = { unit: null, item: null, category: null } as const;

export const CONTRACT_OVERVIEW: Overview = {
  label: 'Distributed from the supply room — not patient use',
  generated_at: '2026-11-02T12:00:00-05:00',
  timezone: 'America/New_York',
  period: PERIOD,
  filters: FILTERS,
  scope_notes: [],
  attention: { below_minimum: 3, zero_stock: 1, estimated: 44, not_counted_recently: 50, needs_reorder: 4 },
  headline: { distribution_events: 12, previous_distribution_events: 9, change_pct: 33.3, items_distributed: 7, units_supplied: 4, requests_submitted: 6, requests_reconciled: 5 },
  trend: [{ week_start: '2026-10-04', distribution_events: 3, requests: 2 }],
  top_items: [{
    item_id: 12, name: 'Gloves, nitrile (L)', count_unit: 'box', category: null, distribution_events: 5, distributed: 9, units_supplied: 3,
    last_distributed_at: '2026-10-30T10:12:00-04:00', previous_distribution_events: 2, direction: 'up',
  }],
  units: [{
    unit_key: 'unit:45', apparatus_id: 45, kind: 'unit', label: 'Rescue 2', labels: ['Rescue 2'], requests: 2, distribution_events: 6, items_distributed: 5,
    last_replenished_at: '2026-10-30T10:12:00-04:00',
  }],
  stock: [{
    item_id: 12, name: 'Gloves, nitrile (L)', category: null, location: 'B4', count_unit: 'box', on_hand: 14, basis: 'counted', baseline_origin: 'count_form',
    last_counted_at: '2026-10-27T08:00:00-04:00', deductions_since_count: 3, minimum: 10, order_level: 10, status: 'ok', needs_reorder: true, recommended_order: 6,
    recommended_order_unit: 'case', recommended_order_basis: 'rounded_up_to_packages', avg_daily_distributed: 0.3, days_of_supply: 46.7, estimated: false, units_resolved: true, active: true,
  }],
  fulfillment: {
    submitted: 6, acknowledged: 5, rejected: 1, corrected: 0, reconciled: 5, open: 1, completed_other_workflow: 0, all_submissions: 7, median_hours_to_reconcile: 70.5,
    qty_requested: 40, qty_recorded_issued: 36,
  },
  count_accuracy: {
    counted_lines: 0, comparable_counts: 0, exact_matches: null, items_compared: 0, coverage_pct: null,
    items: [{ item_id: 12, name: 'Gloves, nitrile (L)', counted_at: '2026-10-27T08:00:00-04:00', counted: 85, expected: 90, variance: -5, variance_pct: -5.6 }],
    note: 'No physical count has a verified baseline to compare against yet.',
  },
  data_quality: {
    unattributed_movements: 0, movements_on_uncounted_requests: 0, estimated_items: 44, uncounted_items: 0, unresolved_conversions: 0, negative_balances: 0,
    items_without_minimum: 0, requests_without_unit: 0, trucks_with_more_than_one_unit_name: 0,
    estimates_far_above_order_level: [{ item_id: 7, name: 'Disposable Razors', on_hand: 341, count_unit: 'case', times_order_level: 341 }],
    insufficient_data: [{ section: 'distribution', message: 'Nothing was distributed from the supply room in this period. This is a real zero, not missing data.' }],
    notes: ['44 balances are best-guess estimates, not physical counts.'],
  },
  definitions: { distributed: 'Net quantity that left the supply room for a unit.' },
  filter_options: { categories: [] },
};

export const CONTRACT_ITEM: ItemReport = {
  label: 'Distributed from the supply room — not patient use',
  generated_at: '2026-11-02T12:00:00-05:00',
  timezone: 'America/New_York',
  item: { item_id: 12, name: 'Gloves, nitrile (L)', category: null, location: 'B4', count_unit: 'box', package_unit: 'box', package_size: 100, controlled: false, active: true, units_resolved: true },
  balance: {
    on_hand: 14, basis: 'counted', baseline_origin: 'count_form', estimated: false, last_counted_at: '2026-10-27T08:00:00-04:00', deductions_since_count: 3, minimum: 10,
    order_level: 10, status: 'ok', needs_reorder: false, recommended_order: null, recommended_order_unit: null, recommended_order_basis: null, days_of_supply: 46.7,
    avg_daily_distributed: 0.3,
  },
  period: PERIOD,
  filters: FILTERS,
  scope_notes: [],
  distribution: { distributed: 9, distribution_events: 5, previous_distribution_events: 2, change_pct: 150.0, avg_weekly: 2.1, median_weekly: 2.0, last_distributed_at: '2026-10-30T10:12:00-04:00' },
  trend: [{ week_start: '2026-10-04', distributed: 3, distribution_events: 2 }],
  units: [{ unit_key: 'unit:45', label: 'Rescue 2', distribution_events: 3, distributed: 5, share_pct: 55.6 }],
  replenishment: [{ occurred_at: '2026-10-20T08:00:00-04:00', type: 'received', quantity: 10, reason: 'Order received' }],
  counts: [{ counted_at: '2026-10-27T08:00:00-04:00', verified: 14, estimated: null, expected: 15, variance: -1, variance_pct: -6.7, basis_note: null }],
  movements: [{
    id: 99, occurred_at: '2026-10-30T10:12:00-04:00', type: 'issued', quantity: -2, unit_label: 'Rescue 2', request_number: 'MIR-000004', reason: null,
    corrects_movement_id: null, reversed: false,
  }],
  movements_truncated: false,
};

export const CONTRACT_UNIT: UnitReport = {
  label: 'Distributed from the supply room — not patient use',
  generated_at: '2026-11-02T12:00:00-05:00',
  timezone: 'America/New_York',
  unit: { unit_key: 'unit:45', apparatus_id: 45, label: 'Rescue 2', labels: ['Rescue 2'], kind: 'unit' },
  period: PERIOD,
  filters: FILTERS,
  summary: { requests: 2, requests_reconciled: 2, qty_requested: 12, distributed_events: 6, items_distributed: 5, last_replenished_at: '2026-10-30T10:12:00-04:00' },
  top_requested: [{ item_key: 'catalog:12|each', label: 'Gloves, nitrile (L)', unit: 'each', qty_requested: 4, requests: 2 }],
  top_distributed: [{ item_id: 12, name: 'Gloves, nitrile (L)', count_unit: 'box', distributed: 5, distribution_events: 3 }],
  trend: [{ week_start: '2026-10-04', requests: 1, distribution_events: 3 }],
  requests: [{ number: 'MIR-000004', uuid: '6f1f2c1e-1111-4222-8333-444455556666', submitted_at: '2026-10-29T09:00:00-04:00', status: 'reconciled', lines: 6, qty_requested: 12, reconciled_at: '2026-10-30T10:12:00-04:00' }],
};
