import { Hono } from 'hono';
import { sql } from 'drizzle-orm';
import { db } from '../db';
import { redis } from '../queue';

export const health = new Hono();

health.get('/health', async (c) => {
  const result: Record<string, unknown> = {
    ok: true,
    service: 'vac-api',
    time: new Date().toISOString(),
  };
  // Postgres
  try {
    await db.execute(sql`SELECT 1`);
    result['db'] = 'ok';
  } catch (err) {
    result['db'] = 'down';
    result['ok'] = false;
  }
  // Redis
  try {
    const pong = await redis.ping();
    result['redis'] = pong === 'PONG' ? 'ok' : 'down';
  } catch {
    result['redis'] = 'down';
    result['ok'] = false;
  }
  return c.json(result, result['ok'] ? 200 : 503);
});
