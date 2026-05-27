import { Queue, QueueEvents } from 'bullmq';
import IORedis from 'ioredis';
import { getEnv } from './env.js';

export const IMPORTS_QUEUE_NAME = 'imports' as const;

const env = getEnv();

export const redis = new IORedis(env.REDIS_URL, {
  maxRetriesPerRequest: null,
  enableReadyCheck: true,
});

export const importsQueue = new Queue(IMPORTS_QUEUE_NAME, { connection: redis });
export const importsQueueEvents = new QueueEvents(IMPORTS_QUEUE_NAME, { connection: redis });

export type ParsePreviewJob = { runId: string };
export type CommitImportJob = { runId: string };

export async function enqueueParsePreview(runId: string): Promise<void> {
  await importsQueue.add(
    'parse-preview',
    { runId } satisfies ParsePreviewJob,
    {
      jobId: `parse-${runId}`,
      attempts: 3,
      backoff: { type: 'exponential', delay: 5_000 },
      removeOnComplete: { age: 86_400, count: 200 },
      removeOnFail: { age: 604_800 },
    },
  );
}

export async function enqueueCommitImport(runId: string): Promise<void> {
  await importsQueue.add(
    'commit-import',
    { runId } satisfies CommitImportJob,
    {
      jobId: `commit-${runId}`,
      attempts: 1, // commits are not retried automatically — they're transactional
      removeOnComplete: { age: 86_400, count: 200 },
      removeOnFail: { age: 604_800 },
    },
  );
}

/** Channel name for SSE progress fan-out. */
export function progressChannel(runId: string): string {
  return `import:${runId}:progress`;
}
