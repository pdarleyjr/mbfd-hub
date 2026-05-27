import { rollbackImportRun } from '@mbfd-vacation/db/operations/rollback';
import { Hono } from 'hono';
import { db } from '../db.js';
import { logger } from '../log.js';

export const importsRollback = new Hono();

importsRollback.post('/imports/:id/rollback', async (c) => {
  const id = c.req.param('id');
  try {
    const result = await rollbackImportRun(db, id);
    logger.info({ runId: id, result }, 'import rolled back');
    return c.json(result);
  } catch (err) {
    if (err instanceof Error && err.message.includes('not found')) {
      return c.json({ error: 'not_found' }, 404);
    }
    logger.error({ err }, 'rollback failed');
    return c.json({ error: 'internal' }, 500);
  }
});
