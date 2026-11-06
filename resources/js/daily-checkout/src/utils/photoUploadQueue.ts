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
const SENT_RETENTION_MS = 3 * 24 * 60 * 60 * 1000;
const REJECTED_RETENTION_MS = 14 * 24 * 60 * 60 * 1000;
const SIGN_IN_WAIT_MS = 60_000;

export type PhotoSendOutcome =
  | { kind: 'sent' }
  | { kind: 'retry'; delayMs: number; message: string }
  | { kind: 'rejected'; message: string }
  | { kind: 'sign_in' };

/** What one attempt means for the photo. Pure. */
export function photoSendOutcome(result: PhotoUploadResponse | PhotoUploadNetworkError, attempts: number): PhotoSendOutcome {
  if (result instanceof PhotoUploadNetworkError) {
    return {
      kind: 'retry',
      delayMs: photoRetryDelayMs(attempts),
      message: result.kind === 'timeout' ? 'The upload timed out on a weak connection.' : 'The connection dropped during the upload.',
    };
  }
  const { status, json } = result;
  if (status === 200 || status === 201) return { kind: 'sent' };
  if (status === 401 || status === 419) return { kind: 'sign_in' };
  if (isRetryablePhotoStatus(status)) {
    return { kind: 'retry', delayMs: photoRetryDelayMs(attempts, result.retryAfterMs), message: 'The Hub is busy. The photo will be sent again shortly.' };
  }
  if (status === 413) return { kind: 'rejected', message: 'This photo is too large to send. Take it again with the camera.' };

  return { kind: 'rejected', message: firstErrorMessage(json) ?? 'The Hub could not use this photo. Take it again.' };
}

export interface PhotoUploadSummary {
  total: number;
  sent: number;
  pending: number;
  rejected: number;
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
  if (summary.total === 0 || (summary.pending === 0 && summary.rejected === 0)) return null;
  const noun = summary.total === 1 ? 'photo' : 'photos';
  const parts = [`${summary.sent} of ${summary.total} ${noun} sent.`];
  if (summary.pending > 0) {
    const one = summary.pending === 1;
    parts.push(online
      ? (one ? 'The other one is sending now and stays saved on this device until it arrives.' : `The other ${summary.pending} are sending now and stay saved on this device until they arrive.`)
      : (one ? 'The other photo will send when this device is back online.' : `The other ${summary.pending} photos will send when this device is back online.`));
  }
  if (summary.rejected > 0) parts.push(`${summary.rejected} could not be used by the Hub; the inspection was still sent.`);

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
  try {
    await db.photoUploads.add({
      id,
      ownerUserId: identity.userId,
      blob: prepared.blob,
      filename: prepared.filename,
      bytes: prepared.blob.size,
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

export async function photoUploadSummary(ids: string[]): Promise<PhotoUploadSummary> {
  const unique = [...new Set(ids)];

  return summarizePhotos(unique.length === 0 ? [] : await db.photoUploads.bulkGet(unique), unique);
}

/** Photos on this device that have not reached the Hub yet. */
export const pendingPhotoCount = (): Promise<number> => db.photoUploads.where('status').equals('pending').count();

const pruneOldRecords = async (): Promise<void> => {
  const now = Date.now();
  await db.photoUploads
    .filter(record => (record.status === 'sent' && now - (record.sentAt ?? record.createdAt) > SENT_RETENTION_MS)
      || (record.status === 'rejected' && now - record.createdAt > REJECTED_RETENTION_MS))
    .delete();
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
  form.append('photo', record.blob, record.filename);
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

  const outcome = photoSendOutcome(result, record.attempts);
  const now = Date.now();
  if (outcome.kind === 'sent') {
    await db.photoUploads.update(record.id, { status: 'sent', sentAt: now, lastError: undefined, nextAttemptAt: undefined });
  } else if (outcome.kind === 'rejected') {
    await db.photoUploads.update(record.id, { status: 'rejected', lastError: outcome.message, attempts: record.attempts + 1 });
  } else if (outcome.kind === 'retry') {
    await db.photoUploads.update(record.id, { attempts: record.attempts + 1, nextAttemptAt: now + outcome.delayMs, lastError: outcome.message });
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
    await pruneOldRecords();
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
