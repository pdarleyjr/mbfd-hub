import IORedis from 'ioredis';
import { getEnv } from './env';

const env = getEnv();
const pub = new IORedis(env.REDIS_URL, { maxRetriesPerRequest: null });

export function progressChannel(runId: string): string {
  return `import:${runId}:progress`;
}

export async function publish(runId: string, event: unknown): Promise<void> {
  await pub.publish(progressChannel(runId), JSON.stringify(event));
}
