import type { ShiftResult } from '../components/acting/types';
import { createRequester, normalizeOnDuty } from './captain5Api';

const BASE = '/api/command-300';

const request = createRequester(BASE, 'Your account does not have access to 300.');

/**
 * `GET /api/command-300/shift`: who is the shift's 300 (Division Chief) today. The same fetch, CSRF and
 * error handling as the Captain 5 workspace, bound to /api/command-300.
 */
export async function fetchCommand300Shift(): Promise<ShiftResult> {
  const payload = await request<{ on_duty?: unknown }>('/shift');
  return { on_duty: normalizeOnDuty(payload.on_duty) };
}
