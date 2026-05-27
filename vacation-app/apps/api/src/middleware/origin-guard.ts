import { createMiddleware } from 'hono/factory';
import { getEnv } from '../env';
import { logger } from '../log';

/**
 * Require the X-Origin-Token header to match the configured secret.
 *
 * Background: the Cloudflare Tunnel exposes BOTH vacation.mbfdhub.com (gated
 * by the PIN Worker) and vacation-origin.mbfdhub.com (un-gated, used by the
 * Worker itself to proxy authenticated traffic without re-entering its own
 * route). Without this middleware, anyone who guesses the un-gated hostname
 * could call /api/* directly and bypass the PIN.
 *
 * The PIN Worker injects this header on every proxied request. The header
 * value is a shared secret known only to the Worker and this API container.
 *
 * Failure mode is intentionally vague (404) so probes can't tell whether
 * the path exists.
 */
export const originGuard = createMiddleware(async (c, next) => {
  const env = getEnv();
  const got = c.req.header('x-origin-token');
  if (!env.ORIGIN_SHARED_TOKEN || !got || got !== env.ORIGIN_SHARED_TOKEN) {
    logger.warn(
      { path: c.req.path, ip: c.req.header('cf-connecting-ip') ?? c.req.header('x-real-ip') },
      'origin guard rejected request',
    );
    return c.json({ error: 'not_found' }, 404);
  }
  await next();
});
