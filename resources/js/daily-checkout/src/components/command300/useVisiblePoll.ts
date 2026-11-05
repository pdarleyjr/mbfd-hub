import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiRequestError, isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';

/** Often enough for a dashboard left open at the station, quiet enough for a phone on cellular. */
export const BOARD_REFRESH_MS = 60_000;

interface Options<T> {
  /** Stable loader; `background` is true for timed and focus refreshes. Change its identity to reload from scratch. */
  load: (background: boolean) => Promise<T>;
  enabled?: boolean;
  onForbidden?: () => void;
  errorMessage: string;
}

export interface PolledResource<T> {
  data: T | null;
  loading: boolean;
  /** Set when the first load failed, or when a manual refresh did. */
  error: string | null;
  /** The last quiet refresh failed: what is shown may be out of date. */
  stale: boolean;
  refreshing: boolean;
  /** Load again from scratch (after an error). */
  retry: () => void;
  /** Reload quietly, keeping what is shown. */
  refresh: () => void;
}

/**
 * Loads once, then refreshes every minute while the page is visible and again when it becomes visible. A failed
 * background refresh keeps the last good data and only marks it stale; an ended session goes to sign-in and a 403
 * hands off to `onForbidden`. Only the newest request may write state.
 */
export function useVisiblePoll<T>({ load, enabled = true, onForbidden, errorMessage }: Options<T>): PolledResource<T> {
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [stale, setStale] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const latest = useRef(0);
  const forbidden = useRef(onForbidden);
  forbidden.current = onForbidden;

  const run = useCallback(async (mode: 'initial' | 'background' | 'manual') => {
    const mine = ++latest.current;
    if (mode === 'manual') setRefreshing(true);
    try {
      const next = await load(mode === 'background');
      if (mine !== latest.current) return;
      setData(next);
      setError(null);
      setStale(false);
    } catch (reason) {
      if (mine !== latest.current) return;
      if (isApiAuthenticationError(reason)) { redirectToLoginAfterSessionExpiry(); return; }
      if (reason instanceof ApiRequestError && reason.status === 403) { forbidden.current?.(); return; }
      if (mode === 'background') { setStale(true); return; }
      if (mode === 'manual') setStale(true);
      else { setError(reason instanceof Error && reason.message ? reason.message : errorMessage); setData(null); }
    } finally {
      if (mine === latest.current) { setLoading(false); setRefreshing(false); }
    }
  }, [load, errorMessage]);

  useEffect(() => {
    if (!enabled) return undefined;
    setData(null);
    setLoading(true);
    setError(null);
    setStale(false);
    void run('initial');
    const refreshIfVisible = () => { if (document.visibilityState === 'visible') void run('background'); };
    const timer = window.setInterval(refreshIfVisible, BOARD_REFRESH_MS);
    document.addEventListener('visibilitychange', refreshIfVisible);
    return () => {
      latest.current += 1;
      window.clearInterval(timer);
      document.removeEventListener('visibilitychange', refreshIfVisible);
    };
  }, [enabled, run, attempt]);

  const retry = useCallback(() => setAttempt((value) => value + 1), []);
  const refresh = useCallback(() => { void run('manual'); }, [run]);

  return { data, loading, error, stale, refreshing, retry, refresh };
}
