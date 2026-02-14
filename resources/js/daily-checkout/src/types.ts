export type ApparatusType = 'engine' | 'ladder1' | 'ladder3' | 'rescue' | 'rope';

export interface Apparatus {
  id: number;
  name: string;
  unit_id?: string;
  type: ApparatusType;
  vehicle_number: string;
  slug: string;
}

export type Rank = 'Chief' | 'Deputy Chief' | 'Captain' | 'Lieutenant' | 'Sergeant' | 'Corporal' | 'Firefighter';

export type Shift = 'A' | 'B' | 'C';

export interface OfficerInfo {
  name: string;
  rank: Rank;
  shift: Shift;
  unitNumber: string;
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
  operator_name: string;
  rank: string;
  shift: string;
  unit_number: string;
  defects: Defect[];
}

export interface InspectionData {
  officer: OfficerInfo;
  compartments: Compartment[];
}

// ============================================
// Station Types
// ============================================

export type ProjectStatus = 'planning' | 'in_progress' | 'on_hold' | 'completed' | 'cancelled';
export type ProjectPriority = 'low' | 'medium' | 'high' | 'critical';
export type RoomType = 'apparatus_bay' | 'office' | 'dormitory' | 'kitchen' | 'restroom' | 'storage' | 'training_room' | 'meeting_room' | 'workshop' | 'other';
export type AssetCondition = 'excellent' | 'good' | 'fair' | 'poor' | 'critical' | 'damaged' | 'obsolete';
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
  rooms_count?: number;
  capital_projects_count?: number;
  under_25k_projects_count?: number;
  shop_works_count?: number;
  personnel_count?: number;
  dorm_beds_count?: number;
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
  room_number?: string;
  floor?: string;
  type: 'apparatus_bay' | 'office' | 'training_room' | 'kitchen' | 'dormitory' | 'restroom' | 'storage' | 'workshop' | 'other';
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
  created_by: number;
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