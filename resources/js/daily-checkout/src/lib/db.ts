import Dexie, { type Table } from 'dexie';
import type { TrtCatalogItem } from '../types/trt-inventory';

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

class MBFDDatabase extends Dexie {
  pendingSubmissions!: Table<PendingSubmission, number>;
  cachedData!: Table<CachedData, string>;
  trtCatalog!: Table<TrtCatalogItem, number>;

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
  }
}

export const db = new MBFDDatabase();
