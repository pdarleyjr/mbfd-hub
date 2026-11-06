import { useEffect, useState } from 'react';
import { ChevronRight, X } from 'lucide-react';
import Tag from '../ui/Tag';
import ClearedCheckouts from './ClearedCheckouts';
import { ChoiceChips } from './ems/shared';
import { dateTimeText } from './format';
import type { RescueDaily } from './types';
import { BTN_SECONDARY, HINT } from './ui';
import { useInspectionClear } from './useInspectionClear';

type View = 'today' | 'cleared';
const VIEWS: Array<{ id: View; label: string }> = [{ id: 'today', label: 'Today' }, { id: 'cleared', label: 'Cleared' }];

interface Props {
  dailies: RescueDaily[];
  /** The viewer may act on the Captain 5 page (Clear and Restore are offered). */
  canAct: boolean;
  /** Changes whenever the workspace reloads. */
  version: string;
  onOpenInspection: (id: number) => void;
  /** A checkout was cleared or restored: the workspace reloads. */
  onChanged: () => void;
}

interface RowProps {
  daily: RescueDaily;
  canClear: boolean;
  busy: boolean;
  disabled: boolean;
  onOpen: (id: number) => void;
  onClear: (daily: RescueDaily) => void;
}

function Row({ daily, canClear, busy, disabled, onOpen, onClear }: RowProps) {
  const submitted = daily.daily === 'submitted';
  const openable = submitted && daily.inspection_id !== null;
  const body = (
    <>
      <span className="flex min-w-0 flex-1 flex-col gap-1.5">
        <span className="flex flex-wrap items-baseline gap-x-2">
          <strong className="text-hub-ink">{daily.unit}</strong>
          {daily.station && <span className={HINT}>{daily.station}</span>}
        </span>
        <span className={`${HINT} break-words`}>
          {submitted
            ? `Submitted ${dateTimeText(daily.submitted_at)}${daily.submitted_by ? ` by ${daily.submitted_by}` : ''}`
            : "Today's daily checkout has not been submitted"}
        </span>
        <span className="flex flex-wrap gap-1.5">
          <Tag tone={submitted ? 'success' : 'warning'}>{submitted ? 'Daily submitted' : 'Daily not submitted'}</Tag>
          {daily.needs_approval && <Tag tone="warning">Needs approval</Tag>}
          {daily.open_issues > 0 && <Tag tone="info">{daily.open_issues} open {daily.open_issues === 1 ? 'issue' : 'issues'}</Tag>}
          {daily.status && daily.status.toLowerCase() !== 'in service' && <Tag>{daily.status}</Tag>}
        </span>
      </span>
      {openable && (
        <span className="flex flex-none items-center gap-1 text-sm font-semibold text-hub-blue">
          View<span className="sr-only"> {daily.unit} inspection</span>
          <ChevronRight className="h-4 w-4" aria-hidden="true" />
        </span>
      )}
    </>
  );
  const shell = 'flex min-h-14 min-w-0 flex-1 items-center gap-3 rounded-hub-control px-3.5 py-3 text-left';
  return (
    <li className="flex flex-col rounded-hub-control border border-hub-border bg-hub-well sm:flex-row sm:items-stretch">
      {openable
        ? <button type="button" data-testid="captain5-rescue-row" className={`${shell} transition-colors hover:bg-hub-blue-tint`} onClick={() => onOpen(daily.inspection_id as number)}>{body}</button>
        : <div data-testid="captain5-rescue-row" className={shell}>{body}</div>}
      {openable && canClear && (
        <div className="flex items-center border-t border-hub-border px-3.5 py-2.5 sm:border-l sm:border-t-0">
          <button type="button" data-testid="captain5-rescue-clear" className={`${BTN_SECONDARY} w-full sm:w-auto`} disabled={disabled} aria-busy={busy}
            aria-label={`Clear ${daily.unit} checkout from this list`} onClick={() => onClear(daily)}>
            {busy ? 'Clearing…' : 'Clear'}
          </button>
        </div>
      )}
    </li>
  );
}

/**
 * Today's Rescue daily checkouts. Captain 5 clears a checkout he has handled: it moves to Cleared (the checkout itself
 * does not change) and Undo or Restore brings it back.
 */
export default function RescueDailies({ dailies, canAct, version, onOpenInspection, onChanged }: Props) {
  const [view, setView] = useState<View>('today');
  /** Cleared here and not yet reflected by a reload. */
  const [hidden, setHidden] = useState<ReadonlySet<number>>(new Set());
  const [undo, setUndo] = useState<{ id: number; unit: string } | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const action = useInspectionClear();
  // A reload carries the server's answer, so what was hidden locally is no longer needed (and a restore elsewhere shows again).
  useEffect(() => { setHidden(new Set()); }, [version]);

  const active = dailies.filter((daily) => !daily.cleared_at && !(daily.inspection_id !== null && hidden.has(daily.inspection_id)));
  const clearedToday = dailies.length - active.length;

  const clear = async (daily: RescueDaily) => {
    if (daily.inspection_id === null) return;
    const id = daily.inspection_id;
    setNotice(null);
    const done = await action.clear(id);
    if (done === null) return;
    setHidden((current) => new Set([...current, id]));
    setUndo({ id, unit: daily.unit });
    onChanged();
  };
  const undoClear = async () => {
    if (undo === null) return;
    const done = await action.restore(undo.id);
    if (done === null) return;
    const id = undo.id;
    setHidden((current) => new Set([...current].filter((entry) => entry !== id)));
    setNotice(`Restored ${undo.unit}'s checkout to the Today list.`);
    setUndo(null);
    onChanged();
  };

  return (
    <section aria-labelledby="c5-dailies-heading" className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h3 id="c5-dailies-heading" className="font-heading text-lg font-bold text-hub-ink">Rescue daily checkouts</h3>
        <ChoiceChips<View> label="Show checkouts" testId="captain5-dailies-views" value={view} options={VIEWS} onChange={(next) => { setView(next); setUndo(null); setNotice(null); }} />
      </div>

      {view === 'cleared'
        ? <ClearedCheckouts canAct={canAct} version={version} onOpenInspection={onOpenInspection} onChanged={onChanged} />
        : (
          <>
            {canAct && dailies.some((daily) => daily.daily === 'submitted') && (
              <p className={HINT}>Clear a checkout once you have handled it. It moves to Cleared and the checkout itself does not change.</p>
            )}
            <div aria-live="polite" data-testid="captain5-dailies-notice">
              {undo !== null && (
                <div role="status" className="hub-callout flex flex-wrap items-center justify-between gap-2 p-3">
                  <p className="min-w-0 flex-1 text-sm font-semibold">Cleared {undo.unit}&apos;s checkout. It is under Cleared.</p>
                  <div className="flex flex-none gap-2">
                    <button type="button" data-testid="captain5-dailies-undo" className={BTN_SECONDARY} disabled={action.busyId !== null} aria-busy={action.busyId === undo.id} onClick={() => void undoClear()}>
                      {action.busyId === undo.id ? 'Restoring…' : 'Undo'}
                    </button>
                    <button type="button" className={`${BTN_SECONDARY} !min-w-11`} aria-label="Dismiss message" onClick={() => setUndo(null)}>
                      <X className="h-4 w-4" aria-hidden="true" />
                    </button>
                  </div>
                </div>
              )}
              {undo === null && notice && <p role="status" className="hub-callout p-3 text-sm font-semibold">{notice}</p>}
            </div>
            {action.error && <p role="alert" className="hub-callout hub-callout--warning p-3 text-sm">{action.error}</p>}
            {dailies.length === 0 && <p className={HINT}>No Rescue units are set up for Daily Checkout yet.</p>}
            {dailies.length > 0 && active.length === 0 && <p className={HINT}>All of today&apos;s Rescue checkouts are cleared. Open Cleared to see them.</p>}
            {active.length > 0 && (
              <ul className="space-y-2" aria-label="Rescue daily checkouts">
                {active.map((daily) => (
                  <Row key={daily.apparatus_id} daily={daily} canClear={canAct} busy={daily.inspection_id !== null && action.busyId === daily.inspection_id}
                    disabled={action.busyId !== null} onOpen={onOpenInspection} onClear={(target) => void clear(target)} />
                ))}
              </ul>
            )}
            {clearedToday > 0 && active.length > 0 && <p className={HINT}>{clearedToday} cleared today. Open Cleared to see {clearedToday === 1 ? 'it' : 'them'}.</p>}
          </>
        )}
    </section>
  );
}
