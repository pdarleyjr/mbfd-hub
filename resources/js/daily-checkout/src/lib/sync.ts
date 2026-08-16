import { db, type PendingSubmission } from './db';
import { QueryClient } from '@tanstack/react-query';

const MAX_RETRIES = 5;
const BASE_DELAY_MS = 1000;

export type SubmissionOutcome = 'submitted' | 'queued';

class PermanentSubmissionError extends Error {}

export function createClientSubmissionId(): string {
  if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function withIdempotencyKey(type: string, data: Record<string, unknown>): Record<string, unknown> {
  if (type !== 'station_request' || typeof data.client_submission_id === 'string') return data;
  return { ...data, client_submission_id: createClientSubmissionId() };
}

function getBackoffDelay(retryCount: number): number {
  return Math.min(BASE_DELAY_MS * Math.pow(2, retryCount), 30000);
}

async function processSubmission(
  submission: PendingSubmission,
  apiBaseUrl: string,
): Promise<unknown> {
  const response = await fetch(`${apiBaseUrl}/${submission.type}`, {
    method: 'POST',
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(submission.data),
  });

  if (!response.ok) {
    let detail = response.statusText;
    try {
      const body = await response.json() as { message?: string };
      detail = body.message || detail;
    } catch {
      // A non-JSON error body still has a useful HTTP status.
    }

    const message = `HTTP ${response.status}: ${detail}`;
    if (response.status < 500 && response.status !== 429) {
      throw new PermanentSubmissionError(message);
    }
    throw new Error(message);
  }

  if (response.status === 204) return null;
  return response.json().catch(() => null);
}

export async function enqueueSubmission(
  type: string,
  data: Record<string, unknown>,
): Promise<number> {
  const durableData = withIdempotencyKey(type, data);
  return db.pendingSubmissions.add({
    type,
    data: durableData,
    createdAt: new Date(),
    status: 'pending',
    retryCount: 0,
  });
}

/**
 * Submit immediately when possible, queue only recoverable connectivity/server
 * failures, and surface permanent validation/route errors to the operator.
 */
export async function submitOrQueue(
  type: string,
  data: Record<string, unknown>,
  apiBaseUrl: string,
): Promise<SubmissionOutcome> {
  return (await submitOrQueueWithResponse(type, data, apiBaseUrl)).outcome;
}

export async function submitOrQueueWithResponse(
  type: string,
  data: Record<string, unknown>,
  apiBaseUrl: string,
): Promise<{ outcome: SubmissionOutcome; response: unknown }> {
  const durableData = withIdempotencyKey(type, data);
  if (!navigator.onLine) {
    await enqueueSubmission(type, durableData);
    return { outcome: 'queued', response: null };
  }

  try {
    const response = await processSubmission({
      type,
      data: durableData,
      createdAt: new Date(),
      status: 'processing',
      retryCount: 0,
    }, apiBaseUrl);
    return { outcome: 'submitted', response };
  } catch (error) {
    if (error instanceof PermanentSubmissionError) {
      throw error;
    }

    await enqueueSubmission(type, durableData);
    return { outcome: 'queued', response: null };
  }
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
      const isPermanent = error instanceof PermanentSubmissionError;
      const newRetryCount = isPermanent ? MAX_RETRIES : submission.retryCount + 1;
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
