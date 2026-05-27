import { leaveCodes, workCodeMappings } from '@mbfd-vacation/db';
import type { Database } from '@mbfd-vacation/db';
import type { WorkCodeDecision } from '@mbfd-vacation/shared';
import { eq, sql } from 'drizzle-orm';

/**
 * Build a Map<telestaffDescription, leaveCodeId> by combining:
 *   1. The existing work_code_mappings table
 *   2. The admin's explicit decisions for unknown descriptions
 *
 * Each 'create_new' decision creates a new leave_code; each 'use_existing'
 * inserts a work_code_mappings row; each 'skip' is omitted from the map.
 */
export async function buildLeaveCodeResolver(
  db: Database,
  decisions: WorkCodeDecision[],
): Promise<{
  resolve: (description: string) => string | null;
  newCodesInserted: number;
  newMappingsInserted: number;
}> {
  const map = new Map<string, string>();

  // 1. Load existing mappings.
  const existing = await db.select().from(workCodeMappings);
  for (const e of existing) {
    map.set(e.telestaffDescription, e.leaveCodeId);
  }

  // 2. Apply admin decisions.
  let newCodes = 0;
  let newMappings = 0;
  for (const d of decisions) {
    if (d.kind === 'skip') continue;
    if (d.kind === 'use_existing') {
      if (!map.has(d.telestaffDescription)) {
        await db
          .insert(workCodeMappings)
          .values({
            telestaffDescription: d.telestaffDescription,
            leaveCodeId: d.leaveCodeId,
          })
          .onConflictDoNothing({ target: workCodeMappings.telestaffDescription });
        newMappings++;
      }
      map.set(d.telestaffDescription, d.leaveCodeId);
    } else if (d.kind === 'create_new') {
      // Ensure the new leave_code exists
      const [existing] = await db
        .select({ id: leaveCodes.id })
        .from(leaveCodes)
        .where(eq(leaveCodes.code, d.newCode.code))
        .limit(1);
      let codeId: string;
      if (existing) {
        codeId = existing.id;
      } else {
        const [row] = await db
          .insert(leaveCodes)
          .values({
            code: d.newCode.code,
            label: d.newCode.label,
            uiColor: d.newCode.uiColor,
            countsAgainstVacationBalance: d.newCode.countsAgainstVacationBalance,
            countsAgainstFloatingBalance: d.newCode.countsAgainstFloatingBalance,
            countsAgainstDailyVacationCapacity: d.newCode.countsAgainstDailyVacationCapacity,
            countsAgainstMinimumStaffing: d.newCode.countsAgainstMinimumStaffing,
            isADayMarker: d.newCode.isADayMarker,
          })
          .returning({ id: leaveCodes.id });
        if (!row) throw new Error('failed to insert leave_code');
        codeId = row.id;
        newCodes++;
      }
      await db
        .insert(workCodeMappings)
        .values({
          telestaffDescription: d.telestaffDescription,
          leaveCodeId: codeId,
        })
        .onConflictDoNothing({ target: workCodeMappings.telestaffDescription });
      newMappings++;
      map.set(d.telestaffDescription, codeId);
    }
  }

  return {
    resolve: (desc: string) => map.get(desc) ?? null,
    newCodesInserted: newCodes,
    newMappingsInserted: newMappings,
  };
}

/** Best-effort code lookup by the literal leave code string ('V', 'FH', …). */
export async function lookupByCode(
  db: Database,
  code: string,
): Promise<string | null> {
  const [row] = await db
    .select({ id: leaveCodes.id })
    .from(leaveCodes)
    .where(sql`upper(${leaveCodes.code}) = ${code.toUpperCase()}`)
    .limit(1);
  return row?.id ?? null;
}
