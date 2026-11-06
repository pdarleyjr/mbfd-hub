import { db, type QueuedPhoto } from '../lib/db';
import { cachedOfflineIdentity, refreshOfflineIdentity } from '../lib/offlineIdentity';
import { createClientSubmissionId } from './storage';
import { preparePhoto } from '../../../shared/photo-compression.ts';
import {
  PhotoUploadNetworkError,
  firstErrorMessage,
  isRetryablePhotoStatus,
  photoRetryDelayMs,
  sendPhoto,
  type PhotoUploadResponse,
} from '../../../shared/photo-upload.ts';

/**
 * Daily Checkout photos travel apart from the checkout.
 *
 * A photo is shrunk on the phone, saved in IndexedDB with its own id and sent on
 * its own request (one at a time, with progress, a 90 s deadline and a backoff
 * schedule). The checkout names photos by id and is never held back by one: the
 * server records where each photo will be stored and shows it when it arrives.
 * A photo survives a closed app or lost signal, and is only ever sent while its
 * own member is signed in (a shared station device serves many members).
 */

export const PHOTO_UPLOADS_CHANGED_EVENT = 'mbfd:photo-uploads-changed';
const UPLOAD_URL = '/api/public/inspection-photos';
const DAY_MS = 24 * 60 * 60 * 1000;
const SENT_RETENTION_MS = 3 * DAY_MS;
const REJECTED_RETENTION_MS = 14 * DAY_MS;
/** A photo still not sent after this long (its member never signed in again, for example) is given up on. */
export const PENDING_RETENTION_MS = 14 * DAY_MS;
/** A photo given up on stays visible to its member this long, then leaves the device. */
const FAILED_RETENTION_MS = 14 * DAY_MS;
const SIGN_IN_WAIT_MS = 60_000;
/** 404/405: the upload route is missing for a moment (a release or rollback under way). Wait, never give up on that alone. */
export const ROUTE_MISSING_RETRY_MS = 15 * 60 * 1000;
/** Server errors (5xx) one photo may meet before it is given up on and shown to its member as not sent. */
export const MAX_SERVER_ERROR_ATTEMPTS = 30;

const NOT_SENT_SUFFIX = 'The inspection was still sent; take the photo again if it is needed.';
export const SERVER_GAVE_UP_MESSAGE = `The Hub could not take this photo after many tries. ${NOT_SENT_SUFFIX}`;
export const EXPIRED_MESSAGE = `This photo was not sent within 14 days. ${NOT_SENT_SUFFIX}`;

export type PhotoSendOutcome =
  | { kind: 'sent' }
  | { kind: 'retry'; delayMs: number; message: string; serverError?: boolean }
  | { kind: 'rejected'; message: string }
  | { kind: 'failed'; message: string }
  | { kind: 'sign_in' };

/**
 * What one attempt means for the photo. Pure.
 *  - 401/403/419: the member must sign in again; the photo is kept.
 *  - 404/405: the route is missing during a release or rollback; tried again after a long wait.
 *  - 408/425/429/5xx and lost connections: tried again with backoff; after
 *    MAX_SERVER_ERROR_ATTEMPTS server errors the photo is given up on ("failed").
 *  - 409/413/415/422 and any other answer: the Hub cannot use this photo ("rejected").
 */
export function photoSendOutcome(result: PhotoUploadResponse | PhotoUploadNetworkError, attempts: number, serverErrors = 0): PhotoSendOutcome {
  if (result instanceof PhotoUploadNetworkError) {
    return {
      kind: 'retry',
      delayMs: photoRetryDelayMs(attempts),
      message: result.kind === 'timeout' ? 'The upload timed out on a weak connection.' : 'The connection dropped during the upload.',
    };
  }
  const { status, json } = result;
  if (status === 200 || status === 201) return { kind: 'sent' };
  if (json.offline === true) {
    // The service worker's own answer when the network failed: a lost connection, not a server error.
    return { kind: 'retry', delayMs: photoRetryDelayMs(attempts), message: 'The connection dropped during the upload.' };
  }
  if (status === 401 || status === 403 || status === 419) return { kind: 'sign_in' };
  if (status === 404 || status === 405) {
    return { kind: 'retry', delayMs: Math.max(ROUTE_MISSING_RETRY_MS, photoRetryDelayMs(attempts, result.retryAfterMs)), message: 'The Hub is being updated. The photo will be sent again later.' };
  }
  if (status >= 500 && serverErrors + 1 >= MAX_SERVER_ERROR_ATTEMPTS) return { kind: 'failed', message: SERVER_GAVE_UP_MESSAGE };
  if (isRetryablePhotoStatus(status)) {
    return { kind: 'retry', delayMs: photoRetryDelayMs(attempts, result.retryAfterMs), message: 'The Hub is busy. The photo will be sent again shortly.', serverError: status >= 500 };
  }
  if (status === 413) return { kind: 'rejected', message: 'This photo is too large to send. Take it again with the camera.' };

  return { kind: 'rejected', message: firstErrorMessage(json) ?? 'The Hub could not use this photo. Take it again.' };
}

/** What housekeeping does with one saved photo record. Pure. */
export function photoRecordFate(record: Pick<QueuedPhoto, 'status' | 'createdAt' | 'sentAt' | 'failedAt'>, now: number): 'keep' | 'expire' | 'delete' {
  switch (record.status) {
    case 'pending':
      return now - record.createdAt > PENDING_RETENTION_MS ? 'expire' : 'keep';
    case 'sent':
      return now - (record.sentAt ?? record.createdAt) > SENT_RETENTION_MS ? 'delete' : 'keep';
    case 'rejected':
      return now - record.createdAt > REJECTED_RETENTION_MS ? 'delete' : 'keep';
    case 'failed':
      return now - (record.failedAt ?? record.createdAt) > FAILED_RETENTION_MS ? 'delete' : 'keep';
    default:
      return 'keep';
  }
}

export interface PhotoUploadSummary {
  total: number;
  sent: number;
  pending: number;
  rejected: number;
  /** Given up on after many server errors or 14 days unsent. */
  failed: number;
}

/** Counts for the photos one checkout names. Pure. */
export function summarizePhotos(records: Array<Pick<QueuedPhoto, 'id' | 'status'> | undefined>, ids: string[]): PhotoUploadSummary {
  const byId = new Map(records.filter((record): record is Pick<QueuedPhoto, 'id' | 'status'> => record !== undefined).map(record => [record.id, record.status]));
  const unique = [...new Set(ids)];
  // A photo no longer on this device was sent and later cleaned up.
  const statuses = unique.map(id => byId.get(id) ?? 'sent');

  return {
    total: unique.length,
    sent: statuses.filter(status => status === 'sent').length,
    pending: statuses.filter(status => status === 'pending').length,
    rejected: statuses.filter(status => status === 'rejected').length,
    failed: statuses.filter(status => status === 'failed').length,
  };
}

/** Every photo id a checkout names (items, findings and concerns). Pure. */
export function photoIdsOf(submission: {
  compartments?: Array<{ items?: Array<{ photo_id?: string }> }>;
  defects?: Array<{ photo_id?: string }>;
  concerns?: Array<{ photo_id?: string }>;
}): string[] {
  const ids = [
    ...(submission.compartments ?? []).flatMap(compartment => (compartment.items ?? []).map(item => item.photo_id)),
    ...(submission.defects ?? []).map(defect => defect.photo_id),
    ...(submission.concerns ?? []).map(concern => concern.photo_id),
  ];

  return [...new Set(ids.filter((id): id is string => typeof id === 'string' && id !== ''))];
}

/** "3 of 4 photos sent", plus what happens to the rest. Null when nothing is outstanding. */
export function photoSummaryLine(summary: PhotoUploadSummary, online: boolean): string | null {
  if (summary.total === 0 || (summary.pending === 0 && summary.rejected === 0 && summary.failed === 0)) return null;
  const noun = summary.total === 1 ? 'photo' : 'photos';
  const parts = [`${summary.sent} of ${summary.total} ${noun} sent.`];
  if (summary.pending > 0) {
    const one = summary.pending === 1;
    parts.push(online
      ? (one ? 'The other one is sending now and stays saved on this device until it arrives.' : `The other ${summary.pending} are sending now and stay saved on this device until they arrive.`)
      : (one ? 'The other photo will send when this device is back online.' : `The other ${summary.pending} photos will send when this device is back online.`));
  }
  if (summary.rejected > 0) parts.push(`${summary.rejected} could not be used by the Hub; the inspection was still sent.`);
  if (summary.failed > 0) parts.push(`${summary.failed} could not be sent; the inspection was still sent.`);

  return parts.join(' ');
}

const progress = new Map<string, number>();
let draining: Promise<void> | null = null;
let timer: ReturnType<typeof setTimeout> | undefined;

const notify = (): void => {
  if (typeof window !== 'undefined') window.dispatchEvent(new Event(PHOTO_UPLOADS_CHANGED_EVENT));
};

export const onPhotoUploadsChanged = (listener: () => void): (() => void) => {
  window.addEventListener(PHOTO_UPLOADS_CHANGED_EVENT, listener);

  return () => window.removeEventListener(PHOTO_UPLOADS_CHANGED_EVENT, listener);
};

/** Upload progress (0-100) of the photo being sent right now, if it is this one. */
export const photoProgress = (id: string): number | undefined => progress.get(id);

const xsrfHeader = (): Record<string, string> => {
  const encoded = typeof document === 'undefined' ? undefined : document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='))?.slice(11);

  return encoded ? { 'X-XSRF-TOKEN': decodeURIComponent(encoded) } : {};
};

/**
 * Shrink, save on this device and start sending. Returns the photo id the checkout
 * carries. Throws only when the photo could not be saved on this device.
 */
export async function queuePhoto(file: File): Promise<string> {
  const identity = await cachedOfflineIdentity();
  if (!identity) throw new Error('Sign in online once before adding photos.');
  const prepared = await preparePhoto(file);
  const id = createClientSubmissionId();
  let data: ArrayBuffer;
  try {
    data = await prepared.blob.arrayBuffer();
  } catch {
    throw new Error('The image could not be read. Choose it again.');
  }
  try {
    await db.photoUploads.add({
      id,
      ownerUserId: identity.userId,
      data,
      type: prepared.blob.type || 'image/jpeg',
      filename: prepared.filename,
      bytes: data.byteLength,
      status: 'pending',
      attempts: 0,
      createdAt: Date.now(),
    });
  } catch {
    throw new Error('The photo could not be saved on this device. Free up some space and choose it again.');
  }
  notify();
  void drainPhotoUploads();

  return id;
}

/** The member removed a photo before it was sent: there is nothing left to deliver. */
export async function discardQueuedPhoto(id: string | undefined): Promise<void> {
  if (!id) return;
  const record = await db.photoUploads.get(id);
  if (record && record.status !== 'sent') {
    await db.photoUploads.delete(id);
    notify();
  }
}

export const getQueuedPhoto = (id: string): Promise<QueuedPhoto | undefined> => db.photoUploads.get(id);

/** The saved photo as a Blob again (for a preview or the upload). */
export const queuedPhotoBlob = (record: Pick<QueuedPhoto, 'data' | 'type'>): Blob => new Blob([record.data], { type: record.type });

export async function photoUploadSummary(ids: string[]): Promise<PhotoUploadSummary> {
  const unique = [...new Set(ids)];

  return summarizePhotos(unique.length === 0 ? [] : await db.photoUploads.bulkGet(unique), unique);
}

/** Photos on this device that have not reached the Hub yet. */
export const pendingPhotoCount = (): Promise<number> => db.photoUploads.where('status').equals('pending').count();

/** This member's photos that were given up on, for the connection bar. */
export async function failedPhotoCount(): Promise<number> {
  const identity = await cachedOfflineIdentity();
  if (!identity) return 0;

  return db.photoUploads.where('status').equals('failed').filter(record => record.ownerUserId === identity.userId).count();
}

/**
 * Keeps this device's photo store bounded: a photo still unsent after 14 days (any member's)
 * is given up on and shown to its member as not sent; sent, rejected and given-up photos
 * leave the device after their retention.
 */
const expireAndPruneRecords = async (): Promise<void> => {
  const now = Date.now();
  const expired = await db.photoUploads
    .filter(record => photoRecordFate(record, now) === 'expire')
    .modify({ status: 'failed', failedAt: now, nextAttemptAt: undefined, lastError: EXPIRED_MESSAGE });
  const deleted = await db.photoUploads.filter(record => photoRecordFate(record, now) === 'delete').delete();
  if (expired > 0 || deleted > 0) notify();
};

const scheduleNextDrain = async (): Promise<void> => {
  if (timer !== undefined) clearTimeout(timer);
  timer = undefined;
  const pending = await db.photoUploads.where('status').equals('pending').toArray();
  if (pending.length === 0) return;
  const next = Math.min(...pending.map(record => record.nextAttemptAt ?? 0));
  timer = setTimeout(() => { timer = undefined; void drainPhotoUploads(); }, Math.max(1_000, next - Date.now()));
};

const sendOne = async (record: QueuedPhoto): Promise<PhotoSendOutcome> => {
  const form = new FormData();
  form.append('client_photo_id', record.id);
  form.append('photo', queuedPhotoBlob(record), record.filename);
  progress.set(record.id, 0);
  notify();
  let result: PhotoUploadResponse | PhotoUploadNetworkError;
  try {
    result = await sendPhoto(UPLOAD_URL, form, {
      headers: xsrfHeader(),
      onProgress: (percent) => { progress.set(record.id, percent); notify(); },
    });
  } catch (error) {
    result = error instanceof PhotoUploadNetworkError ? error : new PhotoUploadNetworkError('network');
  } finally {
    progress.delete(record.id);
  }

  const serverErrors = record.serverErrors ?? 0;
  const outcome = photoSendOutcome(result, record.attempts, serverErrors);
  const now = Date.now();
  if (outcome.kind === 'sent') {
    await db.photoUploads.update(record.id, { status: 'sent', sentAt: now, lastError: undefined, nextAttemptAt: undefined });
  } else if (outcome.kind === 'rejected') {
    await db.photoUploads.update(record.id, { status: 'rejected', lastError: outcome.message, attempts: record.attempts + 1 });
  } else if (outcome.kind === 'failed') {
    await db.photoUploads.update(record.id, { status: 'failed', failedAt: now, lastError: outcome.message, attempts: record.attempts + 1, serverErrors: serverErrors + 1, nextAttemptAt: undefined });
  } else if (outcome.kind === 'retry') {
    await db.photoUploads.update(record.id, { attempts: record.attempts + 1, serverErrors: serverErrors + (outcome.serverError ? 1 : 0), nextAttemptAt: now + outcome.delayMs, lastError: outcome.message });
  } else {
    await db.photoUploads.update(record.id, { nextAttemptAt: now + SIGN_IN_WAIT_MS, lastError: 'Sign in again to send this photo.' });
  }
  notify();

  return outcome;
};

/**
 * Send every due photo of the signed-in member, one at a time. Safe to call often:
 * concurrent calls share one pass, and the next pass is scheduled automatically.
 */
export function drainPhotoUploads(): Promise<void> {
  if (draining) return draining;
  draining = (async () => {
    await expireAndPruneRecords();
    if (typeof navigator !== 'undefined' && !navigator.onLine) return;
    const pending = await db.photoUploads.where('status').equals('pending').sortBy('createdAt');
    if (pending.length === 0) return;
    let userId: number;
    try {
      // Also renews the XSRF cookie. Never navigates: this runs in the background.
      userId = (await refreshOfflineIdentity({ redirectOnExpiry: false })).userId;
    } catch {
      return; // Signed out or unreachable: everything stays saved and is tried again later.
    }
    for (const record of pending) {
      if (record.ownerUserId !== userId) continue; // Another member's photo waits for that member.
      if ((record.nextAttemptAt ?? 0) > Date.now()) continue;
      if (typeof navigator !== 'undefined' && !navigator.onLine) break;
      const outcome = await sendOne(record);
      if (outcome.kind === 'sign_in') break;
    }
  })().catch((error: unknown) => {
    console.error('Daily Checkout photos could not be sent:', error);
  }).finally(() => {
    draining = null;
    void scheduleNextDrain().catch(() => undefined);
  });

  return draining;
}

/** Start background sending (app start, reconnect, return to the app). Returns a stop function. */
export function startPhotoUploadSync(): () => void {
  const onOnline = () => { void drainPhotoUploads(); };
  const onVisible = () => { if (document.visibilityState === 'visible') void drainPhotoUploads(); };
  window.addEventListener('online', onOnline);
  document.addEventListener('visibilitychange', onVisible);
  void drainPhotoUploads();

  return () => {
    window.removeEventListener('online', onOnline);
    document.removeEventListener('visibilitychange', onVisible);
    if (timer !== undefined) clearTimeout(timer);
    timer = undefined;
  };
}
