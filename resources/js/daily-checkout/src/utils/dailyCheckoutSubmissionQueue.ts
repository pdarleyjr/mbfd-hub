import { db, type DailyCheckoutQueuedSubmission } from '../lib/db';
import type { InspectionData, InspectionSubmission } from '../types';
import { AUTOSAVE_STORAGE_PREFIX, createClientSubmissionId } from './storage';
import { ApiClient, ApiRequestError, ApiTimeoutError, READ_TIMEOUT_MS, isChecklistVersion } from './api';
import { identityForQueueCapture, refreshOfflineIdentity, type OfflineIdentity } from '../lib/offlineIdentity';

const LEGACY_QUEUE_KEY = 'mbfd_submission_queue';
const LEGACY_MIGRATION_KEY = 'daily-checkout-queue-migration-v1';
const QUEUE_UPDATED_EVENT = 'mbfd:daily-checkout-queue-updated';
export const DAILY_CHECKOUT_QUEUE_SYNC_EVENT = 'mbfd:daily-checkout-queue-synced';
const CLIENT_ERROR_RETENTION_DAYS = 30;

export type QueuedInspectionSubmissionResult = 'submitted' | 'accepted_with_exception' | 'pending_review' | 'not_found';

export interface DailyCheckoutQueueSyncResult {
  submitted: number;
  submittedQueueIds: string[];
  pendingReviewQueueIds: string[];
  exceptionQueueIds: string[];
  remaining: number;
}

/** What the member's receipt may truthfully say about one inspection. */
export type InspectionReceiptOutcome = 'submitted' | 'pending_review' | 'accepted_with_exception' | 'queued';

export interface InspectionReceiptState {
  outcome: InspectionReceiptOutcome;
  queuedSubmissionId?: string;
  /** In-app path to return to (for example `/stations/1`). */
  returnTo?: string;
}

/** The receipt outcome for one record in a replay result, if it was accepted. */
export const receiptOutcomeForSync = (
  result: DailyCheckoutQueueSyncResult,
  queueId: string,
): InspectionReceiptOutcome | null => {
  if (!result.submittedQueueIds.includes(queueId)) return null;
  if (result.exceptionQueueIds.includes(queueId)) return 'accepted_with_exception';

  return result.pendingReviewQueueIds.includes(queueId) ? 'pending_review' : 'submitted';
};

export interface DailyCheckoutQueueSummary {
  total: number;
  pending: number;
  requiresAttention: number;
  firstAttentionError?: string;
  firstAttentionErrorCode?: string;
  /** Earliest server-requested retry time (epoch ms) among pending records. */
  nextAttemptAt?: number;
}

/** A record that needs a member or officer decision, without its payload. */
export interface DailyCheckoutAttentionRecord {
  id: string;
  apparatusId: number;
  operatorName?: string;
  createdAt: Date;
  lastError?: string;
  lastErrorCode?: string;
  lastErrorStatus?: number;
  unitNumber?: string;
  /** Only the signed-in owner may discard their own rejected inspection. */
  canDiscard: boolean;
}

/**
 * The server asked us to wait (429/503 Retry-After). The record stays pending
 * and is replayed automatically after `retryAt`.
 */
export class DailyCheckoutRetryDeferredError extends Error {
  constructor(readonly retryAt: number) {
    super('The server asked the app to wait before sending again. This inspection stays saved on this device and will be sent automatically.');
    this.name = 'DailyCheckoutRetryDeferredError';
  }
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
/**
 * Outcomes of records the server accepted during this page lifetime, keyed by
 * queue id. A screen that sees its blocking record disappear consults this
 * before assuming the record was discarded, so an accepted inspection is never
 * mistaken for one that must be started again.
 */
const recentlyAcceptedOutcomes = new Map<string, InspectionReceiptOutcome>();
const RECENTLY_ACCEPTED_LIMIT = 50;

const rememberAcceptedOutcome = (queueId: string, outcome: InspectionReceiptOutcome): void => {
  recentlyAcceptedOutcomes.delete(queueId);
  recentlyAcceptedOutcomes.set(queueId, outcome);
  while (recentlyAcceptedOutcomes.size > RECENTLY_ACCEPTED_LIMIT) {
    const oldest = recentlyAcceptedOutcomes.keys().next().value;
    if (oldest === undefined) break;
    recentlyAcceptedOutcomes.delete(oldest);
  }
};

/** The receipt outcome if this queued record was accepted by the server. */
export const acceptedOutcomeFor = (queueId: string): InspectionReceiptOutcome | null => (
  recentlyAcceptedOutcomes.get(queueId) ?? null
);
let activeQueueSynchronization: Promise<DailyCheckoutQueueSyncResult> | null = null;
let legacyMigrationPromise: Promise<void> | null = null;

/**
 * Statuses where the server did not reject the inspection itself: not signed
 * in (401), request timeout (408), expired session/CSRF token (419), too early
 * (425) and rate limited (429). The record stays pending and is replayed with
 * its original payload; every replay first refreshes the member context, which
 * renews the XSRF cookie or sends the member to sign in again.
 */
const RETRYABLE_CLIENT_STATUSES = new Set([401, 408, 419, 425, 429]);

/**
 * Only a genuine client rejection (validation 422, conflict 409, forbidden...)
 * needs review. Network failures, timeouts and 5xx keep the record pending.
 */
export const isPermanentSubmissionFailure = (error: unknown): error is ApiRequestError => (
  error instanceof ApiRequestError
  && error.status >= 400
  && error.status < 500
  && !RETRYABLE_CLIENT_STATUSES.has(error.status)
);

/** Plain-language explanation of why a pending record has not been sent. */
export const describeSubmissionFailure = (error: unknown): string => {
  if (error instanceof DailyCheckoutRetryDeferredError || error instanceof ApiTimeoutError) return error.message;
  if (error instanceof ApiRequestError) {
    if (error.status === 401 || error.status === 419) {
      return 'Your sign-in expired before this inspection was sent. Sign in again; the inspection stays saved on this device and will be sent afterwards.';
    }
    if (error.status === 429 || error.status === 425) {
      return 'The server is busy. This inspection stays saved on this device and will be sent automatically.';
    }
    if (error.status === 408 || error.status >= 500) {
      return 'The server could not take this inspection right now. It stays saved on this device and will be sent automatically.';
    }

    return error.message;
  }
  if (typeof navigator !== 'undefined' && !navigator.onLine) {
    return 'This device is offline. The inspection stays saved on this device and will be sent when the connection returns.';
  }

  return 'The connection failed before the server confirmed this inspection. It stays saved on this device and will be sent again; the server ignores a duplicate.';
};

/** Bound a promise that cannot be cancelled (e.g. the member-context check). */
export const withDeadline = <T>(promise: Promise<T>, timeoutMs: number, message: string): Promise<T> => (
  new Promise<T>((resolve, reject) => {
    const timer = setTimeout(() => reject(new ApiTimeoutError(message)), timeoutMs);
    promise.then(
      (value) => { clearTimeout(timer); resolve(value); },
      (error: unknown) => { clearTimeout(timer); reject(error); },
    );
  })
);

const refreshIdentityWithDeadline = (): Promise<OfflineIdentity> => withDeadline(
  refreshOfflineIdentity(),
  READ_TIMEOUT_MS,
  'Your sign-in could not be checked in time. The inspection stays saved on this device.',
);

const notifyQueueChanged = (): void => {
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new Event(QUEUE_UPDATED_EVENT));
  }
};

/**
 * Tell listeners (connection indicator, open wizard) that a saved-work queue
 * changed outside this module, e.g. after the generic form queue replayed.
 */
export const announceSubmissionQueuesChanged = (): void => notifyQueueChanged();

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
    status: 'requires_attention',
    retryCount: 0,
    ownershipState: 'legacy_unclaimed',
    lastError: requiresAttention
      ? 'This saved inspection predates immutable checklist versions and account-bound queues; it needs operator review.'
      : 'This saved inspection predates account-bound offline queues and needs operator review.',
    lastErrorCode: 'OFFLINE_QUEUE_OWNER_LEGACY',
    lastErrorAt: createdAt,
    retentionExpiresAt: new Date(createdAt.getTime() + CLIENT_ERROR_RETENTION_DAYS * 24 * 60 * 60 * 1000),
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
  /** The member identity the caller already verified for this submission. */
  verifiedOwner?: OfflineIdentity,
): Promise<string> => {
  await ensureDailyCheckoutQueueReady();

  if (!isChecklistVersion(data.checklist_version)) {
    throw new Error('The Daily Checkout checklist version is unavailable. The inspection was not queued.');
  }

  const now = new Date();
  const owner = verifiedOwner ?? await identityForQueueCapture();
  const queuedSubmission: DailyCheckoutQueuedSubmission = {
    id: `daily_${data.client_submission_id}`,
    apparatusId,
    checklistVersion: data.checklist_version,
    data,
    createdAt: now,
    updatedAt: now,
    status: 'pending',
    retryCount: 0,
    ownerUserId: owner.userId,
    ownerSecurityVersion: owner.securityVersion,
    ownershipState: 'owned',
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

const pendingAttemptTimes = async (): Promise<{ nextAttemptAt?: number; lastAttemptAt?: number }> => {
  const now = Date.now();
  let nextAttemptAt: number | undefined;
  let lastAttemptAt: number | undefined;
  // Pending records are few (one per apparatus and checklist version).
  await db.dailyCheckoutSubmissions.where('status').equals('pending').each((submission) => {
    const next = submission.nextAttemptAt instanceof Date ? submission.nextAttemptAt.getTime() : undefined;
    if (next !== undefined && next > now && (nextAttemptAt === undefined || next < nextAttemptAt)) nextAttemptAt = next;
    const last = submission.lastAttemptAt instanceof Date ? submission.lastAttemptAt.getTime() : undefined;
    if (last !== undefined && (lastAttemptAt === undefined || last > lastAttemptAt)) lastAttemptAt = last;
  });

  return { nextAttemptAt, lastAttemptAt };
};

/**
 * Counts come from the `status` index, so the photos and signatures inside
 * stored payloads are not read for the connection indicator. Only the first
 * record needing attention is loaded, for its message.
 */
export const getDailyCheckoutQueueSummary = async (): Promise<DailyCheckoutQueueSummary> => {
  await ensureDailyCheckoutQueueReady();
  const [total, requiresAttention, firstAttention] = await Promise.all([
    db.dailyCheckoutSubmissions.count(),
    db.dailyCheckoutSubmissions.where('status').equals('requires_attention').count(),
    db.dailyCheckoutSubmissions.where('status').equals('requires_attention').first(),
  ]);

  return {
    total,
    pending: total - requiresAttention,
    requiresAttention,
    firstAttentionError: firstAttention?.lastError,
    firstAttentionErrorCode: firstAttention?.lastErrorCode,
  };
};

export interface DailyCheckoutReplaySchedule {
  pending: number;
  /** Earliest time a Retry-After response allows (epoch ms), if in the future. */
  nextAttemptAt?: number;
  /** Most recent send attempt of any pending record (epoch ms). */
  lastAttemptAt?: number;
}

/** When the next automatic replay is allowed, honouring Retry-After. */
export const getDailyCheckoutReplaySchedule = async (): Promise<DailyCheckoutReplaySchedule> => {
  await ensureDailyCheckoutQueueReady();
  const [pending, times] = await Promise.all([
    db.dailyCheckoutSubmissions.where('status').equals('pending').count(),
    pendingAttemptTimes(),
  ]);

  return { pending, ...times };
};

/** Background replay found the member signed out (detail: boolean required). */
export const DAILY_CHECKOUT_SIGN_IN_REQUIRED_EVENT = 'mbfd:daily-checkout-sign-in-required';

/** Records needing a decision, newest first, without their photo payloads. */
export const getDailyCheckoutAttentionRecords = async (currentUserId?: number): Promise<DailyCheckoutAttentionRecord[]> => {
  await ensureDailyCheckoutQueueReady();
  const records = await db.dailyCheckoutSubmissions.where('status').equals('requires_attention').toArray();

  return records
    .sort((left, right) => new Date(right.createdAt).getTime() - new Date(left.createdAt).getTime())
    .map((record) => ({
      id: record.id,
      apparatusId: record.apparatusId,
      operatorName: typeof record.data?.operator_name === 'string' ? record.data.operator_name : undefined,
      createdAt: new Date(record.createdAt),
      lastError: record.lastError,
      lastErrorCode: record.lastErrorCode,
      lastErrorStatus: record.lastErrorStatus,
      unitNumber: typeof record.data?.unit_number === 'string' && record.data.unit_number !== '' ? record.data.unit_number : undefined,
      canDiscard: currentUserId !== undefined
        && record.ownershipState === 'owned'
        && record.ownerUserId === currentUserId,
    }));
};

/**
 * Permanently remove one record that needs attention. Only called after the
 * member explicitly confirms. Pending (unsent) records cannot be discarded,
 * and nothing is ever discarded automatically.
 */
export const discardDailyCheckoutAttentionRecord = async (id: string, currentUserId: number): Promise<boolean> => {
  await ensureDailyCheckoutQueueReady();
  if (inFlightSubmissions.has(id)) return false;
  const removed = await db.transaction('rw', db.dailyCheckoutSubmissions, async () => {
    const record = await db.dailyCheckoutSubmissions.get(id);
    if (!record || record.status !== 'requires_attention'
      || record.ownershipState !== 'owned' || record.ownerUserId !== currentUserId) return false;
    await db.dailyCheckoutSubmissions.delete(id);

    return true;
  });
  if (removed) notifyQueueChanged();

  return removed;
};

const equipmentEvidence = (areas: unknown): string | null => {
  if (!Array.isArray(areas)) return null;
  const evidence = areas.map((area: unknown) => {
    if (!area || typeof area !== 'object' || !('id' in area) || typeof area.id !== 'string'
      || !('items' in area) || !Array.isArray(area.items)) return null;
    const items = area.items.map((value: unknown) => {
      if (!value || typeof value !== 'object') return null;
      const item = value as Record<string, unknown>;
      if (typeof item.id !== 'string' || !['Present', 'Missing', 'Damaged'].includes(String(item.status))) return null;
      return {
        id: item.id,
        status: item.status,
        observed: item.observed === true,
        value: item.value,
        notes: item.notes || null,
        photo: item.photo || undefined,
      };
    });
    return items.includes(null) ? null : { id: area.id, items };
  });
  return evidence.includes(null) ? null : JSON.stringify(evidence);
};

const draftMatchesSubmission = (
  draft: InspectionData | null | undefined,
  submission: DailyCheckoutQueuedSubmission,
  acceptedEvidence: string | null,
): boolean => Boolean(draft) && acceptedEvidence !== null
  && draft!.apparatusSnapshot?.id === submission.apparatusId
  && draft!.checklist_version === submission.checklistVersion
  && draft!.actorUserId === submission.ownerUserId
  && draft!.actorSecurityVersion === submission.ownerSecurityVersion
  && draft!.signature === submission.data.officer_signature
  && draft!.officer?.shift === submission.data.shift
  && JSON.stringify(draft!.fieldValues ?? []) === JSON.stringify(submission.data.field_values ?? [])
  && JSON.stringify(draft!.scheduledTasks ?? []) === JSON.stringify(submission.data.scheduled_tasks ?? [])
  && draft!.meter?.engine_hours === submission.data.engine_hours
  && draft!.meter?.miles === submission.data.miles
  && equipmentEvidence(draft!.compartments) === acceptedEvidence;

/** Page-hide snapshots of the accepted draft must not be restored either. */
const removeMatchingDraftSnapshots = (
  submission: DailyCheckoutQueuedSubmission,
  acceptedEvidence: string | null,
  keys: string[],
): void => {
  try {
    for (const key of keys) localStorage.removeItem(key);
    const snapshotKeys = Array.from({ length: localStorage.length }, (_, index) => localStorage.key(index))
      .filter((key): key is string => key !== null && key.startsWith(AUTOSAVE_STORAGE_PREFIX));
    for (const key of snapshotKeys) {
      let snapshot: InspectionData | null = null;
      try {
        snapshot = JSON.parse(localStorage.getItem(key) ?? 'null') as InspectionData | null;
      } catch {
        continue;
      }
      if (draftMatchesSubmission(snapshot, submission, acceptedEvidence)) localStorage.removeItem(key);
    }
  } catch (error) {
    console.warn('Saved draft copies for an accepted inspection could not be removed:', error);
  }
};

const removeFromQueue = async (
  submission: DailyCheckoutQueuedSubmission,
  outcome: InspectionReceiptOutcome,
): Promise<void> => {
  const acceptedEvidence = equipmentEvidence(submission.data.compartments);
  let removedDraftKeys: string[] = [];
  await db.transaction('rw', db.dailyCheckoutSubmissions, db.dailyCheckoutDrafts, async () => {
    const drafts = await db.dailyCheckoutDrafts.toArray();
    const matching = drafts.filter(draft => draftMatchesSubmission(draft.data, submission, acceptedEvidence));
    removedDraftKeys = matching.map(draft => draft.key);
    await db.dailyCheckoutDrafts.bulkDelete(removedDraftKeys);
    await db.dailyCheckoutSubmissions.delete(submission.id);
  });
  removeMatchingDraftSnapshots(submission, acceptedEvidence, removedDraftKeys);
  // Record the outcome before listeners learn that the record is gone.
  rememberAcceptedOutcome(submission.id, outcome);
  notifyQueueChanged();
};

const recordSubmissionFailure = async (id: string, error: unknown): Promise<void> => {
  const queuedSubmission = await db.dailyCheckoutSubmissions.get(id);
  if (!queuedSubmission) {
    return;
  }

  const now = new Date();
  const permanentFailure = isPermanentSubmissionFailure(error);
  const retryAfterMs = error instanceof ApiRequestError ? error.retryAfterMs : undefined;
  await db.dailyCheckoutSubmissions.update(id, {
    status: permanentFailure ? 'requires_attention' : 'pending',
    retryCount: queuedSubmission.retryCount + 1,
    lastAttemptAt: now,
    // A rejection keeps the server's own message for review; a pending record
    // keeps a plain explanation the member can act on.
    lastError: permanentFailure ? error.message : describeSubmissionFailure(error),
    nextAttemptAt: !permanentFailure && retryAfterMs !== undefined ? new Date(now.getTime() + retryAfterMs) : undefined,
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
const quarantineOwnerMismatch = async (
  submission: DailyCheckoutQueuedSubmission,
  identity: OfflineIdentity,
): Promise<boolean> => {
  const now = new Date();
  if (submission.ownershipState !== 'owned'
    || !Number.isInteger(submission.ownerUserId)
    || !Number.isInteger(submission.ownerSecurityVersion)) {
    await db.dailyCheckoutSubmissions.update(submission.id, {
      status: 'requires_attention',
      ownershipState: 'legacy_unclaimed',
      lastError: 'This saved work has no verified account owner and needs operator review.',
      lastErrorCode: 'OFFLINE_QUEUE_OWNER_LEGACY',
      lastErrorAt: now,
      updatedAt: now,
    });
    return true;
  }
  if (submission.ownerUserId !== identity.userId) {
    await db.dailyCheckoutSubmissions.update(submission.id, {
      status: 'requires_attention',
      ownershipState: 'identity_mismatch',
      lastError: 'This saved Daily Checkout belongs to a different signed-in member and was not submitted.',
      lastErrorCode: 'OFFLINE_QUEUE_OWNER_MISMATCH',
      lastErrorAt: now,
      updatedAt: now,
    });
    return true;
  }
  if (submission.ownerSecurityVersion !== identity.securityVersion) {
    await db.dailyCheckoutSubmissions.update(submission.id, {
      status: 'requires_attention',
      ownershipState: 'security_mismatch',
      lastError: 'The account security context changed after this work was saved; operator review is required.',
      lastErrorCode: 'OFFLINE_QUEUE_SECURITY_VERSION_MISMATCH',
      lastErrorAt: now,
      updatedAt: now,
    });
    return true;
  }
  return false;
};

export const submitQueuedInspection = (
  queueId: string,
  currentIdentity?: OfflineIdentity,
): Promise<QueuedInspectionSubmissionResult> => {
  const inFlight = inFlightSubmissions.get(queueId);
  if (inFlight) {
    return inFlight;
  }

  const submission = (async () => {
    const queuedSubmission = await getQueuedSubmission(queueId);
    if (!queuedSubmission) {
      return 'not_found' as const;
    }

    const deferredUntil = queuedSubmission.nextAttemptAt instanceof Date ? queuedSubmission.nextAttemptAt.getTime() : 0;
    if (deferredUntil > Date.now()) {
      throw new DailyCheckoutRetryDeferredError(deferredUntil);
    }

    const identity = currentIdentity ?? await refreshIdentityWithDeadline();
    if (await quarantineOwnerMismatch(queuedSubmission, identity)) {
      notifyQueueChanged();
      return 'not_found' as const;
    }

    const attemptedAt = new Date();
    await db.dailyCheckoutSubmissions.update(queueId, {
      lastAttemptAt: attemptedAt,
      updatedAt: attemptedAt,
    });

    try {
      const receipt = await ApiClient.submitInspection(queuedSubmission.apparatusId, queuedSubmission.data);
      const outcome = receipt.processing_status === 'accepted_with_exception' ? 'accepted_with_exception' as const
        : receipt.processing_status === 'accepted' ? 'submitted' as const
        : receipt.review_status === 'pending_review' ? 'pending_review' as const : 'submitted' as const;
      await removeFromQueue(queuedSubmission, outcome);

      return outcome;
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
 * Member-initiated "Retry now" for one pending record. It goes through the
 * same replay path as reconnect: refresh the member context (renewing the XSRF
 * cookie), then submit the exact durable payload. The in-flight map makes a
 * concurrent background replay of the same record share this request.
 */
export const retryQueuedSubmission = async (queueId: string): Promise<QueuedInspectionSubmissionResult> => {
  await ensureDailyCheckoutQueueReady();
  const queuedSubmission = await db.dailyCheckoutSubmissions.get(queueId);
  if (!queuedSubmission) {
    return 'not_found';
  }
  if (queuedSubmission.status !== 'pending') {
    throw new Error('This saved inspection needs review and cannot be sent again as it is.');
  }
  if (typeof navigator !== 'undefined' && !navigator.onLine) {
    throw new Error('This device is offline. The inspection stays saved on this device and will be sent when the connection returns.');
  }

  const inFlight = inFlightSubmissions.get(queueId);
  if (inFlight) {
    return inFlight;
  }

  return submitQueuedInspection(queueId, await refreshIdentityWithDeadline());
};

/**
 * Replay pending Daily Checkout records after the application mounts or the
 * browser reconnects. Client-invalid records remain persisted with an error
 * state so an operator can review them instead of silently losing the payload.
 */
export const synchronizeDailyCheckoutQueue = (currentIdentity?: OfflineIdentity): Promise<DailyCheckoutQueueSyncResult> => {
  if (activeQueueSynchronization) {
    return activeQueueSynchronization;
  }

  activeQueueSynchronization = (async () => {
    let submitted = 0;
    const submittedQueueIds: string[] = [];
    const pendingReviewQueueIds: string[] = [];
    const exceptionQueueIds: string[] = [];

    const identity = currentIdentity ?? await refreshIdentityWithDeadline();
    await ensureDailyCheckoutQueueReady();
    // Only pending records are replayed; records awaiting review (and their
    // photos) are not read on every automatic pass.
    const pendingSubmissions = await db.dailyCheckoutSubmissions.where('status').equals('pending').sortBy('createdAt');
    for (const queuedSubmission of pendingSubmissions) {
      if (typeof navigator !== 'undefined' && !navigator.onLine) {
        break;
      }

      if (queuedSubmission.status !== 'pending') {
        continue;
      }

      try {
        const result = await submitQueuedInspection(queuedSubmission.id, identity);
        if (result === 'submitted' || result === 'pending_review' || result === 'accepted_with_exception') {
          submitted += 1;
          submittedQueueIds.push(queuedSubmission.id);
        }

        if (result === 'pending_review') {
          pendingReviewQueueIds.push(queuedSubmission.id);
        }
        if (result === 'accepted_with_exception') exceptionQueueIds.push(queuedSubmission.id);
      } catch (error) {
        if (error instanceof DailyCheckoutRetryDeferredError) {
          continue;
        }
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
      exceptionQueueIds,
      remaining: await db.dailyCheckoutSubmissions.count(),
    };
  })().finally(() => {
    activeQueueSynchronization = null;
  });

  return activeQueueSynchronization;
};
