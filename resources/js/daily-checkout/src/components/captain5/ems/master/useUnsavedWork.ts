import { useEffect, useId } from 'react';
import { setUnsavedWork } from '../../../../pwa/draftBusy';

/**
 * Tells the shared busy registry (resources/js/pwa/busy.ts) that this screen holds work the server does not have,
 * so the app update banner never reloads the page over it. Cleared when the work is saved or the screen goes away.
 */
export function useUnsavedWork(dirty: boolean): void {
  const token = `master-inventory:${useId()}`;
  useEffect(() => {
    setUnsavedWork(token, dirty);
    return () => setUnsavedWork(token, false);
  }, [dirty, token]);
}
