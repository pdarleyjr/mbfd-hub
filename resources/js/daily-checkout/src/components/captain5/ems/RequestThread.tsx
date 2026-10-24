import { useId, useState, type FormEvent } from 'react';
import { postRequestMessage } from '../../../utils/emsApi';
import Tag from '../../ui/Tag';
import { currentIssuances, etDateTime, eventSentence, stockEffectText } from './logic';
import { BTN_PRIMARY, FIELD, HINT, LABEL, LiveNote, useWrite } from './shared';
import type { HistoryEvent, RequestMessage } from './types';

const MESSAGE_MAX = 2000;

interface ThreadProps {
  requestId: string;
  messages: RequestMessage[];
  canPost: boolean;
  /** A message was saved: refresh the request so the thread and history are the server's. */
  onPosted: () => void;
}

/** The conversation with the member. Posting needs act access; reading needs only view. */
export function RequestMessages({ requestId, messages, canPost, onPosted }: ThreadProps) {
  const id = useId();
  const [body, setBody] = useState('');
  const [sent, setSent] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const write = useWrite();

  const send = (event: FormEvent) => {
    event.preventDefault();
    const text = body.trim();
    if (text === '') { setProblem('Write a message first.'); return; }
    setProblem(null);
    setSent(false);
    void write.run(text, (key) => postRequestMessage(requestId, text, key), {
      onDone: () => { setBody(''); setSent(true); onPosted(); },
    });
  };

  return (
    <section aria-labelledby={`${id}-heading`} className="space-y-3" data-testid="ems-thread">
      <h3 id={`${id}-heading`} className="font-heading text-base font-bold text-hub-ink">Messages with the member</h3>
      {messages.length === 0
        ? <p className={HINT}>No messages yet.</p>
        : (
          <ul className="space-y-2" aria-label="Request messages">
            {messages.map((message) => (
              <li key={message.id} data-testid="ems-message" className={`rounded-hub-control border border-hub-border p-3 ${message.author_context === 'member' ? 'bg-hub-well' : 'bg-hub-blue-tint'}`}>
                <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-hub-n-600">
                  <span className="font-semibold text-hub-ink">{[message.author_rank, message.author_name].filter(Boolean).join(' ')}</span>
                  <Tag>{message.author_context_label}</Tag>
                  <time dateTime={message.created_at ?? undefined}>{etDateTime(message.created_at)}</time>
                </p>
                <p className="mt-1 whitespace-pre-wrap break-words text-sm text-hub-ink">{message.body}</p>
              </li>
            ))}
          </ul>
        )}

      {canPost && (
        <form onSubmit={send} noValidate className="space-y-2" aria-label="Write a message to the member">
          <div>
            <label className={LABEL} htmlFor={`${id}-body`}>Message to the member</label>
            <textarea id={`${id}-body`} rows={3} className={FIELD} value={body} maxLength={MESSAGE_MAX} disabled={write.busy}
              aria-invalid={problem ? true : undefined} onChange={(event) => { setBody(event.target.value); setSent(false); }} />
            <p className={HINT}>{body.length} of {MESSAGE_MAX} characters. The member is notified.</p>
          </div>
          <LiveNote tone="warning">{problem ?? write.error}</LiveNote>
          <div role="status" aria-live="polite">{sent && !write.error ? <p className="text-sm font-semibold text-hub-success">Message sent.</p> : null}</div>
          <button type="submit" className={BTN_PRIMARY} disabled={write.busy} aria-busy={write.busy}>{write.busy ? 'Sending…' : write.retry ? 'Retry send' : 'Send message'}</button>
        </form>
      )}
    </section>
  );
}

/** Everything that happened on the request, newest last, across every shift, with who did it and when (Eastern time). */
export function RequestHistory({ history }: { history: HistoryEvent[] }) {
  const id = useId();
  const issuances = new Map(currentIssuances(history).map((issuance) => [issuance.eventId, issuance]));
  return (
    <section aria-labelledby={`${id}-heading`} className="space-y-3" data-testid="ems-history">
      <h3 id={`${id}-heading`} className="font-heading text-base font-bold text-hub-ink">History</h3>
      {history.length === 0 ? <p className={HINT}>Nothing recorded yet.</p> : (
        <ol className="hub-timeline space-y-3 pl-4" aria-label="Request history">
          {history.map((event) => {
            const issuance = issuances.get(event.id);
            return (
              <li key={event.id} data-testid="ems-history-item" className="space-y-0.5">
                <p className="text-sm text-hub-ink">{eventSentence(event)}</p>
                <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-hub-n-600">
                  <span>{[event.actor_rank, event.actor_name].filter(Boolean).join(' ') || 'System'} ({event.actor_context_label})</span>
                  <time dateTime={event.created_at ?? undefined}>{etDateTime(event.created_at)}</time>
                  {event.action === 'issued' && issuance && <Tag tone={issuance.stockEffect === 'applied' ? 'success' : 'neutral'}>{stockEffectText(issuance.stockEffect)}</Tag>}
                </p>
              </li>
            );
          })}
        </ol>
      )}
    </section>
  );
}
