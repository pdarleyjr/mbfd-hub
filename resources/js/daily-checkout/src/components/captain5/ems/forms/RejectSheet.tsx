import { useId, useRef, useState, type FormEvent } from 'react';
import * as Dialog from '@radix-ui/react-dialog';
import { BTN, BTN_SECONDARY, FIELD, HINT, LABEL, LiveNote, useWrite } from '../shared';
import { rejectForm } from './api';
import { REASON_MAX, reasonError } from './logic';
import type { FormDetail } from './types';

export interface RejectTarget { id: string; member: string; number: string }

interface Props {
  target: RejectTarget | null;
  onClose: () => void;
  /** The form went back to the member: the server's fresh answer. */
  onDone: (detail: FormDetail) => void;
  /** Someone else changed the form first. */
  onConflict: () => void;
}

function RejectBody({ target, onClose, onDone, onConflict }: Omit<Props, 'target'> & { target: RejectTarget }) {
  const id = useId();
  const [reason, setReason] = useState('');
  const [problem, setProblem] = useState<string | null>(null);
  const field = useRef<HTMLTextAreaElement>(null);
  const write = useWrite();

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const found = reasonError(reason);
    setProblem(found);
    if (found) { field.current?.focus(); return; }
    const text = reason.trim();
    void write.run(`reject:${target.id}:${text}`, (key) => rejectForm(target.id, text, key), { onDone, onConflict });
  };

  return (
    <form onSubmit={submit} noValidate className="space-y-3" aria-label="Send back">
      <div>
        <label className={LABEL} htmlFor={`${id}-reason`}>What needs to be fixed <span className="text-hub-danger">(required)</span></label>
        <textarea
          id={`${id}-reason`}
          ref={field}
          rows={3}
          className={FIELD}
          value={reason}
          maxLength={REASON_MAX}
          disabled={write.busy}
          aria-invalid={problem ? true : undefined}
          aria-describedby={`${id}-hint${problem ? ` ${id}-error` : ''}`}
          onChange={(event) => { setReason(event.target.value); setProblem(null); }}
        />
        <p id={`${id}-hint`} className={`${HINT} mt-1`}>{reason.trim().length} of {REASON_MAX} characters. {target.member} is notified and can send a corrected form.</p>
        {problem && <p id={`${id}-error`} role="alert" className="mt-1 text-[13px] font-semibold text-hub-danger">{problem}</p>}
      </div>
      <LiveNote tone="warning">{write.error}</LiveNote>
      <div className="flex flex-wrap gap-2">
        <button type="submit" data-testid="ems-reject-send" className={`${BTN} hub-btn--destructive`} disabled={write.busy} aria-busy={write.busy}>
          {write.busy ? 'Sending back…' : write.retry ? 'Retry: Send back' : 'Send back'}
        </button>
        <button type="button" className={BTN_SECONDARY} disabled={write.busy} onClick={onClose}>Cancel</button>
      </div>
    </form>
  );
}

/** A small sheet: a required reason, then Send back. Nothing changes in stock. */
export default function RejectSheet({ target, onClose, onDone, onConflict }: Props) {
  return (
    <Dialog.Root open={target !== null} onOpenChange={(next) => { if (!next) onClose(); }}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 z-[90] bg-hub-n-900/40" />
        <Dialog.Content
          data-hub-ui="2"
          data-testid="ems-reject-sheet"
          className="fixed inset-x-0 bottom-0 z-[91] max-h-[90dvh] overflow-y-auto rounded-t-hub-sheet border border-hub-glass-border bg-hub-n-0 p-4 pb-[max(1rem,env(safe-area-inset-bottom))] font-hub shadow-[var(--hub-elevation-3)] sm:inset-x-auto sm:bottom-auto sm:left-1/2 sm:top-1/2 sm:w-full sm:max-w-md sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-hub-sheet"
        >
          <Dialog.Title className="font-heading text-lg font-bold text-hub-ink">
            {target ? `Send this back to ${target.member} to correct` : 'Send back'}
          </Dialog.Title>
          <Dialog.Description className={`${HINT} mb-3`}>
            {target ? `Form ${target.number}. It stays in Active as returned until ${target.member} sends it again.` : ''}
          </Dialog.Description>
          {target && <RejectBody key={target.id} target={target} onClose={onClose} onDone={onDone} onConflict={onConflict} />}
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
