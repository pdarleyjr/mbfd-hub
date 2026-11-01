import { useCallback, useEffect, useReducer, useRef } from 'react';
import type { ConcernNote, IssueSummary, LiveFindings } from '../types/issueHistory.ts';
import { isApiAuthenticationError } from '../utils/api';
import { asBackgroundRefresh } from '../utils/captain5Api';
import { fetchFindings } from '../utils/issueHistoryApi';
import { initialLiveFindings, liveFindingsReducer } from '../utils/liveFindings.ts';
import { purgeCachedFindings, readCachedFindings, writeCachedFindings } from '../utils/liveFindingsCache';

/** The list is also read on focus and reconnect; this only covers a screen left open and visible. */
export const LIVE_FINDINGS_POLL_MS = 60_000;

const NONE_OPEN: IssueSummary[] = [];
const NO_NOTES: ConcernNote[] = [];

/**
 * Open issues for one apparatus, read live (docs/apparatus-issue-history.md section 5). Fetched on open, on focus, on
 * return to the page, on reconnect, and every 60 seconds while the page is visible and online. A request still
 * running is never doubled. A failed refresh keeps what is on screen and marks it stale. The last good answer is kept
 * per apparatus and member on this device and shown when there is no network.
 *
 * This is display data only. It is never part of the autosaved draft or the checkout, so a refresh cannot change an answer.
 */
export function useLiveFindings(apparatusId: number | undefined, userId: number | undefined): LiveFindings {
  const [store, dispatch] = useReducer(liveFindingsReducer, initialLiveFindings);
  const inFlight = useRef(false);
  // Bumped when the apparatus or member changes, so an answer for the previous one is dropped.
  const generation = useRef(0);

  const load = useCallback((background: boolean) => {
    if (apparatusId === undefined || userId === undefined || inFlight.current) return;
    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
      dispatch({ type: 'failure' });
      return;
    }
    const mine = generation.current;
    inFlight.current = true;
    dispatch({ type: 'request' });
    const start = () => {
      fetchFindings(apparatusId).then((snapshot) => {
        if (generation.current !== mine) return;
        dispatch({ type: 'success', snapshot });
        void writeCachedFindings(apparatusId, userId, snapshot);
      }).catch((error: unknown) => {
        if (generation.current !== mine) return;
        // A signed-out member's copy does not stay on the device; this screen keeps what it already shows.
        if (isApiAuthenticationError(error)) void purgeCachedFindings();
        dispatch({ type: 'failure' });
      }).finally(() => {
        if (generation.current === mine) inFlight.current = false;
      });
    };
    // A timer or a return to the page is not the member working, so it must not hold a session open.
    if (background) asBackgroundRefresh(start); else start();
  }, [apparatusId, userId]);

  useEffect(() => {
    generation.current += 1;
    inFlight.current = false;
    dispatch({ type: 'reset' });
    if (apparatusId === undefined || userId === undefined) return undefined;
    let cancelled = false;
    void readCachedFindings(apparatusId, userId).then((snapshot) => {
      if (!cancelled && snapshot) dispatch({ type: 'cached', snapshot });
    });
    load(false);
    const whenVisible = () => {
      if (document.visibilityState === 'visible') load(true);
    };
    window.addEventListener('focus', whenVisible);
    window.addEventListener('online', whenVisible);
    document.addEventListener('visibilitychange', whenVisible);
    const timer = setInterval(whenVisible, LIVE_FINDINGS_POLL_MS);

    return () => {
      cancelled = true;
      window.removeEventListener('focus', whenVisible);
      window.removeEventListener('online', whenVisible);
      document.removeEventListener('visibilitychange', whenVisible);
      clearInterval(timer);
    };
  }, [apparatusId, userId, load]);

  const refresh = useCallback(() => load(false), [load]);
  const snapshot = store.snapshot;

  return {
    open: snapshot?.open ?? NONE_OPEN,
    notes: snapshot?.notes ?? NO_NOTES,
    closedCount: snapshot?.closed_count ?? 0,
    asOf: snapshot?.as_of ?? null,
    state: store.state,
    refresh,
  };
}
