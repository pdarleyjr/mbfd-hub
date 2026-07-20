import { and, eq, inArray, isNotNull, not, sql } from 'drizzle-orm';
import type { Database } from '../client';
import { importRuns, leaveEntries } from '../schema/index';

export type RollbackResult = {
  rolledBack: boolean;
  restoredCount: number;
  removedCount: number;
};

/**
 * Reverse the supersede pointers for every leave_entry that came from this
 * import run, restoring the prior entries as the active row.
 *
 * No row is ever physically deleted — the audit trail is permanent. The
 * run is marked status='rolled_back'.
 *
 * Wrapped in a single transaction. Idempotent: rolling back an already-
 * rolled-back run is a no-op.
 */
export async function rollbackImportRun(
  db: Database,
  importRunId: string,
): Promise<RollbackResult> {
  return await db.transaction(async (tx) => {
    const [run] = await tx
      .select({ id: importRuns.id, status: importRuns.status })
      .from(importRuns)
      .where(eq(importRuns.id, importRunId))
      .limit(1);

    if (!run) {
      throw new Error(`Import run ${importRunId} not found`);
    }
    if (run.status === 'rolled_back') {
      return { rolledBack: true, restoredCount: 0, removedCount: 0 };
    }

    // 1. Find every entry from this run.
    const ours = await tx
      .select({
        id: leaveEntries.id,
        supersededByEntryId: leaveEntries.supersededByEntryId,
      })
      .from(leaveEntries)
      .where(eq(leaveEntries.sourceImportRunId, importRunId));

    const ourIds = ours.map((r) => r.id);
    if (ourIds.length === 0) {
      await tx
        .update(importRuns)
        .set({ status: 'rolled_back', finishedAt: new Date() })
        .where(eq(importRuns.id, importRunId));
      return { rolledBack: true, restoredCount: 0, removedCount: 0 };
    }

    const ourIdSet = new Set(ourIds);
    const replacedByLaterRun = ours.some(
      (entry) =>
        entry.supersededByEntryId !== null &&
        !ourIdSet.has(entry.supersededByEntryId) &&
        entry.supersededByEntryId !== entry.id,
    );
    if (replacedByLaterRun) {
      throw new Error(
        `Import run ${importRunId} is not the latest committed run for every affected slot`,
      );
    }

    // 2. Retire our active entries before restoring their predecessors. The
    //    ordering is required by leave_entries_active_uk. A self-reference is
    //    the durable audit sentinel for rows removed by rollback.
    await tx
      .update(leaveEntries)
      .set({ supersededByEntryId: sql`${leaveEntries.id}` })
      .where(inArray(leaveEntries.id, ourIds));

    // 3. Restore only predecessors from earlier runs. Entries within this
    //    run can point at one another when an import repeats a slot; none of
    //    those rows should become active when the whole run is rolled back.
    const restored = await tx
      .update(leaveEntries)
      .set({ supersededByEntryId: null })
      .where(
        and(
          not(inArray(leaveEntries.id, ourIds)),
          isNotNull(leaveEntries.supersededByEntryId),
          inArray(leaveEntries.supersededByEntryId, ourIds),
        ),
      )
      .returning({ id: leaveEntries.id });

    // 4. Mark the run.
    await tx
      .update(importRuns)
      .set({ status: 'rolled_back', finishedAt: new Date() })
      .where(eq(importRuns.id, importRunId));

    return {
      rolledBack: true,
      restoredCount: restored.length,
      removedCount: ourIds.length,
    };
  });
}
