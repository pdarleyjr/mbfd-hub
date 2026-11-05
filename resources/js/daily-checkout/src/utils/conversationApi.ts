import { Captain5ApiError, createRequester } from './captain5Api';

/**
 * Member conversations (docs/member-replies.md section 2): the thread on an equipment issue the member is part
 * of, and the thread on a station request, supply request or service ticket the member sent. Network only: a
 * reply is never queued offline. `status` 0 means no answer, so the caller keeps the text and retries with the
 * SAME client message id (the server stores a retried message once).
 */
const request = createRequester('/api/public', 'Only the members on this can read or reply to its conversation.');

export const CONVERSATION_BODY_MAX = 4000;

/** Subjects whose conversation is served by /submission-conversations. */
export type SubmissionConversationType = 'station_request' | 'station_supply_request' | 'apparatus_service_ticket';

export interface ConversationMessage {
  id: number;
  body: string;
  /** Written by the signed-in member. */
  own: boolean;
  /** Written by staff (Logistics, Captain 5, Fleet, Support Services) or the automated Support Services reply. */
  from_staff: boolean;
  author: string;
  at: string;
}

export interface Conversation {
  can_reply: boolean;
  messages: ConversationMessage[];
}

const asRecord = (value: unknown): Record<string, unknown> => (value && typeof value === 'object' ? value as Record<string, unknown> : {});

/** A reply of the wrong shape is an error, so the screen keeps what it has instead of replacing it with nothing. */
export function parseConversation(raw: unknown): Conversation | null {
  const data = asRecord(asRecord(raw).data);
  if (!Array.isArray(data.messages)) return null;
  const messages: ConversationMessage[] = [];
  for (const entry of data.messages) {
    const row = asRecord(entry);
    if (typeof row.id !== 'number' || typeof row.body !== 'string' || typeof row.at !== 'string') return null;
    messages.push({
      id: row.id,
      body: row.body,
      own: row.own === true,
      from_staff: row.from_staff === true,
      author: typeof row.author === 'string' && row.author !== '' ? row.author : 'Member',
      at: row.at,
    });
  }

  return { can_reply: data.can_reply === true, messages };
}

async function read(path: string, init?: RequestInit): Promise<Conversation> {
  const conversation = parseConversation(await request<unknown>(path, init));
  if (conversation === null) throw new Captain5ApiError('The conversation came back in a form that could not be read.', 500);

  return conversation;
}

const send = (body: string, clientMessageId: string): RequestInit => ({
  method: 'POST',
  body: JSON.stringify({ body, client_message_id: clientMessageId }),
});

export const fetchIssueConversation = (issueId: number): Promise<Conversation> => read(`/apparatus-issues/${issueId}/conversation`);

export const postIssueReply = (issueId: number, body: string, clientMessageId: string): Promise<Conversation> =>
  read(`/apparatus-issues/${issueId}/conversation`, send(body, clientMessageId));

export const fetchSubmissionConversation = (type: SubmissionConversationType, id: number): Promise<Conversation> =>
  read(`/submission-conversations/${type}/${id}`);

export const postSubmissionReply = (type: SubmissionConversationType, id: number, body: string, clientMessageId: string): Promise<Conversation> =>
  read(`/submission-conversations/${type}/${id}`, send(body, clientMessageId));

/** In words for the screen. A failed send always says the text is kept. */
export function describeConversationError(error: unknown, sending: boolean): string {
  const status = (error as { status?: unknown } | null)?.status;
  const fields = (error as { fieldErrors?: Record<string, string> } | null)?.fieldErrors ?? {};
  if (status === 422 && typeof fields.body === 'string') return fields.body;
  if (error instanceof Error && error.message !== '') return error.message;

  return sending ? 'The reply could not be sent. Your text is kept; try again.' : 'The conversation could not be loaded. Try again.';
}
