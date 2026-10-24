import type { ActingApi, ActingResult } from '../components/acting/types';
import { actingResult, buildActingApi, createRequester } from './captain5Api';

const BASE = '/api/command-300';

const request = createRequester(BASE, 'Your account does not have access to 300.');

/**
 * Who is the shift's 300 (Division Chief) today, and the acting requests around that seat. The same
 * fetch, CSRF and error handling as the Captain 5 workspace, bound to /api/command-300.
 */
export const command300ActingApi: ActingApi = buildActingApi(request);

/** `GET /api/command-300/shift`: `{ on_duty, acting }`, shaped and checked by the shared normalizers. */
export async function fetchCommand300Shift(): Promise<ActingResult> {
  return actingResult(await request('/shift'));
}
