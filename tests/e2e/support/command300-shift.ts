import type { Route } from '@playwright/test';
import type { ActingRequest, ActingState, OnDuty } from '../../../resources/js/daily-checkout/src/components/acting/types';

/**
 * A mocked /api/command-300 (the shift's 300 and the acting-300 requests), shared by every spec that renders the
 * 300 page or the 300 card on the stations list.
 *   GET  /shift                         -> { on_duty, acting }
 *   POST /acting-requests               -> 201 { on_duty, acting }
 *   POST /acting-requests/{id}/approve | reject | withdraw -> { on_duty, acting }
 */
export const SHIFT_BASE = '/api/command-300';

export const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export const minutesAgo = (minutes: number): string => new Date(Date.now() - minutes * 60_000).toISOString();
export const inHours = (hours: number): string => new Date(Date.now() + hours * 3_600_000).toISOString();
export const ymd = (offsetDays: number): string => {
  const date = new Date(Date.now() + offsetDays * 86_400_000);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

export type Duty = Omit<OnDuty, 'duty_date'>;

export const chiefOnDuty = (shift: OnDuty['shift'] = 'A', name = 'Jane Smith', rank: string | null = 'Division Chief'): Duty => (
  { shift, source: 'bid_holder', resolved: true, name, rank, acting_until: null, acting_request_id: null }
);
export const actingChief = (shift: OnDuty['shift'], name: string, rank: string | null = 'Battalion Chief', until = inHours(10)): Duty => (
  { shift, source: 'acting', resolved: true, name, rank, acting_until: until, acting_request_id: 8 }
);
export const UNSET_DUTY: Duty = { shift: null, source: 'unknown', resolved: false, name: null, rank: null, acting_until: null, acting_request_id: null };

/** 09:14 ET on a fixed day, so the note's clock text is the same wherever the test runs. */
export const ROSTER_AS_OF = '2026-10-30T13:14:15+00:00';
export const rosterChief = (shift: OnDuty['shift'] = 'B', name = 'Jane Smith', rank: string | null = 'Division Chief', identity: 'linked' | 'unlinked' = 'linked'): Duty => (
  { ...chiefOnDuty(shift, name, rank), source: 'telestaff', as_of: ROSTER_AS_OF, freshness: 'current', identity, reason: null }
);
export const seatAwaiting = (freshness: 'awaiting' | 'delayed'): Duty => ({ ...chiefOnDuty('B'), as_of: null, freshness, identity: null, reason: null });
export const rosterUnknown = (reason: 'conflict' | 'needs_review' | 'no_working_row'): Duty => (
  { shift: 'B', source: 'unknown', resolved: false, name: null, rank: null, acting_until: null, acting_request_id: null, as_of: ROSTER_AS_OF, freshness: 'current', identity: null, reason }
);

export const noActing300 = (overrides: Partial<ActingState> = {}): ActingState => ({
  role: 'command_300', can_request: true, can_approve: false, mine: null, pending: [],
  choices: { '12h': inHours(12), '24h': inHours(24) }, ...overrides,
});

export const makeRequest300 = (id: number, overrides: Partial<ActingRequest> = {}): ActingRequest => ({
  id, role: 'command_300', role_label: '300', kind: 'act', requested_by: 'Pat Doe', name: 'Pat Doe', rank: 'Battalion Chief',
  duration: '12h', shift: 'C', ends_at: inHours(12), requested_at: minutesAgo(5), own: false, ...overrides,
});

export interface ShiftServer {
  onDuty: Duty;
  acting: ActingState;
  /** Status of GET /shift; anything but 200 answers with an error body. */
  status: number;
  shiftCalls: number;
  actingBodies: Array<Record<string, unknown>>;
  decisions: Array<{ call: string; body: Record<string, unknown> }>;
  /** Return true after answering the route yourself; false lets the default handler answer. */
  onActing?: (route: Route, body: Record<string, unknown>, server: ShiftServer) => Promise<boolean>;
}

export function newShiftServer(overrides: Partial<ShiftServer> = {}): ShiftServer {
  return { onDuty: chiefOnDuty(), acting: noActing300(), status: 200, shiftCalls: 0, actingBodies: [], decisions: [], ...overrides };
}

const payloadOf = (server: ShiftServer) => ({ on_duty: { duty_date: ymd(0), ...server.onDuty }, acting: server.acting });

const readBody = (route: Route): Record<string, unknown> => {
  try { return JSON.parse(route.request().postData() ?? '{}') as Record<string, unknown>; } catch { return {}; }
};

/** Answers every /api/command-300/* call. Call it for paths that start with SHIFT_BASE. */
export async function handleShiftRoute(route: Route, pathname: string, server: ShiftServer): Promise<void> {
  const method = route.request().method();
  if (pathname === `${SHIFT_BASE}/shift` && method === 'GET') {
    server.shiftCalls += 1;
    return server.status === 200
      ? route.fulfill({ json: payloadOf(server) })
      : route.fulfill({ status: server.status, json: { message: 'The shift 300 service is unavailable.' } });
  }
  if (pathname === `${SHIFT_BASE}/acting-requests` && method === 'POST') {
    const body = readBody(route);
    server.actingBodies.push(body);
    if (server.onActing && await server.onActing(route, body, server)) return;
    const kind = body.kind === 'end' ? 'end' : 'act';
    const hours = body.duration === '24h' ? 24 : 12;
    const request = makeRequest300(100 + server.actingBodies.length, {
      kind, requested_by: 'Test Member', name: kind === 'act' ? 'Test Member' : null, rank: kind === 'act' ? 'Battalion Chief' : null,
      duration: kind === 'act' ? (body.duration as ActingRequest['duration']) : null, shift: 'B',
      ends_at: kind === 'act' ? (typeof body.ends_at === 'string' ? new Date(body.ends_at).toISOString() : inHours(hours)) : null, own: true,
    });
    server.acting = { ...server.acting, mine: request };
    return route.fulfill({ status: 201, json: payloadOf(server) });
  }
  const decision = /^\/api\/command-300\/acting-requests\/(\d+)\/(approve|reject|withdraw)$/.exec(pathname);
  if (decision && method === 'POST') {
    const id = Number(decision[1]);
    const body = readBody(route);
    server.decisions.push({ call: `${decision[2]}:${id}`, body });
    if (decision[2] === 'reject' && String(body.note ?? '').trim() === '') {
      return route.fulfill({ status: 422, json: { message: 'The note field is required.', errors: { note: ['The note field is required.'] } } });
    }
    const found = [...server.acting.pending, ...(server.acting.mine ? [server.acting.mine] : [])].find((request) => request.id === id);
    if (decision[2] === 'approve' && found) {
      server.onDuty = found.kind === 'act'
        ? { shift: 'B', source: 'acting', resolved: true, name: found.name, rank: found.rank, acting_until: found.ends_at, acting_request_id: found.id }
        : chiefOnDuty('B');
    }
    server.acting = { ...server.acting, pending: server.acting.pending.filter((request) => request.id !== id), mine: server.acting.mine?.id === id ? null : server.acting.mine };
    return route.fulfill({ json: payloadOf(server) });
  }
  return route.fulfill({ status: 404, json: { message: `Unmocked route ${pathname}` } });
}
