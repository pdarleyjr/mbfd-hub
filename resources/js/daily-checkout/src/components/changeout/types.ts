/** Shapes from the changeout API. Vehicle numbers are strings: 002-16 is not 2-16. */

export type ChangeoutHandling = 'transfer' | 'leave';
export type AlertActionKind = 'start' | 'handoff' | 'none';

export interface AlertAction {
  kind: AlertActionKind;
  label: string;
  apparatus_id: number | null;
  alert_id: number | null;
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
  workflow: { state: string; label: string } | null;
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

interface ListedVehicle {
  apparatus_id: number;
  vehicle_number: string;
  label: string | null;
  location: string | null;
  status: string | null;
}

export interface ChangeoutCandidate extends ListedVehicle {
  is_return: boolean;
}

export interface OtherVehicle extends ListedVehicle {
  /** The unit this truck is carrying now, or null when it is a spare. */
  listed_as: string | null;
}

export interface LeftItem {
  name: string;
  note: string | null;
}

/** Sent next to a checklist: what the unit's last changeout left on the truck it came from. */
export interface ChangeoutHandoff {
  from_vehicle_number: string | null;
  effective_at: string | null;
  left_behind: LeftItem[];
}

export interface EquipmentLine {
  item_key: string;
  name: string;
  /** What happened to this item last time. Information only: it never presets anything. */
  previous: { handling: string | null; note: string | null } | null;
}

export interface EquipmentCompartment {
  id: string;
  name: string;
  items: EquipmentLine[];
}

export interface PreviousChangeout {
  id: number;
  from_apparatus_id: number | null;
  from_vehicle_number: string | null;
  to_vehicle_number: string | null;
  effective_at: string | null;
  moved: number;
  left_behind: LeftItem[];
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
}

export interface ChangeoutException {
  item_key: string;
  handling: ChangeoutHandling;
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
  note: string | null;
}

export interface ChangeoutSummary {
  id: number;
  status: string;
  unit_designation: string;
  from_vehicle_number: string | null;
  to_vehicle_number: string | null;
  to_apparatus_id: number | null;
  station_id: number | null;
  effective_at: string | null;
  old_truck_status: string | null;
  alert_completed: boolean;
  return_of_changeout_id: number | null;
  submitted_by_name: string | null;
  moved: number;
  /** Named when the server names them. leftCount can be higher when it only sends a count. */
  left_behind: LeftItem[];
  leftCount: number;
  /** A client route for today's checkout on the new truck, or null. */
  next_daily_checkout_path: string | null;
  message: string | null;
  note: string | null;
  /** The typed number was not in the system, so a record was added and Fleet fills in the details. */
  new_truck_record: boolean;
  /** The unit that was listed on the new truck. It moved to the old truck. */
  displaced_unit: string | null;
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

export type ChangeoutStep = 'truck' | 'equipment' | 'review';

export interface ChangeoutDraft {
  version: 2;
  apparatusId: number;
  userId: number | null;
  alertId: number | null;
  /** Generated once per draft and reused on every retry. */
  clientSubmissionId: string;
  step: ChangeoutStep;
  choice: TruckChoice | null;
  /** The choice came from the plan or the link, not from the member, so it alone is not work to keep. */
  preselected: boolean;
  typedVehicle: string;
  /** Only the items left on the old truck, with the note for each. Everything else was taken. */
  left: Record<string, string>;
  note: string;
  /** A send was started and no definite answer came back, so the outcome is unknown. */
  attempted: boolean;
  savedAt: number;
}

export interface ResolvedTruck {
  apparatusId: number | null;
  vehicleNumber: string;
  location: string | null;
  source: 'candidate' | 'other' | 'typed' | 'current';
  isReturn: boolean;
  /** The real unit listed on this truck, which moves to the old truck when this one is taken. */
  listedAs: string | null;
}

/** Kept in the router's history state so a reload of the Done screen does not start a new changeout. */
export interface ChangeoutDoneState {
  summary: ChangeoutSummary;
  left: LeftItem[];
  stationPath: string;
}
