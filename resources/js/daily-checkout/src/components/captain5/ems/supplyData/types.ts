/**
 * Shapes of the Supply Data analytics API (docs/ems/supply-data-contract.md, section 3). Every answer rides the shared
 * EMS envelope `{ok, data, meta}`; these are the `data` members. `null` means "cannot be determined"; `0` is a real zero.
 */

export type PeriodKey = '30d' | '90d' | '12m' | 'custom';

export type Basis = 'counted' | 'estimate' | 'calculated' | 'none';
export type StockStatus = 'zero' | 'below_minimum' | 'near_minimum' | 'ok' | 'unknown';
export type Direction = 'up' | 'down' | 'flat' | 'new';

export interface PeriodInfo {
  key: PeriodKey;
  from: string;
  to: string;
  days: number;
  previous: { from: string; to: string };
}

export interface Filters { unit: string | null; item: number | string | null; category: string | null }

export interface Attention {
  below_minimum: number;
  zero_stock: number;
  estimated: number;
  not_counted_recently: number;
  needs_reorder: number;
}

export interface Headline {
  distribution_events: number;
  previous_distribution_events: number;
  /** null when the previous period had no events. */
  change_pct: number | null;
  items_distributed: number;
  units_supplied: number;
  requests_submitted: number;
  requests_reconciled: number;
}

/** Weekly buckets carry `week_start`; monthly ones carry `month_start`. The key present says which. */
export interface TrendBucket {
  week_start?: string;
  month_start?: string;
  distribution_events: number;
  requests?: number;
  distributed?: number;
}

export interface TopItem {
  item_id: number;
  name: string;
  count_unit: string;
  category: string | null;
  distribution_events: number;
  distributed: number;
  units_supplied: number;
  last_distributed_at: string | null;
  previous_distribution_events: number;
  direction: Direction;
}

export interface UnitRow {
  unit_key: string;
  apparatus_id: number | null;
  label: string;
  requests: number;
  distribution_events: number;
  items_distributed: number;
  last_replenished_at: string | null;
}

export interface StockRow {
  item_id: number;
  name: string;
  category: string | null;
  location: string | null;
  count_unit: string;
  on_hand: number | null;
  basis: Basis;
  last_counted_at: string | null;
  deductions_since_count: number | null;
  minimum: number | null;
  order_level: number | null;
  status: StockStatus;
  needs_reorder: boolean;
  recommended_order: number | null;
  avg_daily_distributed: number | null;
  days_of_supply: number | null;
}

export interface Fulfillment {
  submitted: number;
  acknowledged: number;
  rejected: number;
  corrected: number;
  reconciled: number;
  open: number;
  median_hours_to_reconcile: number | null;
  qty_requested: number;
  qty_distributed: number;
}

/** The contract leaves the element shape open; every field is read defensively. */
export interface CountAccuracyItem {
  item_id?: number;
  name?: string;
  count_unit?: string;
  counted_at?: string | null;
  /** The API names the counted number `counted`; the contract's example says `verified`. Both are read. */
  counted?: number | null;
  verified?: number | null;
  expected?: number | null;
  variance_pct?: number | null;
  variance?: number | null;
  basis_note?: string | null;
}

export interface CountAccuracy {
  comparable_counts: number;
  items: CountAccuracyItem[];
  coverage_pct: number | null;
  note: string | null;
  counted_lines?: number;
  exact_matches?: number | null;
  items_compared?: number;
}

export interface DataQuality {
  unattributed_movements: number;
  estimated_items: number;
  unresolved_conversions: number;
  notes: string[];
  /** Extras the API sends beyond the contract's example; each is shown only when it is a number above zero. */
  movements_on_uncounted_requests?: number;
  uncounted_items?: number;
  negative_balances?: number;
  items_without_minimum?: number;
  requests_without_unit?: number;
  trucks_with_more_than_one_unit_name?: number;
  /** Plain statements of what is too thin to conclude, by section (distribution, trend, comparison). */
  insufficient_data?: Array<{ section: string; message: string }>;
}

export interface Overview {
  label: string;
  generated_at: string;
  timezone: string;
  period: PeriodInfo;
  filters: Filters;
  attention: Attention;
  headline: Headline;
  trend: TrendBucket[];
  top_items: TopItem[];
  units: UnitRow[];
  stock: StockRow[];
  fulfillment: Fulfillment;
  count_accuracy: CountAccuracy;
  data_quality: DataQuality;
  definitions: Record<string, string>;
  /** Sentences about which filters narrow which figures; empty when no filter is applied. */
  scope_notes?: string[];
}

// ------------------------------------------------------------------ one supply

export interface ItemIdentity {
  item_id: number;
  name: string;
  category: string | null;
  location: string | null;
  count_unit: string;
  package_unit: string | null;
  package_size: number | null;
  controlled: boolean;
  active: boolean;
}

export interface ItemBalance {
  on_hand: number | null;
  basis: Basis;
  last_counted_at: string | null;
  deductions_since_count: number | null;
  minimum: number | null;
  order_level: number | null;
  status: StockStatus;
  needs_reorder: boolean;
  recommended_order: number | null;
  days_of_supply: number | null;
}

export interface ItemDistribution {
  distributed: number;
  distribution_events: number;
  previous_distribution_events: number;
  avg_weekly: number | null;
  median_weekly: number | null;
  last_distributed_at: string | null;
}

export interface ItemUnitShare {
  unit_key: string;
  label: string;
  distribution_events: number;
  distributed: number;
  share_pct: number | null;
}

export interface Replenishment { occurred_at: string; type: 'received' | 'adjusted'; quantity: number; reason: string | null }

export interface CountEntry {
  counted_at: string;
  verified: number | null;
  expected: number | null;
  variance: number | null;
  basis_note: string | null;
}

export interface Movement {
  id: number;
  occurred_at: string;
  type: string;
  quantity: number;
  unit_label: string | null;
  request_number: string | null;
  reason: string | null;
  reversed: boolean;
}

export interface ItemReport {
  item: ItemIdentity;
  balance: ItemBalance;
  period: PeriodInfo;
  distribution: ItemDistribution;
  trend: TrendBucket[];
  units: ItemUnitShare[];
  replenishment: Replenishment[];
  counts: CountEntry[];
  movements: Movement[];
  movements_truncated: boolean;
}

// ------------------------------------------------------------------ one unit

export interface UnitIdentity { unit_key: string; apparatus_id: number | null; label: string; kind: 'unit' | 'detail' | 'unassigned' }

export interface UnitSummary {
  requests: number;
  requests_reconciled: number;
  qty_requested: number;
  distributed_events: number;
  last_replenished_at: string | null;
}

export interface TopRequested { item_key: string; label: string; qty_requested: number; requests: number }
export interface TopDistributed { item_id: number; name: string; count_unit: string; distributed: number; distribution_events: number }

export interface UnitRequest {
  number: string;
  uuid: string;
  submitted_at: string | null;
  status: string;
  lines: number;
  qty_requested: number;
  reconciled_at: string | null;
}

export interface UnitReport {
  unit: UnitIdentity;
  period: PeriodInfo;
  summary: UnitSummary;
  top_requested: TopRequested[];
  top_distributed: TopDistributed[];
  trend: TrendBucket[];
  requests: UnitRequest[];
}

/** What the address bar says: period, the Explore view and which drill-down is open. */
export type ExploreView = 'supply' | 'unit' | 'stock';
export type StockFilter = 'all' | 'zero' | 'below_minimum' | 'near_minimum' | 'ok' | 'estimated' | 'reorder' | 'oldest_count';

export interface PeriodSelection { period: PeriodKey; from: string | null; to: string | null }
