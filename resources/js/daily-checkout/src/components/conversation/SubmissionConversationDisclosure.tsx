import { useCallback, useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { fetchSubmissionConversation, postSubmissionReply, type SubmissionConversationType } from '../../utils/conversationApi';
import ConversationThread from './ConversationThread';

interface Props {
  type: SubmissionConversationType;
  id: number;
}

/**
 * "Conversation" under a row the member sent (supply request, service ticket). Collapsed by default; the thread
 * loads only when opened, so a long list never reads every conversation. Render it only when the row's
 * `member_conversation.participant` is true; the server refuses everyone else anyway.
 */
export default function SubmissionConversationDisclosure({ type, id }: Props) {
  const [open, setOpen] = useState(false);
  const load = useCallback(() => fetchSubmissionConversation(type, id), [type, id]);
  const send = useCallback((body: string, clientMessageId: string) => postSubmissionReply(type, id, body, clientMessageId), [type, id]);

  return (
    <details className="mt-2" onToggle={(event) => setOpen(event.currentTarget.open)} data-testid={`conversation-${type}-${id}`}>
      <summary className="flex min-h-11 cursor-pointer items-center gap-2 text-sm font-semibold text-hub-blue">
        <span>{open ? 'Hide conversation' : 'Conversation and reply'}</span>
        <ChevronDown size={16} aria-hidden="true" />
      </summary>
      {open && (
        <div className="mt-2">
          <ConversationThread id={`${type}-${id}`} load={load} send={send} heading="Conversation with Support Services" />
        </div>
      )}
    </details>
  );
}
