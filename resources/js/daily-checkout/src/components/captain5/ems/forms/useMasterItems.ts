import { useCallback, useRef, useState } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../../../utils/api';
import { failureMessage, isForbidden } from '../../../../utils/emsApi';
import { fetchMasterItems } from './api';
import type { MasterItem } from './types';

export interface MasterItems {
  /** null until the list has loaded. */
  items: readonly MasterItem[] | null;
  error: string | null;
  /** Start loading unless the list is already here or on its way. `force` tries again after a failure. */
  load: (force?: boolean) => void;
}

/** The supply room items the picker searches, fetched once for as long as the tab is open. */
export function useMasterItems(onForbidden: () => void): MasterItems {
  const [items, setItems] = useState<readonly MasterItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const started = useRef(false);
  const forbidden = useRef(onForbidden);
  forbidden.current = onForbidden;

  const load = useCallback((force = false) => {
    if (started.current && !force) return;
    started.current = true;
    setError(null);
    fetchMasterItems()
      .then((next) => setItems(next))
      .catch((failure) => {
        started.current = false;
        if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
        if (isForbidden(failure)) { forbidden.current(); return; }
        setError(failureMessage(failure, 'The supply room list could not be loaded.'));
      });
  }, []);

  return { items, error, load };
}
