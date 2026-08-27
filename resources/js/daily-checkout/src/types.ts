export type ApparatusType = 'engine' | 'ladder1' | 'ladder3' | 'rescue' | 'rope';
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
  vehicle_number: string;
  designation?: string;
  slug: string;
  status?: string;
  daily_checkout_requirement?: DailyCheckoutRequirement;
  // PM Maintenance fields
  current_engine_hours?: number | null;
  current_miles?: number | null;
  last_pm_engine_hours?: number | null;
  last_pm_mileage?: number | null;
  last_pm_date?: string | null;
  pm_interval_hours?: number;
  // Computed PM health from API
  pm_health?: PmHealthStatus;
}

export type Rank = 'Chief' | 'Deputy Chief' | 'Captain' | 'Lieutenant' | 'Sergeant' | 'Corporal' | 'Firefighter';

export type Shift = 'A' | 'B' | 'C';

export interface EmployeeOption {
  id: number;
  name: string;
  rank: string | null;
}

export interface OfficerInfo {
  name: string;
  rank: Rank;
  shift: Shift;
  unitNumber: string;
  employeeId?: number;
}

export type ItemStatus = 'Present' | 'Missing' | 'Damaged';

export interface ChecklistItem {
  id: string;
  name: string;
  status: ItemStatus;
  notes?: string;
  photo?: string; // base64 encoded image
}

export interface Compartment {
  id: string;
  name: string;
  items: ChecklistItem[];
}

export interface ChecklistData {
  checklist_version: string;
  compartments: Compartment[];
}

export interface Defect {
  item: string;
  compartment: string;
  status: 'Missing' | 'Damaged';
  notes?: string;
  photo?: string;
}

export interface InspectionSubmission {
  client_submission_id: string;
  checklist_version: string;
  operator_name: string;
  rank: string;
  shift: string;
  unit_number: string;
  employee_id?: number;
  engine_hours?: number | null;
  miles?: number | null;
  defects: Defect[];
  compartments?: unknown[];
  officer_signature?: string | null;
}

export interface MeterData {
  engine_hours: number | null;
  miles: number | null;
}

export interface InspectionData {
  checklist_version: string;
  officer: OfficerInfo;
  meter: MeterData;
  compartments: Compartment[];
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
}

// Station image mapping (station_number → image path)
export const STATION_IMAGES: Record<number, string> = {
  1: '/images/stations/station-1.png',
  2: '/images/stations/station-2.png',
  3: '/images/stations/station-3.png',
  4: '/images/stations/station-4.png',
  6: '/images/stations/station-6.png',
};

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
  created_by_name: string;
  created_by_shift: Shift;
  created_at: string;
  updated_at: string;
}

export interface PINVerifyRequest {
  station_id: number;
  pin: string;
  actor_name: string;
  actor_shift: Shift;
}

export interface PINVerifyResponse {
  success: boolean;
  station_id: number; // Canonical PK
  station: {
    id: number;
    name: string;
    station_number: string;
    address: string;
  };
  // Absolute signed URLs - use as-is, do NOT concatenate
  inventory_url: string;
  supply_requests_url: string;
  message?: string;
}

export interface UpdateItemRequest {
  on_hand: number;
  actor_name: string;
  actor_shift: Shift;
}

export interface CreateSupplyRequestRequest {
  request_text: string;
  actor_name: string;
  actor_shift: Shift;
}
