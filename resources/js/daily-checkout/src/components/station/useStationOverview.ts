import { useCallback, useEffect, useState } from 'react';
import type { StationOverview } from '../../types';
import { ApiClient, isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';

/** Often enough that the noon alert and a crew's new checkout appear on a page left open at the station. */
const REFRESH_MS = 60_000;

/**
 * Loads a station's Overview and keeps it current while the page is on screen. A failed background refresh changes
 * nothing: the last good data stays up and the next refresh tries again, so a phone losing signal never blanks the
 * page. An ended session goes to sign-in, because a screen left open must not keep showing old data as if it were live.
 */
export function useStationOverview(stationId: number) {
  const [overview, setOverview] = useState<StationOverview | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let active = true;
    let latestRequest = 0;
    const load = async (background: boolean) => {
      latestRequest += 1;
      const request = latestRequest;
      try {
        const loaded = await ApiClient.getStationOverview(stationId, background);
        if (!active || request !== latestRequest) return;
        setOverview(loaded);
        setError(null);
      } catch (reason) {
        if (!active) return;
        if (isApiAuthenticationError(reason)) {
          redirectToLoginAfterSessionExpiry();
          return;
        }
        if (!background) setError(reason instanceof Error ? reason.message : 'The station overview could not be loaded');
      } finally {
        if (active && !background) setLoading(false);
      }
    };
    const refreshIfVisible = () => {
      if (document.visibilityState === 'visible') void load(true);
    };

    setOverview(null);
    setLoading(true);
    setError(null);
    void load(false);
    const timer = window.setInterval(refreshIfVisible, REFRESH_MS);
    document.addEventListener('visibilitychange', refreshIfVisible);
    return () => {
      active = false;
      window.clearInterval(timer);
      document.removeEventListener('visibilitychange', refreshIfVisible);
    };
  }, [stationId, attempt]);

  const retry = useCallback(() => setAttempt((value) => value + 1), []);

  return { overview, error, loading, retry };
}
