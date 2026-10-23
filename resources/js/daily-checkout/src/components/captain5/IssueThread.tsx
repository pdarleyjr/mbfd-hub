import { useId, useRef, useState, type FormEvent } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { isRetryable, postIssueMessage } from '../../utils/captain5Api';
import Tag from '../ui/Tag';
import { ageText, dateTimeText, newKey } from './format';
import type { IssueDetail } from './types';
import { BTN_PRIMARY, FIELD, HINT, LABEL } from './ui';

interface Props {
  issue: IssueDetail;
  /** Posting is a write control: view-only viewers read the thread but get no composer. */
  canWrite: boolean;
  onPosted: (issue: IssueDetail) => void;
}

export default function IssueThread({ issue, canWrite, onPosted }: Props) {
  const id = useId();
  const [body, setBody] = useState('');
  const [visibility, setVisibility] = useState<'member' | 'internal'>('member');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);
  const [retry, setRetry] = useState(false);
  // Reused while a send is being retried, replaced once the server has it.
  const keyRef = useRef(newKey());
  const attemptedRef = useRef<string | null>(null);

  const send = async (event: FormEvent) => {
    event.preventDefault();
    if (busy) return;
    if (body.trim() === '') { setError('Write a message first.'); return; }
    // A retry of the same text keeps its key; edited text is a different message.
    const text = body.trim();
    if (attemptedRef.current !== null && attemptedRef.current !== `${visibility}:${text}`) keyRef.current = newKey();
    attemptedRef.current = `${visibility}:${text}`;
    setBusy(true);
    setError(null);
    setRetry(false);
    setSent(false);
    try {
      const updated = await postIssueMessage(issue.id, text, visibility, keyRef.current);
      keyRef.current = newKey();
      attemptedRef.current = null;
      setBody('');
      setSent(true);
      onPosted(updated);
    } catch (failure) {
      if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
      setRetry(isRetryable(failure));
      setError(failure instanceof Error ? failure.message : 'The message could not be sent. Try again.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <section aria-labelledby={`${id}-heading`} className="space-y-3" data-testid="captain5-thread">
      <h3 id={`${id}-heading`} className="font-heading text-base font-bold text-hub-ink">Messages on this issue</h3>
      {issue.messages.length === 0
        ? <p className={HINT}>No messages yet.</p>
        : (
          <ul className="space-y-2" aria-label="Issue messages">
            {issue.messages.map((message) => (
              <li key={message.id} className={`rounded-hub-control border border-hub-border p-3 ${message.own ? 'bg-hub-blue-tint' : 'bg-hub-well'}`} data-testid="captain5-message">
                <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-hub-n-600">
                  <span className="font-semibold text-hub-ink">{message.own ? 'You' : message.author}</span>
                  {message.visibility === 'internal' && <Tag>Internal, crew cannot see this</Tag>}
                  <time dateTime={message.at ?? undefined} title={dateTimeText(message.at)}>{ageText(message.at)}</time>
                </p>
                <p className="mt-1 whitespace-pre-wrap break-words text-sm text-hub-ink">{message.body}</p>
              </li>
            ))}
          </ul>
        )}

      {canWrite && issue.can_post && (
        <form onSubmit={send} noValidate className="space-y-2" aria-label="Write a message">
          <div>
            <label className={LABEL} htmlFor={`${id}-body`}>Message</label>
            <textarea id={`${id}-body`} rows={3} className={FIELD} value={body} disabled={busy} maxLength={4000}
              onChange={(event) => { setBody(event.target.value); setSent(false); }} />
          </div>
          {issue.can_post_internal && (
            <fieldset className="flex flex-wrap gap-x-5">
              <legend className="sr-only">Who can see this message</legend>
              <label className="flex min-h-11 items-center gap-2 text-sm text-hub-ink">
                <input type="radio" name={`${id}-visibility`} className="h-5 w-5" checked={visibility === 'member'} onChange={() => setVisibility('member')} />
                To the crew
              </label>
              <label className="flex min-h-11 items-center gap-2 text-sm text-hub-ink">
                <input type="radio" name={`${id}-visibility`} className="h-5 w-5" checked={visibility === 'internal'} onChange={() => setVisibility('internal')} />
                Internal note
              </label>
            </fieldset>
          )}
          {error && <p role="alert" data-testid="captain5-thread-error" className="hub-callout hub-callout--warning p-3 text-sm">{error}</p>}
          {sent && !error && <p role="status" className="text-sm font-semibold text-hub-success">Message sent.</p>}
          <button type="submit" className={BTN_PRIMARY} disabled={busy} aria-busy={busy}>{busy ? 'Sending…' : retry ? 'Retry send' : 'Send message'}</button>
        </form>
      )}
    </section>
  );
}
