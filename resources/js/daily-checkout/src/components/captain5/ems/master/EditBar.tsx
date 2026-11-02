import { useState } from 'react';
import { BTN, BTN_PRIMARY, BTN_SECONDARY, ConfirmStep, HINT } from '../shared';
import { saveLabel } from './batchLogic';
import type { MasterItem } from './types';

interface Props {
  dirtyCount: number;
  busy: boolean;
  retry: boolean;
  error: string | null;
  general: readonly string[];
  problems: readonly MasterItem[];
  /** What the last save did, shown in place of the hint until the next change. */
  note: string | null;
  /** Asking to leave while changes are open shows the confirm step instead of leaving. */
  confirming: boolean;
  onConfirming: (open: boolean) => void;
  onSave: () => void;
  onDiscard: () => void;
  onDone: () => void;
  onJump: (item: MasterItem) => void;
}

/**
 * The bar that stays at the bottom of the screen in Edit mode: Save N changes and Cancel, or Done when nothing has
 * changed. Leaving with changes asks first. Rows that need fixing are listed so the ones on other shelves can be found.
 */
export default function EditBar({ dirtyCount, busy, retry, error, general, problems, note, confirming, onConfirming, onSave, onDiscard, onDone, onJump }: Props) {
  const [showAll, setShowAll] = useState(false);
  const shown = showAll ? problems : problems.slice(0, 5);
  return (
    <div className="sticky bottom-0 z-20 rounded-hub-card border border-hub-border bg-hub-n-0 px-3 py-3 shadow-[var(--hub-elevation-3)] sm:px-4" data-testid="edit-bar">
      <div className="space-y-2">
        <div role="alert" aria-live="assertive" data-testid="edit-errors">
          {(general.length > 0 || error) && (
            <div className="space-y-1 text-sm font-semibold text-hub-danger">
              {general.map((message) => <p key={message}>{message}</p>)}
              {error && <p>{error}</p>}
            </div>
          )}
        </div>
        {/* The one polite region for Edit mode: what the last save did. */}
        <div role="status" aria-live="polite" data-testid="edit-saved-region">
          {note && dirtyCount === 0 && !confirming && <p className="text-sm font-semibold text-hub-success" data-testid="edit-saved">{note}</p>}
        </div>
        {problems.length > 0 && (
          <ul className="flex flex-wrap gap-x-3 text-sm" aria-label="Items that need fixing" data-testid="edit-problems">
            {shown.map((item) => (
              <li key={item.id}>
                <button type="button" className="inline-flex min-h-11 items-center font-semibold text-hub-blue-strong underline underline-offset-2" onClick={() => onJump(item)}>{item.name}</button>
              </li>
            ))}
            {problems.length > shown.length && (
              <li><button type="button" className="inline-flex min-h-11 items-center text-hub-n-600 underline" onClick={() => setShowAll(true)}>{problems.length - shown.length} more</button></li>
            )}
          </ul>
        )}

        {confirming ? (
          <ConfirmStep testId="edit-discard-confirm" title={`Discard ${dirtyCount} ${dirtyCount === 1 ? 'change' : 'changes'}?`} confirmLabel="Discard changes" busy={false}
            body={<p>Nothing you changed in Edit mode has been saved yet.</p>} onConfirm={onDiscard} onCancel={() => onConfirming(false)} />
        ) : dirtyCount > 0 ? (
          <div className="flex flex-wrap items-center gap-2">
            <button type="button" className={`${BTN_PRIMARY} !min-h-12 flex-1 text-base sm:flex-none`} disabled={busy} aria-busy={busy} data-testid="edit-save" onClick={onSave}>
              {busy ? 'Saving…' : retry ? `Try again: ${saveLabel(dirtyCount)}` : saveLabel(dirtyCount)}
            </button>
            <button type="button" className={`${BTN_SECONDARY} !min-h-12`} disabled={busy} data-testid="edit-cancel" onClick={() => onConfirming(true)}>Cancel</button>
          </div>
        ) : (
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className={HINT}>{note ? 'Change more boxes, or press Done.' : 'Change any box, then save once for everything.'}</p>
            <button type="button" className={`${BTN} hub-btn--secondary`} data-testid="edit-done-bottom" onClick={onDone}>Done</button>
          </div>
        )}
      </div>
    </div>
  );
}
