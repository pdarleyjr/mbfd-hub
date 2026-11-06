import { useCallback, useEffect, useRef, useState } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { fetchClearedInspections, requestFailureMessage } from '../../utils/captain5Api';
import { LoadError, SkeletonRows } from '../ui/StateViews';
import { dateTimeText } from './format';
import type { ClearedInspection } from './types';
import { BTN_SECONDARY, HINT } from './ui';
import { useInspectionClear } from './useInspectionClear';

interface Props {
  canAct: boolean;
  /** Changes whenever the workspace reloads, so this list follows it. */
  version: string;
  onOpenInspection: (id: number) => void;
  /** A checkout was restored: the workspace reloads and it is back on the Today list. */
  onChanged: () => void;
}

/** Rescue checkouts Captain 5 cleared in the last 90 days, newest first. Each can be opened or restored. */
export default function ClearedCheckouts({ canAct, version, onOpenInspection, onChanged }: Props) {
  const [rows, setRows] = useState<ClearedInspection[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const latest = useRef(0);
  const action = useInspectionClear();

  const load = useCallback(async () => {
    const mine = ++latest.current;
    try {
      const next = await fetchClearedInspections();
      if (mine !== latest.current) return;
      setRows(next);
      setError(null);
    } catch (failure) {
      if (mine !== latest.current) return;
      if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
      setError(requestFailureMessage(failure, 'Cleared checkouts could not be loaded.'));
    }
  }, []);

  useEffect(() => { void load(); }, [load, version]);

  const restore = async (row: ClearedInspection) => {
    setNotice(null);
    const done = await action.restore(row.inspection_id);
    if (done === null) return;
    latest.current += 1; // an older answer still on its way must not put the row back
    setRows((current) => (current ?? []).filter((entry) => entry.inspection_id !== row.inspection_id));
    setNotice(`Restored ${row.unit}'s checkout to the Today list.`);
    onChanged();
  };

  if (error && rows === null) return <LoadError title="Cleared checkouts could not be loaded" message={error} onRetry={() => void load()} />;
  if (rows === null) return <SkeletonRows rows={2} />;

  return (
    <div className="space-y-3" data-testid="captain5-cleared-checkouts">
      <div aria-live="polite">{notice && <p role="status" className="hub-callout p-3 text-sm font-semibold">{notice}</p>}</div>
      {action.error && <p role="alert" className="hub-callout hub-callout--warning p-3 text-sm">{action.error}</p>}
      {error && <p role="alert" className="hub-callout hub-callout--warning p-3 text-sm">The list could not be refreshed: {error}</p>}
      {rows.length === 0
        ? <p className={HINT}>No cleared checkouts in the last 90 days. When you clear a Rescue checkout you have handled, it is kept here.</p>
        : (
          <ul className="space-y-2" aria-label="Cleared Rescue checkouts">
            {rows.map((row) => {
              const busy = action.busyId === row.inspection_id;
              return (
                <li key={row.inspection_id} data-testid="captain5-cleared-row" className="space-y-2 rounded-hub-control border border-hub-border bg-hub-well px-3.5 py-3">
                  <div className="min-w-0 space-y-1">
                    <strong className="text-hub-ink">{row.unit}</strong>
                    <p className={`${HINT} break-words`}>Submitted {dateTimeText(row.submitted_at)}{row.submitted_by ? ` by ${row.submitted_by}` : ''}</p>
                    <p className="break-words text-sm text-hub-ink">
                      Cleared{row.cleared_by ? ` by ${row.cleared_by}` : ''}{row.cleared_at ? `, ${dateTimeText(row.cleared_at)}` : ''}{row.clear_note ? `: ${row.clear_note}` : ''}
                    </p>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    <button type="button" className={BTN_SECONDARY} onClick={() => onOpenInspection(row.inspection_id)}>
                      View<span className="sr-only"> {row.unit} checkout</span>
                    </button>
                    {canAct && (
                      <button type="button" data-testid="captain5-cleared-restore" className={BTN_SECONDARY} disabled={action.busyId !== null} aria-busy={busy}
                        aria-label={`Restore ${row.unit} checkout to the Today list`} onClick={() => void restore(row)}>
                        {busy ? 'Restoring…' : 'Restore'}
                      </button>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>
        )}
    </div>
  );
}
