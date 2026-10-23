import { useCallback, useEffect, useRef, useState } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { fetchDepartmentUpdates } from '../../utils/departmentUpdatesApi';
import type { PostedUpdate, UpdateOrigin } from './types';

export type UpdatesStatus = 'loading' | 'ready' | 'error';

/** Recent posts from one page. A new post is added from the server's answer, never guessed. */
export function useDepartmentUpdates(origin: UpdateOrigin) {
  const [updates, setUpdates] = useState<PostedUpdate[]>([]);
  const [status, setStatus] = useState<UpdatesStatus>('loading');
  const [error, setError] = useState<string | null>(null);
  const latest = useRef(0);

  const load = useCallback(async () => {
    const mine = ++latest.current;
    setStatus((current) => (current === 'ready' ? current : 'loading'));
    try {
      const loaded = await fetchDepartmentUpdates(origin);
      if (mine !== latest.current) return;
      setUpdates(loaded);
      setError(null);
      setStatus('ready');
    } catch (failure) {
      if (mine !== latest.current) return;
      if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
      setError(failure instanceof Error ? failure.message : 'Recent updates could not be loaded.');
      setStatus((current) => (current === 'ready' ? current : 'error'));
    }
  }, [origin]);

  useEffect(() => { void load(); }, [load]);

  const added = useCallback((update: PostedUpdate) => {
    setUpdates((current) => [update, ...current.filter((candidate) => candidate.id !== update.id)]);
    setStatus('ready');
  }, []);

  const replaced = useCallback((update: PostedUpdate) => {
    setUpdates((current) => current.map((candidate) => (candidate.id === update.id ? update : candidate)));
  }, []);

  return { updates, status, error, reload: load, added, replaced };
}
