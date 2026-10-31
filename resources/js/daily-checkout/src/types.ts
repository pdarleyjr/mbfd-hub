import type { ChangeoutHandoff } from './components/changeout/types';
export type ApparatusType = 'engine' | 'ladder1' | 'ladder3' | 'rescue' | 'rope' | 'fireboat' | 'fire boat';
export type DailyCheckoutRequirement = 'required' | 'exempt' | 'reserve' | 'administrative' | 'inactive' | 'unknown';
export type DailyCheckoutState = 'checked' | 'attention' | 'review_pending' | 'not_checked' | 'out_of_service' | 'exempt' | 'classification_required';

export interface DailyCheckoutMatrixRow {
  apparatus_id: number;
  state: DailyCheckoutState;
  daily_checkout_requirement: DailyCheckoutRequirement;
  out_of_service: boolean;
  classification_required: boolean;
  included_in_required_total: boolean;
  included_in_completed: boolean;
  has_pending_submission: boolean;
  open_inspection_exceptions?: number;
  revision_requested?: boolean;
  return_checkout_required: boolean;
  return_checkout_verified: boolean;
}

export interface DailyCheckoutSummary {
  required_total: number;
  checked: number;
  attention: number;
  review_pending: number;
  not_checked: number;
  completed: number;
  out_of_service: number;
  exempt: number;
  classification_required: number;
  completion_percent: number | null;
  completion_available: boolean;
  matrix: DailyCheckoutMatrixRow[];
}

export interface PmHealthStatus {
  status: 'green' | 'yellow' | 'red';
  hours_since_pm: number;
  miles_since_pm: number;
  overdue: boolean;
  interval_hours: number;
  last_pm_date: string | null;
}

export interface Apparatus {
  id: number;
  name: string;
  unit_id?: string;
  type: ApparatusType;
  vehicle_number: string | null;
  designation?: string;
  slug: string;
  status?: string;
  daily_checkout_requirement?: DailyCheckoutRequirement;
  /** Unresolved missing/damaged equipment on this unit (station profile payload). */
  current_defects_count?: number;
  // PM Maintenance fields
  current_engine_hours?: number | null;
  current_miles?: number | null;
  meter_baseline_token?: string;
  last_pm_engine_hours?: number | null;
  last_pm_mileage?: number | null;
  last_pm_date?: string | null;
  pm_interval_hours?: number;
  // Computed PM health from API
  pm_health?: PmHealthStatus | null;
}

export type Rank = 'Chief' | 'Deputy Chief' | 'Captain' | 'Lieutenant' | 'Sergeant' | 'Corporal' | 'Firefighter';

export type Shift = 'A' | 'B' | 'C';

export interface EmployeeOption {
  id: number;
  name: string;
  rank: string | null;
}

export type SwapAnswer = 'swap_completed' | 'unknown';

/** One vehicle on the fleet list offered by the "confirm the physical vehicle" dropdown. */
export interface FleetVehicle {
  vehicle_number: string;
  designation: string | null;
  role: 'frontline' | 'reserve' | 'out_of_service';
}

/** The member's confirmation of the truck they are standing at; kept in the draft. */
export interface VehicleConfirmation {
  /** The vehicle assigned to the unit when this was confirmed. */
  assignedVehicle: string;
  /** Selected vehicle ID, or the "Not listed" marker. */
  choice: string;
  /** Typed ID when the vehicle is not listed. */
  typedVehicle: string;
  swapAnswer: SwapAnswer | '';
  note: string;
  confirmed: boolean;
}

/** Reported with the checkout; the server decides whether it differs from the assignment. */
export interface PhysicalVehicleReport {
  vehicle_number: string;
  swap_answer?: SwapAnswer;
  note?: string;
}

/** One open "one-time vehicle information" question. The server decides which are open. */
export interface VehicleIdentificationQuestion {
  /** Server field key, for example plate, vin, year. */
  field: string;
  label: string;
  hint: string | null;
  /** 'year' is a 4 digit number; everything else is text. */
  input: 'text' | 'year';
  max: number;
  /** What the Hub has on record, shown only when `confirm` is true. */
  current_value: string | null;
  /** The crew confirms the value on record or enters what is on the vehicle. */
  confirm: boolean;
}

export interface VehicleIdentificationReason {
  code: string;
  label: string;
  needs_note: boolean;
}

/** The server's block of open questions; kept outside the hashed checklist. */
export interface VehicleIdentification {
  note: string;
  /** False when this person may only read the questions (Fleet or a Captain 5 officer answers). Missing means true. */
  can_answer: boolean;
  kind: 'vehicle' | 'boat';
  questions: VehicleIdentificationQuestion[];
  reasons: VehicleIdentificationReason[];
  questions_version: string;
}

/**
 * One unit on a station page that needs one-time vehicle information but has no Daily Checkout or
 * Captain 5 page. The station detail read model carries a list of these under
 * `vehicle_identification_units` (empty or missing when none).
 */
export interface VehicleIdentificationUnit {
  apparatus_id: number;
  designation: string;
  vehicle_identification: VehicleIdentification;
}

/** The crew's working answer to one question. */
export interface VehicleIdentificationAnswer {
  /** '' means not answered yet. 'confirm' is only valid for questions that ask to confirm. */
  mode: '' | 'value' | 'confirm' | 'unavailable';
  value: string;
  reason: string;
  note: string;
}

/** Working answers keyed by question field; kept in the draft. */
export type VehicleIdentificationAnswers = Record<string, VehicleIdentificationAnswer>;

/** One answer as sent to the server. */
export type VehicleIdentificationSubmittedAnswer =
  | { field: string; value: string }
  | { field: string; unavailable_reason: string; note: string | null };

export interface VehicleIdentificationSubmission {
  questions_version: string;
  answers: VehicleIdentificationSubmittedAnswer[];
}

export interface OfficerInfo {
  name: string;
  rank: Rank;
  shift: Shift | '';
  unitNumber: string;
  employeeId?: number;
  vehicleConfirmation?: VehicleConfirmation;
}

export type ItemStatus = 'Present' | 'Missing' | 'Damaged';
export type ChecklistInputType = 'text' | 'number' | 'date' | 'checkbox' | 'percentage';
export type ChecklistFieldValue = string | number | boolean | null;

export interface ChecklistField {
  multiline?: boolean;
  id: string;
  name: string;
  inputType: ChecklistInputType;
  required: boolean;
}

export interface ScheduledChecklistTask {
  id: string;
  name: string;
  instructions?: string;
  recurrence: {
    type: 'weekday' | 'monthly_day';
    weekday?: string;
    day?: number;
  };
}

export interface InspectionSessionContract {
  id: string;
  token: string;
  issued_at: string;
  expires_at: string;
  duty_date: string;
  checklist_template_id: string;
  checklist_template_version: string;
  checklist_hash: string;
  due_tasks: ScheduledChecklistTask[];
  due_tasks_hash: string;
  replay_key: string;
}

export interface ScheduledChecklistTaskResult {
  observed?: boolean;
  id: string;
  status: ItemStatus;
  notes?: string | null;
}

export interface ChecklistItem {
  instructions?: string;
  id: string;
  name: string;
  status: ItemStatus;
  /** Explicit member observation; template defaults are not inspection evidence. */
  observed?: boolean;
  value?: ChecklistFieldValue;
  valueRequired?: boolean;
  notes?: string;
  photo?: string; // base64 encoded image
  inputType?: ChecklistInputType;
  expectedQuantity?: number;
  /** Display only: shown as one muted line in the expanded item details. */
  manufacturer?: string;
  model?: string;
  description?: string;
  /** Optional member claim: new damage that affects safe operation. Only meaningful while status is Damaged. */
  significant?: boolean;
}

export interface Compartment {
  id: string;
  name: string;
  items: ChecklistItem[];
}

/** A previously reported, still-open issue on this apparatus. The newer keys are optional until the server sends them. */
export interface OpenFinding {
  id: number;
  compartment: string;
  item: string;
  issue_type: string;
  operational_impact: string;
  last_observation: string | null;
  last_observed_at: string | null;
  service_status: string | null;
  /** Stable checklist ids; matched before display names when both are present. */
  compartment_id?: string;
  item_id?: string;
  action_status?: string;
  /** Member-facing corrective action wording, for example "Replacement available". */
  action_label?: string;
  severity?: 'routine' | 'significant';
  first_reported_at?: string | null;
  latest_member_update?: { text: string; at: string } | null;
}

/** A member's inventory correction report that Logistics has not decided yet (outside the hashed checklist). */
export interface PendingInventoryCorrection {
  /** Only for the signed-in member's own reports; another member's report is just a marker. */
  id: number | null;
  /** Null or absent for a report about an unlisted item that has no checklist item yet. */
  compartment_id?: string | null;
  item_id?: string | null;
  reason: string | null;
  reason_label?: string;
  status: string;
  reported_at?: string;
  /** True when the signed-in member filed it. */
  mine: boolean;
}

export interface ChecklistData {
  open_findings?: OpenFinding[];
  /** Hash of the baseline template without inventory overlays; equal across overlay-only changes. */
  checklist_base_version?: string;
  pending_corrections?: PendingInventoryCorrection[];
  /** Open one-time vehicle information questions. Absent when nothing is open. */
  vehicle_identification?: VehicleIdentification;
  /** Equipment the last changeout left on the old truck. Absent on older cached responses. */
  changeout_handoff?: ChangeoutHandoff;
  checklist_version: string;
  checklist_type?: string;
  schema_version: 1 | 2;
  template_id?: string;
  template_version?: string;
  inspection_date?: string;
  inspection_date_field_id?: string;
  fields: ChecklistField[];
  due_tasks: ScheduledChecklistTask[];
  inspection_session?: InspectionSessionContract;
  compartments: Compartment[];
}

export interface Defect {
  item: string;
  compartment: string;
  status: 'Missing' | 'Damaged';
  compartment_id?: string;
  item_id?: string;
  notes?: string;
  photo?: string;
  /** Present only when the member marked new damage that affects safe operation. */
  significant?: boolean;
}

/** One checklist item the member relates to a review flag. */
export interface ReviewFlagItemRef {
  compartment_id: string;
  item_id: string;
}

/** The member's request for further review by Logistics or Fleet. Sent only when the flag is checked. */
export interface ReviewFlag {
  explanation: string;
  items: ReviewFlagItemRef[];
}

export interface SubmittedChecklistItem {
  id: string;
  name: string;
  status: ItemStatus;
  observed: boolean;
  photo?: string;
  value?: ChecklistFieldValue;
  notes: string | null;
  significant?: true;
}

export interface SubmittedCompartment {
  id: string;
  name: string;
  items: SubmittedChecklistItem[];
}

export interface InspectionSubmission {
  processing_version?: 1;
  meter_baseline_token?: string;
  client_submission_id: string;
  checklist_version: string;
  operator_name: string;
  rank: string;
  shift: string;
  unit_number: string;
  employee_id?: number;
  /** The member's confirmed physical vehicle. Older queued submissions do not have it. */
  physical_vehicle?: PhysicalVehicleReport;
  /** Answers to the one-time vehicle information questions. Omitted when none were open. */
  vehicle_identification?: VehicleIdentificationSubmission;
  engine_hours?: number | null;
  miles?: number | null;
  defects: Defect[];
  compartments?: SubmittedCompartment[];
  field_values?: Array<{ id: string; value: ChecklistFieldValue }>;
  scheduled_tasks?: ScheduledChecklistTaskResult[];
  inspection_session_id?: string;
  inspection_session_token?: string;
  inspection_session_replay_key?: string;
  officer_signature?: string | null;
  /** Present only when the member checked "Flag this inspection for further review". */
  review_flag?: ReviewFlag;
  /** Links inventory corrections reported while this draft was open; the server treats it as optional. */
  inventory_draft_id?: string;
}

export interface MeterData {
  engine_hours: number | null;
  miles: number | null;
}

export interface ReviewFlagDraft {
  checked: boolean;
  explanation: string;
  items: ReviewFlagItemRef[];
}

export interface InspectionData {
  signature?: string | null;
  /** Draft state of the review flag; absent when never checked. */
  reviewFlag?: ReviewFlagDraft;
  /** Created once per draft; sent as `inspection_draft_id` with inventory corrections and `inventory_draft_id` with the inspection. */
  draftId?: string;
  /** Working answers to the one-time vehicle information questions. */
  vehicleIdentification?: VehicleIdentificationAnswers;
  actorUserId?: number;
  actorSecurityVersion?: number;
  checklist_version: string;
  apparatusSnapshot?: Apparatus;
  officer: OfficerInfo;
  meter: MeterData;
  compartments: Compartment[];
  fieldValues?: Array<{ id: string; value: ChecklistFieldValue }>;
  scheduledTasks?: ScheduledChecklistTaskResult[];
  inspectionSession?: InspectionSessionContract;
  checklistSnapshot?: ChecklistData;
}

export interface InspectionRevision {
  id: number;
  status: 'revision_requested' | 'revision_submitted';
  field: string;
  reason: string;
  submitted_value: string | number | null;
  current_value: string | number | null;
  reviewer_note: string | null;
  apparatus: { id: number; vehicle_number: string | null; name: string; unit_id: string | null };
}

// ============================================
// Station Types
// ============================================

export type ProjectStatus = 'planning' | 'in_progress' | 'on_hold' | 'completed' | 'cancelled';
export type ProjectPriority = 'low' | 'medium' | 'high' | 'critical';
export type RoomType = 'combat_apparatus_bay' | 'rescue_apparatus_bay' | 'support_apparatus_bay' | 'fireboat_apparatus_area' | 'apparatus_bay' | 'office' | 'dormitory' | 'kitchen' | 'common_area' | 'restroom' | 'laundry' | 'fitness' | 'storage' | 'utility' | 'exterior' | 'training_room' | 'meeting_room' | 'workshop' | 'other';
export type AssetCondition = 'new' | 'excellent' | 'good' | 'fair' | 'poor' | 'critical' | 'damaged' | 'needs_repair' | 'out_of_service' | 'obsolete' | 'retired';
export type AuditStatus = 'pending' | 'in_progress' | 'completed' | 'cancelled';
export type FindingType = 'surplus' | 'deficit' | 'damaged' | 'mislabeled' | 'expired' | 'other';
export type FindingStatus = 'open' | 'resolved' | 'pending_approval' | 'accepted';

export interface Station {
  id: number;
  name: string;
  address: string;
  city: string;
  state: string;
  zip_code: string;
  phone: string;
  fax?: string;
  station_number: number;
  latitude?: number;
  longitude?: number;
  is_active: boolean;
  notes?: string;
  created_at: string;
  updated_at: string;
  apparatuses_count?: number;
  active_apparatuses_count?: number;
  assigned_apparatus_count?: number | null;
  in_service_assigned_count?: number | null;
  assigned_personnel_count?: number | null;
  assigned_units?: string[];
  unavailable_assigned_units?: string[];
  unmatched_assigned_units?: string[];
  staffing_known?: boolean;
  rooms_count?: number;
  capital_projects_count?: number;
  under_25k_projects_count?: number;
  shop_works_count?: number;
  personnel_count?: number;
  dorm_beds_count?: number;
  /** Station photo recorded in Admin (stations.image_url), when the API provides it. */
  image_url?: string | null;
}

// Bundled station artwork (station_number → image path), the fallback when no photo is recorded.
// WebP derivatives (max 1200px wide, about 30-220 KB) of the original PNGs, which stay in the repo.
export const STATION_IMAGES: Record<number, string> = {
  1: '/images/stations/station-1.webp',
  2: '/images/stations/station-2.webp',
  3: '/images/stations/station-3.webp',
  4: '/images/stations/station-4.webp',
  6: '/images/stations/station-6.webp?v=0457f603b620',
};

export interface ImageSize {
  width: number;
  height: number;
}

/** Intrinsic size of each bundled image, so cards reserve their box before the image loads. */
export const BUNDLED_IMAGE_SIZES: Record<string, ImageSize> = {
  '/images/stations/station-1.webp': { width: 1200, height: 1200 },
  '/images/stations/station-2.webp': { width: 1200, height: 470 },
  '/images/stations/station-3.webp': { width: 1200, height: 742 },
  '/images/stations/station-4.webp': { width: 1200, height: 680 },
  '/images/stations/station-6.webp?v=0457f603b620': { width: 1200, height: 900 },
  '/images/stations/command-300-wide.webp': { width: 1200, height: 800 },
  '/images/stations/captain-5-wide.webp': { width: 1200, height: 800 },
};

/** Size of a bundled image; null for an uploaded photo whose dimensions are unknown. */
export function bundledImageSize(url: string | null): ImageSize | null {
  return url ? BUNDLED_IMAGE_SIZES[url] ?? null : null;
}

/** Prefers the recorded station photo (same-origin path or https URL) and falls back to the bundled image. */
export function stationImageUrl(station: Pick<Station, 'station_number' | 'image_url'>): string | null {
  const recorded = station.image_url?.trim();
  if (recorded && (/^\/(?!\/)[^\s\\]*$/.test(recorded) || /^https:\/\/[^\s\\]+$/i.test(recorded))) {
    return recorded;
  }

  return STATION_IMAGES[station.station_number] ?? null;
}

/** 300 Command is a unit profile, not a station row: it never has a station id or number.
 *  3:2 framing of the command SUV with margin on every side, so the cover crop used from xl up never clips the vehicle. */
export const COMMAND_300_IMAGE = '/images/stations/command-300-wide.webp';

/** Captain 5 (EMS Operations) is an operations workspace, not a station: same 3:2 framing as the command image. */
export const CAPTAIN_5_IMAGE = '/images/stations/captain-5-wide.webp';
export interface CommandOverviewApparatus {
  id: number;
  name: string | null;
  unit_id: string | null;
  type: string;
  status: string | null;
  service_state: 'in_service' | 'out_of_service' | 'unknown';
  open_defects: number;
  open_service_tickets: number;
  daily_checkout_state: string | null;
  last_checkout_at: string | null;
}

export interface CommandOverviewStation {
  /** Station database id: the /daily/stations/:id route key. */
  id: number;
  station_number: number | string;
  name: string;
  is_active: boolean;
  apparatus_count: number;
  open_defects: number;
  open_service_tickets: number;
  last_checkout_at: string | null;
  daily_checkout: {
    required_total: number;
    completed: number;
    not_checked: number;
    review_pending: number;
    out_of_service: number;
    completion_percent: number | null;
  };
  apparatuses: CommandOverviewApparatus[];
}

export interface CommandOverview {
  generated_at: string;
  total: number;
  stations: CommandOverviewStation[];
}

export interface StationInspectionSummary {
  id: number;
  inspection_date: string;
  inspection_type: string;
  overall_status: string;
  inspector_name: string;
  notes?: string;
  created_at: string;
}

export interface FireEquipmentRequestSummary {
  id: number;
  equipment_type: string;
  description: string;
  priority: string;
  status: string;
  requested_by_name: string;
  created_at: string;
}

export type StationRequestType = 'repair_service' | 'equipment';
export type StationRequestStatus =
  | 'pending' | 'acknowledged' | 'under_review' | 'approved' | 'scheduled'
  | 'ordered' | 'in_progress' | 'awaiting_parts' | 'awaiting_vendor'
  | 'on_hold' | 'completed' | 'denied' | 'cancelled';

export interface StationRequestItem {
  id: number;
  room_asset_id?: number | null;
  item_name: string;
  category?: string | null;
  quantity: number;
  reason?: string | null;
  requested_action?: string | null;
  condition?: string | null;
}

export interface StationRequestUpdate {
  id: number;
  status: StationRequestStatus;
  public_note?: string | null;
  created_at: string;
}

export interface StationRequestSummary {
  id: number;
  request_number: string;
  station_id: number;
  room_id?: number | null;
  room_name_snapshot?: string | null;
  room?: { id: number; name: string } | null;
  request_type: StationRequestType;
  subject_type?: string | null;
  title: string;
  description: string;
  priority: 'low' | 'normal' | 'high' | 'critical';
  status: StationRequestStatus;
  is_open: boolean;
  current_public_response?: string | null;
  acknowledged_at?: string | null;
  completed_at?: string | null;
  created_at: string;
  updated_at: string;
  items?: StationRequestItem[];
  updates?: StationRequestUpdate[];
}

/** A unit on a station's Overview for the current 7 AM to 7 AM window. Frontline units owe a daily inspection. */
export interface StationOverviewUnit {
  apparatus_id: number;
  designation: string;
  vehicle_number?: string | null;
  type?: string | null;
  slug?: string | null;
  frontline: boolean;
  state: 'submitted' | 'not_submitted' | 'out_of_service';
  inspection_id?: number | null;
  submitted_at?: string | null;
}

export interface StationCheckoutRow {
  id: number;
  apparatus_id: number;
  designation: string;
  vehicle_number?: string | null;
  type?: string | null;
  submitted_by?: string | null;
  shift?: string | null;
  submitted_at?: string | null;
  status: string;
  needs_attention: boolean;
}

export interface StationCheckoutDetail extends StationCheckoutRow {
  engine_hours?: string | null;
  miles?: number | null;
  compartments: Array<{
    name: string;
    items: Array<{ name: string; status: string; value?: string | null; notes?: string | null; has_photo: boolean }>;
  }>;
}

export interface StationOverview {
  station_id: number;
  as_of: string;
  shift_window: { starts_at: string; ends_at: string; alert_from: string };
  units: StationOverviewUnit[];
  checkouts: StationCheckoutRow[];
  requests: StationRequestSummary[];
  requests_total: number;
  alert: { active: boolean; units: StationOverviewUnit[] };
}

export type ApparatusServiceTicketStatus = 'submitted' | 'acknowledged' | 'scheduled' | 'in_progress' | 'waiting_for_parts' | 'completed' | 'cancelled';

export interface ApparatusServiceTicketUpdate {
  id: number;
  status: ApparatusServiceTicketStatus;
  public_note?: string | null;
  scheduled_for?: string | null;
  created_at: string;
}

export interface ApparatusServiceTicketSummary {
  id: number;
  ticket_number: string;
  apparatus_id: number;
  station_id?: number | null;
  unit_designation: string;
  origin: 'station' | 'fleet' | 'pm';
  category: 'repair_mechanical' | 'preventive_maintenance' | 'electrical' | 'specialty_system' | 'other';
  service_type?: string | null;
  title: string;
  priority: 'routine' | 'attention' | 'urgent';
  status: ApparatusServiceTicketStatus;
  is_open: boolean;
  scheduled_for?: string | null;
  scheduled_location?: string | null;
  expected_return_at?: string | null;
  current_public_response?: string | null;
  created_at: string;
  updated_at: string;
  updates?: ApparatusServiceTicketUpdate[];
}

export interface StationActivityEntry {
  type: 'apparatus_inspection' | 'station_inspection' | 'inventory_submission' | 'supply_request' | 'station_request' | 'apparatus_service_ticket';
  label: string;
  status: string;
  occurred_at: string;
  request_type?: StationRequestType;
  request_number?: string;
}

export interface RoomAssetEventSummary {
  id: number;
  room_asset_id: number;
  asset_name: string;
  request_number?: string | null;
  event_type: string;
  event_at: string;
}

export interface RoomProfile {
  room: Room;
  current_assets: RoomAsset[];
  open_requests: StationRequestSummary[];
  request_history: StationRequestSummary[];
  asset_events: RoomAssetEventSummary[];
}

export interface SingleGasMeterSummary {
  id: number;
  serial_number: string;
  activation_date: string;
  expiration_date: string;
  status: string;
  days_until_expiration: number;
  apparatus_name: string;
}

export interface StationDetail extends Station {
  apparatuses?: Apparatus[];
  active_apparatuses?: Apparatus[];
  rooms?: Room[];
  capital_projects?: CapitalProject[];
  active_capital_projects?: CapitalProject[];
  under_25k_projects?: Under25kProject[];
  active_under_25k_projects?: Under25kProject[];
  shop_works?: ShopWork[];
  active_shop_works?: ShopWork[];
  summary?: StationSummary;
  daily_checkout?: DailyCheckoutSummary;
  /** Units at this station with open one-time vehicle questions; empty or missing when none. Parse before use. */
  vehicle_identification_units?: VehicleIdentificationUnit[];
}

export interface StationSummary {
  total_apparatuses: number;
  active_apparatuses: number;
  total_personnel: number;
  dorm_beds_count: number;
  occupied_beds: number;
  available_beds: number;
  open_projects: number;
  pending_shop_works: number;
  total_rooms: number;
  active_assets: number;
  pending_audits: number;
}

export interface Room {
  id: number;
  station_id: number;
  name: string;
  blueprint_key?: string | null;
  sort_order?: number;
  room_number?: string;
  floor?: string;
  type: RoomType;
  room_type?: RoomType;
  capacity?: number;
  is_active: boolean;
  notes?: string;
  assets_count?: number;
  audits_count?: number;
  created_at?: string;
  updated_at?: string;
}

export interface RoomAsset {
  id: number;
  room_id: number;
  name: string;
  description?: string;
  asset_tag?: string;
  category?: string;
  quantity: number;
  unit: 'each' | 'box' | 'case' | 'set' | 'gallon' | 'pound' | 'dozen';
  condition: AssetCondition;
  location?: string;
  serial_number?: string;
  purchase_date?: string;
  purchase_price?: number;
  useful_life_years?: number;
  depreciation_rate?: number;
  last_audit_date?: string;
  is_active: boolean;
  created_at?: string;
  updated_at?: string;
}

export interface RoomAudit {
  id: number;
  room_id: number;
  audit_type: 'physical_count' | 'random_spot' | 'annual' | 'incident' | 'transfer';
  audit_status: 'pending' | 'in_progress' | 'completed' | 'cancelled';
  audited_by?: string;
  audit_date?: string;
  items_checked?: number;
  discrepancies?: number;
  notes?: string;
  findings?: string;
  recommendations?: string;
  started_at?: string;
  completed_at?: string;
  created_at?: string;
  updated_at?: string;
}

export interface CapitalProject {
  id: number;
  project_number: string;
  title: string;
  description?: string;
  station_id?: number;
  budget: number;
  spent: number;
  status: ProjectStatus;
  priority: ProjectPriority;
  start_date?: string;
  estimated_completion?: string;
  actual_completion?: string;
  project_manager?: string;
  vendor?: string;
  is_approved: boolean;
  approved_by?: string;
  approved_at?: string;
  created_at: string;
  updated_at: string;
  station?: Station;
}

export interface Under25kProject {
  id: number;
  project_number: string;
  title: string;
  description?: string;
  station_id?: number;
  budget: number;
  spent: number;
  status: ProjectStatus;
  priority: ProjectPriority;
  start_date?: string;
  estimated_completion?: string;
  actual_completion?: string;
  project_lead?: string;
  vendor?: string;
  is_approved: boolean;
  approved_by?: string;
  approved_at?: string;
  created_at: string;
  updated_at: string;
  station?: Station;
}

export interface ShopWork {
  id: number;
  work_order_number: string;
  title: string;
  description?: string;
  station_id?: number;
  apparatus_id?: number;
  priority: ProjectPriority;
  status: ProjectStatus;
  work_type?: string;
  requested_by?: string;
  assigned_to?: string;
  estimated_hours?: number;
  actual_hours?: number;
  labor_cost?: number;
  parts_cost?: number;
  total_cost?: number;
  start_date?: string;
  estimated_completion?: string;
  actual_completion?: string;
  is_warranty_work: boolean;
  is_insurance_claim: boolean;
  created_at: string;
  updated_at: string;
  station?: Station;
  apparatus?: Apparatus;
}

export interface User {
  id: number;
  name: string;
  email: string;
  role?: string;
  shift?: Shift;
  rank?: Rank;
  station_id?: number;
  is_active: boolean;
}

// ============================================
// Big Ticket Request Types
// ============================================

export type BigTicketRoomType = 'kitchen' | 'common_areas' | 'dorms' | 'apparatus_bay' | 'watch_office';

export interface BigTicketItem {
  id: string;
  name: string;
  category: string;
}

export interface BigTicketRequest {
  id: number;
  station_id: number;
  room_type: string;
  room_label?: string;
  items: string[];
  other_item?: string;
  notes?: string;
  created_by: number | null;
  created_at: string;
  updated_at: string;
}

export interface BigTicketRequestFormData {
  station_id: number;
  room_type: BigTicketRoomType;
  room_label?: string;
  items: string[];
  other_item?: string;
  notes?: string;
}

// ============================================
// Station Inventory Types
// ============================================

export interface InventoryCategory {
  id: string;
  name: string;
  items: InventoryItem[];
}

export interface InventoryItem {
  id: string;
  name: string;
  unit: string;
  max_quantity: number;
}

export interface InventorySubmissionItem {
  category_id: string;
  item_id: string;
  quantity: number;
}

export interface StationInventorySubmission {
  id: number;
  station_id: number;
  items: InventorySubmissionItem[];
  notes?: string;
  submitted_by: number;
  pdf_path?: string;
  created_at: string;
}

// ============================================
// Station Inventory V2 Types
// ============================================

export interface InventoryV2Category {
  name: string;
  items: InventoryV2Item[];
}

export interface InventoryV2Item {
  id: number;
  sku: string;
  name: string;
  unit_label: string;
  par: number;
  par_units: number;
  on_hand: number;
  status: 'ok' | 'low' | 'ordered';
}

export interface InventoryV2Response {
  success: boolean;
  station: {
    id: number;
    name: string;
    station_number: string;
  };
  inventory: Array<{
    category: string;
    items: Array<{
      id: number;
      inventory_item_id: number;
      name: string;
      sku: string;
      unit_label: string;
      par_quantity: number;
      par_units: number;
      on_hand: number;
      status: 'ok' | 'low' | 'ordered';
      last_updated_at: string | null;
    }>;
  }>;
}

export interface SupplyRequest {
  id: number;
  request_text: string;
  status: 'open' | 'ordered' | 'denied' | 'replenished';
  public_response?: string | null;
  created_by_name: string;
  created_by_shift: Shift;
  created_at: string;
  updated_at: string;
}

export interface UpdateItemRequest {
  on_hand: number;
  actor_shift: Shift;
}

export interface CreateSupplyRequestRequest {
  request_text: string;
  actor_shift: Shift;
}
