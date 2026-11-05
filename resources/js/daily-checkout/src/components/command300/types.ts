import type { StationCheckoutDetail, StationCheckoutRow } from '../../types';

/** Payloads of GET /api/public/command/stations (the 300 board). All read-only; see Command300StationBoard.php. */

export type AttentionLevel = 'ok' | 'watch' | 'attention';
export type Presence = 'working' | 'away' | 'unknown';

export interface BoardCrewMember {
  name: string;
  rank: string;
  unit: string;
  presence: Presence;
  status_label: string;
  status_tone: string;
}

export interface BoardCrew {
  available: boolean;
  message: string | null;
  shift?: string | null;
  working_count: number;
  away_count: number;
  members: BoardCrewMember[];
}

export interface BoardUnit {
  apparatus_id: number;
  designation: string;
  vehicle_number?: string | null;
  type?: string | null;
  frontline: boolean;
  state: 'submitted' | 'not_submitted' | 'out_of_service';
  inspection_id: number | null;
  submitted_at: string | null;
  submitted_by: string | null;
  open_issues: number;
}

export interface BoardRequestItem {
  id: number;
  request_number: string;
  title: string;
  status: string;
  priority: string;
  created_at: string | null;
  last_update_at: string | null;
}

export interface BoardIssueUnit {
  apparatus_id: number;
  designation: string;
  defect_count: number;
  ticket_count: number;
  top: Array<{ kind: 'defect' | 'ticket'; label: string; significant: boolean }>;
}

export interface BoardStation {
  id: number;
  station_number: number | string;
  name: string;
  attention: { level: AttentionLevel; reasons: string[] };
  crew: BoardCrew;
  checkouts: { expected: number; done: number; missing: number; out_of_service: number; units: BoardUnit[] };
  requests: { open: number; pending: number; new_today: number; updated_recently: number; items: BoardRequestItem[] };
  vehicle_issues: { units_with_issues: number; defects: number; tickets: number; units: BoardIssueUnit[] };
}

export interface BoardSummary {
  stations: number;
  checkouts_done: number;
  checkouts_expected: number;
  requests_open: number;
  requests_pending: number;
  requests_new_today: number;
  vehicles_with_issues: number;
  units_out_of_service: number;
  crew_working: number;
  stations_needing_attention: number;
}

export interface CommandBoard {
  generated_at: string;
  shift_window: { starts_at: string; ends_at: string };
  roster: { available: boolean; message: string | null; shift: string | null; date_label: string | null };
  summary: BoardSummary;
  stations: BoardStation[];
  total: number;
}

export interface DetailCrewMember {
  name: string;
  rank: string;
  is_vacant: boolean;
  presence: Presence | 'vacant';
  status_label: string;
  status_tone: string;
  from: string;
  through: string;
}

export interface DetailCrew extends BoardCrew {
  units: Array<{ unit: string; members: DetailCrewMember[] }>;
  date_label: string | null;
  window: string | null;
  notice?: string | null;
}

export interface IssueDefect {
  id: number;
  compartment: string | null;
  item: string | null;
  issue_type: string | null;
  severity: 'routine' | 'significant';
  reported_date: string | null;
  notes: string | null;
  from_this_checkout: boolean;
}

export interface IssueTicket {
  id: number;
  ticket_number: string;
  title: string;
  status: string;
  priority: string;
  created_at: string | null;
}

export interface UnitIssues {
  defects: IssueDefect[];
  tickets: IssueTicket[];
  defect_count: number;
  ticket_count: number;
}

export interface DetailUnit extends BoardUnit {
  checkout: StationCheckoutRow | null;
}

export interface DetailRequest {
  id: number;
  request_number: string;
  title: string;
  description: string | null;
  status: string;
  priority: string;
  request_type: string;
  is_open: boolean;
  room_name: string | null;
  created_at: string | null;
  updated_at: string | null;
  current_public_response: string | null;
  items: Array<{ id: number; item_name: string; quantity: number | null; reason: string | null }>;
  updates: Array<{ id: number; status: string | null; public_note: string | null; created_at: string | null; sender: string | null }>;
}

export interface StationDetail {
  station: { id: number; station_number: number | string; name: string };
  as_of: string;
  shift_window: { starts_at: string; ends_at: string };
  crew: DetailCrew;
  units: DetailUnit[];
  checkouts: StationCheckoutRow[];
  requests: DetailRequest[];
  requests_total: number;
  vehicle_issues: Array<UnitIssues & { apparatus_id: number; designation: string; vehicle_number?: string | null; type?: string | null }>;
}

export interface CommandCheckoutDetail extends StationCheckoutDetail {
  issues: UnitIssues;
}
