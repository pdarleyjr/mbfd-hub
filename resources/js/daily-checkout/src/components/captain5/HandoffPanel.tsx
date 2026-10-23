import { useRef, useState, type FormEvent } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { acknowledgeHandoff, isRetryable, writeHandoff } from '../../utils/captain5Api';
import Tag from '../ui/Tag';
import { ageText, dateTimeText, dayText, newKey } from './format';
import type { Handoff } from './types';
import { BTN_PRIMARY, BTN_SECONDARY, FIELD, HINT, LABEL } from './ui';

interface Props {
  handoff: Handoff;
  canAct: boolean;
  onChange: (handoff: Handoff) => void;
}

const messageOf = (error: unknown, fallback: string) => (error instanceof Error && error.message ? error.message : fallback);

export default function HandoffPanel({ handoff, canAct, onChange }: Props) {
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const [retry, setRetry] = useState(false);
  const [ackBusy, setAckBusy] = useState<number | null>(null);
  const [ackError, setAckError] = useState<{ id: number; text: string } | null>(null);
  // Notes written in this session; the next Captain acknowledges them, not their author.
  const ownIds = useRef(new Set<number>());
  const keyRef = useRef(newKey());
  const attempted = useRef<string | null>(null);

  const save = async (event: FormEvent) => {
    event.preventDefault();
    const text = body.trim();
    if (busy) return;
    if (text === '') { setError('Write the handoff before saving it.'); return; }
    if (attempted.current !== null && attempted.current !== text) keyRef.current = newKey();
    attempted.current = text;
    setBusy(true);
    setError(null);
    setRetry(false);
    try {
      const known = new Set(handoff.notes.map((note) => note.id));
      const next = await writeHandoff(text, keyRef.current);
      next.notes.filter((note) => !known.has(note.id)).forEach((note) => ownIds.current.add(note.id));
      keyRef.current = newKey();
      attempted.current = null;
      setBody('');
      setSaved(true);
      onChange(next);
    } catch (failure) {
      if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
      setRetry(isRetryable(failure));
      setError(messageOf(failure, 'The handoff could not be saved. Try again.'));
    } finally {
      setBusy(false);
    }
  };

  const acknowledge = async (id: number) => {
    setAckBusy(id);
    setAckError(null);
    try {
      onChange(await acknowledgeHandoff(id));
    } catch (failure) {
      if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
      setAckError({ id, text: messageOf(failure, 'The handoff could not be acknowledged. Try again.') });
    } finally {
      setAckBusy(null);
    }
  };

  return (
    <section aria-labelledby="c5-handoff-heading" className="space-y-3" data-testid="captain5-handoff">
      <h3 id="c5-handoff-heading" className="font-heading text-lg font-bold text-hub-ink">Shift handoff</h3>
      {handoff.notes.length === 0
        ? <p className={HINT}>No handoff notes for {dayText(handoff.duty_date)} or the day before.</p>
        : (
          <ul className="space-y-2" aria-label="Handoff notes">
            {handoff.notes.map((note) => (
              <li key={note.id} className="rounded-hub-control border border-hub-border bg-hub-well p-3" data-testid="captain5-handoff-note">
                <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-hub-n-600">
                  <span className="font-semibold text-hub-ink">{note.author}</span>
                  <span>{dayText(note.duty_date)}</span>
                  <time dateTime={note.at ?? undefined} title={dateTimeText(note.at)}>{ageText(note.at)}</time>
                  {note.acknowledged_at
                    ? <Tag tone="success">Acknowledged by {note.acknowledged_by ?? 'next Captain'}</Tag>
                    : <Tag tone="warning">Not yet acknowledged</Tag>}
                </p>
                <p className="mt-1 whitespace-pre-wrap break-words text-sm text-hub-ink">{note.body}</p>
                {canAct && !note.acknowledged_at && !note.own && !ownIds.current.has(note.id) && (
                  <button type="button" className={`${BTN_SECONDARY} mt-2`} disabled={ackBusy === note.id} aria-busy={ackBusy === note.id} onClick={() => void acknowledge(note.id)}>
                    {ackBusy === note.id ? 'Acknowledging…' : 'Acknowledge handoff'}
                  </button>
                )}
                {ackError?.id === note.id && <p role="alert" className="hub-callout hub-callout--warning mt-2 p-3 text-sm">{ackError.text}</p>}
              </li>
            ))}
          </ul>
        )}

      {canAct && (
        <form onSubmit={save} noValidate className="space-y-2" aria-label="Write a handoff note">
          <div>
            <label className={LABEL} htmlFor="c5-handoff-body">Handoff note for the next Captain 5</label>
            <textarea id="c5-handoff-body" rows={3} className={FIELD} value={body} disabled={busy} maxLength={4000}
              onChange={(event) => { setBody(event.target.value); setSaved(false); }} />
          </div>
          {error && <p role="alert" className="hub-callout hub-callout--warning p-3 text-sm">{error}</p>}
          {saved && !error && <p role="status" className="text-sm font-semibold text-hub-success">Handoff saved.</p>}
          <button type="submit" className={BTN_PRIMARY} disabled={busy} aria-busy={busy}>{busy ? 'Saving…' : retry ? 'Retry save' : 'Save handoff'}</button>
        </form>
      )}
    </section>
  );
}
