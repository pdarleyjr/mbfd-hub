import { db, type DailyCheckoutQueuedSubmission } from '../lib/db';
import type { InspectionSubmission } from '../types';
import { createClientSubmissionId } from './storage';
import { ApiClient, ApiRequestError, isChecklistVersion } from './api';

const LEGACY_QUEUE_KEY = 'mbfd_submission_queue';
const LEGACY_MIGRATION_KEY = 'daily-checkout-queue-migration-v1';
const QUEUE_UPDATED_EVENT = 'mbfd:daily-checkout-queue-updated';
export const DAILY_CHECKOUT_QUEUE_SYNC_EVENT = 'mbfd:daily-checkout-queue-synced';
const CLIENT_ERROR_RETENTION_DAYS = 30;

export type QueuedInspectionSubmissionResult = 'submitted' | 'pending_review' | 'not_found';

export interface DailyCheckoutQueueSyncResult {
  submitted: number;
  submittedQueueIds: string[];
  pendingReviewQueueIds: string[];
  remaining: number;
}

export interface DailyCheckoutQueueSummary {
  total: number;
  pending: number;
  requiresAttention: number;
  firstAttentionError?: string;
  firstAttentionErrorCode?: string;
}

export class DailyCheckoutQueueConflictError extends Error {
  constructor(existing: DailyCheckoutQueuedSubmission) {
    super(existing.status === 'requires_attention'
      ? 'A saved Daily Checkout using this checklist version needs officer review. This inspection remains saved locally and was not submitted.'
      : 'A saved Daily Checkout using this checklist version is still awaiting synchronization. This inspection remains saved locally and was not submitted.');
    this.name = 'DailyCheckoutQueueConflictError';
  }
}

interface LegacyQueuedSubmission {
  id?: unknown;
  apparatusId?: unknown;
  data?: unknown;
  timestamp?: unknown;
}

const inFlightSubmissions = new Map<string, Promise<QueuedInspectionSubmissionResult>>();
let activeQueueSynchronization: Promise<DailyCheckoutQueueSyncResult> | null = null;
let legacyMigrationPromise: Promise<void> | null = null;

const isPermanentSubmissionFailure = (error: unknown): error is ApiRequestError => (
  error instanceof ApiRequestError
  && error.status >= 400
  && error.status < 500
);

const notifyQueueChanged = (): void => {
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new Event(QUEUE_UPDATED_EVENT));
  }
};

export const onDailyCheckoutQueueChanged = (listener: () => void): (() => void) => {
  window.addEventListener(QUEUE_UPDATED_EVENT, listener);

  return () => window.removeEventListener(QUEUE_UPDATED_EVENT, listener);
};

const legacyMigrationMarker = (state: 'migrated' | 'needs_review', detail?: string) => ({
  key: LEGACY_MIGRATION_KEY,
  data: { state, detail },
  updatedAt: new Date(),
});

const asLegacyQueue = (raw: string): LegacyQueuedSubmission[] | null => {
  try {
    const parsed: unknown = JSON.parse(raw);

    return Array.isArray(parsed) ? parsed as LegacyQueuedSubmission[] : null;
  } catch {
    return null;
  }
};

const normalizeLegacySubmission = (
  submission: LegacyQueuedSubmission,
): DailyCheckoutQueuedSubmission | null => {
  if (
    !Number.isInteger(submission.apparatusId)
    || (submission.apparatusId as number) < 1
    || !submission.data
    || typeof submission.data !== 'object'
    || Array.isArray(submission.data)
  ) {
    return null;
  }

  const data = submission.data as Record<string, unknown>;
  const clientSubmissionId = typeof data.client_submission_id === 'string' && data.client_submission_id.trim() !== ''
    ? data.client_submission_id
    : createClientSubmissionId();
  const timestamp = typeof submission.timestamp === 'number' && Number.isFinite(submission.timestamp)
    ? submission.timestamp
    : Date.now();
  const createdAt = new Date(timestamp);
  const id = typeof submission.id === 'string' && submission.id.trim() !== ''
    ? submission.id
    : `legacy_${clientSubmissionId}`;
  const checklistVersion = data.checklist_version;
  const requiresAttention = !isChecklistVersion(checklistVersion);

  return {
    id,
    apparatusId: submission.apparatusId as number,
    checklistVersion: requiresAttention ? `legacy-${id}` : checklistVersion,
    data: { ...data, client_submission_id: clientSubmissionId } as InspectionSubmission,
    createdAt,
    updatedAt: createdAt,
    status: requiresAttention ? 'requires_attention' : 'pending',
    retryCount: 0,
    ...(requiresAttention
      ? {
          lastError: 'This saved inspection predates immutable checklist versions and needs officer review before it can be sent.',
          lastErrorCode: 'DAILY_CHECKOUT_CHECKLIST_VERSION_REVIEW_REQUIRED',
          lastErrorAt: createdAt,
          retentionExpiresAt: new Date(createdAt.getTime() + CLIENT_ERROR_RETENTION_DAYS * 24 * 60 * 60 * 1000),
        }
      : {}),
  };
};

const hasSamePersistedPayload = (
  existing: DailyCheckoutQueuedSubmission,
  incoming: DailyCheckoutQueuedSubmission,
): boolean => (
  existing.apparatusId === incoming.apparatusId
  && existing.checklistVersion === incoming.checklistVersion
  && existing.data.client_submission_id === incoming.data.client_submission_id
  && JSON.stringify(existing.data) === JSON.stringify(incoming.data)
);

/**
 * Import the previous localStorage queue once. The legacy key is removed only
 * after the IndexedDB transaction and migration marker both commit, so a
 * quota/schema failure leaves the original payload recoverable.
 */
const migrateLegacyQueue = async (): Promise<void> => {
  if (typeof window === 'undefined') {
    return;
  }

  const marker = await db.cachedData.get(LEGACY_MIGRATION_KEY);
  if (marker) {
    return;
  }

  let rawLegacyQueue: string | null;
  try {
    rawLegacyQueue = window.localStorage.getItem(LEGACY_QUEUE_KEY);
  } catch {
    await db.cachedData.put(legacyMigrationMarker('needs_review', 'The legacy queue could not be read from local storage.'));
    return;
  }
  if (rawLegacyQueue === null) {
    await db.cachedData.put(legacyMigrationMarker('migrated'));
    return;
  }

  const legacyQueue = asLegacyQueue(rawLegacyQueue);
  if (legacyQueue === null) {
    await db.cachedData.put(legacyMigrationMarker('needs_review', 'The legacy queue is not valid JSON.'));
    return;
  }

  const normalized = legacyQueue.map(normalizeLegacySubmission);
  if (normalized.some((submission) => submission === null)) {
    await db.cachedData.put(legacyMigrationMarker('needs_review', 'The legacy queue contains an invalid inspection.'));
    return;
  }

  const submissions = normalized as DailyCheckoutQueuedSubmission[];
  const apparatusIds = new Set(submissions.map((submission) => submission.apparatusId));
  if (apparatusIds.size !== submissions.length) {
    await db.cachedData.put(legacyMigrationMarker('needs_review', 'The legacy queue contains more than one inspection for an apparatus.'));
    return;
  }

  try {
    await db.transaction('rw', db.dailyCheckoutSubmissions, db.cachedData, async () => {
      for (const submission of submissions) {
        const existingByChecklistVersion = await db.dailyCheckoutSubmissions
          .where('[apparatusId+checklistVersion]')
          .equals([submission.apparatusId, submission.checklistVersion])
          .first();
        const existingById = await db.dailyCheckoutSubmissions.get(submission.id);

        if (
          (existingByChecklistVersion && !hasSamePersistedPayload(existingByChecklistVersion, submission))
          || (existingById && !hasSamePersistedPayload(existingById, submission))
        ) {
          throw new Error('A different Daily Checkout submission is already persisted for this apparatus and checklist version.');
        }

        if (!existingByChecklistVersion && !existingById) {
          await db.dailyCheckoutSubmissions.add(submission);
        }
      }

      await db.cachedData.put(legacyMigrationMarker('migrated'));
    });
  } catch (error) {
    // The legacy record remains untouched. Persisting this review marker would
    // hide a transient IndexedDB failure on the next launch.
    console.error('Unable to migrate the legacy Daily Checkout queue:', error);
    throw error;
  }

  try {
    window.localStorage.removeItem(LEGACY_QUEUE_KEY);
  } catch (error) {
    // The durable copy and marker committed. Keeping the legacy copy is safer
    // than treating a cleanup failure as a failed submission.
    console.warn('The migrated legacy Daily Checkout queue could not be removed:', error);
  }
  notifyQueueChanged();
};

export const ensureDailyCheckoutQueueReady = (): Promise<void> => {
  if (!legacyMigrationPromise) {
    legacyMigrationPromise = migrateLegacyQueue().catch((error) => {
      legacyMigrationPromise = null;
      throw error;
    });
  }

  return legacyMigrationPromise;
};

/**
 * The browser owns one unresolved inspection per apparatus and checklist
 * version. A newer source version may queue separately while an older immutable
 * payload remains retained for officer review. Every retry uses its exact
 * payload and UUID until the server accepts it or an operator resolves it.
 */
export const queueSubmission = async (
  apparatusId: number,
  data: InspectionSubmission,
): Promise<string> => {
  await ensureDailyCheckoutQueueReady();

  if (!isChecklistVersion(data.checklist_version)) {
    throw new Error('The Daily Checkout checklist version is unavailable. The inspection was not queued.');
  }

  const now = new Date();
  const queuedSubmission: DailyCheckoutQueuedSubmission = {
    id: `daily_${data.client_submission_id}`,
    apparatusId,
    checklistVersion: data.checklist_version,
    data,
    createdAt: now,
    updatedAt: now,
    status: 'pending',
    retryCount: 0,
  };

  const existingById = await db.dailyCheckoutSubmissions.get(queuedSubmission.id);
  if (existingById) {
    if (hasSamePersistedPayload(existingById, queuedSubmission)) {
      return existingById.id;
    }

    throw new Error('A Daily Checkout submission ID is already saved with different data. This inspection was not submitted.');
  }

  const existingByChecklistVersion = await db.dailyCheckoutSubmissions
    .where('[apparatusId+checklistVersion]')
    .equals([apparatusId, queuedSubmission.checklistVersion])
    .first();
  if (existingByChecklistVersion) {
    if (hasSamePersistedPayload(existingByChecklistVersion, queuedSubmission)) {
      return existingByChecklistVersion.id;
    }

    throw new DailyCheckoutQueueConflictError(existingByChecklistVersion);
  }

  try {
    await db.dailyCheckoutSubmissions.add(queuedSubmission);
  } catch (error) {
    // Two windows can enqueue one checklist revision at once. Re-read both
    // final unique boundaries; only an exact logical retry may reuse a record.
    const concurrentlyQueuedById = await db.dailyCheckoutSubmissions.get(queuedSubmission.id);
    if (concurrentlyQueuedById) {
      if (hasSamePersistedPayload(concurrentlyQueuedById, queuedSubmission)) {
        return concurrentlyQueuedById.id;
      }

      throw new Error('A Daily Checkout submission ID is already saved with different data. This inspection was not submitted.');
    }

    const concurrentlyQueuedByChecklistVersion = await db.dailyCheckoutSubmissions
      .where('[apparatusId+checklistVersion]')
      .equals([apparatusId, queuedSubmission.checklistVersion])
      .first();
    if (concurrentlyQueuedByChecklistVersion) {
      if (hasSamePersistedPayload(concurrentlyQueuedByChecklistVersion, queuedSubmission)) {
        return concurrentlyQueuedByChecklistVersion.id;
      }

      throw new DailyCheckoutQueueConflictError(concurrentlyQueuedByChecklistVersion);
    }

    throw error;
  }

  notifyQueueChanged();

  return queuedSubmission.id;
};

export const getQueuedSubmission = async (id: string): Promise<DailyCheckoutQueuedSubmission | null> => {
  await ensureDailyCheckoutQueueReady();

  return (await db.dailyCheckoutSubmissions.get(id)) ?? null;
};

export const getQueuedSubmissionForApparatusAndChecklistVersion = async (
  apparatusId: number,
  checklistVersion: string,
): Promise<DailyCheckoutQueuedSubmission | null> => {
  await ensureDailyCheckoutQueueReady();

  return (await db.dailyCheckoutSubmissions
    .where('[apparatusId+checklistVersion]')
    .equals([apparatusId, checklistVersion])
    .first()) ?? null;
};

export const getSubmissionQueue = async (): Promise<DailyCheckoutQueuedSubmission[]> => {
  await ensureDailyCheckoutQueueReady();

  return db.dailyCheckoutSubmissions.orderBy('createdAt').toArray();
};

export const getDailyCheckoutQueueSummary = async (): Promise<DailyCheckoutQueueSummary> => {
  const submissions = await getSubmissionQueue();
  const requiringAttention = submissions.filter((submission) => submission.status === 'requires_attention');

  return {
    total: submissions.length,
    pending: submissions.length - requiringAttention.length,
    requiresAttention: requiringAttention.length,
    firstAttentionError: requiringAttention[0]?.lastError,
    firstAttentionErrorCode: requiringAttention[0]?.lastErrorCode,
  };
};

const removeFromQueue = async (id: string): Promise<void> => {
  await db.dailyCheckoutSubmissions.delete(id);
  notifyQueueChanged();
};

const recordSubmissionFailure = async (id: string, error: unknown): Promise<void> => {
  const queuedSubmission = await db.dailyCheckoutSubmissions.get(id);
  if (!queuedSubmission) {
    return;
  }

  const now = new Date();
  const permanentFailure = isPermanentSubmissionFailure(error);
  await db.dailyCheckoutSubmissions.update(id, {
    status: permanentFailure ? 'requires_attention' : 'pending',
    retryCount: queuedSubmission.retryCount + 1,
    lastAttemptAt: now,
    lastError: error instanceof Error ? error.message : String(error),
    lastErrorStatus: error instanceof ApiRequestError ? error.status : undefined,
    lastErrorCode: error instanceof ApiRequestError ? error.code : undefined,
    lastErrorAt: now,
    // A 4xx response is retained locally for operator review; it is never
    // treated as a successful or disposable submission.
    retentionExpiresAt: permanentFailure
      ? new Date(now.getTime() + CLIENT_ERROR_RETENTION_DAYS * 24 * 60 * 60 * 1000)
      : queuedSubmission.retentionExpiresAt,
    updatedAt: now,
  });
  notifyQueueChanged();
};

/**
 * Submit the durable payload, never a freshly reconstructed duplicate. The
 * module-level promise also prevents an app-level reconnect and an open
 * wizard from posting the same queued record at the same time.
 */
export const submitQueuedInspection = (queueId: string): Promise<QueuedInspectionSubmissionResult> => {
  const inFlight = inFlightSubmissions.get(queueId);
  if (inFlight) {
    return inFlight;
  }

  const submission = (async () => {
    const queuedSubmission = await getQueuedSubmission(queueId);
    if (!queuedSubmission) {
      return 'not_found' as const;
    }

    const attemptedAt = new Date();
    await db.dailyCheckoutSubmissions.update(queueId, {
      lastAttemptAt: attemptedAt,
      updatedAt: attemptedAt,
    });

    try {
      const receipt = await ApiClient.submitInspection(queuedSubmission.apparatusId, queuedSubmission.data);
      await removeFromQueue(queueId);

      return receipt.review_status === 'pending_review'
        ? 'pending_review' as const
        : 'submitted' as const;
    } catch (error) {
      await recordSubmissionFailure(queueId, error);
      throw error;
    }
  })().finally(() => {
    inFlightSubmissions.delete(queueId);
  });

  inFlightSubmissions.set(queueId, submission);

  return submission;
};

/**
 * Replay pending Daily Checkout records after the application mounts or the
 * browser reconnects. Client-invalid records remain persisted with an error
 * state so an operator can review them instead of silently losing the payload.
 */
export const synchronizeDailyCheckoutQueue = (): Promise<DailyCheckoutQueueSyncResult> => {
  if (activeQueueSynchronization) {
    return activeQueueSynchronization;
  }

  activeQueueSynchronization = (async () => {
    let submitted = 0;
    const submittedQueueIds: string[] = [];
    const pendingReviewQueueIds: string[] = [];

    for (const queuedSubmission of await getSubmissionQueue()) {
      if (typeof navigator !== 'undefined' && !navigator.onLine) {
        break;
      }

      if (queuedSubmission.status !== 'pending') {
        continue;
      }

      try {
        const result = await submitQueuedInspection(queuedSubmission.id);
        if (result === 'submitted' || result === 'pending_review') {
          submitted += 1;
          submittedQueueIds.push(queuedSubmission.id);
        }

        if (result === 'pending_review') {
          pendingReviewQueueIds.push(queuedSubmission.id);
        }
      } catch (error) {
        if (isPermanentSubmissionFailure(error)) {
          console.warn('A Daily Checkout submission needs review and remains saved locally.');
          continue;
        }

        console.error('Failed to sync queued Daily Checkout submission:', error);
      }
    }

    return {
      submitted,
      submittedQueueIds,
      pendingReviewQueueIds,
      remaining: (await getSubmissionQueue()).length,
    };
  })().finally(() => {
    activeQueueSynchronization = null;
  });

  return activeQueueSynchronization;
};
