import { useId, useState } from 'react';
import { BTN_PRIMARY, BTN_SECONDARY, FIELD, HINT, LABEL, LiveNote, useWrite } from '../shared';
import { acknowledgeForm, clearForm } from './api';
import { CLEAR_NOTE_MAX, clearReason } from './logic';
import type { FormDetail, FormSummary } from './types';

type Target = Pick<FormSummary, 'id' | 'number' | 'target_label' | 'can_acknowledge' | 'can_reject' | 'can_clear'>;

interface Props {
  form: Target;
  canAct: boolean;
  /** Acknowledged: the server's fresh answer for this form. */
  onAcknowledged: (detail: FormDetail) => void;
  /** Cleared (closed and archived): the server's fresh answer for this form. */
  onCleared?: (detail: FormDetail) => void;
  /** Open the Send back sheet for this form. */
  onReject: () => void;
  /** Someone else changed the form first: refresh what is on screen. */
  onConflict: () => void;
  /** The form's own sheet offers an optional note for Clear; a list card clears in one tap. */
  withClearNote?: boolean;
  testIdPrefix?: string;
}

/**
 * The actions on a form that is still being worked: Acknowledge, Clear and Reject. Clear is for supplies Captain 5
 * handed over himself: the form moves to Archived and stock does not change. Reconcile (the step that takes items off
 * the supply room shelf) stays on the selection bar. The idempotency key is kept until the server confirms, so a
 * retry after a dropped connection is safe.
 */
export default function FormActionButtons({
  form, canAct, onAcknowledged, onCleared, onReject, onConflict, withClearNote = false, testIdPrefix = 'ems-form',
}: Props) {
  const write = useWrite();
  const [doing, setDoing] = useState<'acknowledge' | 'clear' | null>(null);
  const [note, setNote] = useState('');
  const noteId = useId();
  const canClear = form.can_clear === true && onCleared !== undefined;
  if (!canAct || (!form.can_acknowledge && !form.can_reject && !canClear)) return null;

  const acknowledgeLabel = write.busy && doing === 'acknowledge' ? 'Acknowledging…' : write.retry && doing === 'acknowledge' ? 'Retry: Acknowledge' : 'Acknowledge';
  const clearLabel = write.busy && doing === 'clear' ? 'Clearing…' : write.retry && doing === 'clear' ? 'Retry: Clear' : 'Clear';
  const acknowledge = () => {
    setDoing('acknowledge');
    void write.run(`acknowledge:${form.id}`, (key) => acknowledgeForm(form.id, key), { onDone: onAcknowledged, onConflict });
  };
  const clear = () => {
    if (!onCleared) return;
    const reason = clearReason(note);
    setDoing('clear');
    void write.run(`clear:${form.id}:${reason}`, (key) => clearForm(form.id, reason, key), {
      onDone: (detail) => { setNote(''); onCleared(detail); },
      onConflict,
    });
  };

  return (
    <div className="space-y-2">
      {canClear && withClearNote && (
        <div>
          <label className={LABEL} htmlFor={noteId}>Note for Clear (optional)</label>
          <input id={noteId} type="text" className={FIELD} value={note} maxLength={CLEAR_NOTE_MAX} autoComplete="off" disabled={write.busy}
            placeholder="For example: handed over at Station 2" data-testid={`${testIdPrefix}-clear-note`} onChange={(event) => setNote(event.target.value)} />
        </div>
      )}
      <div className="flex flex-wrap gap-2">
        {form.can_acknowledge && (
          <button type="button" data-testid={`${testIdPrefix}-acknowledge`} className={BTN_PRIMARY} disabled={write.busy} aria-busy={write.busy && doing === 'acknowledge'}
            aria-label={`${acknowledgeLabel} ${form.target_label} form ${form.number}`} onClick={acknowledge}>
            {acknowledgeLabel}
          </button>
        )}
        {canClear && (
          <button type="button" data-testid={`${testIdPrefix}-clear`} className={BTN_SECONDARY} disabled={write.busy} aria-busy={write.busy && doing === 'clear'}
            aria-label={`${clearLabel} ${form.target_label} form ${form.number}: supplies provided, move to Archived`} onClick={clear}>
            {clearLabel}
          </button>
        )}
        {form.can_reject && (
          <button type="button" data-testid={`${testIdPrefix}-reject`} className={BTN_SECONDARY} disabled={write.busy}
            aria-label={`Reject ${form.target_label} form ${form.number}`} onClick={onReject}>
            Reject
          </button>
        )}
      </div>
      {canClear && withClearNote && <p className={HINT}>Clear when you handed the supplies over yourself. The form moves to Archived and supply room stock does not change. To take items off the shelf, reconcile instead.</p>}
      <LiveNote tone="warning">{write.error}</LiveNote>
    </div>
  );
}
