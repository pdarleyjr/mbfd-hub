import { db, type PendingSubmission } from './db';
import { QueryClient } from '@tanstack/react-query';

const MAX_RETRIES = 5;
const BASE_DELAY_MS = 1000;

function getBackoffDelay(retryCount: number): number {
  return Math.min(BASE_DELAY_MS * Math.pow(2, retryCount), 30000);
}

async function processSubmission(
  submission: PendingSubmission,
  apiBaseUrl: string,
): Promise<boolean> {
  const response = await fetch(`${apiBaseUrl}/${submission.type}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(submission.data),
  });

  if (!response.ok) {
    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
  }

  return true;
}

export async function enqueueSubmission(
  type: string,
  data: Record<string, unknown>,
): Promise<number> {
  return db.pendingSubmissions.add({
    type,
    data,
    createdAt: new Date(),
    status: 'pending',
    retryCount: 0,
  });
}

export async function processPendingSubmissions(
  apiBaseUrl: string,
  queryClient?: QueryClient,
): Promise<{ processed: number; failed: number }> {
  const pending = await db.pendingSubmissions
    .where('status')
    .anyOf('pending', 'failed')
    .and((s) => s.retryCount < MAX_RETRIES)
    .toArray();

  let processed = 0;
  let failed = 0;

  for (const submission of pending) {
    if (!navigator.onLine) break;

    try {
      await db.pendingSubmissions.update(submission.id!, {
        status: 'processing',
      });

      await processSubmission(submission, apiBaseUrl);

      await db.pendingSubmissions.delete(submission.id!);
      processed++;
    } catch (error) {
      const newRetryCount = submission.retryCount + 1;
      await db.pendingSubmissions.update(submission.id!, {
        status: newRetryCount >= MAX_RETRIES ? 'failed' : 'pending',
        retryCount: newRetryCount,
        lastError: error instanceof Error ? error.message : String(error),
      });
      failed++;
    }
  }

  if (processed > 0 && queryClient) {
    queryClient.invalidateQueries();
  }

  return { processed, failed };
}

export class BackgroundSyncManager {
  private intervalId: ReturnType<typeof setInterval> | null = null;
  private apiBaseUrl: string;
  private queryClient?: QueryClient;

  constructor(apiBaseUrl: string, queryClient?: QueryClient) {
    this.apiBaseUrl = apiBaseUrl;
    this.queryClient = queryClient;
  }

  start(intervalMs = 30000): void {
    if (this.intervalId) return;

    window.addEventListener('online', this.onOnline);

    this.intervalId = setInterval(() => {
      if (navigator.onLine) {
        processPendingSubmissions(this.apiBaseUrl, this.queryClient);
      }
    }, intervalMs);
  }

  stop(): void {
    if (this.intervalId) {
      clearInterval(this.intervalId);
      this.intervalId = null;
    }
    window.removeEventListener('online', this.onOnline);
  }

  private onOnline = (): void => {
    // When coming back online, process after a short delay
    setTimeout(() => {
      processPendingSubmissions(this.apiBaseUrl, this.queryClient);
    }, getBackoffDelay(0));
  };
}
