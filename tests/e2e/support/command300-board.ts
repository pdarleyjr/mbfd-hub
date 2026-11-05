import type { Route } from '@playwright/test';

/**
 * Mock of the 300 station board contract: GET /api/public/command/stations (+ /{id} and /{id}/checkouts/{id}).
 * Shapes follow App\Services\Command300\Command300StationBoard and components/command300/types.ts.
 */

export const BOARD_BASE = '/api/public/command/stations';

const ago = (minutes: number): string => new Date(Date.now() - minutes * 60_000).toISOString();

type Json = Record<string, unknown>;

export interface BoardServer {
  /** HTTP status for the list; anything but 200 returns a message body. */
  status: number;
  requests: string[];
  stations: Json[];
  details: Record<number, Json>;
  checkouts: Record<number, Json>;
  summary: Json;
  roster: Json;
}

const crew = (names: Array<[string, string, string, 'working' | 'away']>) => ({
  available: true,
  message: null,
  shift: 'A',
  working_count: names.filter((n) => n[3] === 'working').length,
  away_count: names.filter((n) => n[3] === 'away').length,
  members: names.map(([name, rank, unit, presence]) => ({ name, rank, unit, presence, status_label: presence === 'working' ? 'SP' : 'VACA', status_tone: presence === 'working' ? 'sp' : 'vaca' })),
});

const unit = (id: number, designation: string, type: string, state: 'submitted' | 'not_submitted' | 'out_of_service', minutes: number | null, by: string | null, issues = 0) => ({
  apparatus_id: id, designation, vehicle_number: designation, type, frontline: true, state,
  inspection_id: state === 'submitted' ? 900 + id : null, submitted_at: minutes === null ? null : ago(minutes), submitted_by: by, open_issues: issues,
});

export function boardStations(): Json[] {
  return [
    {
      id: 41, station_number: 1, name: 'Station 1',
      attention: { level: 'watch', reasons: ['1 truck not checked out', '1 vehicle with open issues'] },
      crew: crew([['SMITH, JANE', 'Lt', 'Engine 1', 'working'], ['DOE, JOHN', 'FF', 'Engine 1', 'working'], ['LEE, SAM', 'FF', 'Ladder 1', 'working'], ['AWAY, ALEX', 'FF', 'Ladder 1', 'away']]),
      checkouts: { expected: 3, done: 2, missing: 1, out_of_service: 0, units: [unit(1, 'E1', 'Engine', 'submitted', 95, 'Captain Sam Lee', 1), unit(2, 'L1', 'Ladder', 'submitted', 80, 'Lt Jane Smith'), unit(3, 'R1', 'Rescue', 'not_submitted', null, null)] },
      requests: { open: 2, pending: 1, new_today: 1, updated_recently: 1, items: [
        { id: 5, request_number: 'SR-5', title: 'Broken recliner in the day room', status: 'pending', priority: 'normal', created_at: ago(120), last_update_at: null },
        { id: 6, request_number: 'SR-6', title: 'Leaky faucet', status: 'in_progress', priority: 'normal', created_at: ago(3000), last_update_at: ago(60) },
      ] },
      vehicle_issues: { units_with_issues: 1, defects: 1, tickets: 0, units: [{ apparatus_id: 1, designation: 'E1', defect_count: 1, ticket_count: 0, top: [{ kind: 'defect', label: 'Cracked mirror', significant: false }] }] },
    },
    {
      id: 42, station_number: 2, name: 'Station 2',
      attention: { level: 'attention', reasons: ['Significant damage reported', '1 unit out of service'] },
      crew: crew([['RIVERA, ALEX', 'Capt', 'Engine 2', 'working'], ['NGUYEN, PAT', 'FF', 'Engine 2', 'working']]),
      checkouts: { expected: 1, done: 1, missing: 0, out_of_service: 1, units: [unit(4, 'E2', 'Engine', 'submitted', 30, 'Captain Alex Rivera', 2), { ...unit(5, 'R2', 'Rescue', 'out_of_service', null, null), frontline: true }] },
      requests: { open: 0, pending: 0, new_today: 0, updated_recently: 0, items: [] },
      vehicle_issues: { units_with_issues: 1, defects: 1, tickets: 1, units: [{ apparatus_id: 4, designation: 'E2', defect_count: 1, ticket_count: 1, top: [{ kind: 'defect', label: 'Brake warning light', significant: true }] }] },
    },
    {
      id: 43, station_number: 3, name: 'Station 3',
      attention: { level: 'ok', reasons: [] },
      crew: { available: true, message: null, shift: 'A', working_count: 0, away_count: 0, members: [] },
      checkouts: { expected: 1, done: 1, missing: 0, out_of_service: 0, units: [unit(6, 'E3', 'Engine', 'submitted', 20, 'Lt Chris Park')] },
      requests: { open: 0, pending: 0, new_today: 0, updated_recently: 0, items: [] },
      vehicle_issues: { units_with_issues: 0, defects: 0, tickets: 0, units: [] },
    },
  ];
}

export function newBoardServer(overrides: Partial<BoardServer> = {}): BoardServer {
  const stations = boardStations();
  return {
    status: 200,
    requests: [],
    stations,
    summary: {
      stations: 3, checkouts_done: 4, checkouts_expected: 5, requests_open: 2, requests_pending: 1, requests_new_today: 1,
      vehicles_with_issues: 2, units_out_of_service: 1, crew_working: 5, stations_needing_attention: 1,
    },
    roster: { available: true, message: null, shift: 'A', date_label: 'Thursday, November 5' },
    details: {
      41: {
        station: { id: 41, station_number: 1, name: 'Station 1' },
        as_of: new Date().toISOString(),
        shift_window: { starts_at: ago(300), ends_at: ago(-1140) },
        crew: {
          ...(stations[0].crew as Json), date_label: 'Thursday, November 5', window: '08:00 to 08:00',
          units: [
            { unit: 'Engine 1', members: [
              { name: 'SMITH, JANE', rank: 'Lt', is_vacant: false, presence: 'working', status_label: 'SP', status_tone: 'sp', from: '08:00', through: '08:00' },
              { name: 'DOE, JOHN', rank: 'FF', is_vacant: false, presence: 'working', status_label: 'SP', status_tone: 'sp', from: '08:00', through: '08:00' },
            ] },
            { unit: 'Ladder 1', members: [
              { name: 'LEE, SAM', rank: 'FF', is_vacant: false, presence: 'working', status_label: 'OT', status_tone: 'ot', from: '08:00', through: '08:00' },
              { name: 'AWAY, ALEX', rank: 'FF', is_vacant: false, presence: 'away', status_label: 'VACA', status_tone: 'vaca', from: '08:00', through: '08:00' },
            ] },
          ],
        },
        units: (stations[0].checkouts as { units: Json[] }).units.map((u) => ({
          ...u,
          checkout: u.state === 'submitted' ? { id: u.inspection_id, apparatus_id: u.apparatus_id, designation: u.designation, type: u.type, submitted_by: u.submitted_by, shift: 'A', submitted_at: u.submitted_at, status: 'Approved', needs_attention: false } : null,
        })),
        checkouts: [],
        requests: [
          {
            id: 5, request_number: 'SR-5', title: 'Broken recliner in the day room', description: 'The arm is loose and unsafe.', status: 'pending', priority: 'normal', request_type: 'equipment', is_open: true,
            room_name: 'Day room', created_at: ago(120), updated_at: ago(120), current_public_response: null, items: [{ id: 1, item_name: 'Recliner', quantity: 1, reason: 'Arm broken' }], updates: [],
          },
          {
            id: 6, request_number: 'SR-6', title: 'Leaky faucet', description: 'Kitchen sink drips.', status: 'in_progress', priority: 'high', request_type: 'repair_service', is_open: true,
            room_name: 'Kitchen', created_at: ago(3000), updated_at: ago(60), current_public_response: 'A plumber is booked for Friday.', items: [],
            updates: [{ id: 10, status: 'acknowledged', public_note: 'Seen by Support Services', created_at: ago(2800), sender: null }, { id: 11, status: 'in_progress', public_note: 'A plumber is booked for Friday.', created_at: ago(60), sender: null }],
          },
        ],
        requests_total: 2,
        vehicle_issues: [{
          apparatus_id: 1, designation: 'E1', vehicle_number: 'E1', type: 'Engine', defect_count: 1, ticket_count: 0, tickets: [],
          defects: [{ id: 3, compartment: 'Cab', item: 'Cracked mirror', issue_type: 'damaged', severity: 'routine', reported_date: '2026-11-04', notes: 'Passenger side', from_this_checkout: true }],
        }],
      },
    },
    checkouts: {
      901: {
        id: 901, apparatus_id: 1, designation: 'E1', vehicle_number: 'E1', type: 'Engine', submitted_by: 'Captain Sam Lee', shift: 'A', submitted_at: ago(95), status: 'Approved', needs_attention: false,
        miles: 41250, engine_hours: '3120.5',
        compartments: [
          { name: 'Cab', items: [{ name: 'Mirror', status: 'damaged', value: null, notes: 'Cracked, passenger side', has_photo: false }, { name: 'Seat belts', status: 'present', value: null, notes: null, has_photo: false }] },
          { name: 'Officer side', items: [{ name: 'SCBA', status: 'present', value: null, notes: null, has_photo: false }] },
        ],
        issues: { defect_count: 1, ticket_count: 0, tickets: [], defects: [{ id: 3, compartment: 'Cab', item: 'Cracked mirror', issue_type: 'damaged', severity: 'routine', reported_date: '2026-11-04', notes: 'Passenger side', from_this_checkout: true }] },
      },
    },
    ...overrides,
  };
}

/** Returns true when it handled the route. */
export async function handleBoardRoute(route: Route, pathname: string, server: BoardServer): Promise<boolean> {
  if (!pathname.startsWith(BOARD_BASE)) return false;
  server.requests.push(`${route.request().method()} ${pathname}`);
  if (server.status !== 200) {
    await route.fulfill({ status: server.status, json: { message: server.status === 403 ? 'Forbidden' : 'Service unavailable' } });
    return true;
  }
  const rest = pathname.slice(BOARD_BASE.length);
  if (rest === '' || rest === '/') {
    await route.fulfill({
      json: { generated_at: new Date().toISOString(), shift_window: { starts_at: ago(300), ends_at: ago(-1140) }, roster: server.roster, summary: server.summary, stations: server.stations, total: server.stations.length },
    });
    return true;
  }
  const checkout = /^\/(\d+)\/checkouts\/(\d+)$/.exec(rest);
  if (checkout) {
    const body = server.checkouts[Number(checkout[2])];
    await (body ? route.fulfill({ json: { checkout: body } }) : route.fulfill({ status: 404, json: { message: 'Not found' } }));
    return true;
  }
  const station = /^\/(\d+)$/.exec(rest);
  if (station) {
    const body = server.details[Number(station[1])];
    await (body ? route.fulfill({ json: body }) : route.fulfill({ status: 404, json: { message: 'Not found' } }));
    return true;
  }
  await route.fulfill({ status: 404, json: { message: 'Unmocked route' } });
  return true;
}
