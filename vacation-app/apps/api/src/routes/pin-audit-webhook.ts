import { timingSafeEqual } from 'node:crypto';
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

/**
 * Timing-safe Bearer-token check. The webhook is intentionally exposed
 * without `originGuard` (so the Cloudflare Worker can hit it directly),
 * which makes this token the only thing protecting the audit table.
 * Using `===` would leak the token byte-by-byte via response timing to
 * any attacker who can measure sub-millisecond differences.
 */
function bearerOk(auth: string, expected: string): boolean {
  const got = Buffer.from(auth);
  const want = Buffer.from(expected);
  if (got.length !== want.length) return false;
  return timingSafeEqual(got, want);
}

pinAuditWebhook.post('/__pin/audit-webhook', async (c) => {
  const env = getEnv();
  const auth = c.req.header('authorization') ?? '';
  const expected = `Bearer ${env.PIN_AUDIT_WEBHOOK_SECRET}`;
  if (!bearerOk(auth, expected)) {
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
