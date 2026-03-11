import Dexie, { type Table } from 'dexie';

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

  constructor() {
    super('mbfd-daily-checkout');

    this.version(1).stores({
      pendingSubmissions: '++id, type, status, createdAt, retryCount',
      cachedData: 'key, updatedAt',
    });
  }
}

export const db = new MBFDDatabase();
