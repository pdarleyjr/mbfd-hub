import type { CommandBoard, CommandCheckoutDetail, StationDetail } from '../components/command300/types';
import { ApiRequestError, fetchWithTimeout } from './api';

const BASE = '/api/public/command/stations';

async function read<T>(path: string, fallback: string, background: boolean): Promise<T> {
  const response = await fetchWithTimeout(`${BASE}${path}`, {
    headers: { Accept: 'application/json', ...(background ? { 'X-Hub-Background-Refresh': '1' } : {}) },
    cache: 'no-store',
  });
  if (!response.ok) {
    let message = fallback;
    try {
      const body = await response.json() as { message?: unknown };
      if (typeof body.message === 'string' && body.message) message = body.message;
    } catch { /* keep the fallback */ }
    throw new ApiRequestError(message, response.status);
  }
  return response.json() as Promise<T>;
}

/** The 300 board: every active station at a glance. Read-only. */
export async function fetchCommandBoard(background = false): Promise<CommandBoard> {
  const board = await read<Partial<CommandBoard>>('', 'The station board could not be loaded.', background);
  if (!Array.isArray(board.stations) || !board.summary) throw new Error('The station board could not be loaded.');
  return board as CommandBoard;
}

export async function fetchCommandStation(stationId: number, background = false): Promise<StationDetail> {
  const detail = await read<Partial<StationDetail>>(`/${stationId}`, 'The station could not be loaded.', background);
  if (!detail.station || !Array.isArray(detail.units) || !Array.isArray(detail.requests)) throw new Error('The station could not be loaded.');
  return detail as StationDetail;
}

export async function fetchCommandCheckout(stationId: number, inspectionId: number): Promise<CommandCheckoutDetail> {
  const payload = await read<{ checkout?: CommandCheckoutDetail }>(`/${stationId}/checkouts/${inspectionId}`, 'The checkout could not be loaded.', false);
  if (!Array.isArray(payload.checkout?.compartments)) throw new Error('The checkout could not be loaded.');
  return payload.checkout;
}
