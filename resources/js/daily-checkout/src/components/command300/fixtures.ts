import type { BoardStation, CommandBoard, StationDetail } from './types';

export const station: BoardStation = {
  id: 41,
  station_number: 1,
  name: 'Station 1',
  attention: { level: 'watch', reasons: ['1 truck not checked out', '1 vehicle with open issues'] },
  crew: {
    available: true,
    message: null,
    shift: 'A',
    working_count: 3,
    away_count: 1,
    members: [
      { name: 'SMITH, JANE', rank: 'Lt', unit: 'Engine 1', presence: 'working', status_label: 'SP', status_tone: 'sp' },
      { name: 'DOE, JOHN', rank: 'FF', unit: 'Engine 1', presence: 'working', status_label: 'SP', status_tone: 'sp' },
      { name: 'LEE, SAM', rank: 'FF', unit: 'Ladder 1', presence: 'working', status_label: 'OT', status_tone: 'ot' },
      { name: 'AWAY, ALEX', rank: 'FF', unit: 'Ladder 1', presence: 'away', status_label: 'VACA', status_tone: 'vaca' },
    ],
  },
  checkouts: {
    expected: 2,
    done: 1,
    missing: 1,
    out_of_service: 0,
    units: [
      { apparatus_id: 1, designation: 'E1', type: 'Engine', frontline: true, state: 'submitted', inspection_id: 900, submitted_at: '2026-11-05T13:00:00Z', submitted_by: 'Captain Sam Lee', open_issues: 1 },
      { apparatus_id: 2, designation: 'L1', type: 'Ladder', frontline: true, state: 'not_submitted', inspection_id: null, submitted_at: null, submitted_by: null, open_issues: 0 },
    ],
  },
  requests: {
    open: 2,
    pending: 1,
    new_today: 1,
    updated_recently: 1,
    items: [{ id: 5, request_number: 'SR-5', title: 'Broken recliner', status: 'pending', priority: 'normal', created_at: '2026-11-05T12:00:00Z', last_update_at: null }],
  },
  vehicle_issues: { units_with_issues: 1, defects: 1, tickets: 0, units: [{ apparatus_id: 1, designation: 'E1', defect_count: 1, ticket_count: 0, top: [{ kind: 'defect', label: 'Cracked mirror', significant: false }] }] },
};

export const board: CommandBoard = {
  generated_at: '2026-11-05T15:00:00Z',
  shift_window: { starts_at: '2026-11-05T12:00:00Z', ends_at: '2026-11-06T12:00:00Z' },
  roster: { available: true, message: null, shift: 'A', date_label: 'Thursday, November 5' },
  summary: {
    stations: 1, checkouts_done: 1, checkouts_expected: 2, requests_open: 2, requests_pending: 1, requests_new_today: 1,
    vehicles_with_issues: 1, units_out_of_service: 0, crew_working: 3, stations_needing_attention: 0,
  },
  stations: [station],
  total: 1,
};

export const detail: StationDetail = {
  station: { id: 41, station_number: 1, name: 'Station 1' },
  as_of: '2026-11-05T15:00:00Z',
  shift_window: board.shift_window,
  crew: {
    ...station.crew,
    date_label: 'Thursday, November 5',
    window: '08:00 to 08:00',
    units: [{
      unit: 'Engine 1',
      members: [{ name: 'SMITH, JANE', rank: 'Lt', is_vacant: false, presence: 'working', status_label: 'SP', status_tone: 'sp', from: '08:00', through: '08:00' }],
    }],
  },
  units: [
    {
      ...station.checkouts.units[0],
      checkout: { id: 900, apparatus_id: 1, designation: 'E1', type: 'Engine', submitted_by: 'Captain Sam Lee', shift: 'A', submitted_at: '2026-11-05T13:00:00Z', status: 'Approved', needs_attention: false },
    },
    { ...station.checkouts.units[1], checkout: null },
  ],
  checkouts: [],
  requests: [{
    id: 5, request_number: 'SR-5', title: 'Broken recliner', description: 'Arm is loose.', status: 'acknowledged', priority: 'normal', request_type: 'equipment', is_open: true,
    room_name: 'Day room', created_at: '2026-11-05T12:00:00Z', updated_at: '2026-11-05T14:00:00Z', current_public_response: null, items: [],
    updates: [{ id: 1, status: 'acknowledged', public_note: 'Looking into it', created_at: '2026-11-05T14:00:00Z', sender: null }],
  }],
  requests_total: 1,
  vehicle_issues: [{
    apparatus_id: 1, designation: 'E1', defect_count: 1, ticket_count: 0, tickets: [],
    defects: [{ id: 3, compartment: 'Cab', item: 'Cracked mirror', issue_type: 'damaged', severity: 'significant', reported_date: '2026-11-04', notes: null, from_this_checkout: false }],
  }],
};
