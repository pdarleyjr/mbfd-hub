import {
  aDayGroups,
  calendarDays,
  importRuns,
  leaveCodes,
  leaveEntries,
  members,
  ranks,
  shiftBlocks,
  type Database,
  type Member,
} from '@mbfd-vacation/db';
import {
  findTarget,
  type ColumnMapping,
  type WorkCodeDecision,
} from '@mbfd-vacation/shared';
import { and, eq, isNull } from 'drizzle-orm';
import { db } from '../db';
import { logger } from '../log';
import { ensureShiftBlock } from '../commit/ensure-blocks';
import { buildLeaveCodeResolver } from '../commit/resolve-leave-code';
import { upsertMember } from '../commit/upsert-members';
import { detectKindFromName, iterRows } from '../parse/detect';
import { getStream } from '../r2';
import { publish } from '../publish';

const CHUNK_SIZE = 500; // commit every N rows to bound transaction size

type Caches = {
  members: Map<string, Member>;          // employee_id -> member
  blocks: Map<string, string>;            // isoDate|blockIndex -> shift_block_id
  leaveCodeByCode: Map<string, string>;   // CODE -> leave_code id
};

async function preloadCaches(tx: Database, decisions: WorkCodeDecision[]) {
  const caches: Caches = {
    members: new Map(),
    blocks: new Map(),
    leaveCodeByCode: new Map(),
  };
  // Preload all members and leave codes (bounded for a fire dept — 250-ish
  // members, ~20 leave codes). Members get keyed by employee_id.
  const m = await tx.select().from(members);
  for (const row of m) caches.members.set(row.employeeId, row);
  const lc = await tx.select().from(leaveCodes);
  for (const row of lc) caches.leaveCodeByCode.set(row.code.toUpperCase(), row.id);
  return caches;
}

async function ensureBlockCached(
  tx: Database,
  caches: Caches,
  isoDt: string,
): Promise<{ shiftBlockId: string; blockIndex: number; dayDate: string }> {
  const block = await ensureShiftBlock(tx, isoDt);
  const day = new Date(isoDt);
  const isoDate = day.toISOString().slice(0, 10);
  const key = `${isoDate}|${block.blockIndex}`;
  caches.blocks.set(key, block.shiftBlockId);
  return { ...block, dayDate: isoDate };
}

/**
 * The main commit handler.
 *
 * Streams the file from R2, resolves leave codes per row using the admin's
 * decisions, upserts members and shift blocks lazily, then upserts
 * leave_entries with supersede semantics.
 *
 * Wrapped in chunked transactions so a crash leaves the DB in a consistent
 * state: each chunk of CHUNK_SIZE rows commits or rolls back as a unit.
 * In-memory caches reduce per-row queries from 5–7 to 1–2.
 */
export async function commitImportJob(runId: string): Promise<void> {
  const [run] = await db.select().from(importRuns).where(eq(importRuns.id, runId)).limit(1);
  if (!run) throw new Error(`run ${runId} not found`);
  if (!run.columnMappingJson) throw new Error('no column mapping on run');

  const mapping = run.columnMappingJson as unknown as ColumnMapping;
  const decisions = ((run.workCodeDecisionsJson as unknown) ?? []) as WorkCodeDecision[];

  const kind = detectKindFromName(run.fileName);
  if (!kind) throw new Error(`unsupported file type: ${run.fileName}`);

  const empIdCol = findTarget(mapping, 'employee_id');
  const dtCol = findTarget(mapping, 'event_datetime');
  if (!empIdCol || !dtCol) throw new Error('mapping missing required targets');

  const lastCol = findTarget(mapping, 'last_name');
  const firstCol = findTarget(mapping, 'first_name');
  const rankCol = findTarget(mapping, 'rank');
  const shiftCol = findTarget(mapping, 'shift');
  const adayCol = findTarget(mapping, 'a_day_group');
  const hireCol = findTarget(mapping, 'hire_date');
  const badgeCol = findTarget(mapping, 'badge_number');
  const descCol = findTarget(mapping, 'event_description');
  const codeCol = findTarget(mapping, 'event_work_code');

  const skipDescriptions = new Set(
    decisions.filter((d) => d.kind === 'skip').map((d) => d.telestaffDescription),
  );

  let processed = 0;
  let inserted = 0;
  let superseded = 0;
  let skipped = 0;
  let errors = 0;
  const uniqueEmployees = new Set<string>();
  let earliestDate: string | null = null;
  let latestDate: string | null = null;

  try {
    const stream = await getStream(run.r2Key);
    // Pre-build the description→leaveCodeId map (writes new work_code_mappings)
    const resolver = await buildLeaveCodeResolver(db, decisions);
    const caches = await preloadCaches(db, decisions);

    // Accumulator per chunk. We commit each chunk inside its own
    // transaction so partial failures don't leave half-imported data.
    let chunk: Array<{ row: Record<string, unknown>; isoDt: string; empId: string; codeId: string }> = [];

    const flushChunk = async (): Promise<void> => {
      if (chunk.length === 0) return;
      await db.transaction(async (tx) => {
        for (const item of chunk) {
          const row = item.row;
          // Member (cached upsert)
          let member = caches.members.get(item.empId);
          if (!member) {
            member = await upsertMember(
              tx,
              {
                employeeId: item.empId,
                lastName: lastCol ? String(row[lastCol] ?? '') : undefined,
                firstName: firstCol ? String(row[firstCol] ?? '') : undefined,
                rankCode: rankCol ? String(row[rankCol] ?? '') : undefined,
                shift: shiftCol ? String(row[shiftCol] ?? '') : undefined,
                aDayGroupCode: adayCol ? String(row[adayCol] ?? '') : undefined,
                hireDate: hireCol ? String(row[hireCol] ?? '') : undefined,
                badgeNumber: badgeCol ? String(row[badgeCol] ?? '') : undefined,
              },
              runId,
            );
            caches.members.set(item.empId, member);
          }

          // Shift block (cached lazy-create)
          const block = await ensureBlockCached(tx, caches, item.isoDt);

          // Track date range
          if (!earliestDate || block.dayDate < earliestDate) earliestDate = block.dayDate;
          if (!latestDate || block.dayDate > latestDate) latestDate = block.dayDate;

          // Supersede prior active entry for this slot
          const existing = await tx
            .select({ id: leaveEntries.id })
            .from(leaveEntries)
            .where(
              and(
                eq(leaveEntries.memberId, member.id),
                eq(leaveEntries.shiftBlockId, block.shiftBlockId),
                isNull(leaveEntries.supersededByEntryId),
              ),
            )
            .limit(1);

          const [created] = await tx
            .insert(leaveEntries)
            .values({
              memberId: member.id,
              shiftBlockId: block.shiftBlockId,
              leaveCodeId: item.codeId,
              sourceImportRunId: runId,
              rawTelestaffRow: row,
            })
            .returning({ id: leaveEntries.id });
          if (!created) throw new Error('failed to insert leave_entry');

          if (existing[0]) {
            await tx
              .update(leaveEntries)
              .set({ supersededByEntryId: created.id })
              .where(eq(leaveEntries.id, existing[0].id));
            superseded++;
          }
          inserted++;
          uniqueEmployees.add(item.empId);
        }
      });
      processed += chunk.length;
      chunk = [];
      await publish(runId, {
        type: 'progress',
        rowsProcessed: processed,
        totalBytes: null,
        bytesProcessed: 0,
      });
      logger.info({ runId, processed }, 'commit chunk flushed');
    };

    for await (const ev of iterRows(kind, stream)) {
      try {
        const row = ev.row;
        const empId = String(row[empIdCol] ?? '').trim();
        const dtRaw = row[dtCol];
        if (!empId || !dtRaw) {
          skipped++;
          continue;
        }
        const isoDt = String(dtRaw);
        const desc = descCol ? String(row[descCol] ?? '').trim() : '';
        const workCode = codeCol ? String(row[codeCol] ?? '').trim() : '';

        if (desc && skipDescriptions.has(desc)) {
          skipped++;
          continue;
        }

        // Resolve code: prefer description→mapping, else literal code
        let leaveCodeId: string | null = null;
        if (desc) leaveCodeId = resolver.resolve(desc);
        if (!leaveCodeId && workCode) {
          leaveCodeId = caches.leaveCodeByCode.get(workCode.toUpperCase()) ?? null;
        }
        if (!leaveCodeId) {
          skipped++;
          continue;
        }

        chunk.push({ row, isoDt, empId, codeId: leaveCodeId });
        if (chunk.length >= CHUNK_SIZE) {
          await flushChunk();
        }
      } catch (rowErr) {
        errors++;
        logger.warn({ rowErr, rowIndex: ev.rowIndex }, 'row failed');
      }
    }
    await flushChunk();

    await db
      .update(importRuns)
      .set({
        status: 'committed',
        finishedAt: new Date(),
        parseStats: {
          totalRows: processed + skipped + errors,
          parsedRows: processed,
          errorRows: errors,
          skippedRows: skipped,
          uniqueEmployees: uniqueEmployees.size,
          leaveEntriesInserted: inserted,
          leaveEntriesSuperseded: superseded,
          newWorkCodeMappings: resolver.newMappingsInserted,
          newLeaveCodesInserted: resolver.newCodesInserted,
          dateRange:
            earliestDate && latestDate
              ? { from: earliestDate, to: latestDate }
              : undefined,
        },
      })
      .where(eq(importRuns.id, runId));

    logger.info({ runId, inserted, superseded, skipped, errors }, 'commit complete');
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err);
    logger.error({ err, runId }, 'commit failed');
    await db
      .update(importRuns)
      .set({ status: 'failed', errorMessage: msg, finishedAt: new Date() })
      .where(eq(importRuns.id, runId));
    throw err;
  }
}
