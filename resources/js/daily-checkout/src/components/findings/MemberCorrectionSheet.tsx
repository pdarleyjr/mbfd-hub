import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import * as Dialog from '@radix-ui/react-dialog';
import type { IssueSummary } from '../../types/issueHistory.ts';
import { useOffline } from '../../hooks/useOffline';
import { setUnsavedWork } from '../../pwa/draftBusy.ts';
import {
  MemberIssueActionError, NOTE_MAX, newClientId, submitMemberCorrection, type CorrectionOutcome,
} from '../../utils/memberIssueActionsApi';
import { announceFindingsChanged, ownerName } from './findingsChanged.ts';
import './findings.css';
import './memberActions.css';

export const CORRECTION_CHOICES: ReadonlyArray<{ value: CorrectionOutcome; label: string }> = [
  { value: 'found', label: 'Found' },
  { value: 'replaced', label: 'Replaced' },
  { value: 'repaired', label: 'Repaired' },
  { value: 'corrected', label: 'Fixed' },
  { value: 'no_longer_deficient', label: 'No longer a problem' },
];

const OFFLINE_NOTICE = "You're offline. This can't be sent until you are back online. It is not saved for later.";
const CLOSED_ELSEWHERE = 'Someone already closed this issue. The list will refresh when you close this.';

type Phase = 'idle' | 'saving' | 'done' | 'closed' | 'blocked';

interface Props {
  issue: IssueSummary;
  open: boolean;
  onClose: () => void;
}

interface BodyProps {
  issue: IssueSummary;
  onClose: () => void;
}

function SheetBody({ issue, onClose }: BodyProps) {
  const id = useId();
  // One id for this opening of the sheet: a retry after a dropped answer is never counted twice.
  const [clientActionId] = useState(() => newClientId());
  const busyToken = useRef(Symbol('member-correction'));
  const offline = useOffline();
  const [outcome, setOutcome] = useState<CorrectionOutcome | null>(null);
  const [note, setNote] = useState('');
  const [choiceProblem, setChoiceProblem] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [phase, setPhase] = useState<Phase>('idle');
  const [doneMessage, setDoneMessage] = useState('');
  const firstChoice = useRef<HTMLInputElement>(null);
  const correction = issue.member_correction;
  const closesNow = correction?.closes_immediately === true;
  const baseLabel = closesNow ? 'Mark resolved' : 'Report fixed';
  const saving = phase === 'saving';
  const finished = phase === 'done' || phase === 'closed' || phase === 'blocked';

  // An update must not reload the page while this is being sent or while a note is half written.
  useEffect(() => {
    setUnsavedWork(busyToken.current, saving || (!finished && note.trim() !== ''));
  }, [saving, finished, note]);
  useEffect(() => {
    const token = busyToken.current;
    return () => setUnsavedWork(token, false);
  }, []);

  const send = (event: FormEvent) => {
    event.preventDefault();
    if (saving || finished) return;
    if (outcome === null) {
      setChoiceProblem('Choose what happened.');
      firstChoice.current?.focus();
      return;
    }
    setChoiceProblem(null);
    setFailure(null);
    setPhase('saving');
    submitMemberCorrection(issue.id, { clientActionId, outcome, note }).then((result) => {
      setDoneMessage(result.message);
      setPhase('done');
      // The card may vanish when the list is read again, so the page keeps the server's words.
      announceFindingsChanged({ message: result.message, refresh: false });
    }).catch((error: unknown) => {
      const known = error instanceof MemberIssueActionError ? error : null;
      if (known?.isIssueClosed) {
        setFailure(CLOSED_ELSEWHERE);
        setPhase('closed');
        return;
      }
      if (known?.isForbidden) {
        setFailure(known.message);
        setPhase('blocked');
        return;
      }
      setFailure(known ? known.message : 'This could not be sent. Try again.');
      setPhase('idle');
    });
  };

  const finish = () => {
    // Done and "already closed" both mean the list on screen is out of date.
    if (phase === 'done' || phase === 'closed') announceFindingsChanged({ refresh: true });
    onClose();
  };

  const verifyLine = closesNow
    ? 'This closes the issue right away.'
    : `${ownerName(issue.owner)} will verify this before it is closed.`;

  return (
    <form onSubmit={send} noValidate className="member-sheet__form" aria-label="Mark this issue fixed">
      {phase === 'done' ? (
        <div className="member-sheet__done" role="status" data-testid="member-correction-done">
          <p>{doneMessage}</p>
        </div>
      ) : (
        <>
          <fieldset className="member-sheet__choices" disabled={saving || finished}>
            <legend>What happened?</legend>
            {CORRECTION_CHOICES.map((choice, index) => (
              <label key={choice.value} className="concern-option">
                <input
                  ref={index === 0 ? firstChoice : undefined}
                  type="radio"
                  name={`${id}-outcome`}
                  value={choice.value}
                  checked={outcome === choice.value}
                  aria-describedby={choiceProblem ? `${id}-choice-error` : undefined}
                  onChange={() => { setOutcome(choice.value); setChoiceProblem(null); }}
                />
                <span>{choice.label}</span>
              </label>
            ))}
          </fieldset>
          {choiceProblem && <p id={`${id}-choice-error`} role="alert" className="concern-error">{choiceProblem}</p>}

          <div className="member-sheet__note">
            <label htmlFor={`${id}-note`}>Note (optional)</label>
            <textarea
              id={`${id}-note`}
              rows={3}
              maxLength={NOTE_MAX}
              value={note}
              disabled={saving || finished}
              aria-describedby={`${id}-note-count`}
              onChange={event => setNote(event.target.value)}
            />
            <p id={`${id}-note-count`} className="member-sheet__hint">{note.length} of {NOTE_MAX} characters</p>
          </div>

          {!finished && <p className="member-sheet__verify" data-testid="member-correction-verify">{verifyLine}</p>}
        </>
      )}

      {offline && !finished && <p role="status" className="member-sheet__offline" data-testid="member-correction-offline">{OFFLINE_NOTICE}</p>}
      <div aria-live="assertive">
        {failure && <p role="alert" className="member-sheet__error" data-testid="member-correction-error">{failure}</p>}
      </div>

      <div className="member-sheet__buttons">
        {finished ? (
          <button type="button" className="hub-btn hub-btn--primary" onClick={finish}>{phase === 'done' ? 'Done' : 'Close'}</button>
        ) : (
          <>
            <button type="submit" className="hub-btn hub-btn--primary" disabled={saving} aria-busy={saving}>
              {saving ? 'Saving...' : failure ? `Retry: ${baseLabel}` : baseLabel}
            </button>
            <button type="button" className="hub-btn hub-btn--secondary" disabled={saving} onClick={onClose}>Cancel</button>
          </>
        )}
      </div>
    </form>
  );
}

/**
 * Mark an open issue fixed. The server decides whether that closes it now or waits for the owner to verify, and the
 * button says which. Network only: nothing here is queued for later.
 */
export default function MemberCorrectionSheet({ issue, open, onClose }: Props) {
  return (
    <Dialog.Root open={open} onOpenChange={(next) => { if (!next) onClose(); }}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 z-[90] bg-hub-n-900/40" />
        <Dialog.Content
          data-hub-ui="2"
          data-testid="member-correction-sheet"
          className="member-sheet fixed inset-x-0 bottom-0 z-[91] max-h-[90dvh] overflow-y-auto rounded-t-hub-sheet border border-hub-glass-border bg-hub-n-0 p-4 pb-[max(1rem,env(safe-area-inset-bottom))] font-hub shadow-[var(--hub-elevation-3)] sm:inset-x-auto sm:bottom-auto sm:left-1/2 sm:top-1/2 sm:w-full sm:max-w-md sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-hub-sheet"
        >
          <Dialog.Title className="font-heading text-lg font-bold text-hub-ink">Mark this issue fixed</Dialog.Title>
          <Dialog.Description className="member-sheet__subject">
            {issue.item !== '' ? issue.item : 'Item not recorded'}{issue.compartment !== '' ? ` in ${issue.compartment}` : ''}
          </Dialog.Description>
          {open && <SheetBody key={issue.id} issue={issue} onClose={onClose} />}
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
