import type { ActingState, OnDuty } from '../acting/types';

/** Shapes of /api/captain5/* (docs/captain5-ems-operations-center.md). Only what the page reads. */

import type { VehicleIdentification } from '../../types';

export type IssueKind = 'rescue' | 'medical';

export type IssueActionName =
  | 'acknowledge' | 'ordered' | 'update_delivery' | 'available_for_pickup'
  | 'replaced' | 'verify' | 'dismiss' | 'reopen';

/**
 * Everyone who can open the page can do everything on it, so there is no view-only mode. `act` stays in
 * the payload as a server hint and is never used to hide a control.
 */
export interface Captain5Access {
  view: boolean;
  act: boolean;
  configure: boolean;
  acts_as: string;
  authority: string;
  authority_label: string;
}

// The shift line and acting request shapes are shared with the 300 page, so they live in ../acting/types.
export type { ActingDuration, ActingRequest, ActingResult, ActingState, OnDuty, ShiftLetter } from '../acting/types';

export interface IssueDetails {
  parts_ordered?: string;
  vendor_reference?: string;
  expected_delivery?: string;
  pickup_instructions?: string;
}

export interface IssueSummary {
  id: number;
  kind: IssueKind;
  apparatus_id: number;
  unit: string;
  compartment: string;
  item: string;
  issue_type: string;
  severity: string;
  action_status: string;
  action_label: string;
  open: boolean;
  details: IssueDetails;
  expected_delivery: string | null;
  overdue: boolean;
  awaiting_verification: boolean;
  first_reported_at: string | null;
  first_reported_by: string | null;
  last_observed_at: string | null;
  resolved_at: string | null;
  resolution_type: string | null;
  /** How a closed issue ended: fixed, or closed with no action required. */
  closure?: 'resolved' | 'closed_no_action' | null;
  /** Why no corrective action was necessary, for closure = closed_no_action. */
  closure_reason?: string | null;
  state_token: string;
}

export interface IssueEvent {
  id: number;
  type: string;
  from: string | null;
  to: string | null;
  actor: string;
  at: string | null;
  member_message: string | null;
  internal_note: string | null;
  details: Record<string, unknown>;
  inspection_id: number | null;
}

export interface IssueObservation {
  inspection_id: number;
  observation: string;
  reported_status: string | null;
  notes: string | null;
  by: string | null;
  at: string | null;
}

export interface IssueMessage {
  id: number;
  body: string;
  visibility: 'member' | 'internal' | string;
  author: string;
  context: string;
  own: boolean;
  at: string | null;
}

export interface IssueDetail extends IssueSummary {
  resolution_notes: string | null;
  observations: IssueObservation[];
  events: IssueEvent[];
  messages: IssueMessage[];
  can_post: boolean;
  can_post_internal: boolean;
  actions: IssueActionName[];
  origin_inspection_id: number | null;
}

export interface WorkspaceCounts {
  open: number;
  needs_acknowledgement: number;
  ordered: number;
  ready_for_pickup: number;
  overdue: number;
  awaiting_verification: number;
  significant: number;
  rescue: number;
  medical: number;
}

export interface RescueDaily {
  apparatus_id: number;
  unit: string;
  vehicle_number: string | null;
  station: string | null;
  status: string;
  daily: 'submitted' | 'not_submitted';
  inspection_id: number | null;
  submitted_at: string | null;
  submitted_by: string | null;
  needs_approval: boolean;
  open_issues: number;
}

export interface HandoffNote {
  id: number;
  duty_date: string;
  author: string;
  authority: string;
  /** The viewer wrote this note (only the next Captain 5 acknowledges it). */
  own?: boolean;
  body: string;
  at: string | null;
  acknowledged_by: string | null;
  acknowledged_at: string | null;
}

export interface Handoff {
  duty_date: string;
  notes: HandoffNote[];
}

export interface Workspace {
  /** The Captain 5 unit's apparatus id; the one-time vehicle information is saved against it. */
  apparatus_id?: number;
  /** Open one-time vehicle information questions for this unit. Absent when nothing is open. */
  vehicle_identification?: VehicleIdentification;
  access: Captain5Access;
  on_duty: OnDuty;
  duty_date: string;
  counts: WorkspaceCounts;
  rescue_dailies: RescueDaily[];
  issues: IssueSummary[];
  /** More in-scope issues exist than the page lists (logged on the server). */
  issues_truncated?: boolean;
  handoff: Handoff;
  acting: ActingState;
  generated_at: string;
}

export interface Captain5Summary {
  access: Captain5Access;
  on_duty: OnDuty;
  open_issues: number;
}

export interface InspectionItem {
  id: string | null;
  name: string;
  status: string;
  notes: string | null;
}

export interface InspectionCompartment {
  id: string | null;
  name: string;
  items: InspectionItem[];
}

export interface InspectionView {
  id: number;
  kind: IssueKind;
  unit: string;
  submitted_by: string | null;
  shift: string | null;
  submitted_at: string | null;
  acceptance: string;
  scope_note: string | null;
  compartments: InspectionCompartment[];
}

/** Body of POST /issues/{id}/actions apart from the concurrency and idempotency fields. */
export interface IssueActionFields {
  member_message?: string;
  internal_note?: string;
  vendor_reference?: string;
  expected_delivery?: string;
  parts_ordered?: string;
  pickup_instructions?: string;
  resolution_type?: 'replacement_installed' | 'spare_issued' | 'repair_completed';
  resolution_notes?: string;
  verified_in_place?: boolean;
  reason?: string;
}

export interface IssueActionRequest {
  action: IssueActionName;
  expected_state: string;
  idempotency_key: string;
  fields: IssueActionFields;
}
