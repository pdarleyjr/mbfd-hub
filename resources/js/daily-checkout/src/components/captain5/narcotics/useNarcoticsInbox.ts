import { useCallback, useEffect, useRef, useState } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../../utils/api';
import { failureMessage } from '../../../utils/emsApi';
import { fetchNarcoticsMeta } from '../../../utils/narcoticsApi';

export type NarcoticsAccessState =
  | { status: 'loading'; pending: number; error: null }
  | { status: 'ready'; pending: number; error: null }
  | { status: 'denied'; pending: number; error: null }
  | { status: 'error'; pending: number; error: string };

/** One look a minute while the page is visible, and one when it becomes visible or focused again. No other polling. */
const REFRESH_MS = 60_000;

/**
 * Whether this person gets the Narcotics Checks tab, and how many checks are waiting. The answer comes from the server
 * (GET /api/narcotics-checks/staff/meta): a person who may not review gets `review: false` and sees nothing at all.
 * `tick` also tells the open list to refresh quietly, so a visible page makes one cheap request a minute.
 */
export function useNarcoticsInbox(): NarcoticsAccessState & { tick: number; reload: () => void; refresh: () => void; deny: () => void } {
  const [state, setState] = useState<NarcoticsAccessState>({ status: 'loading', pending: 0, error: null });
  const [tick, setTick] = useState(0);
  const [attempt, setAttempt] = useState(0);
  const latest = useRef(0);

  useEffect(() => {
    const mine = ++latest.current;
    fetchNarcoticsMeta()
      .then((meta) => {
        if (mine !== latest.current) return;
        setState(meta.permissions?.review === true ? { status: 'ready', pending: Number(meta.pending_count) || 0, error: null } : { status: 'denied', pending: 0, error: null });
      })
      .catch((failure) => {
        if (mine !== latest.current) return;
        if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
        // A failed refresh keeps the last good answer; only the first load can show an error.
        setState((current) => (current.status === 'ready' ? current : { status: 'error', pending: 0, error: failureMessage(failure, 'Narcotics checks could not be loaded.') }));
      });
  }, [attempt, tick]);

  const ready = state.status === 'ready';
  useEffect(() => {
    if (!ready) return undefined;
    let last = 0;
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
  }, [ready]);

  const reload = useCallback(() => { setState({ status: 'loading', pending: 0, error: null }); setAttempt((n) => n + 1); }, []);
  const refresh = useCallback(() => setTick((n) => n + 1), []);
  /** A narcotics call answered 403 after the meta said yes: the tab goes away. */
  const deny = useCallback(() => setState({ status: 'denied', pending: 0, error: null }), []);

  return { ...state, tick, reload, refresh, deny };
}
