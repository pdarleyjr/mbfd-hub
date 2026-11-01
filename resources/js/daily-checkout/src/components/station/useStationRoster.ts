import { useCallback, useEffect, useState } from 'react';
import type { StationRoster } from '../../types';
import { ApiClient, ApiRequestError, isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';

/** TeleStaff sends a new export every 5 minutes, and a tablet left on this tab must change crews at 08:00 unprompted. */
const REFRESH_MS = 120_000;

/**
 * Loads a station's part of today's roster and keeps it current while the tab is on screen. A failed background
 * refresh keeps the list up and says it may be out of date, so a phone losing signal never blanks it and a tablet
 * offline at 08:00 does not pass the old crew off as current. An ended session goes to sign-in and a withdrawn
 * permission clears the list, because a screen left open must not keep showing names its viewer may no longer read.
 */
export function useStationRoster(stationId: number) {
  const [roster, setRoster] = useState<StationRoster | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshFailed, setRefreshFailed] = useState(false);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let active = true;
    let latestRequest = 0;
    const load = async (background: boolean) => {
      latestRequest += 1;
      const request = latestRequest;
      try {
        const loaded = await ApiClient.getStationRoster(stationId, background);
        if (!active || request !== latestRequest) return;
        setRoster(loaded);
        setError(null);
        setRefreshFailed(false);
      } catch (reason) {
        if (!active || request !== latestRequest) return;
        if (isApiAuthenticationError(reason)) {
          redirectToLoginAfterSessionExpiry();
          return;
        }
        if (background && !(reason instanceof ApiRequestError && reason.status === 403)) {
          setRefreshFailed(true);
          return;
        }
        setRoster(null);
        setError(reason instanceof Error ? reason.message : "Today's roster could not be loaded");
      } finally {
        if (active && !background) setLoading(false);
      }
    };
    const refreshIfVisible = () => {
      if (document.visibilityState === 'visible') void load(true);
    };

    setRoster(null);
    setLoading(true);
    setError(null);
    setRefreshFailed(false);
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

  return { roster, error, loading, refreshFailed, retry };
}
