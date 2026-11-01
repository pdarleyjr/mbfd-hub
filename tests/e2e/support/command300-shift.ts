import type { Route } from '@playwright/test';
import type { OnDuty } from '../../../resources/js/daily-checkout/src/components/acting/types';

/**
 * A mocked /api/command-300 (the shift's 300), shared by every spec that renders the 300 page or the 300 card
 * on the stations list.
 *   GET /shift -> { on_duty }
 */
export const SHIFT_BASE = '/api/command-300';

const ymd = (offsetDays: number): string => {
  const date = new Date(Date.now() + offsetDays * 86_400_000);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

export type Duty = Omit<OnDuty, 'duty_date'>;

export const chiefOnDuty = (shift: OnDuty['shift'] = 'A', name = 'Jane Smith', rank: string | null = 'Division Chief'): Duty => (
  { shift, source: 'bid_holder', resolved: true, name, rank }
);
export const UNSET_DUTY: Duty = { shift: null, source: 'unknown', resolved: false, name: null, rank: null };

/** 09:14 ET on a fixed day, so the note's clock text is the same wherever the test runs. */
export const ROSTER_AS_OF = '2026-10-30T13:14:15+00:00';
export const rosterChief = (shift: OnDuty['shift'] = 'B', name = 'Jane Smith', rank: string | null = 'Division Chief', identity: 'linked' | 'unlinked' = 'linked'): Duty => (
  { ...chiefOnDuty(shift, name, rank), source: 'telestaff', as_of: ROSTER_AS_OF, freshness: 'current', identity, reason: null }
);
export const seatAwaiting = (freshness: 'awaiting' | 'delayed'): Duty => ({ ...chiefOnDuty('B'), as_of: null, freshness, identity: null, reason: null });
export const rosterUnknown = (reason: 'conflict' | 'needs_review' | 'no_working_row'): Duty => (
  { shift: 'B', source: 'unknown', resolved: false, name: null, rank: null, as_of: ROSTER_AS_OF, freshness: 'current', identity: null, reason }
);

export interface ShiftServer {
  onDuty: Duty;
  /** Status of GET /shift; anything but 200 answers with an error body. */
  status: number;
  shiftCalls: number;
}

export function newShiftServer(overrides: Partial<ShiftServer> = {}): ShiftServer {
  return { onDuty: chiefOnDuty(), status: 200, shiftCalls: 0, ...overrides };
}

const payloadOf = (server: ShiftServer) => ({ on_duty: { duty_date: ymd(0), ...server.onDuty } });

/** Answers every /api/command-300/* call. Call it for paths that start with SHIFT_BASE. */
export async function handleShiftRoute(route: Route, pathname: string, server: ShiftServer): Promise<void> {
  const method = route.request().method();
  if (pathname === `${SHIFT_BASE}/shift` && method === 'GET') {
    server.shiftCalls += 1;
    return server.status === 200
      ? route.fulfill({ json: payloadOf(server) })
      : route.fulfill({ status: server.status, json: { message: 'The shift 300 service is unavailable.' } });
  }
  return route.fulfill({ status: 404, json: { message: `Unmocked route ${pathname}` } });
}
