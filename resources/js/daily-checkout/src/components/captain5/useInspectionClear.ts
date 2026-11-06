import { useCallback, useRef, useState } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { clearInspection, requestFailureMessage, restoreInspection, type InspectionClearState } from '../../utils/captain5Api';

/**
 * Clear and Restore for Rescue daily checkouts on the Captain 5 page. The server treats a repeat as a no-op, so a
 * retry after a dropped connection is always safe and needs no key. One request at a time.
 */
export function useInspectionClear() {
  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const inFlight = useRef(false);

  const run = useCallback(async (kind: 'clear' | 'restore', inspectionId: number, note = ''): Promise<InspectionClearState | null> => {
    if (inFlight.current) return null;
    inFlight.current = true;
    setBusyId(inspectionId);
    setError(null);
    try {
      return await (kind === 'clear' ? clearInspection(inspectionId, note) : restoreInspection(inspectionId));
    } catch (failure) {
      if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return null; }
      setError(requestFailureMessage(failure, kind === 'clear' ? 'The checkout could not be cleared. Try again.' : 'The checkout could not be restored. Try again.'));
      return null;
    } finally {
      inFlight.current = false;
      setBusyId(null);
    }
  }, []);

  return { busyId, error, clear: (id: number, note = '') => run('clear', id, note), restore: (id: number) => run('restore', id), dismissError: () => setError(null) };
}
