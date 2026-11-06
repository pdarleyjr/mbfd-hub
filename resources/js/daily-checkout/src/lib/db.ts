import Dexie, { type Table } from 'dexie';
import type { TrtCatalogItem } from '../types/trt-inventory';
import type { InspectionData, InspectionSubmission } from '../types';

export interface DailyCheckoutDraft {
  key: string;
  apparatusSlug: string;
  timestamp: number;
  data: InspectionData;
}

export interface PendingSubmission {
  id?: number;
  type: string;
  data: Record<string, unknown>;
  createdAt: Date;
  status: 'pending' | 'processing' | 'failed' | 'requires_attention';
  retryCount: number;
  lastError?: string;
  lastErrorCode?: string;
  ownerUserId?: number;
  ownerSecurityVersion?: number;
  ownershipState?: 'owned' | 'legacy_unclaimed' | 'identity_mismatch' | 'security_mismatch';
}

export interface CachedData {
  key: string;
  data: unknown;
  updatedAt: Date;
}

export interface DailyCheckoutQueuedSubmission {
  id: string;
  apparatusId: number;
  checklistVersion: string;
  /** Not indexed: the template hash beneath approved inventory corrections (absent on older records). */
  checklistBaseVersion?: string;
  data: InspectionSubmission;
  createdAt: Date;
  updatedAt: Date;
  status: 'pending' | 'requires_attention';
  retryCount: number;
  lastAttemptAt?: Date;
  lastError?: string;
  lastErrorStatus?: number;
  lastErrorCode?: string;
  lastErrorAt?: Date;
  /** Not indexed: the earliest automatic replay a Retry-After response allows. */
  nextAttemptAt?: Date;
  retentionExpiresAt?: Date;
  ownerUserId?: number;
  ownerSecurityVersion?: number;
  ownershipState?: 'owned' | 'legacy_unclaimed' | 'identity_mismatch' | 'security_mismatch';
}

/**
 * One Daily Checkout photo waiting on (or already sent from) this device. Photos travel
 * on their own requests (utils/photoUploadQueue.ts); the checkout only names them by id.
 */
export interface QueuedPhoto {
  /** The photo id the server keys on (idempotency key for every attempt). */
  id: string;
  ownerUserId: number;
  /** The shrunken JPEG (or the original when the phone could not convert it). */
  blob: Blob;
  filename: string;
  bytes: number;
  status: 'pending' | 'sent' | 'rejected';
  attempts: number;
  /** Epoch ms before which no automatic attempt is made. */
  nextAttemptAt?: number;
  lastError?: string;
  createdAt: number;
  sentAt?: number;
}

class MBFDDatabase extends Dexie {
  pendingSubmissions!: Table<PendingSubmission, number>;
  cachedData!: Table<CachedData, string>;
  trtCatalog!: Table<TrtCatalogItem, number>;
  dailyCheckoutSubmissions!: Table<DailyCheckoutQueuedSubmission, string>;
  dailyCheckoutDrafts!: Table<DailyCheckoutDraft, string>;
  photoUploads!: Table<QueuedPhoto, string>;

  constructor() {
    super('mbfd-daily-checkout');

    this.version(1).stores({
      pendingSubmissions: '++id, type, status, createdAt, retryCount',
      cachedData: 'key, updatedAt',
    });

    this.version(2).stores({
      pendingSubmissions: '++id, type, status, createdAt, retryCount',
      cachedData: 'key, updatedAt',
      trtCatalog: 'id, category, sort_order',
    });

    // Keep Daily Checkout records separate from the generic form-sync queue.
    // IndexedDB handles the signature/photo payloads that exceed localStorage's
    // practical quota. (Version 7 makes the duplicate guard per member.)
    this.version(3).stores({
      pendingSubmissions: '++id, type, status, createdAt, retryCount',
      cachedData: 'key, updatedAt',
      trtCatalog: 'id, category, sort_order',
      dailyCheckoutSubmissions: '&id, &apparatusId, status, createdAt, updatedAt, retentionExpiresAt',
    });

    // A checklist revision can change while a completed offline inspection is
    // awaiting officer review. Keep the old immutable payload and permit one
    // current-version payload for the same apparatus, while the compound unique
    // index still rejects duplicate unresolved submissions for one version.
    this.version(4).stores({
      pendingSubmissions: '++id, type, status, createdAt, retryCount',
      cachedData: 'key, updatedAt',
      trtCatalog: 'id, category, sort_order',
      dailyCheckoutSubmissions: '&id, &[apparatusId+checklistVersion], apparatusId, checklistVersion, status, createdAt, updatedAt, retentionExpiresAt',
    }).upgrade(async (transaction) => {
      await transaction.table('dailyCheckoutSubmissions').toCollection().modify((submission: DailyCheckoutQueuedSubmission) => {
        const checklistVersion = submission.data?.checklist_version;
        submission.checklistVersion = typeof checklistVersion === 'string' && checklistVersion !== ''
          ? checklistVersion
          : `legacy-${submission.id}`;
      });
    });

    // Existing records have no provable owner. Preserve them for review, but
    // never silently assign them to whichever member signs in next.
    this.version(5).stores({
      pendingSubmissions: '++id, type, status, createdAt, retryCount, ownerUserId, ownershipState',
      cachedData: 'key, updatedAt',
      trtCatalog: 'id, category, sort_order',
      dailyCheckoutSubmissions: '&id, &[apparatusId+checklistVersion], apparatusId, checklistVersion, status, createdAt, updatedAt, retentionExpiresAt, ownerUserId, ownershipState',
    }).upgrade(async (transaction) => {
      const markLegacy = (submission: PendingSubmission | DailyCheckoutQueuedSubmission) => {
        submission.status = 'requires_attention';
        submission.ownershipState = 'legacy_unclaimed';
        submission.lastError = 'This saved work predates account-bound offline queues and needs operator review.';
        submission.lastErrorCode = 'OFFLINE_QUEUE_OWNER_LEGACY';
      };
      await transaction.table('pendingSubmissions').toCollection().modify(markLegacy);
      await transaction.table('dailyCheckoutSubmissions').toCollection().modify(markLegacy);
    });

    this.version(6).stores({
      dailyCheckoutDrafts: '&key, apparatusSlug, timestamp',
    });

    // A shared station device: one member's unsent or rejected checkout must
    // never stop another member from inspecting the same truck. The duplicate
    // guard is per owner: one unresolved record per apparatus, checklist
    // version and member. The server accepts several checkouts per truck per
    // day (idempotent per client_submission_id), so nothing is lost.
    //
    // No upgrade step: every existing record is kept exactly as it is (no
    // deletion, no status change). Version 6 already held at most one record
    // per apparatus and checklist version, so adding the owner keeps them
    // unique. A record without an owner (legacy work saved before account-bound
    // queues) has no valid key for the owner index, so IndexedDB leaves it out
    // of that index: two legacy records can never collide, and no member's
    // checkout ever collides with a legacy one.
    this.version(7).stores({
      dailyCheckoutSubmissions: '&id, &[apparatusId+checklistVersion+ownerUserId], [apparatusId+checklistVersion], apparatusId, checklistVersion, status, createdAt, updatedAt, retentionExpiresAt, ownerUserId, ownershipState',
    });

    // Photos are queued and uploaded one at a time, apart from the checkout that names them.
    this.version(8).stores({
      photoUploads: '&id, status, ownerUserId, createdAt',
    });
  }
}

export const db = new MBFDDatabase();
