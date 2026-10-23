import { useState, type FormEvent } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { approveActing, rejectActing, requestFailureMessage } from '../../utils/captain5Api';
import Tag from '../ui/Tag';
import { ageText, dateTimeText } from './format';
import type { ActingRequest, ActingResult, ActingState } from './types';
import { BTN_PRIMARY, BTN_SECONDARY, FIELD, HINT, LABEL } from './ui';

interface Props {
  acting: ActingState;
  onChange: (result: ActingResult) => void;
}

const whoOf = (request: ActingRequest): string => [request.rank, request.name].filter(Boolean).join(' ') || request.requested_by;

function Request({ request, onChange }: { request: ActingRequest; onChange: Props['onChange'] }) {
  const [busy, setBusy] = useState<'approve' | 'reject' | null>(null);
  const [declining, setDeclining] = useState(false);
  const [note, setNote] = useState('');
  const [error, setError] = useState<string | null>(null);
  const who = whoOf(request);

  const decide = async (kind: 'approve' | 'reject') => {
    setBusy(kind);
    setError(null);
    try {
      onChange(kind === 'approve' ? await approveActing(request.id) : await rejectActing(request.id, note));
    } catch (failure) {
      if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
      setError(requestFailureMessage(failure, 'The decision could not be saved. Try again.'));
    } finally {
      setBusy(null);
    }
  };

  const decline = (event: FormEvent) => {
    event.preventDefault();
    if (busy) return;
    if (note.trim() === '') { setError('Write a short note so the member knows why.'); return; }
    void decide('reject');
  };

  return (
    <li className="rounded-hub-control border border-hub-border bg-hub-well p-3" data-testid="captain5-acting-pending">
      <p className="break-words text-sm font-semibold text-hub-ink">
        {request.kind === 'end'
          ? 'End the current acting Captain 5'
          : `${who} to act as Captain 5${request.ends_at ? ` until ${dateTimeText(request.ends_at)}` : ''}`}
      </p>
      <p className={`${HINT} flex flex-wrap items-center gap-x-2`}>
        <span>Requested by {request.requested_by}, {ageText(request.requested_at)}</span>
        {request.shift && <Tag>{request.shift} Shift</Tag>}
      </p>
      {declining ? (
        <form onSubmit={decline} noValidate className="mt-3 space-y-2" aria-label={`Not approved: ${who}`}>
          <label className={LABEL} htmlFor={`c5-reject-${request.id}`}>Why is this not approved?</label>
          <textarea id={`c5-reject-${request.id}`} rows={2} className={FIELD} value={note} maxLength={500} disabled={busy !== null} onChange={(event) => setNote(event.target.value)} />
          {error && <p role="alert" className="hub-callout hub-callout--warning p-3 text-sm">{error}</p>}
          <div className="flex flex-wrap gap-2">
            <button type="submit" className={BTN_PRIMARY} disabled={busy !== null} aria-busy={busy === 'reject'}>{busy === 'reject' ? 'Saving…' : 'Send decision'}</button>
            <button type="button" className={BTN_SECONDARY} disabled={busy !== null} onClick={() => { setDeclining(false); setError(null); }}>Cancel</button>
          </div>
        </form>
      ) : (
        <>
          {error && <p role="alert" className="hub-callout hub-callout--warning mt-2 p-3 text-sm">{error}</p>}
          <div className="mt-3 flex flex-wrap gap-2">
            <button type="button" className={BTN_PRIMARY} disabled={busy !== null} aria-busy={busy === 'approve'} onClick={() => void decide('approve')}>
              {busy === 'approve' ? 'Approving…' : 'Approve'}<span className="sr-only"> {who}</span>
            </button>
            <button type="button" className={BTN_SECONDARY} disabled={busy !== null} onClick={() => { setDeclining(true); setError(null); }}>
              Not approved<span className="sr-only"> {who}</span>
            </button>
          </div>
        </>
      )}
    </li>
  );
}

/** Administrators only: requests to be shown (or to stop being shown) as the acting Captain 5. */
export default function ActingApprovals({ acting, onChange }: Props) {
  if (!acting.can_approve || acting.pending.length === 0) return null;
  return (
    <section className="hub-panel space-y-3 p-4 sm:p-5" aria-labelledby="c5-approvals-heading" data-testid="captain5-acting-approvals">
      <div>
        <h2 id="c5-approvals-heading" className="font-heading text-lg font-bold text-hub-ink">Waiting for approval</h2>
        <p className={HINT}>Approving only changes the name shown at the top of this page.</p>
      </div>
      <ul className="space-y-2" aria-label="Acting Captain 5 requests">
        {acting.pending.map((request) => <Request key={request.id} request={request} onChange={onChange} />)}
      </ul>
    </section>
  );
}
