import { importRunRows, importRuns, workCodeMappings } from '@mbfd-vacation/db';
import type { PreviewEvent } from '@mbfd-vacation/shared';
import { eq, sql } from 'drizzle-orm';
import { db } from '../db';
import { logger } from '../log';
import { detectKindFromName, iterRows } from '../parse/detect';
import { inferColumnMapping } from '../parse/infer-mapping';
import { getStream } from '../r2';
import { publish } from '../publish';
import { findTarget } from '@mbfd-vacation/shared';

const SAMPLE_LIMIT = 100;

/**
 * Job handler: read a freshly-uploaded file from R2, peek at its first ~100
 * rows, infer a column mapping, collect unknown event descriptions, and
 * publish a `preview_ready` event over Redis pub/sub.
 */
export async function parsePreviewJob(runId: string): Promise<void> {
  const [run] = await db
    .select()
    .from(importRuns)
    .where(eq(importRuns.id, runId))
    .limit(1);
  if (!run) {
    logger.warn({ runId }, 'parse-preview: run not found');
    return;
  }

  await db
    .update(importRuns)
    .set({ status: 'parsing', startedAt: new Date(), errorMessage: null })
    .where(eq(importRuns.id, runId));

  try {
    const kind = detectKindFromName(run.fileName);
    if (!kind) throw new Error(`unsupported file type: ${run.fileName}`);

    const stream = await getStream(run.r2Key);

    let headers: string[] = [];
    const sample: Array<Record<string, string | number | null>> = [];
    let rows = 0;
    let bytes = 0;

    for await (const ev of iterRows(kind, stream)) {
      if (headers.length === 0) headers = ev.headers;
      if (sample.length < SAMPLE_LIMIT) sample.push(ev.row as Record<string, string | number | null>);
      rows++;
      bytes += JSON.stringify(ev.row).length;

      if (sample.length >= SAMPLE_LIMIT && rows % 250 === 0) {
        await publish(runId, {
          type: 'progress',
          rowsProcessed: rows,
          totalBytes: null,
          bytesProcessed: bytes,
        } satisfies PreviewEvent);
      }
      if (rows > 5_000) {
        // we have enough to suggest a mapping — stop reading the rest;
        // commit will read it all.
        break;
      }
    }

    const suggested = inferColumnMapping(headers);

    // Persist sample rows for audit
    await db.transaction(async (tx) => {
      for (const [i, r] of sample.entries()) {
        await tx.insert(importRunRows).values({
          importRunId: runId,
          rowIndex: i,
          rawRowJson: r,
          parsedStatus: 'ok',
        });
      }
    });

    // Collect unknown descriptions present in this sample.
    const descHeader = findTarget(suggested, 'event_description');
    const descriptions = new Set<string>();
    if (descHeader) {
      for (const r of sample) {
        const v = r[descHeader];
        if (typeof v === 'string' && v.trim().length > 0) descriptions.add(v.trim());
      }
    }
    let unknown: string[] = [];
    if (descriptions.size > 0) {
      const known = await db
        .select({ d: workCodeMappings.telestaffDescription })
        .from(workCodeMappings);
      const knownSet = new Set(known.map((k) => k.d));
      unknown = [...descriptions].filter((d) => !knownSet.has(d));
    }

    const parseStats = {
      totalRows: rows,
      parsedRows: rows,
      errorRows: 0,
      skippedRows: 0,
      uniqueEmployees: 0,
    };

    const event: PreviewEvent = {
      type: 'preview_ready',
      columns: headers,
      sampleRows: sample,
      suggestedMapping: suggested,
      unknownDescriptions: unknown,
      parseStats,
    };

    await db
      .update(importRuns)
      .set({
        status: 'preview_ready',
        parseStats: parseStats,
        columnMappingJson: suggested,
        // Cache the full payload so the SSE endpoint can replay it on
        // client reconnect (page reload, network blip).
        previewPayloadJson: event,
        finishedAt: new Date(),
      })
      .where(eq(importRuns.id, runId));

    await publish(runId, event);
    logger.info({ runId, rows, unknown: unknown.length }, 'preview ready');
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err);
    logger.error({ err, runId }, 'parse-preview failed');
    await db
      .update(importRuns)
      .set({ status: 'failed', errorMessage: msg, finishedAt: new Date() })
      .where(eq(importRuns.id, runId));
    await publish(runId, { type: 'failed', errorMessage: msg } satisfies PreviewEvent);
  }
}

// rows-count helper used during commit (kept here for symmetry)
export async function countRows(runId: string): Promise<number> {
  const [r] = await db
    .select({ count: sql<number>`count(*)::int` })
    .from(importRunRows)
    .where(eq(importRunRows.importRunId, runId));
  return r?.count ?? 0;
}
