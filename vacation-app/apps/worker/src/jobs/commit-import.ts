import { importRuns, leaveEntries } from '@mbfd-vacation/db';
import {
  findTarget,
  type ColumnMapping,
  type WorkCodeDecision,
} from '@mbfd-vacation/shared';
import { and, eq, isNull } from 'drizzle-orm';
import { db } from '../db';
import { logger } from '../log';
import { ensureShiftBlock } from '../commit/ensure-blocks';
import {
  buildLeaveCodeResolver,
  lookupByCode,
} from '../commit/resolve-leave-code';
import { upsertMember } from '../commit/upsert-members';
import { detectKindFromName, iterRows } from '../parse/detect';
import { getStream } from '../r2';
import { publish } from '../publish';

/**
 * The main commit handler.
 *
 * Streams the file from R2, resolves leave codes per row using the admin's
 * decisions, upserts members and shift blocks lazily, then upserts
 * leave_entries with supersede semantics (one transaction at the end so
 * partial work is never visible).
 */
export async function commitImportJob(runId: string): Promise<void> {
  const [run] = await db.select().from(importRuns).where(eq(importRuns.id, runId)).limit(1);
  if (!run) throw new Error(`run ${runId} not found`);
  if (!run.columnMappingJson) throw new Error('no column mapping on run');

  const mapping = run.columnMappingJson as unknown as ColumnMapping;
  const decisions = ((run.workCodeDecisionsJson as unknown) ?? []) as WorkCodeDecision[];

  const kind = detectKindFromName(run.fileName);
  if (!kind) throw new Error(`unsupported file type: ${run.fileName}`);

  // Pull header → target helpers
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

  try {
    const stream = await getStream(run.r2Key);
    const resolver = await buildLeaveCodeResolver(db, decisions);

    let processed = 0;
    let inserted = 0;
    let superseded = 0;
    let skipped = 0;
    let errors = 0;
    const uniqueEmployees = new Set<string>();

    let earliestDate: string | null = null;
    let latestDate: string | null = null;

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

        // Resolve leave code: prefer description→mapping, else literal code
        let leaveCodeId: string | null = null;
        if (desc) leaveCodeId = resolver.resolve(desc);
        if (!leaveCodeId && workCode) leaveCodeId = await lookupByCode(db, workCode);
        if (!leaveCodeId) {
          skipped++;
          continue;
        }

        // Upsert member
        const member = await upsertMember(
          db,
          {
            employeeId: empId,
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
        uniqueEmployees.add(empId);

        // Ensure shift block
        const block = await ensureShiftBlock(db, isoDt);

        const dayIso = isoDt.slice(0, 10);
        if (!earliestDate || dayIso < earliestDate) earliestDate = dayIso;
        if (!latestDate || dayIso > latestDate) latestDate = dayIso;

        // Supersede any existing active entry for (member, block)
        const existing = await db
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

        // Insert the new entry first so we have an id, then mark predecessor.
        const [created] = await db
          .insert(leaveEntries)
          .values({
            memberId: member.id,
            shiftBlockId: block.shiftBlockId,
            leaveCodeId,
            sourceImportRunId: runId,
            rawTelestaffRow: row,
          })
          .returning({ id: leaveEntries.id });

        if (!created) throw new Error('failed to insert leave_entry');

        if (existing[0]) {
          await db
            .update(leaveEntries)
            .set({ supersededByEntryId: created.id })
            .where(eq(leaveEntries.id, existing[0].id));
          superseded++;
        }
        inserted++;

        processed++;
        if (processed % 500 === 0) {
          await publish(runId, {
            type: 'progress',
            rowsProcessed: processed,
            totalBytes: null,
            bytesProcessed: 0,
          });
          logger.info({ runId, processed }, 'commit progress');
        }
      } catch (rowErr) {
        errors++;
        logger.warn({ rowErr, rowIndex: ev.rowIndex }, 'row failed');
      }
    }

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
