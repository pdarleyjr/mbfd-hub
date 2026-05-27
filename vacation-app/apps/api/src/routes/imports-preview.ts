import { importRuns } from '@mbfd-vacation/db';
import { PreviewEventSchema, type PreviewEvent } from '@mbfd-vacation/shared';
import { eq } from 'drizzle-orm';
import { Hono } from 'hono';
import { streamSSE } from 'hono/streaming';
import IORedis from 'ioredis';
import { db } from '../db';
import { getEnv } from '../env';
import { logger } from '../log';
import { progressChannel } from '../queue';

export const importsPreview = new Hono();

/**
 * Server-sent events endpoint streaming worker progress.
 *
 * Subscribes to the Redis pub/sub channel `import:{id}:progress` and forwards
 * each message as an SSE event. The terminal event is either `preview_ready`
 * or `failed`, after which the connection closes.
 *
 * On (re)connect we replay the latest known state from import_runs so a brief
 * disconnect doesn't leave the UI in limbo.
 */
importsPreview.get('/imports/:id/preview', (c) => {
  const id = c.req.param('id');
  return streamSSE(c, async (stream) => {
    const env = getEnv();
    // dedicated subscriber connection — IORedis requires this for pub/sub
    const sub = new IORedis(env.REDIS_URL, { maxRetriesPerRequest: null });
    const channel = progressChannel(id);

    // Replay snapshot from DB so a client reconnect picks up the right state.
    const [run] = await db
      .select({
        status: importRuns.status,
        parseStats: importRuns.parseStats,
        previewPayloadJson: importRuns.previewPayloadJson,
        errorMessage: importRuns.errorMessage,
      })
      .from(importRuns)
      .where(eq(importRuns.id, id))
      .limit(1);

    if (!run) {
      await stream.writeSSE({ event: 'error', data: JSON.stringify({ error: 'not_found' }) });
      await sub.quit();
      return;
    }

    if (run.status === 'failed') {
      await stream.writeSSE({
        event: 'failed',
        data: JSON.stringify({
          type: 'failed',
          errorMessage: run.errorMessage ?? 'unknown error',
        }),
      });
      await sub.quit();
      return;
    }

    // If preview already completed, emit the cached preview_ready event
    // directly so the client can continue without waiting for live pub/sub.
    if (
      (run.status === 'preview_ready' || run.status === 'committing' ||
        run.status === 'committed' || run.status === 'rolled_back') &&
      run.previewPayloadJson
    ) {
      await stream.writeSSE({
        event: 'preview_ready',
        data: JSON.stringify(run.previewPayloadJson),
      });
      // For non-active states there's nothing more to stream.
      if (run.status !== 'committing') {
        await sub.quit();
        return;
      }
    }

    await sub.subscribe(channel);
    const cleanup = async () => {
      try {
        await sub.unsubscribe(channel);
      } catch (err) {
        logger.warn({ err }, 'unsubscribe failed');
      }
      await sub.quit();
    };

    sub.on('message', async (_ch, payload) => {
      try {
        const parsed = PreviewEventSchema.parse(JSON.parse(payload)) as PreviewEvent;
        await stream.writeSSE({
          event: parsed.type,
          data: JSON.stringify(parsed),
        });
        if (parsed.type === 'preview_ready' || parsed.type === 'failed') {
          await cleanup();
          await stream.close();
        }
      } catch (err) {
        logger.error({ err, payload }, 'invalid preview event');
      }
    });

    stream.onAbort(async () => {
      await cleanup();
    });
  });
});
