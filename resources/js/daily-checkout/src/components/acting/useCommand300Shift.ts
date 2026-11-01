import { useCallback, useEffect, useRef, useState } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { asBackgroundRefresh } from '../../utils/captain5Api';
import { fetchCommand300Shift } from '../../utils/command300ShiftApi';
import type { ShiftResult } from './types';

/** The roster may change while the page is open; a quiet refetch keeps the name honest. */
const POLL_MS = 60_000;

interface Options {
  /** False until access is known, so a refused account never asks. */
  enabled?: boolean;
  /** The 300 page keeps the line fresh; the station-list card refreshes slowly itself (useVisibleRefresh). */
  poll?: boolean;
}

export interface Command300Shift {
  data: ShiftResult | null;
  loading: boolean;
  /** Why the last load failed. With `data` set, the name shown may be out of date. */
  error: string | null;
  /** Load again after a failure, showing the loading state. */
  retry: () => void;
  /** Reload quietly, keeping what is shown: the page's Refresh button. */
  refresh: () => void;
}

/**
 * Loads `GET /api/command-300/shift`. A failed refresh never blanks a name the member is already
 * reading: the data stays and `error` says it may be out of date.
 */
export function useCommand300Shift({ enabled = true, poll = true }: Options = {}): Command300Shift {
  const [data, setData] = useState<ShiftResult | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  // Only the newest request may write state, so a slow poll never overwrites a fresher answer.
  const latest = useRef(0);

  const load = useCallback(async (mode: 'initial' | 'silent') => {
    const mine = ++latest.current;
    if (mode === 'initial') { setLoading(true); setError(null); }
    try {
      const next = await fetchCommand300Shift();
      if (mine !== latest.current) return;
      setData(next);
      setError(null);
    } catch (failure) {
      if (mine !== latest.current) return;
      if (isApiAuthenticationError(failure)) {
        // A card's background refresh never takes the member off the page they are reading.
        if (poll || mode !== 'silent') redirectToLoginAfterSessionExpiry();
        return;
      }
      const offline = typeof navigator !== 'undefined' && navigator.onLine === false;
      if (mode !== 'silent' || !offline) setError(failure instanceof Error && failure.message ? failure.message : 'The shift 300 could not be loaded.');
    } finally {
      if (mine === latest.current) setLoading(false);
    }
  }, [poll]);

  useEffect(() => { if (enabled) void load('initial'); }, [enabled, load]);

  useEffect(() => {
    if (!enabled || !poll) return undefined;
    const tick = () => { if (document.visibilityState === 'visible') asBackgroundRefresh(() => { void load('silent'); }); };
    const timer = setInterval(tick, POLL_MS);
    document.addEventListener('visibilitychange', tick);
    return () => { clearInterval(timer); document.removeEventListener('visibilitychange', tick); };
  }, [enabled, poll, load]);

  const retry = useCallback(() => { void load('initial'); }, [load]);
  const refresh = useCallback(() => { void load('silent'); }, [load]);

  return { data, loading, error, retry, refresh };
}
