import { useCallback, useEffect, useRef, useState } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { fetchMessagesUnread, fetchWorkspace, isCaptain5Forbidden } from '../../utils/captain5Api';
import type { Workspace } from './types';

/** The queue changes while someone else works it; a quiet refetch keeps counts honest without a manual tap. */
const POLL_MS = 60_000;

export type LoadMode = 'initial' | 'manual' | 'silent';

/**
 * Loads the workspace and keeps it fresh. A failed refresh never blanks what the member is already
 * reading: the data stays and `error` explains that it may be out of date.
 */
export function useWorkspace() {
  const [data, setData] = useState<Workspace | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [unread, setUnread] = useState(0);
  // Only the newest request may write state, so a slow poll never overwrites a fresher refresh.
  const latest = useRef(0);

  const load = useCallback(async (mode: LoadMode = 'silent') => {
    const mine = ++latest.current;
    if (mode === 'manual') setRefreshing(true);
    try {
      const next = await fetchWorkspace();
      if (mine !== latest.current) return;
      setData(next);
      setError(null);
      fetchMessagesUnread().then(setUnread).catch(() => undefined);
    } catch (failure) {
      if (mine !== latest.current) return;
      if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
      if (isCaptain5Forbidden(failure)) { setForbidden(true); return; }
      const offline = typeof navigator !== 'undefined' && navigator.onLine === false;
      if (mode !== 'silent' || !offline) setError(failure instanceof Error ? failure.message : 'The workspace could not be loaded.');
    } finally {
      if (mine === latest.current) { setLoading(false); setRefreshing(false); }
    }
  }, []);

  useEffect(() => { void load('initial'); }, [load]);

  useEffect(() => {
    const tick = () => { if (document.visibilityState === 'visible') void load('silent'); };
    const timer = setInterval(tick, POLL_MS);
    document.addEventListener('visibilitychange', tick);
    return () => { clearInterval(timer); document.removeEventListener('visibilitychange', tick); };
  }, [load]);

  const patch = useCallback((changes: Partial<Workspace>) => setData((current) => (current ? { ...current, ...changes } : current)), []);
  const markForbidden = useCallback(() => setForbidden(true), []);

  return { data, loading, refreshing, error, forbidden, unread, load, patch, markForbidden, setUnread };
}
