import { serve } from '@hono/node-server';
import { Hono } from 'hono';
import { logger as honoLogger } from 'hono/logger';
import { secureHeaders } from 'hono/secure-headers';
import { getEnv } from './env';
import { logger } from './log';
import { board } from './routes/board';
import { health } from './routes/health';
import { importsCommit } from './routes/imports-commit';
import { importsList } from './routes/imports-list';
import { importsPreview } from './routes/imports-preview';
import { importsRollback } from './routes/imports-rollback';
import { importsUpload } from './routes/imports-upload';
import { leaveCodesRoute } from './routes/leave-codes';
import { membersRoute } from './routes/members';
import { originGuard } from './middleware/origin-guard';
import { pinAuditWebhook } from './routes/pin-audit-webhook';
import { staffingDecision } from './routes/staffing-decision';
import { staffingRulesRoute } from './routes/staffing-rules';

const env = getEnv();

const app = new Hono();
app.use('*', honoLogger((m) => logger.info(m)));
app.use(
  '*',
  secureHeaders({
    contentSecurityPolicy: undefined, // SPA handles its own CSP
    crossOriginEmbedderPolicy: false,
    crossOriginResourcePolicy: 'same-origin',
  }),
);

// /api/health and the PIN audit webhook are intentionally unprotected:
// health is needed by docker + nginx healthchecks, and the audit webhook
// authenticates via its own HMAC bearer token.
app.route('/api', health);
app.route('/api', pinAuditWebhook);

// Everything else requires the X-Origin-Token header injected by the
// Cloudflare Worker (PIN gate). Closes the vacation-origin.mbfdhub.com
// bypass — even if an attacker discovers the un-gated tunnel hostname,
// they cannot reach board / import / rollback / leave-code endpoints.
app.use('/api/*', originGuard);

app.route('/api', importsUpload);
app.route('/api', importsPreview);
app.route('/api', importsCommit);
app.route('/api', importsRollback);
app.route('/api', importsList);
app.route('/api', board);
app.route('/api', leaveCodesRoute);
app.route('/api', membersRoute);
app.route('/api', staffingRulesRoute);
app.route('/api', staffingDecision);

app.notFound((c) => c.json({ error: 'not_found' }, 404));
app.onError((err, c) => {
  logger.error({ err }, 'unhandled error');
  return c.json({ error: 'internal' }, 500);
});

const port = env.API_PORT;
serve({ fetch: app.fetch, port }, () => {
  logger.info({ port, env: env.NODE_ENV }, 'vac-api listening');
});

const shutdown = (signal: string) => {
  logger.info({ signal }, 'shutting down');
  setTimeout(() => process.exit(0), 1_000).unref();
};
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
