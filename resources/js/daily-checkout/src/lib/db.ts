import Dexie, { type Table } from 'dexie';
import type { TrtCatalogItem } from '../types/trt-inventory';
import type { InspectionSubmission } from '../types';

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
  retentionExpiresAt?: Date;
  ownerUserId?: number;
  ownerSecurityVersion?: number;
  ownershipState?: 'owned' | 'legacy_unclaimed' | 'identity_mismatch' | 'security_mismatch';
}

class MBFDDatabase extends Dexie {
  pendingSubmissions!: Table<PendingSubmission, number>;
  cachedData!: Table<CachedData, string>;
  trtCatalog!: Table<TrtCatalogItem, number>;
  dailyCheckoutSubmissions!: Table<DailyCheckoutQueuedSubmission, string>;

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
    // practical quota, and the unique apparatus index preserves one unresolved
    // inspection per apparatus.
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
  }
}

export const db = new MBFDDatabase();
