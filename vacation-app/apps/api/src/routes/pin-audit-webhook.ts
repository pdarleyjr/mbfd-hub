import { pinAudit } from '@mbfd-vacation/db';
import { Hono } from 'hono';
import { z } from 'zod';
import { db } from '../db';
import { getEnv } from '../env';
import { logger } from '../log';

export const pinAuditWebhook = new Hono();

const BodySchema = z.object({
  ip: z.string().optional(),
  userAgent: z.string().optional(),
  outcome: z.enum(['success', 'failure', 'rate_limited']),
});

pinAuditWebhook.post('/__pin/audit-webhook', async (c) => {
  const env = getEnv();
  const auth = c.req.header('authorization') ?? '';
  const expected = `Bearer ${env.PIN_AUDIT_WEBHOOK_SECRET}`;
  if (auth !== expected) {
    logger.warn('pin audit webhook bad auth');
    return c.json({ error: 'unauthorized' }, 401);
  }
  const raw = await c.req.json().catch(() => null);
  const parsed = BodySchema.safeParse(raw);
  if (!parsed.success) {
    return c.json({ error: 'invalid_body' }, 400);
  }
  await db.insert(pinAudit).values({
    ip: parsed.data.ip ?? null,
    userAgent: parsed.data.userAgent ?? null,
    outcome: parsed.data.outcome,
  });
  return c.body(null, 204);
});
