import { importRuns } from '@mbfd-vacation/db';
import { desc, eq } from 'drizzle-orm';
import { Hono } from 'hono';
import { db } from '../db';

export const importsList = new Hono();

importsList.get('/imports/runs', async (c) => {
  const limit = Math.min(Math.max(Number(c.req.query('limit') ?? 25), 1), 200);
  const offset = Math.max(Number(c.req.query('offset') ?? 0), 0);

  const runs = await db
    .select({
      id: importRuns.id,
      fileName: importRuns.fileName,
      fileSize: importRuns.fileSize,
      fileSha256: importRuns.fileSha256,
      uploadedAt: importRuns.uploadedAt,
      status: importRuns.status,
      parseStats: importRuns.parseStats,
      errorMessage: importRuns.errorMessage,
      finishedAt: importRuns.finishedAt,
    })
    .from(importRuns)
    .orderBy(desc(importRuns.uploadedAt))
    .limit(limit)
    .offset(offset);

  return c.json({ runs, limit, offset });
});

importsList.get('/imports/runs/:id', async (c) => {
  const id = c.req.param('id');
  const [run] = await db
    .select()
    .from(importRuns)
    .where(eq(importRuns.id, id))
    .limit(1);
  if (!run) return c.json({ error: 'not_found' }, 404);
  return c.json(run);
});
