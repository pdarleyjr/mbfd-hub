import { importRuns } from '@mbfd-vacation/db';
import {
  ColumnMappingSchema,
  REQUIRED_TARGETS,
  WorkCodeDecisionsSchema,
} from '@mbfd-vacation/shared';
import { eq } from 'drizzle-orm';
import { Hono } from 'hono';
import { z } from 'zod';
import { db } from '../db';
import { logger } from '../log';
import { enqueueCommitImport } from '../queue';

export const importsCommit = new Hono();

const BodySchema = z.object({
  columnMapping: ColumnMappingSchema,
  workCodeDecisions: WorkCodeDecisionsSchema.default([]),
  saveAsTemplateName: z.string().min(1).max(64).optional(),
});

importsCommit.post('/imports/:id/commit', async (c) => {
  const id = c.req.param('id');
  const raw = await c.req.json().catch(() => null);
  const parsed = BodySchema.safeParse(raw);
  if (!parsed.success) {
    return c.json({ error: 'invalid_body', issues: parsed.error.flatten() }, 400);
  }
  const body = parsed.data;

  const mappedTargets = new Set(body.columnMapping.columns.map((c) => c.target));
  for (const req of REQUIRED_TARGETS) {
    if (!mappedTargets.has(req)) {
      return c.json({ error: `missing required target: ${req}` }, 400);
    }
  }

  const [run] = await db
    .select({ id: importRuns.id, status: importRuns.status })
    .from(importRuns)
    .where(eq(importRuns.id, id))
    .limit(1);
  if (!run) return c.json({ error: 'not_found' }, 404);

  if (run.status !== 'preview_ready' && run.status !== 'failed') {
    return c.json({ error: `cannot commit from status ${run.status}` }, 409);
  }

  await db
    .update(importRuns)
    .set({
      columnMappingJson: body.columnMapping,
      workCodeDecisionsJson: body.workCodeDecisions,
      status: 'committing',
      startedAt: new Date(),
      errorMessage: null,
    })
    .where(eq(importRuns.id, id));

  await enqueueCommitImport(id);
  logger.info({ runId: id }, 'enqueued commit-import');

  return c.json({ queued: true });
});
