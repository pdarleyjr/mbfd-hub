import { useEffect, useRef, useState, type FormEvent } from 'react';
import { CircleAlert, CircleCheck } from 'lucide-react';
import type { StationRequestSummary } from '../../types';
import { redirectToLoginAfterSessionExpiry } from '../../utils/api';
import {
  memberResolutionOf,
  newResolveActionId,
  RESOLVE_NOTE_MAX,
  RESOLVE_OTHER_NOTE_MIN,
  RESOLVE_OUTCOMES,
  resolveStationRequest,
  StationRequestResolveError,
  type ResolveOutcome,
} from '../../utils/stationRequestResolveApi';
import { clearBusy, markBusy } from '../../../../pwa/busy.ts';
import { BTN_PRIMARY, BTN_SECONDARY, FIELD, HINT, LABEL, Sheet } from '../captain5/ui';

const BUSY_REASON = 'station-request-resolve';

type Phase = 'form' | 'saving' | 'saved' | 'closed';

interface StationRequestResolveSheetProps {
  request: StationRequestSummary | null;
  /** 1 when opened over the request detail sheet. */
  layer?: 0 | 1;
  onClose: () => void;
  /** The server accepted the resolve, or says the request was already closed: refresh the list. */
  onResolved: () => void;
  returnFocusTo?: () => HTMLElement | null;
}

/**
 * "Mark resolved": a short form for the member who fixed it, no longer needs it, or received it. The server decides
 * who may and whether a note is required; this form only asks and shows the answer. Network only: offline says so
 * and keeps what was typed. One client action id per open form, so a retry after a dropped answer is safe.
 */
export default function StationRequestResolveSheet({ request, layer = 0, onClose, onResolved, returnFocusTo }: StationRequestResolveSheetProps) {
  const [phase, setPhase] = useState<Phase>('form');
  const [outcome, setOutcome] = useState<ResolveOutcome | null>(null);
  const [note, setNote] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [noteError, setNoteError] = useState<string | null>(null);
  const [doneMessage, setDoneMessage] = useState('');
  const actionId = useRef<string>('');
  const requestId = request?.id ?? null;
  const noteRequired = request !== null && memberResolutionOf(request)?.note_required === true;

  // A fresh form, and a fresh action id, every time the sheet opens on a request.
  useEffect(() => {
    actionId.current = newResolveActionId();
    setPhase('form');
    setOutcome(null);
    setNote('');
    setError(null);
    setNoteError(null);
    setDoneMessage('');
  }, [requestId]);

  // A save in flight must not be dropped by an automatic app update.
  useEffect(() => {
    if (phase !== 'saving') return undefined;
    markBusy(BUSY_REASON);

    return () => clearBusy(BUSY_REASON);
  }, [phase]);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (request === null || phase === 'saving') return;
    if (outcome === null) {
      setError('Choose what happened.');
      return;
    }
    if (noteRequired && note.trim().length < RESOLVE_OTHER_NOTE_MIN) {
      setNoteError('Say what you did. A few words is enough.');
      setError(null);
      return;
    }

    setPhase('saving');
    setError(null);
    setNoteError(null);
    try {
      const result = await resolveStationRequest(request.id, { clientActionId: actionId.current, outcome, note });
      setDoneMessage(result.message);
      setPhase('saved');
      onResolved();
    } catch (reason) {
      if (!(reason instanceof StationRequestResolveError)) {
        setError('This could not be saved. Try again.');
        setPhase('form');
        return;
      }
      if (reason.isSessionEnded) {
        redirectToLoginAfterSessionExpiry();
        return;
      }
      if (reason.isClosed) {
        setDoneMessage(`This request is already ${(reason.currentStatusLabel ?? 'closed').toLowerCase()}. Nothing was changed.`);
        setPhase('closed');
        onResolved();
        return;
      }
      if (reason.fieldErrors.note) setNoteError(reason.fieldErrors.note);
      else setError(reason.message);
      setPhase('form');
    }
  };

  const saving = phase === 'saving';
  const finished = phase === 'saved' || phase === 'closed';

  return (
    <Sheet
      open={request !== null}
      onClose={() => { if (!saving) onClose(); }}
      layer={layer}
      testId="station-request-resolve-sheet"
      title="Mark resolved"
      description={request ? `${request.request_number} · ${request.title}` : undefined}
      returnFocusTo={returnFocusTo}
    >
      {request && finished && (
        <div className="space-y-4" role="status" data-testid="station-request-resolve-done">
          <p className="flex items-start gap-2 text-base font-semibold text-hub-ink">
            <CircleCheck className="mt-0.5 h-5 w-5 flex-none text-hub-success" aria-hidden="true" />
            {phase === 'saved' ? 'Marked resolved' : 'Already closed'}
          </p>
          <p className="text-sm text-hub-n-800">{doneMessage}</p>
          <button type="button" className={BTN_PRIMARY} onClick={onClose}>Done</button>
        </div>
      )}
      {request && !finished && (
        <form className="space-y-5" onSubmit={submit} noValidate data-testid="station-request-resolve-form">
          <fieldset disabled={saving} className="space-y-2">
            <legend className={LABEL}>What happened?</legend>
            {RESOLVE_OUTCOMES.map((choice) => (
              <label key={choice.value} className="hub-well flex min-h-11 cursor-pointer items-center gap-3 px-3 py-2 text-base text-hub-ink">
                <input
                  type="radio"
                  name="station-request-outcome"
                  value={choice.value}
                  checked={outcome === choice.value}
                  onChange={() => { setOutcome(choice.value); setError(null); }}
                  className="h-5 w-5 flex-none"
                />
                {choice.label}
              </label>
            ))}
          </fieldset>
          <div>
            <label htmlFor="station-request-resolve-note" className={LABEL}>
              {noteRequired ? 'What did you do? (required)' : 'Note (optional)'}
            </label>
            <textarea
              id="station-request-resolve-note"
              className={`${FIELD} min-h-28`}
              rows={4}
              maxLength={RESOLVE_NOTE_MAX}
              value={note}
              disabled={saving}
              onChange={(event) => { setNote(event.target.value); setNoteError(null); }}
              aria-invalid={noteError !== null}
              aria-describedby={noteError ? 'station-request-resolve-note-error' : 'station-request-resolve-note-hint'}
            />
            {noteError
              ? <p id="station-request-resolve-note-error" role="alert" className="mt-1 text-sm font-semibold text-hub-danger">{noteError}</p>
              : <p id="station-request-resolve-note-hint" className={`mt-1 ${HINT}`}>
                {noteRequired ? 'You did not submit this request, so say what you did.' : 'Anything the crew should know.'}
              </p>}
          </div>
          {error && (
            <p role="alert" className="flex items-start gap-2 rounded-hub-control border border-hub-danger-border bg-hub-danger-bg p-3 text-sm text-hub-n-800" data-testid="station-request-resolve-error">
              <CircleAlert className="mt-0.5 h-4 w-4 flex-none text-hub-danger" aria-hidden="true" />
              {error}
            </p>
          )}
          <div className="flex flex-wrap items-center gap-3">
            <button type="submit" className={`${BTN_PRIMARY} min-w-40`} disabled={saving} aria-busy={saving}>
              {saving ? 'Saving…' : 'Mark resolved'}
            </button>
            <button type="button" className={BTN_SECONDARY} onClick={onClose} disabled={saving}>Cancel</button>
          </div>
        </form>
      )}
    </Sheet>
  );
}
