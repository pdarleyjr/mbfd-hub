/** Shapes from docs/apparatus-changeouts.md section 6. Vehicle numbers are strings: 002-16 is not 2-16. */

export type ChangeoutHandling = 'transfer' | 'leave' | 'review';
export type ChangeoutCondition = 'ok' | 'missing' | 'damaged';
export type ChangeoutStatus = 'completed' | 'pending_fleet' | 'rejected' | 'cancelled';
export type HandoffStatus = 'complete' | 'pending_verification';
export type AlertActionKind = 'start' | 'handoff' | 'view' | 'none';
export type WorkflowState = 'planned' | 'vehicle_changed' | 'awaiting_fleet' | 'awaiting_verification';

export interface AlertAction {
  kind: AlertActionKind;
  label: string;
  apparatus_id: number | null;
  alert_id: number | null;
  changeout_id: number | null;
}

export interface OperationalAlert {
  id: number;
  category: string;
  category_label: string;
  title: string;
  message: string | null;
  reason: string | null;
  instructions: string | null;
  unit_designation: string;
  vehicle_number: string | null;
  current_vehicle_number: string | null;
  current_apparatus_id: number | null;
  schedule_label: string | null;
  date_passed: boolean;
  workflow: { state: WorkflowState | string; label: string; changeout_id: number | null } | null;
  action: AlertAction | null;
}

export interface ChangeoutUnit {
  apparatus_id: number;
  designation: string;
  slug: string | null;
  vehicle_number: string | null;
  station_id: number | null;
  station_label: string | null;
  status: string | null;
}

export interface ChangeoutCandidate {
  apparatus_id: number;
  vehicle_number: string;
  label: string | null;
  location: string | null;
  status: string | null;
  direct: boolean;
  is_return: boolean;
}

export type OtherVehicle = Omit<ChangeoutCandidate, 'is_return'>;

/** Sent next to a checklist: what the unit's last changeout left on the truck it came from. */
export interface ChangeoutHandoff {
  from_vehicle_number: string | null;
  effective_at: string | null;
  left_behind: Array<{ name: string; note: string | null }>;
}

export interface PreviousLineNote {
  handling: string | null;
  status: string | null;
  note: string | null;
  /** What a return to the truck this unit last left starts from. */
  return_handling: ChangeoutHandling | null;
  return_quantity: number | null;
}

export interface EquipmentLine {
  item_key: string;
  name: string;
  /** Null stays null: an unknown quantity is never treated as 1. */
  expected_quantity: number | null;
  controlled: boolean;
  default_handling: ChangeoutHandling;
  /** Set only on a return: a partial count or a note carried from last time. */
  default_quantity: number | null;
  default_note: string;
  previous: PreviousLineNote | null;
}

export interface EquipmentCompartment {
  id: string;
  name: string;
  items: EquipmentLine[];
}

export interface PreviousChangeout {
  id: number;
  from_vehicle_number: string | null;
  to_vehicle_number: string | null;
  effective_at: string | null;
  moved: number;
  left_behind: Array<{ name: string; note: string | null }>;
  open: Array<{ name: string; status: string | null }>;
  submitted_by_name: string | null;
}

export interface ChangeoutContext {
  unit: ChangeoutUnit;
  alert: { id: number; title: string | null; reason: string | null; schedule_label: string | null; vehicle_number: string | null; planned_replacement_apparatus_id: number | null } | null;
  mode: 'start' | 'handoff_only';
  candidates: ChangeoutCandidate[];
  other_vehicles: OtherVehicle[];
  equipment: {
    checklist_version: string | null;
    usable: boolean;
    compartments: EquipmentCompartment[];
  };
  open_issues_on_truck: number;
  previous_changeout: PreviousChangeout | null;
  pending_changeout: { id: number; to_vehicle_number: string | null; submitted_by_name: string | null } | null;
  can_fleet_commit: boolean;
}

export interface ChangeoutException {
  item_key: string;
  handling: ChangeoutHandling;
  quantity: number | null;
  condition: ChangeoutCondition;
  note: string | null;
}

export interface ChangeoutSubmission {
  client_submission_id: string;
  expected_vehicle_number: string | null;
  expected_unit_slug: string | null;
  alert_id: number | null;
  to_apparatus_id: number | null;
  to_vehicle_number: string | null;
  checklist_version: string | null;
  exceptions: ChangeoutException[];
  note: string | null;
}

export interface ChangeoutLine {
  id: number;
  item_key: string;
  name: string;
  compartment: string | null;
  handling: string | null;
  custody_status: string;
  expected_quantity: number | null;
  quantity_moved: number | null;
  condition: string | null;
  note: string | null;
  controlled: boolean;
  can_verify: boolean;
  verified_at: string | null;
  verified_by_name: string | null;
  verification_note: string | null;
}

export interface ChangeoutSummary {
  id: number;
  status: ChangeoutStatus | string;
  handoff_status: HandoffStatus | string;
  unit_designation: string;
  from_vehicle_number: string | null;
  to_vehicle_number: string | null;
  from_apparatus_id: number | null;
  to_apparatus_id: number | null;
  station_id: number | null;
  station_label: string | null;
  effective_at: string | null;
  moved: number;
  left_behind: number;
  open: number;
  open_items: Array<{ name: string; status: string | null }>;
  alert_completed: boolean;
  message: string | null;
  note: string | null;
  decision_note: string | null;
  submitted_by_name: string | null;
  /** A client route for today's checkout on the new truck, or null. */
  next_daily_checkout_path: string | null;
  lines: ChangeoutLine[];
}

export interface ChangeoutSubmitResult {
  changeout: ChangeoutSummary;
  replayed: boolean;
}

/** What the 409 refusals carry, read from the top level or a `context` object. */
export interface ChangeoutConflictDetails {
  current_vehicle_number: string | null;
  current_apparatus_id: number | null;
  changeout_id: number | null;
}

/** The truck the member picked. A typed number is resolved against the lists when it is read. */
export type TruckChoice =
  | { kind: 'listed'; apparatusId: number; vehicleNumber: string }
  | { kind: 'typed' };

export interface LineChoice {
  handling: ChangeoutHandling;
  quantity: number | null;
  condition: ChangeoutCondition;
  note: string;
}

export type ChangeoutStep = 'truck' | 'equipment' | 'review';

export interface ChangeoutDraft {
  version: 1;
  apparatusId: number;
  userId: number | null;
  alertId: number | null;
  /** Generated once per draft and reused on every retry. */
  clientSubmissionId: string;
  step: ChangeoutStep;
  choice: TruckChoice | null;
  typedVehicle: string;
  /** Only lines that differ from their default. */
  exceptions: Record<string, LineChoice>;
  note: string;
  /** A send was started and no definite answer came back, so the outcome is unknown. */
  attempted: boolean;
  savedAt: number;
}

export interface ResolvedTruck {
  apparatusId: number | null;
  vehicleNumber: string;
  label: string | null;
  location: string | null;
  source: 'candidate' | 'other' | 'typed' | 'current';
  direct: boolean;
  isReturn: boolean;
}

/** Kept in the router's history state so a reload of the Done screen does not start a new changeout. */
export interface ChangeoutDoneState {
  summary: ChangeoutSummary;
  waiting: string[];
  stationPath: string;
}
