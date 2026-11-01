import { useCallback, useRef, useState } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { Captain5ApiError, performIssueAction } from '../../utils/captain5Api';
import { newKey } from './format';
import type { IssueActionName, IssueSummary } from './types';

type ClearingAction = Extract<IssueActionName, 'send_to_logistics' | 'bring_back_to_captain5'>;

/** Only what a run needs of an issue: the Undo list holds these with the tokens the server just returned. */
type Target = Pick<IssueSummary, 'id' | 'state_token'>;

export interface ClearingNotice {
  tone: 'success' | 'warning';
  text: string;
  /** The issues this run cleared, ready to be brought back with one tap. */
  undo: Target[];
}

interface Tally {
  done: Target[];
  changed: number;
  failed: number;
  /** The server's reason when it refused outright; the run stops there. */
  refusal: string | null;
}

const plural = (count: number, one: string, many: string): string => (count === 1 ? one : many);

/** Plain English for what a run did, including anything it did not do. */
export function describeRun(action: ClearingAction, total: number, tally: Pick<Tally, 'changed' | 'failed' | 'refusal'> & { done: number }): string {
  const clearing = action === 'send_to_logistics';
  const notDone = clearing ? 'not cleared' : 'not brought back';
  const problems: string[] = [];
  if (tally.changed > 0) problems.push(`${tally.changed} changed and ${plural(tally.changed, 'was', 'were')} ${notDone}.`);
  if (tally.failed > 0) problems.push(`${tally.failed} could not be sent. Try again.`);
  if (tally.refusal) problems.push(tally.refusal);
  if (problems.length === 0) {
    if (!clearing) return total === 1 ? 'Brought back to the Captain 5 queue.' : `Brought back all ${total} to the Captain 5 queue.`;
    return total === 1 ? 'Cleared from Captain 5. It stays open with Logistics.' : `Cleared all ${total} from Captain 5. They stay open with Logistics.`;
  }
  return `${clearing ? 'Cleared' : 'Brought back'} ${tally.done} of ${total}. ${problems.join(' ')}`;
}

/**
 * Clear for Logistics and Bring back, for one issue or a whole unit. Each issue goes through the ordinary action
 * endpoint with its own state token and key, one at a time, so one changed issue never blocks the rest. A retry of
 * the same issue in the same state reuses its key, so an answer lost on the way back cannot record it twice.
 */
export function useIssueClearing(onChanged: () => Promise<void> | void) {
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<ClearingNotice | null>(null);
  const keys = useRef(new Map<string, string>());

  const keyFor = (action: ClearingAction, target: Target): string => {
    const slot = `${action}:${target.id}:${target.state_token}`;
    const key = keys.current.get(slot) ?? newKey();
    keys.current.set(slot, key);
    return key;
  };

  const run = useCallback(async (action: ClearingAction, targets: Target[]): Promise<void> => {
    if (busy || targets.length === 0) return;
    setBusy(true);
    setNotice(null);
    const tally: Tally = { done: [], changed: 0, failed: 0, refusal: null };
    for (const [index, target] of targets.entries()) {
      try {
        const updated = await performIssueAction(target.id, { action, expected_state: target.state_token, idempotency_key: keyFor(action, target), fields: {} });
        tally.done.push({ id: updated.id, state_token: updated.state_token });
      } catch (error) {
        if (isApiAuthenticationError(error)) { redirectToLoginAfterSessionExpiry(); return; }
        if (error instanceof Captain5ApiError && error.isConflict) tally.changed += 1;
        else if (error instanceof Captain5ApiError && error.status === 403) { tally.refusal = error.message; tally.failed += targets.length - index - 1; break; }
        else tally.failed += 1;
      }
    }
    await onChanged();
    setBusy(false);
    setNotice({
      tone: tally.changed + tally.failed > 0 || tally.refusal ? 'warning' : 'success',
      text: describeRun(action, targets.length, { ...tally, done: tally.done.length }),
      undo: action === 'send_to_logistics' ? tally.done : [],
    });
  }, [busy, onChanged]);

  const dismiss = useCallback(() => setNotice(null), []);

  return { busy, notice, run, dismiss };
}
