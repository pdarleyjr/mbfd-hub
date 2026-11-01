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
