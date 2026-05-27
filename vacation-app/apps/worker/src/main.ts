import { Worker } from 'bullmq';
import IORedis from 'ioredis';
import { close } from './db';
import { getEnv } from './env';
import { commitImportJob } from './jobs/commit-import';
import { parsePreviewJob } from './jobs/parse-preview';
import { logger } from './log';

const env = getEnv();
const connection = new IORedis(env.REDIS_URL, { maxRetriesPerRequest: null });

const worker = new Worker(
  'imports',
  async (job) => {
    const { runId } = job.data as { runId: string };
    logger.info({ jobId: job.id, name: job.name, runId }, 'job start');
    if (job.name === 'parse-preview') {
      await parsePreviewJob(runId);
    } else if (job.name === 'commit-import') {
      await commitImportJob(runId);
    } else {
      logger.warn({ name: job.name }, 'unknown job');
    }
  },
  {
    connection,
    concurrency: env.WORKER_CONCURRENCY,
    autorun: true,
  },
);

worker.on('completed', (job) => {
  logger.info({ jobId: job.id }, 'job completed');
});
worker.on('failed', (job, err) => {
  logger.error({ jobId: job?.id, err }, 'job failed');
});

logger.info({ concurrency: env.WORKER_CONCURRENCY }, 'vac-worker started');

const shutdown = async (signal: string) => {
  logger.info({ signal }, 'shutting down worker');
  await worker.close();
  await close();
  await connection.quit();
  process.exit(0);
};
process.on('SIGTERM', () => void shutdown('SIGTERM'));
process.on('SIGINT', () => void shutdown('SIGINT'));
