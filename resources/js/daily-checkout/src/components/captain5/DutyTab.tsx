import { useEffect, useRef, useState, type FormEvent } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { Captain5ApiError, assignActing, isRetryable, revokeAssignment, searchPeople } from '../../utils/captain5Api';
import { dayText, newKey, todayYmd } from './format';
import type { DutyAssignment, PersonOption } from './types';
import { BTN_PRIMARY, BTN_SECONDARY, FIELD, HINT, LABEL } from './ui';

interface Props {
  assignments: DutyAssignment[];
  onChange: (assignments: DutyAssignment[]) => void;
}

const message = (error: unknown, fallback: string) => (error instanceof Error && error.message ? error.message : fallback);

function AssignmentRow({ assignment, onChange }: { assignment: DutyAssignment; onChange: Props['onChange'] }) {
  const [ending, setEnding] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const end = async (event: FormEvent) => {
    event.preventDefault();
    if (busy) return;
    if (reason.trim() === '') { setError('Give a reason for ending this assignment.'); return; }
    setBusy(true);
    setError(null);
    try {
      onChange(await revokeAssignment(assignment.id, reason.trim()));
    } catch (failure) {
      if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
      setError(message(failure, 'The assignment could not be ended. Try again.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <li className="rounded-hub-control border border-hub-border bg-hub-well p-3" data-testid="captain5-assignment">
      <p className="text-sm font-semibold text-hub-ink">{[assignment.rank, assignment.name].filter(Boolean).join(' ')}</p>
      <p className={HINT}>{dayText(assignment.duty_date)}{assignment.shift ? ` · ${assignment.shift} Shift` : ''}{assignment.assigned_by ? ` · assigned by ${assignment.assigned_by}` : ''}</p>
      {assignment.reason && <p className="mt-1 break-words text-sm text-hub-ink">Reason: {assignment.reason}</p>}
      {ending ? (
        <form onSubmit={end} noValidate className="mt-3 space-y-2" aria-label={`End assignment for ${assignment.name}`}>
          <label className={LABEL} htmlFor={`c5-end-${assignment.id}`}>Why is this assignment ending?</label>
          <textarea id={`c5-end-${assignment.id}`} rows={2} className={FIELD} value={reason} disabled={busy} maxLength={500} onChange={(e) => setReason(e.target.value)} />
          {error && <p role="alert" className="hub-callout hub-callout--warning p-3 text-sm">{error}</p>}
          <div className="flex flex-wrap gap-2">
            <button type="submit" className={BTN_PRIMARY} disabled={busy} aria-busy={busy}>{busy ? 'Ending…' : 'End assignment'}</button>
            <button type="button" className={`${BTN_SECONDARY}`} disabled={busy} onClick={() => { setEnding(false); setError(null); }}>Keep assignment</button>
          </div>
        </form>
      ) : (
        <button type="button" className={`${BTN_SECONDARY} mt-2`} onClick={() => setEnding(true)}>End assignment<span className="sr-only"> for {assignment.name} on {dayText(assignment.duty_date)}</span></button>
      )}
    </li>
  );
}

function AssignForm({ onChange }: { onChange: Props['onChange'] }) {
  const [term, setTerm] = useState('');
  const [people, setPeople] = useState<PersonOption[] | null>(null);
  const [searchError, setSearchError] = useState<string | null>(null);
  const [person, setPerson] = useState<PersonOption | null>(null);
  const [date, setDate] = useState(todayYmd());
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);
  const [retry, setRetry] = useState(false);
  const keyRef = useRef(newKey());

  useEffect(() => {
    setSearchError(null);
    if (person !== null || term.trim().length < 2) { setPeople(null); return undefined; }
    let active = true;
    const timer = setTimeout(() => {
      searchPeople(term)
        .then((found) => { if (active) setPeople(found); })
        .catch((failure) => { if (active) setSearchError(message(failure, 'The member search failed. Try again.')); });
    }, 300);
    return () => { active = false; clearTimeout(timer); };
  }, [term, person]);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (busy) return;
    if (!person) { setError('Choose the member who will act as Captain 5.'); return; }
    if (reason.trim() === '') { setError('Give a reason for this assignment.'); return; }
    setBusy(true);
    setError(null);
    setRetry(false);
    try {
      const next = await assignActing({ employee_profile_id: person.id, duty_date: date, reason: reason.trim(), client_request_id: keyRef.current });
      keyRef.current = newKey();
      setDone(`${person.name} is acting Captain 5 on ${dayText(date)}.`);
      setPerson(null);
      setTerm('');
      setReason('');
      onChange(next);
    } catch (failure) {
      if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
      if (failure instanceof Captain5ApiError && failure.status === 422) keyRef.current = newKey();
      setRetry(isRetryable(failure));
      setError(message(failure, 'The assignment could not be saved. Try again.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit} noValidate className="space-y-3 rounded-hub-control border border-hub-border bg-hub-n-25 p-3 sm:p-4" aria-label="Assign acting Captain 5" data-testid="captain5-assign-form">
      <h3 className="font-heading text-base font-bold text-hub-ink">Assign an acting Captain 5</h3>
      <p className={HINT}>Gives one member Captain 5 authority for one duty day, up to two weeks ahead. Rank alone never grants it.</p>
      <div>
        <label className={LABEL} htmlFor="c5-person-search">Member</label>
        {person ? (
          <div className="flex flex-wrap items-center gap-2">
            <p className="text-sm font-semibold text-hub-ink">{[person.rank, person.name].filter(Boolean).join(' ')}{person.station ? ` (${person.station})` : ''}</p>
            <button type="button" className={BTN_SECONDARY} onClick={() => { setPerson(null); setDone(null); }}>Change member</button>
          </div>
        ) : (
          <>
            <input id="c5-person-search" type="search" className={`${FIELD} sm:max-w-md`} value={term} placeholder="Type at least 2 letters of a name" autoComplete="off" maxLength={60} onChange={(e) => { setTerm(e.target.value); setDone(null); }} />
            {searchError && <p role="alert" className="mt-1 text-[13px] font-semibold text-hub-danger">{searchError}</p>}
            {people !== null && people.length === 0 && <p className={`${HINT} mt-1`} role="status">No current member matches &ldquo;{term.trim()}&rdquo;.</p>}
            {people !== null && people.length > 0 && (
              <ul className="mt-2 max-h-64 space-y-1 overflow-y-auto sm:max-w-md" aria-label="Matching members">
                {people.map((candidate) => (
                  <li key={candidate.id}>
                    <button type="button" className="flex min-h-11 w-full items-center rounded-hub-control border border-hub-border bg-hub-well px-3 py-2 text-left text-sm text-hub-ink hover:bg-hub-blue-tint" onClick={() => setPerson(candidate)}>
                      <span className="font-semibold">{[candidate.rank, candidate.name].filter(Boolean).join(' ')}</span>
                      {candidate.station && <span className="ml-2 text-hub-n-600">{candidate.station}</span>}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
      </div>
      <div>
        <label className={LABEL} htmlFor="c5-duty-date">Duty date</label>
        <input id="c5-duty-date" type="date" className={`${FIELD} sm:max-w-xs`} min={todayYmd()} value={date} disabled={busy} onChange={(e) => setDate(e.target.value)} />
      </div>
      <div>
        <label className={LABEL} htmlFor="c5-duty-reason">Reason</label>
        <textarea id="c5-duty-reason" rows={2} className={FIELD} value={reason} disabled={busy} maxLength={500} onChange={(e) => setReason(e.target.value)} />
      </div>
      {error && <p role="alert" className="hub-callout hub-callout--warning p-3 text-sm">{error}</p>}
      {done && !error && <p role="status" className="text-sm font-semibold text-hub-success">{done}</p>}
      <button type="submit" className={BTN_PRIMARY} disabled={busy} aria-busy={busy}>{busy ? 'Saving…' : retry ? 'Retry assignment' : 'Confirm assignment'}</button>
    </form>
  );
}

export default function DutyTab({ assignments, onChange }: Props) {
  return (
    <div className="space-y-6">
      <section aria-labelledby="c5-assignments-heading" className="space-y-3">
        <h3 id="c5-assignments-heading" className="font-heading text-lg font-bold text-hub-ink">Acting assignments</h3>
        {assignments.length === 0
          ? <p className={HINT}>No acting Captain 5 is assigned for today or upcoming duty days. The bid holder is on duty.</p>
          : <ul className="space-y-2" aria-label="Acting assignments">{assignments.map((a) => <AssignmentRow key={a.id} assignment={a} onChange={onChange} />)}</ul>}
      </section>
      <AssignForm onChange={onChange} />
    </div>
  );
}
