import { serve } from '@hono/node-server';
import { Hono } from 'hono';
import { logger as honoLogger } from 'hono/logger';
import { secureHeaders } from 'hono/secure-headers';
import { getEnv } from './env.js';
import { logger } from './log.js';
import { board } from './routes/board.js';
import { health } from './routes/health.js';
import { importsCommit } from './routes/imports-commit.js';
import { importsList } from './routes/imports-list.js';
import { importsPreview } from './routes/imports-preview.js';
import { importsRollback } from './routes/imports-rollback.js';
import { importsUpload } from './routes/imports-upload.js';
import { pinAuditWebhook } from './routes/pin-audit-webhook.js';

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

app.route('/api', health);
app.route('/api', importsUpload);
app.route('/api', importsPreview);
app.route('/api', importsCommit);
app.route('/api', importsRollback);
app.route('/api', importsList);
app.route('/api', board);
app.route('/api', pinAuditWebhook);

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
