import { and, eq, inArray, isNotNull, sql } from 'drizzle-orm';
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
      .select({ id: leaveEntries.id })
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

    // 2. Restore the prior entries that were superseded by our entries.
    const restored = await tx
      .update(leaveEntries)
      .set({ supersededByEntryId: null })
      .where(
        and(
          isNotNull(leaveEntries.supersededByEntryId),
          inArray(leaveEntries.supersededByEntryId, ourIds),
        ),
      )
      .returning({ id: leaveEntries.id });

    // 3. Mark our entries as superseded by a synthetic null pointer that
    //    still keeps them out of the active unique index (we use a sentinel
    //    self-reference: superseded_by_entry_id = id). This keeps the
    //    history without violating the partial unique index.
    await tx
      .update(leaveEntries)
      .set({ supersededByEntryId: sql`${leaveEntries.id}` })
      .where(inArray(leaveEntries.id, ourIds));

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
