import { useEffect } from 'react';
import { asBackgroundRefresh } from '../../utils/captain5Api';

/** The roster arrives once or twice a day, so the station-list cards do not need to ask often. */
const CARD_REFRESH_MS = 5 * 60_000;

/**
 * Calls `refresh` when the page comes back into view and every five minutes while it is visible. Never while
 * hidden or offline. `refresh` must be stable, must not show a loading state and must begin its request
 * synchronously, so the request is marked as a background refresh that does not count as member activity.
 */
export function useVisibleRefresh(refresh: () => void): void {
  useEffect(() => {
    const tick = () => {
      if (document.visibilityState === 'visible' && navigator.onLine !== false) asBackgroundRefresh(refresh);
    };
    const timer = setInterval(tick, CARD_REFRESH_MS);
    document.addEventListener('visibilitychange', tick);
    return () => { clearInterval(timer); document.removeEventListener('visibilitychange', tick); };
  }, [refresh]);
}
