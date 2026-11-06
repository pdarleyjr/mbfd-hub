import { useCallback, useRef, useState } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../../../utils/api';
import { failureMessage, isForbidden, isRetryable } from '../../../../utils/emsApi';
import { newKey } from '../../format';
import { clearForm, restoreForm } from './api';
import { CLEAR_REASON, RESTORE_REASON } from './logic';

export interface BatchOutcome {
  done: string[];
  failed: Array<{ id: string; message: string }>;
}

/**
 * Clears (or restores) several forms one by one with ONE idempotency key per batch. The server scopes keys per form
 * and action, so a retry after a dropped connection replays the forms already done and only sends the rest. The key is
 * kept until a batch finishes with nothing left to retry.
 */
export function useClearForms(onForbidden: () => void) {
  const [busy, setBusy] = useState<'clear' | 'restore' | null>(null);
  const keys = useRef<{ clear: string | null; restore: string | null }>({ clear: null, restore: null });

  const runBatch = useCallback(async (kind: 'clear' | 'restore', ids: readonly string[]): Promise<BatchOutcome | null> => {
    if (busy !== null || ids.length === 0) return null;
    setBusy(kind);
    const key = keys.current[kind] ?? newKey();
    keys.current[kind] = key;
    const outcome: BatchOutcome = { done: [], failed: [] };
    let retryable = false;
    try {
      for (const id of ids) {
        try {
          await (kind === 'clear' ? clearForm(id, CLEAR_REASON, key) : restoreForm(id, RESTORE_REASON, key));
          outcome.done.push(id);
        } catch (failure) {
          if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return null; }
          if (isForbidden(failure)) { onForbidden(); return null; }
          retryable = retryable || isRetryable(failure);
          outcome.failed.push({ id, message: failureMessage(failure, 'That could not be saved. Try again.') });
        }
      }
      if (!retryable) keys.current[kind] = null;
      return outcome;
    } finally {
      setBusy(null);
    }
  }, [busy, onForbidden]);

  return {
    busy,
    clear: (ids: readonly string[]) => runBatch('clear', ids),
    restore: (ids: readonly string[]) => runBatch('restore', ids),
  };
}
