/**
 * Shapes of the Supply Data analytics API (docs/ems/supply-data-contract.md, section 3). Every answer rides the shared
 * EMS envelope `{ok, data, meta}`; these are the `data` members. `null` means "cannot be determined"; `0` is a real zero.
 */

export type PeriodKey = '30d' | '90d' | '12m' | 'custom';

export type Basis = 'counted' | 'estimate' | 'calculated' | 'none';
/** There is one order threshold, so there is no \
ear_minimum\ (contract v2, change 4). */
export type StockStatus = 'zero' | 'below_minimum' | 'ok' | 'unknown';
export type BaselineOrigin = 'count_form' | 'direct_edit' | 'workbook' | 'estimate';
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

export type UnitKind = 'unit' | 'detail' | 'unassigned';

export interface UnitRow {
  unit_key: string;
  apparatus_id: number | null;
  kind: UnitKind;
  label: string;
  /** Every name the unit's requests carried in the period, newest first. */
  labels: string[];
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
  baseline_origin: BaselineOrigin | null;
  /** \
ull\ for an estimate: an estimate is not a count. */
  last_counted_at: string | null;
  deductions_since_count: number | null;
  /** The one order level, in count units. \order_level\ is the same number, kept for older code. */
  minimum: number | null;
  order_level: number | null;
  status: StockStatus;
  needs_reorder: boolean;
  /** Packages to order, in \ecommended_order_unit\; null when the package size is unknown. */
  recommended_order: number | null;
  recommended_order_unit: string | null;
  recommended_order_basis: string | null;
  avg_daily_distributed: number | null;
  days_of_supply: number | null;
  estimated: boolean;
  units_resolved: boolean;
  /** False for a retired item that was asked for by id; it is shown, labelled, but not counted in "What matters now". */
  active: boolean;
}

export interface Fulfillment {
  /** Requests that COUNTED: not cancelled, sent back or replaced, so a corrected request is one. */
  submitted: number;
  acknowledged: number;
  rejected: number;
  corrected: number;
  reconciled: number;
  open: number;
  /** Counted, neither open nor reconciled. */
  completed_other_workflow: number;
  /** Every submission, whatever its status. */
  all_submissions: number;
  median_hours_to_reconcile: number | null;
  qty_requested: number;
  qty_recorded_issued: number;
}

export interface CountAccuracyItem {
  item_id: number;
  name: string;
  counted_at: string;
  counted: number;
  expected: number;
  variance: number;
  variance_pct: number | null;
}

export interface CountAccuracy {
  counted_lines: number;
  comparable_counts: number;
  /** null when nothing is comparable. */
  exact_matches: number | null;
  items_compared: number;
  coverage_pct: number | null;
  /** The 25 largest differences. */
  items: CountAccuracyItem[];
  note: string | null;
}

export interface EstimateFarAboveOrderLevel { item_id: number; name: string; on_hand: number | null; count_unit: string; times_order_level: number }

export interface DataQuality {
  unattributed_movements: number;
  movements_on_uncounted_requests: number;
  estimated_items: number;
  uncounted_items: number;
  unresolved_conversions: number;
  negative_balances: number;
  items_without_minimum: number;
  requests_without_unit: number;
  trucks_with_more_than_one_unit_name: number;
  estimates_far_above_order_level: EstimateFarAboveOrderLevel[];
  /** Plain statements of what is too thin to conclude, by section (distribution, trend, comparison). */
  insufficient_data: Array<{ section: string; message: string }>;
  /** Human text. */
  notes: string[];
}

export interface Overview {
  label: string;
  generated_at: string;
  timezone: string;
  period: PeriodInfo;
  filters: Filters;
  /** Sentences about which filters narrow which figures; empty when no filter is applied. */
  scope_notes: string[];
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
  /** Category choices for a category filter. This screen offers none, so it is not read. */
  filter_options: { categories: string[] };
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
  /** false: the box or case size is not on file, so quantities are in single units. */
  units_resolved: boolean;
}

export type ItemBalance = Pick<StockRow,
  'on_hand' | 'basis' | 'baseline_origin' | 'estimated' | 'last_counted_at' | 'deductions_since_count' | 'minimum' | 'order_level' | 'status'
  | 'needs_reorder' | 'recommended_order' | 'recommended_order_unit' | 'recommended_order_basis' | 'days_of_supply' | 'avg_daily_distributed'>;

export interface ItemDistribution {
  distributed: number;
  distribution_events: number;
  previous_distribution_events: number;
  /** null when the previous period had no events. */
  change_pct: number | null;
  avg_weekly: number | null;
  /** null for monthly periods. */
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

/** An estimate shows in `estimated`, never in `verified`. */
export interface CountEntry {
  counted_at: string;
  verified: number | null;
  estimated: number | null;
  expected: number | null;
  variance: number | null;
  variance_pct: number | null;
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
  corrects_movement_id: number | null;
  reversed: boolean;
}

export interface ItemReport {
  label: string;
  generated_at: string;
  timezone: string;
  item: ItemIdentity;
  balance: ItemBalance;
  period: PeriodInfo;
  filters: Filters;
  /** Present when a unit narrows the page: stock and pace stay room-wide. */
  scope_notes: string[];
  distribution: ItemDistribution;
  trend: TrendBucket[];
  units: ItemUnitShare[];
  replenishment: Replenishment[];
  counts: CountEntry[];
  movements: Movement[];
  movements_truncated: boolean;
}

// ------------------------------------------------------------------ one unit

export interface UnitIdentity { unit_key: string; apparatus_id: number | null; label: string; labels: string[]; kind: UnitKind }

export interface UnitSummary {
  requests: number;
  requests_reconciled: number;
  qty_requested: number;
  distributed_events: number;
  items_distributed: number;
  last_replenished_at: string | null;
}

/** `item_key` is a stable identity string (`catalog:{id}|{unit}` or `line:{bag}|{item}|{unit}`), never a display name. */
export interface TopRequested { item_key: string; label: string; unit: string | null; qty_requested: number; requests: number }
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
  label: string;
  generated_at: string;
  timezone: string;
  unit: UnitIdentity;
  period: PeriodInfo;
  filters: Filters;
  summary: UnitSummary;
  top_requested: TopRequested[];
  top_distributed: TopDistributed[];
  trend: TrendBucket[];
  requests: UnitRequest[];
}

/** What the address bar says: period, the Explore view and which drill-down is open. */
export type ExploreView = 'supply' | 'unit' | 'stock';
/** Each filter shows exactly the rows its "What matters now" count adds up. `not_counted`: no physical count in 10 days, or never. */
export type StockFilter = 'all' | 'zero' | 'below_minimum' | 'ok' | 'estimated' | 'reorder' | 'not_counted';

export interface PeriodSelection { period: PeriodKey; from: string | null; to: string | null }
