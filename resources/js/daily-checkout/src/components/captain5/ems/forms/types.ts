/** Shapes of the Submitted Inventory Forms API (docs/ems/captain5-medical-inventory-workflow.md, sections 2, 3, 6). */
import type { PageMeta, RequestDetail, RequestSummary } from '../types.ts';

export type { PageMeta };

export type FormStatus =
  | 'submitted' | 'reviewed' | 'prepared' | 'returned'
  | 'reconciled' | 'superseded' | 'issued' | 'partially_issued' | 'closed' | 'cancelled';

export type FormView = 'active' | 'archived';

/** `quantity` is null for a Yes/No line. */
export interface PreviewItem { label: string; quantity: number | null; unit: string | null }

/** One row of the forms list. Everything beyond the old request summary is what the new workflow adds. */
export interface FormSummary extends Omit<RequestSummary, 'status'> {
  status: FormStatus;
  acknowledged: boolean;
  acknowledged_at: string | null;
  acknowledged_by: string | null;
  can_reconcile: boolean;
  can_reject: boolean;
  can_acknowledge: boolean;
  return_reason: string | null;
  returned_at: string | null;
  returned_by?: string | null;
  reconciled_at: string | null;
  reconciled_by: string | null;
  revision_number: number;
  items_preview: PreviewItem[];
  quantity_total: number;
}

export interface RevisionLink {
  id: string;
  number: string;
  status: FormStatus;
  status_label: string;
  submitted_at: string | null;
  /** True for the version the detail was asked for, not necessarily the newest. Versions arrive oldest first. */
  is_current: boolean;
}

export interface FormDetail extends Omit<RequestDetail, 'status'>, FormSummary {
  revisions: RevisionLink[];
}

// ------------------------------------------------------------------ reconcile

export type PreviewLineState = 'ready' | 'needs_item' | 'needs_unit' | 'no_stock_effect' | 'not_stocked';

export interface PreviewForm {
  id: string;
  number: string;
  target_label: string;
  submitter_name: string;
  submitted_at: string | null;
  eligible: boolean;
  ineligible_reason: string | null;
}

export interface PreviewItemRef { id: number; name: string; location_code: string | null; count_unit: string }

export interface PreviewLine {
  line_id: number;
  request_id: string;
  number: string;
  target_label: string;
  label: string;
  variant_label: string | null;
  requested_qty: number | null;
  unit: string | null;
  quantity: number;
  quantity_base: number | null;
  state: PreviewLineState;
  message: string | null;
  item: PreviewItemRef | null;
}

export interface PreviewTotal {
  supply_item_id: number;
  name: string;
  location_code: string | null;
  count_unit: string;
  units_per_count_unit: number;
  deduct_base: number;
  before_base: number | null;
  after_base: number | null;
  low: boolean;
  not_counted: boolean;
}

export interface FormsPreview {
  last_movement_id: number;
  blocked: boolean;
  forms: PreviewForm[];
  lines: PreviewLine[];
  totals: PreviewTotal[];
}

export interface ReconcileDecision {
  line_id: number;
  quantity?: number;
  supply_item_id?: number;
  quantity_base?: number;
  not_stocked?: boolean;
}

export interface ReconcileRequest {
  request_ids: string[];
  expected_last_movement_id: number;
  accept_low_stock?: boolean;
  decisions?: ReconcileDecision[];
}

export interface ReconcileResult {
  batch: string | number;
  reconciled: Array<{ id: string; number: string }>;
  movement_count: number;
  deducted: Array<{ supply_item_id: number; name: string; deduct_base: number }>;
}

// ------------------------------------------------------------- master inventory

/** The part of GET /supply/master the item picker and the unit maths use. */
export interface MasterItem {
  id: number;
  name: string;
  aliases: string[];
  location_code: string | null;
  location_label: string | null;
  base_unit: string;
  count_unit: string;
  units_per_count_unit: number;
  package_unit: string | null;
  units_per_package: number | null;
  on_hand_base: number | null;
  counted_at: string | null;
}

export interface FormsPage { forms: FormSummary[]; meta: PageMeta }
