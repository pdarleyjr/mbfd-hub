/** Shapes of the EMS staff JSON API (docs/ems/ems-inventory-contract.md, section 9.3). Every answer is {ok, data, meta}. */

export interface EmsPermissions { view: boolean; act: boolean; configure: boolean }

export type RequestStatus = 'submitted' | 'reviewed' | 'prepared' | 'partially_issued' | 'issued' | 'closed' | 'cancelled';
export type TargetKind = 'rescue_unit' | 'other_unit' | 'detail_event';
export type LineStatus = 'open' | 'prepared' | 'partially_issued' | 'issued' | 'not_issued';

export interface EmsUnitOption {
  /** null for Detail / Special Event. */
  id: number | null;
  kind: TargetKind;
  label: string;
  designation: string | null;
  status: string | null;
  group: string;
}

export interface EmsMeta {
  permissions: EmsPermissions;
  statuses: RequestStatus[];
  target_kinds: TargetKind[];
  units: EmsUnitOption[];
  close_reasons: string[];
}

export interface PageMeta { page: number; per_page: number; total: number; last_page: number }

export interface RequestSummary {
  id: string;
  number: string;
  status: RequestStatus;
  status_label: string;
  submitted_at: string | null;
  submitter_name: string;
  submitter_rank: string | null;
  target_kind: TargetKind;
  target_label: string;
  apparatus_id: number | null;
  event_assignment: string | null;
  line_count: number;
  issued_line_count: number;
  has_note: boolean;
  last_activity_at: string | null;
  closed_at: string | null;
}

export interface SupplyCandidate {
  id: number;
  name: string;
  location_code: string | null;
  package_unit: string | null;
  units_per_package: number | null;
}

export interface RequestLine {
  id: number;
  bag_key: string;
  item_key: string;
  label: string;
  section_label: string | null;
  variant_label: string | null;
  min_text: string | null;
  unit: string | null;
  response_type: 'quantity' | 'yes_no';
  requested_qty: number | null;
  requested_flag: boolean | null;
  prepared_qty: number | null;
  issued_qty: number;
  line_status: LineStatus;
  line_status_label: string;
  not_issued_reason: string | null;
  refrigerated: boolean;
  controlled_substance: boolean;
  catalog_item_id?: number | null;
  supply_candidates?: SupplyCandidate[];
}

export interface HistoryEvent {
  id: number;
  action: string;
  actor_name: string | null;
  actor_rank: string | null;
  actor_context: string;
  actor_context_label: string;
  request_line_id: number | null;
  from_status: string | null;
  to_status: string | null;
  payload: Record<string, unknown>;
  created_at: string | null;
}

export interface RequestMessage {
  id: number;
  author_name: string;
  author_rank: string | null;
  author_context: string;
  author_context_label: string;
  body: string;
  created_at: string | null;
}

export interface RequestDetail extends RequestSummary {
  member_note: string | null;
  lines: RequestLine[];
  history: HistoryEvent[];
  messages: RequestMessage[];
  close_reason: string | null;
  cancel_reason: string | null;
}

export interface RequestFilters {
  chip: 'open' | 'issued' | 'closed' | 'all';
  unit: string;
  dateFrom: string;
  dateTo: string;
  q: string;
  page: number;
}

/** One issuance as it stands now, after any later corrections. */
export interface IssuanceView {
  eventId: number;
  lineId: number | null;
  label: string;
  quantity: number;
  originalQuantity: number;
  supplyItemId: number | null;
  supplyItemName: string | null;
  stockEffect: string | null;
  corrected: boolean;
  actor: string | null;
  at: string | null;
}

export interface LineGroup {
  bagKey: string;
  bagLabel: string;
  sections: Array<{ label: string; lines: RequestLine[] }>;
}

// ------------------------------------------------------------------ statistics

export interface StatisticsReport {
  label: string;
  filters: { apparatus_id: number | null; target_kind: TargetKind | null; date_from: string | null; date_to: string | null };
  totals: {
    requests_submitted: number;
    requests_cancelled: number;
    requests_counted: number;
    lines_requested: number;
    qty_requested: number;
    yes_answers: number;
    qty_issued: number;
    qty_outstanding: number;
    weeks_in_range: number;
    requests_per_week: number;
  };
  top_requested_items: Array<{ bag_key: string; item_key: string; label: string; qty_requested: number; qty_issued: number; requests: number }>;
  monthly_trend: Array<{ month: string; requests: number; qty_requested: number; qty_issued: number }>;
  detail_special_event: { requests: number; without_event_text: number; events: Array<{ event_assignment: string; requests: number }> };
  by_unit: Array<{ apparatus_id: number | null; target_kind: TargetKind; label: string; requests: number; qty_requested: number; qty_issued: number }>;
  definitions: Record<string, string>;
}

// ----------------------------------------------------------------- supply room

export interface SupplyItem {
  id: number;
  catalog_item_id: number | null;
  name: string;
  bag_alias_text: string | null;
  location_code: string | null;
  section_key: string | null;
  base_unit: string;
  package_unit: string | null;
  units_per_package: number | null;
  packages_per_case: number | null;
  qty_per_package_text: string | null;
  threshold_qty_base: number | null;
  threshold_source_text: string | null;
  order_amount_text: string | null;
  order_qty_packages: number | null;
  suppliers: Array<Record<string, unknown>>;
  item_numbers: string[];
  suppliers_note: string;
  refrigerated: boolean;
  controlled_substance: boolean;
  has_expiration: boolean | null;
  needs_review: boolean;
  review_reasons: string[];
  active: boolean;
  tracks_stock: boolean;
  controlled_note: string | null;
  current_quantity: number | null;
  current_basis: 'counted' | 'calculated' | 'none' | null;
  current_basis_label: string | null;
  current_basis_at: string | null;
  movements_since_basis: number;
  below_threshold: boolean | null;
  audit?: Array<{ field: string; before: unknown; after: unknown; reason: string | null; actor_name: string | null; created_at: string | null }>;
}

export interface StorageLocation { id: number; code: string; label: string; active: boolean; item_count: number }

export interface SnapshotSummary {
  id: string;
  kind: 'physical_count' | 'reconciliation' | 'baseline';
  kind_label: string;
  status: 'draft' | 'final';
  version: number | null;
  scope_location_code: string | null;
  started_at: string | null;
  started_by: string | null;
  finalized_at: string | null;
  finalized_name: string | null;
  cutoff_at: string | null;
  through_movement_id: number | null;
  previous_snapshot_id: string | null;
  notes: string | null;
  line_count: number;
  counted_count: number;
}

export interface SnapshotLine {
  supply_item_id: number;
  item_name: string;
  location_code: string | null;
  quantity_base: number | null;
  entry: Record<string, unknown>;
  value_source: 'counted' | 'calculated' | 'not_counted';
  warnings: string[];
}

export interface CountItem {
  id: number;
  name: string;
  location_code: string | null;
  package_unit: string | null;
  units_per_package: number | null;
  packages_per_case: number | null;
  entry: { cases?: number; boxes?: number; each?: number } | null;
  quantity_base: number | null;
  warnings: string[];
}

export interface SnapshotDetail extends SnapshotSummary {
  lines: SnapshotLine[];
  items?: CountItem[];
  resumed?: boolean;
  unchanged?: boolean;
}

export interface ReconcileRow {
  supply_item_id: number;
  name: string;
  location_code: string | null;
  previous_quantity: number | null;
  previous_basis_label: string | null;
  issued: number;
  received: number;
  adjusted: number;
  movement_count: number;
  calculated_quantity: number | null;
  threshold_qty_base: number | null;
  threshold_source_text: string | null;
  below_threshold: boolean | null;
  warnings: string[];
}

export interface UnmatchedIssuance {
  event_id: number;
  request_id: string | null;
  request_number: string | null;
  label: string | null;
  quantity: number;
  note: string | null;
  issued_at: string | null;
}

export interface ReconcilePreview {
  previous_snapshot: SnapshotSummary | null;
  last_movement_id: number;
  idempotency_key: string;
  nothing_to_reconcile: boolean;
  summary: { items: number; without_previous_count: number; negative_results: number; movements_included: number };
  unmatched_issuances: UnmatchedIssuance[];
  rows: ReconcileRow[];
}

export interface AdvisoryBase {
  supply_item_id: number;
  name: string;
  location_code: string | null;
  threshold_qty_base: number | null;
  threshold_source_text: string | null;
  needs_review: boolean;
  review_reasons: string[];
}

export interface Advisory extends AdvisoryBase {
  current_quantity: number;
  current_basis: string;
  current_basis_label: string;
  shortfall_base: number;
  suggested_packages: number | null;
  suggested_basis: 'source_order_amount' | 'rounded_up_to_packages' | 'unknown_package_size';
  package_unit: string | null;
  units_per_package: number | null;
  order_amount_text: string | null;
  supplier_code: string | null;
  item_number: string | null;
  details_unverified: boolean;
  open_orders: Array<{ id: number; packages: number; placed_at: string | null }>;
}

export interface Advisories {
  advisories: Advisory[];
  unknown_threshold: AdvisoryBase[];
  needs_count: AdvisoryBase[];
  note: string;
}

export interface SupplyOrder {
  id: number;
  supply_item_id: number;
  item_name: string | null;
  status: 'placed' | 'received' | 'cancelled';
  packages: number;
  quantity_base: number | null;
  supplier_code: string | null;
  item_number: string | null;
  notes: string | null;
  placed_by_name: string | null;
  placed_at: string | null;
  received_at: string | null;
  cancelled_at: string | null;
}

export interface Movement {
  id: number;
  uuid: string;
  supply_item_id: number;
  item_name: string | null;
  movement_type: 'issued' | 'received' | 'adjusted';
  quantity_base: number;
  occurred_at: string | null;
  actor_name: string | null;
  reason: string | null;
  request_id: number | null;
  supply_order_id: number | null;
  corrects_movement_id: number | null;
}
