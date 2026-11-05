import { useCallback } from 'react';
import { fetchCommandBoard, fetchCommandStation } from '../../utils/command300BoardApi';
import type { CommandBoard, StationDetail } from './types';
import { useVisiblePoll, type PolledResource } from './useVisiblePoll';

export function useCommandBoard(options: { enabled: boolean; onForbidden: () => void }): PolledResource<CommandBoard> {
  const load = useCallback((background: boolean) => fetchCommandBoard(background), []);
  return useVisiblePoll({ load, ...options, errorMessage: 'The station board could not be loaded.' });
}

/** One station's read-only detail; reloads from scratch when the station changes and idles while none is open. */
export function useCommandStation(stationId: number | null, onForbidden: () => void): PolledResource<StationDetail> {
  const load = useCallback((background: boolean) => fetchCommandStation(stationId ?? 0, background), [stationId]);
  return useVisiblePoll({ load, enabled: stationId !== null, onForbidden, errorMessage: 'The station could not be loaded.' });
}
