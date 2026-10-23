import type { PostedUpdate, UpdateInput, UpdateOrigin } from '../components/department-updates/types';
import { ApiRequestError, fetchWithTimeout, mutationHeaders, parseRetryAfter } from './api';

/** Both pages post through the same controller; the path says which page's access rule applies. */
const BASES: Record<UpdateOrigin, string> = {
  captain_5: '/api/captain5/department-updates',
  command_300: '/api/command-communications/department-updates',
};
const UPLOAD_TIMEOUT_MS = 180_000;

export const OFFLINE_POST_MESSAGE = "You're offline. Nothing was posted; what you wrote is kept.";
export const UNREACHABLE_POST_MESSAGE = 'The server could not be reached, so it is not confirmed that this was posted. What you wrote is kept; try again and it will not be posted twice.';

/** A failed call. `status` 0 means no answer arrived, so the outcome is unknown and a retry reuses the same key. */
export class DepartmentUpdateError extends ApiRequestError {
  constructor(message: string, status: number, readonly fieldErrors: Record<string, string> = {}, retryAfterMs?: number) {
    super(message, status, undefined, retryAfterMs);
    this.name = 'DepartmentUpdateError';
  }

  get isNetwork(): boolean { return this.status === 0; }
}

const asRecord = (value: unknown): Record<string, unknown> => (value && typeof value === 'object' ? value as Record<string, unknown> : {});
const asString = (value: unknown, fallback = ''): string => (typeof value === 'string' ? value : fallback);
const asIso = (value: unknown): string | null => (typeof value === 'string' && value !== '' ? value : null);

export function normalizeUpdate(raw: unknown): PostedUpdate {
  const item = asRecord(raw);
  return {
    id: typeof item.id === 'number' ? item.id : 0,
    title: asString(item.title),
    excerpt: asString(item.excerpt),
    status: item.status === 'taken_down' ? 'taken_down' : 'published',
    posted_at: asIso(item.posted_at),
    expires_at: asIso(item.expires_at),
    author: asString(item.author, 'A member'),
    author_rank: typeof item.author_rank === 'string' && item.author_rank !== '' ? item.author_rank : null,
    author_name: typeof item.author_name === 'string' && item.author_name !== '' ? item.author_name : null,
    own: item.own === true,
    has_image: item.has_image === true,
    has_attachment: item.has_attachment === true,
    url: asString(item.url),
  };
}

const FALLBACKS: Record<number, string> = {
  401: 'Your session has ended. Sign in again.',
  403: 'Your account cannot post Department Updates from this page.',
  404: 'That update is no longer available.',
  413: 'The files are too large to send. Remove one or choose smaller files.',
  419: 'Your session expired. Reload the page and try again.',
  429: 'You are posting too quickly. Wait a moment, then try again.',
};

function fieldErrors(payload: Record<string, unknown>): Record<string, string> {
  const result: Record<string, string> = {};
  for (const [field, value] of Object.entries(asRecord(payload.errors))) {
    const first = Array.isArray(value) ? value[0] : value;
    if (typeof first === 'string') result[field] = first;
  }
  return result;
}

function failure(status: number, payloadValue: unknown, retryAfter: string | null): DepartmentUpdateError {
  const payload = asRecord(payloadValue);
  const fields = status === 422 ? fieldErrors(payload) : {};
  const message = status === 422
    ? Object.values(fields)[0] ?? asString(payload.message, 'The update could not be accepted. Check it and try again.')
    : asString(payload.message) || FALLBACKS[status] || (status >= 500 ? 'The server had a problem. Try again in a moment.' : 'The request could not be completed.');
  return new DepartmentUpdateError(message, status, fields, parseRetryAfter(retryAfter));
}

async function send(path: string, init: RequestInit, method: 'GET' | 'POST', origin: UpdateOrigin): Promise<Record<string, unknown>> {
  if (method === 'POST' && typeof navigator !== 'undefined' && navigator.onLine === false) {
    throw new DepartmentUpdateError(OFFLINE_POST_MESSAGE, 0);
  }
  let response: Response;
  try {
    response = await fetchWithTimeout(`${BASES[origin]}${path}`, { credentials: 'same-origin', cache: 'no-store', ...init });
  } catch {
    throw new DepartmentUpdateError(typeof navigator !== 'undefined' && navigator.onLine === false ? OFFLINE_POST_MESSAGE : UNREACHABLE_POST_MESSAGE, 0);
  }
  const payload = await response.json().catch(() => null);
  if (!response.ok) throw failure(response.status, payload, response.headers.get('Retry-After'));
  return asRecord(payload);
}

/** Recent posts from this page, newest first. */
export async function fetchDepartmentUpdates(origin: UpdateOrigin): Promise<PostedUpdate[]> {
  const payload = await send('', { headers: { Accept: 'application/json' } }, 'GET', origin);
  return Array.isArray(payload.updates) ? payload.updates.map(normalizeUpdate) : [];
}

/** The author takes their own post off the home page. It stays in the archive as history. */
export async function takeDownDepartmentUpdate(origin: UpdateOrigin, id: number): Promise<PostedUpdate> {
  const payload = await send(`/${id}/take-down`, { method: 'POST', headers: mutationHeaders(), body: '{}' }, 'POST', origin);
  return normalizeUpdate(payload.update);
}

function buildForm(input: UpdateInput): FormData {
  const form = new FormData();
  form.append('title', input.title.trim());
  form.append('body', input.body.trim());
  form.append('category', input.category);
  if (input.expiresOn !== '') form.append('expires_at', input.expiresOn);
  form.append('client_request_id', input.clientRequestId);
  if (input.image) form.append('image', input.image, input.image.name);
  if (input.attachment) form.append('attachment', input.attachment, input.attachment.name);
  return form;
}

/**
 * Multipart upload over XMLHttpRequest so upload progress is real. Content-Type is left to the browser
 * (it adds the multipart boundary). Resolves only on a 201 with the saved update; status 0 means the
 * outcome is unknown, so the caller keeps the text and retries with the SAME client_request_id.
 */
export function postDepartmentUpdate(origin: UpdateOrigin, input: UpdateInput, onProgress: (fraction: number) => void): Promise<PostedUpdate> {
  return new Promise((resolve, reject) => {
    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
      reject(new DepartmentUpdateError(OFFLINE_POST_MESSAGE, 0));
      return;
    }
    const xhr = new XMLHttpRequest();
    xhr.open('POST', BASES[origin]);
    xhr.timeout = UPLOAD_TIMEOUT_MS;
    const { 'Content-Type': _contentType, ...headers } = mutationHeaders();
    Object.entries(headers).forEach(([name, value]) => xhr.setRequestHeader(name, value));
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable && event.total > 0) onProgress(Math.min(1, event.loaded / event.total));
    };
    xhr.onerror = () => reject(new DepartmentUpdateError(navigator.onLine === false ? OFFLINE_POST_MESSAGE : UNREACHABLE_POST_MESSAGE, 0));
    xhr.ontimeout = () => reject(new DepartmentUpdateError(UNREACHABLE_POST_MESSAGE, 0));
    xhr.onload = () => {
      let payload: unknown = null;
      try { payload = JSON.parse(xhr.responseText); } catch { payload = null; }
      if (xhr.status === 200 || xhr.status === 201) {
        const update = normalizeUpdate(asRecord(payload).update);
        if (update.id === 0) {
          reject(new DepartmentUpdateError('The server answered without the saved update. Try again; it will not be posted twice.', 502));
          return;
        }
        resolve(update);
        return;
      }
      reject(failure(xhr.status, payload, xhr.getResponseHeader('Retry-After')));
    };

    xhr.send(buildForm(input));
  });
}

