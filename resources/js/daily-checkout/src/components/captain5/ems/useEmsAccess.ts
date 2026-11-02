import { useCallback, useEffect, useRef, useState } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../../utils/api';
import { fetchEmsMeta, fetchRequestTotal, failureMessage, isForbidden } from '../../../utils/emsApi';
import type { EmsMeta } from './types';

export type EmsAccessState =
  | { status: 'loading'; meta: null; error: null }
  | { status: 'ready'; meta: EmsMeta; error: null }
  | { status: 'denied'; meta: null; error: null }
  | { status: 'error'; meta: null; error: string };

/**
 * Whether this person may use the EMS tabs. The Captain 5 page admits some people the EMS API does not (an Admin Panel
 * user with no EMS grant), so the tabs appear only when GET /ems/api/meta says `permissions.view`. A 403 means "no EMS
 * tabs for you" and is not an error to show.
 */
export function useEmsAccess(): EmsAccessState & { reload: () => void; deny: () => void } {
  const [state, setState] = useState<EmsAccessState>({ status: 'loading', meta: null, error: null });
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let active = true;
    fetchEmsMeta()
      .then((meta) => { if (active) setState(meta.permissions?.view === true ? { status: 'ready', meta, error: null } : { status: 'denied', meta: null, error: null }); })
      .catch((failure) => {
        if (!active) return;
        if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
        setState(isForbidden(failure) ? { status: 'denied', meta: null, error: null } : { status: 'error', meta: null, error: failureMessage(failure, 'EMS could not be loaded.') });
      });
    return () => { active = false; };
  }, [attempt]);

  const reload = useCallback(() => { setState({ status: 'loading', meta: null, error: null }); setAttempt((n) => n + 1); }, []);
  /** An EMS call answered 403 after the meta said yes: the tabs go away. */
  const deny = useCallback(() => setState({ status: 'denied', meta: null, error: null }), []);
  return { ...state, reload, deny };
}

/** The tab badge counts forms Captain 5 can act on; one sent back is waiting on the member. */
const WAITING_COUNT_QUERY = 'status=submitted,reviewed,prepared&per_page=1';

/** One refresh a minute while the page is visible, and one when it becomes visible or focused again. No other polling. */
const REFRESH_MS = 60_000;

/**
 * The waiting form count for the tab badge, read from `meta.total` of a one-row list. `tick` also tells the open Submitted
 * Inventory Forms list to refresh quietly, so a visible page makes one request a minute for the count and one for the list.
 */
export function useOpenRequestCount(enabled: boolean): { count: number; tick: number; refresh: () => void } {
  const [count, setCount] = useState(0);
  const [tick, setTick] = useState(0);
  const latest = useRef(0);

  const refresh = useCallback(() => setTick((n) => n + 1), []);

  useEffect(() => {
    if (!enabled) return undefined;
    let last = 0;
    // Focus and visibility often fire together; one refresh is enough.
    const bump = () => {
      const now = Date.now();
      if (document.visibilityState !== 'visible' || now - last < 2_000) return;
      last = now;
      setTick((n) => n + 1);
    };
    const timer = setInterval(bump, REFRESH_MS);
    document.addEventListener('visibilitychange', bump);
    window.addEventListener('focus', bump);
    return () => { clearInterval(timer); document.removeEventListener('visibilitychange', bump); window.removeEventListener('focus', bump); };
  }, [enabled]);

  useEffect(() => {
    if (!enabled) return;
    const mine = ++latest.current;
    fetchRequestTotal(WAITING_COUNT_QUERY)
      .then((total) => { if (mine === latest.current) setCount(total); })
      .catch((failure) => { if (isApiAuthenticationError(failure)) redirectToLoginAfterSessionExpiry(); /* a failed count keeps the last one */ });
  }, [enabled, tick]);

  return { count, tick, refresh };
}
