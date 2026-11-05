import { useCallback, useEffect, useRef, useState } from 'react';
import { formatHubDate } from '../../../../shared/timezone.ts';
import { newClientId } from '../../utils/memberIssueActionsApi';
import { CONVERSATION_BODY_MAX, describeConversationError, type Conversation } from '../../utils/conversationApi';
import { BTN_PRIMARY, FIELD, HINT, LABEL } from '../captain5/ui';

type LoadState =
  | { status: 'loading' }
  | { status: 'error'; message: string }
  | { status: 'loaded'; conversation: Conversation };

interface Props {
  /** Stable id for labels (for example "issue-12"). */
  id: string;
  load: () => Promise<Conversation>;
  send: (body: string, clientMessageId: string) => Promise<Conversation>;
  heading?: string;
  placeholder?: string;
}

/**
 * A member conversation with a reply box (docs/member-replies.md). Shows what staff, the Support Services reply
 * and other members on it wrote, oldest first. A send that gets no answer keeps the text and the client message
 * id, so trying again cannot post the message twice.
 */
export default function ConversationThread({ id, load, send, heading = 'Conversation', placeholder }: Props) {
  const [state, setState] = useState<LoadState>({ status: 'loading' });
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);
  const clientMessageId = useRef<string | null>(null);
  const latest = useRef(0);

  const refresh = useCallback(() => {
    const mine = ++latest.current;
    load().then((conversation) => {
      if (mine === latest.current) setState({ status: 'loaded', conversation });
    }).catch((error: unknown) => {
      // A failed re-read keeps the thread already on screen.
      if (mine === latest.current) setState(previous => (previous.status === 'loaded' ? previous : { status: 'error', message: describeConversationError(error, false) }));
    });
  }, [load]);

  useEffect(() => { refresh(); }, [refresh]);

  const submit = async () => {
    const body = draft.trim();
    if (body === '' || sending) return;
    clientMessageId.current ??= newClientId();
    setSending(true);
    setSendError(null);
    try {
      const conversation = await send(body, clientMessageId.current);
      latest.current += 1;
      setState({ status: 'loaded', conversation });
      setDraft('');
      clientMessageId.current = null;
    } catch (error: unknown) {
      setSendError(describeConversationError(error, true));
    } finally {
      setSending(false);
    }
  };

  const conversation = state.status === 'loaded' ? state.conversation : null;

  return (
    <section aria-labelledby={`${id}-conversation`} className="space-y-3" data-testid="member-conversation">
      <h3 id={`${id}-conversation`} className="font-heading text-base font-semibold text-hub-ink">{heading}</h3>
      {state.status === 'loading' && <p className={HINT} role="status">Loading conversation...</p>}
      {state.status === 'error' && (
        <div role="alert" className="space-y-2">
          <p className={HINT}>{state.message}</p>
          <button type="button" className="hub-btn hub-btn--secondary" onClick={refresh}>Try again</button>
        </div>
      )}
      {conversation && conversation.messages.length === 0 && <p className={HINT}>No messages yet.</p>}
      {conversation && conversation.messages.length > 0 && (
        <ol className="space-y-2">
          {conversation.messages.map(message => (
            <li
              key={message.id}
              data-message-side={message.from_staff ? 'staff' : 'member'}
              className={`rounded-hub-control border p-3 text-sm ${message.from_staff ? 'border-hub-info-border bg-hub-info-bg' : 'border-hub-n-200 bg-hub-well'}`}
            >
              <p className="text-[13px] font-semibold text-hub-ink">{message.author} <span className="font-normal text-hub-n-600">· {formatHubDate(message.at)}</span></p>
              <p className="mt-1 whitespace-pre-wrap break-words text-hub-n-800">{message.body}</p>
            </li>
          ))}
        </ol>
      )}
      {conversation?.can_reply && (
        <form className="space-y-2" onSubmit={(event) => { event.preventDefault(); void submit(); }}>
          <label htmlFor={`${id}-reply`} className={LABEL}>Reply</label>
          <textarea
            id={`${id}-reply`}
            className={FIELD}
            rows={3}
            maxLength={CONVERSATION_BODY_MAX}
            value={draft}
            placeholder={placeholder}
            onChange={(event) => setDraft(event.target.value)}
            aria-invalid={sendError !== null}
            aria-describedby={sendError ? `${id}-reply-error` : undefined}
          />
          {sendError && <p id={`${id}-reply-error`} role="alert" className="text-sm font-semibold text-hub-danger">{sendError}</p>}
          <button type="submit" className={`${BTN_PRIMARY} w-full sm:w-auto`} disabled={sending || draft.trim() === ''}>
            {sending ? 'Sending...' : 'Send reply'}
          </button>
        </form>
      )}
    </section>
  );
}
