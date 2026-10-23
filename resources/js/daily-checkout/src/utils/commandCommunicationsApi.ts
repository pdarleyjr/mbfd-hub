import {
  DEFAULT_CHANNEL,
  type CommandAttachment, type CommandAudience, type CommandChannel, type CommandPost, type CommandPostInput, type CommandPostSide,
  type CommandPostsPage, type CommandReadReceipt, type CommandSummary,
} from '../components/command-communications/types';
import { ApiRequestError, fetchWithTimeout, mutationHeaders, parseRetryAfter } from './api';

/** The 300 page keeps its original endpoint; the Captain 5 page reads the same posts through its own gate. */
const BASES: Record<CommandChannel, string> = {
  command_300: '/api/command-communications',
  captain_5: '/api/captain5/communications',
};
const UPLOAD_TIMEOUT_MS = 180_000;

export const isForbiddenError = (error: unknown): error is ApiRequestError => (
  error instanceof ApiRequestError && error.status === 403
);

const asString = (value: unknown, fallback = ''): string => (typeof value === 'string' ? value : fallback);
const asNumber = (value: unknown): number => (typeof value === 'number' && Number.isFinite(value) ? value : 0);
const asRecord = (value: unknown): Record<string, unknown> => (value && typeof value === 'object' ? value as Record<string, unknown> : {});

const SIDES: readonly CommandPostSide[] = ['command', 'administration', 'captain5'];
const AUDIENCES: readonly CommandAudience[] = ['command_300', 'captain_5', 'both'];

function normalizeAttachment(raw: unknown): CommandAttachment {
  const item = asRecord(raw);
  return {
    id: asString(item.id),
    name: asString(item.name, 'Attachment'),
    mime_type: asString(item.mime_type),
    size: asNumber(item.size),
    kind: item.kind === 'image' ? 'image' : 'document',
    url: asString(item.url),
  };
}

function normalizeRemoval(raw: unknown): CommandPost['removed'] {
  const removed = asRecord(raw);
  return { by: asString(removed.by, 'an administrator'), at: typeof removed.at === 'string' ? removed.at : null };
}

/** Never trust the shape: a missing list becomes an empty one so rendering cannot throw. */
export function normalizePost(raw: unknown): CommandPost {
  const item = asRecord(raw);
  const author = asRecord(item.author);
  return {
    id: String(item.id ?? ''),
    parent_id: item.parent_id == null ? null : String(item.parent_id),
    audience: AUDIENCES.find((audience) => audience === item.audience) ?? 'command_300',
    subject: typeof item.subject === 'string' && item.subject.trim() !== '' ? item.subject : null,
    body: asString(item.body),
    author: {
      name: asString(author.name, 'Unknown'),
      rank: typeof author.rank === 'string' && author.rank !== '' ? author.rank : null,
      side: SIDES.find((side) => side === author.side) ?? 'command',
    },
    is_mine: item.is_mine === true,
    created_at: asString(item.created_at),
    pinned: item.pinned === true,
    deleted: item.deleted === true,
    removed: item.deleted === true ? normalizeRemoval(item.removed) : null,
    can_delete: item.can_delete === true && item.deleted !== true,
    attachments: Array.isArray(item.attachments) ? item.attachments.map(normalizeAttachment) : [],
    replies: Array.isArray(item.replies) ? item.replies.map(normalizePost) : [],
  };
}

const normalizeList = (raw: unknown): CommandPost[] => (Array.isArray(raw) ? raw.map(normalizePost) : []);

function validationMessage(payload: Record<string, unknown>): string | null {
  const errors = asRecord(payload.errors);
  const messages = Object.values(errors).flatMap((value) => (Array.isArray(value) ? value : [value])).filter((value): value is string => typeof value === 'string');
  if (messages.length > 0) return messages.slice(0, 3).join(' ');
  return typeof payload.message === 'string' ? payload.message : null;
}

/** Maps a failed response to a message the member can act on. */
export function errorFromResponse(status: number, payloadValue: unknown, retryAfter: string | null, fallback: string): ApiRequestError {
  const payload = asRecord(payloadValue);
  const retryAfterMs = parseRetryAfter(retryAfter);
  if (status === 422) return new ApiRequestError(validationMessage(payload) ?? 'The message could not be accepted. Check it and try again.', status);
  if (status === 429) return new ApiRequestError('You are sending messages too quickly. Wait a moment, then try again.', status, undefined, retryAfterMs);
  if (status === 413) return new ApiRequestError('The files are too large to send. Remove one or choose smaller files.', status);
  const message = typeof payload.message === 'string' && payload.message !== '' ? payload.message : fallback;
  return new ApiRequestError(message, status, undefined, retryAfterMs);
}

async function readJson(response: Response): Promise<unknown> {
  return response.json().catch(() => null);
}

async function request(channel: CommandChannel, path: string, init: RequestInit, fallback: string): Promise<unknown> {
  const response = await fetchWithTimeout(`${BASES[channel]}${path}`, { credentials: 'same-origin', cache: 'no-store', ...init });
  const payload = await readJson(response);
  if (!response.ok) throw errorFromResponse(response.status, payload, response.headers.get('Retry-After'), fallback);
  return payload;
}

export async function fetchCommandPosts(
  { before, limit = 20, channel = DEFAULT_CHANNEL }: { before?: string | null; limit?: number; channel?: CommandChannel } = {},
): Promise<CommandPostsPage> {
  const query = new URLSearchParams({ limit: String(limit) });
  if (before) query.set('before', before);
  const payload = asRecord(await request(channel, `/posts?${query}`, { headers: { Accept: 'application/json' } }, 'Messages could not be loaded.'));
  return {
    data: normalizeList(payload.data),
    pinned: normalizeList(payload.pinned),
    next_before: payload.next_before == null ? null : String(payload.next_before),
    unread_count: asNumber(payload.unread_count),
  };
}

export async function fetchCommandSummary(channel: CommandChannel = DEFAULT_CHANNEL): Promise<CommandSummary> {
  const payload = asRecord(await request(channel, '/summary', { headers: { Accept: 'application/json' } }, 'The message summary could not be loaded.'));
  return { unread_count: asNumber(payload.unread_count), latest_activity_at: typeof payload.latest_activity_at === 'string' ? payload.latest_activity_at : null };
}

export async function setCommandPostPinned(id: string, pinned: boolean, channel: CommandChannel = DEFAULT_CHANNEL): Promise<CommandPost> {
  const payload = asRecord(await request(channel, `/posts/${encodeURIComponent(id)}/pin`, {
    method: 'POST',
    headers: mutationHeaders(),
    body: JSON.stringify({ pinned }),
  }, pinned ? 'The message could not be pinned.' : 'The message could not be unpinned.'));
  return normalizePost(payload.data);
}

/**
 * Controlled removal (the author, or a communications administrator). The reason is required and
 * kept in the audit trail. Resolves with the tombstone the server now serves for that message.
 */
export async function deleteCommandPost(id: string, reason: string, channel: CommandChannel = DEFAULT_CHANNEL): Promise<CommandPost> {
  const payload = asRecord(await request(channel, `/posts/${encodeURIComponent(id)}`, {
    method: 'DELETE',
    headers: mutationHeaders(),
    body: JSON.stringify({ reason }),
  }, 'The message could not be removed.'));
  return normalizePost(payload.data);
}

export async function markCommandPostsRead(channel: CommandChannel = DEFAULT_CHANNEL): Promise<CommandReadReceipt> {
  const payload = asRecord(await request(channel, '/read', { method: 'POST', headers: mutationHeaders(), body: '{}' }, 'Messages could not be marked as read.'));
  return { unread_count: asNumber(payload.unread_count), last_read_at: typeof payload.last_read_at === 'string' ? payload.last_read_at : null };
}

function buildForm(input: CommandPostInput): FormData {
  const form = new FormData();
  if (input.subject.trim() !== '') form.append('subject', input.subject.trim());
  if (input.body.trim() !== '') form.append('body', input.body);
  if (input.parentId) form.append('parent_id', input.parentId);
  form.append('client_submission_id', input.clientSubmissionId);
  input.files.forEach((file) => form.append('attachments[]', file, file.name));
  return form;
}

/**
 * Multipart upload over XMLHttpRequest so upload progress is real. Content-Type is left to the
 * browser (it adds the multipart boundary). Resolves only on a 200/201 from the server; status 0
 * means the outcome is unknown (network drop or timeout), so the caller keeps the draft and
 * retries with the same client_submission_id.
 */
export function sendCommandPost(input: CommandPostInput, onProgress: (fraction: number) => void, channel: CommandChannel = DEFAULT_CHANNEL): Promise<CommandPost> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', `${BASES[channel]}/posts`);
    xhr.timeout = UPLOAD_TIMEOUT_MS;
    xhr.withCredentials = false;
    const { 'Content-Type': _contentType, ...headers } = mutationHeaders();
    Object.entries(headers).forEach(([name, value]) => xhr.setRequestHeader(name, value));
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable && event.total > 0) onProgress(Math.min(1, event.loaded / event.total));
    };
    xhr.onerror = () => reject(new ApiRequestError('The message could not be sent. Check your connection and try again.', 0));
    xhr.ontimeout = () => reject(new ApiRequestError('The server did not respond in time. Try again; the message will not be sent twice.', 0));
    xhr.onload = () => {
      let payload: unknown = null;
      try { payload = JSON.parse(xhr.responseText); } catch { payload = null; }
      if (xhr.status === 200 || xhr.status === 201) {
        const post = normalizePost(asRecord(payload).data);
        if (post.id === '') {
          reject(new ApiRequestError('The server answered without the saved message. Try again.', 502));
          return;
        }
        resolve(post);
        return;
      }
      reject(errorFromResponse(xhr.status, payload, xhr.getResponseHeader('Retry-After'), 'The message could not be sent. Try again.'));
    };

    xhr.send(buildForm(input));
  });
}
