import { useCallback, useEffect, useRef } from 'react';
import { useEmsLoad } from '../shared';
import { fetchMaster } from './api';
import type { MasterData, MasterItem } from './types';

/** How often the table quietly checks for newer numbers while the page is in front and nothing is open. */
export const REFRESH_MS = 60_000;

/**
 * The master list, kept fresh. It refreshes when the window gets focus and on a gentle timer, but never while a sheet is
 * open or something is unsaved (`paused`), and a failed refresh never blanks what is already on screen.
 */
export function useMasterData({ paused, onForbidden }: { paused: boolean; onForbidden: () => void }) {
  const load = useEmsLoad<MasterData>(fetchMaster, 'master', { onForbidden });
  const pausedRef = useRef(paused);
  pausedRef.current = paused;
  const { reload } = load;

  useEffect(() => {
    const refresh = () => {
      if (!pausedRef.current && document.visibilityState === 'visible') void reload(true);
    };
    const timer = window.setInterval(refresh, REFRESH_MS);
    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', refresh);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener('focus', refresh);
      document.removeEventListener('visibilitychange', refresh);
    };
  }, [reload]);

  const { setData } = load;
  const replaceItem = useCallback((item: MasterItem) => {
    setData((current) => {
      if (!current) return current;
      const exists = current.items.some((entry) => entry.id === item.id);
      return { ...current, items: exists ? current.items.map((entry) => (entry.id === item.id ? item : entry)) : [...current.items, item] };
    });
  }, [setData]);

  const removeItem = useCallback((id: number) => {
    setData((current) => (current ? { ...current, items: current.items.filter((entry) => entry.id !== id) } : current));
  }, [setData]);

  return { ...load, replaceItem, removeItem };
}
