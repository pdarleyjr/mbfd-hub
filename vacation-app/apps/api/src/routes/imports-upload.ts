import { importRuns } from '@mbfd-vacation/db';
import busboy from 'busboy';
import { eq } from 'drizzle-orm';
import { Hono } from 'hono';
import { createHash, randomUUID } from 'node:crypto';
import { PassThrough, Readable } from 'node:stream';
import { pipeline } from 'node:stream/promises';
import { db } from '../db.js';
import { getEnv } from '../env.js';
import { logger } from '../log.js';
import { putStream, r2Key } from '../r2.js';
import { enqueueParsePreview } from '../queue.js';

export const importsUpload = new Hono();

const env = getEnv();

/**
 * Streaming upload to R2.
 *
 * The request body is a multipart/form-data with one field 'file'. We pipe
 * the file part directly through:
 *   - a SHA-256 hash transform (so we know the content hash before commit)
 *   - a byte counter (so we can reject early if MAX_UPLOAD_BYTES exceeded)
 *   - the R2 multipart Upload helper (so we never hold the whole file in RAM)
 *
 * Idempotency: if a run already exists with the same SHA-256, we return its
 * id without re-uploading.
 */
importsUpload.post('/imports', async (c) => {
  const contentType = c.req.header('content-type') ?? '';
  if (!contentType.startsWith('multipart/form-data')) {
    return c.json({ error: 'Content-Type must be multipart/form-data' }, 400);
  }

  const bb = busboy({
    headers: { 'content-type': contentType },
    limits: {
      fileSize: env.MAX_UPLOAD_BYTES,
      files: 1,
      fields: 5,
    },
  });

  const reqStream = c.req.raw.body
    ? (Readable.fromWeb(c.req.raw.body as never) as Readable)
    : null;

  if (!reqStream) {
    return c.json({ error: 'No request body' }, 400);
  }

  // Capture the upload work as a promise so the route handler can await it.
  type UploadResult =
    | { ok: true; runId: string; wasDuplicate: boolean }
    | { ok: false; status: number; error: string };

  const work = new Promise<UploadResult>((resolve, reject) => {
    let resolved = false;
    const safeResolve = (r: UploadResult) => {
      if (!resolved) {
        resolved = true;
        resolve(r);
      }
    };

    bb.on('file', (fieldname, file, info) => {
      if (fieldname !== 'file') {
        file.resume();
        return;
      }

      const fileName = info.filename ?? 'upload.bin';
      const mimeType = info.mimeType ?? 'application/octet-stream';
      const hash = createHash('sha256');
      let bytesSeen = 0;

      // Tee: feed both the hasher and the R2 upload from the same source.
      const r2Source = new PassThrough();
      file.on('data', (chunk: Buffer) => {
        bytesSeen += chunk.length;
        hash.update(chunk);
        r2Source.write(chunk);
      });
      file.on('end', () => r2Source.end());
      file.on('error', (err) => r2Source.destroy(err));
      file.on('limit', () => {
        r2Source.destroy(new Error('upload exceeded MAX_UPLOAD_BYTES'));
      });

      // We don't know the SHA until the stream ends — but we already need
      // a key to put to R2. Use a UUID-prefixed key; record the sha after.
      const provisionalId = randomUUID();
      const safeName = fileName.replace(/[^\w.\-]/g, '_').slice(0, 200);
      const key = r2Key(`${provisionalId}/${safeName}`);

      putStream(key, r2Source, mimeType)
        .then(async () => {
          const sha = hash.digest('hex');

          // Idempotency check.
          const [existing] = await db
            .select({ id: importRuns.id })
            .from(importRuns)
            .where(eq(importRuns.fileSha256, sha))
            .limit(1);

          if (existing) {
            logger.info({ runId: existing.id, sha }, 'duplicate upload detected');
            safeResolve({ ok: true, runId: existing.id, wasDuplicate: true });
            return;
          }

          const [row] = await db
            .insert(importRuns)
            .values({
              fileName: safeName,
              fileSize: bytesSeen,
              fileSha256: sha,
              r2Key: key,
              status: 'uploaded',
            })
            .returning({ id: importRuns.id });

          if (!row) throw new Error('failed to insert import_runs row');

          await enqueueParsePreview(row.id);
          safeResolve({ ok: true, runId: row.id, wasDuplicate: false });
        })
        .catch((err) => {
          logger.error({ err }, 'R2 upload failed');
          safeResolve({
            ok: false,
            status: 500,
            error: err instanceof Error ? err.message : 'upload failed',
          });
        });
    });

    bb.on('error', (err) => {
      logger.error({ err }, 'busboy error');
      reject(err);
    });

    bb.on('finish', () => {
      // If no file resolved us by now, that's an error.
      setTimeout(() => {
        if (!resolved) {
          safeResolve({ ok: false, status: 400, error: 'no file part in upload' });
        }
      }, 100);
    });
  });

  pipeline(reqStream, bb).catch((err) => {
    logger.error({ err }, 'pipeline error');
  });

  const result = await work;
  if (!result.ok) {
    return c.json({ error: result.error }, result.status as 400 | 500);
  }
  return c.json({ runId: result.runId, wasDuplicate: result.wasDuplicate }, 201);
});
