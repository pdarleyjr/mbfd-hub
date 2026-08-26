import Dexie, { type Table } from 'dexie';
import type { TrtCatalogItem } from '../types/trt-inventory';
import type { InspectionSubmission } from '../types';

export interface PendingSubmission {
  id?: number;
  type: string;
  data: Record<string, unknown>;
  createdAt: Date;
  status: 'pending' | 'processing' | 'failed';
  retryCount: number;
  lastError?: string;
}

export interface CachedData {
  key: string;
  data: unknown;
  updatedAt: Date;
}

export interface DailyCheckoutQueuedSubmission {
  id: string;
  apparatusId: number;
  data: InspectionSubmission;
  createdAt: Date;
  updatedAt: Date;
  status: 'pending' | 'requires_attention';
  retryCount: number;
  lastAttemptAt?: Date;
  lastError?: string;
  lastErrorStatus?: number;
  lastErrorAt?: Date;
  retentionExpiresAt?: Date;
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
  }
}

export const db = new MBFDDatabase();
