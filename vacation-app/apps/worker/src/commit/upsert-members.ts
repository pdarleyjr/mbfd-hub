import {
  aDayGroups,
  members,
  ranks,
  type Member,
} from '@mbfd-vacation/db';
import { eq } from 'drizzle-orm';
import type { Database } from '@mbfd-vacation/db';

export type IncomingMember = {
  employeeId: string;
  lastName?: string;
  firstName?: string;
  rankCode?: string;
  shift?: string;
  aDayGroupCode?: string;
  hireDate?: string;
  badgeNumber?: string;
};

async function ensureRank(db: Database, code: string): Promise<string> {
  const [existing] = await db.select({ id: ranks.id }).from(ranks).where(eq(ranks.code, code)).limit(1);
  if (existing) return existing.id;
  const sortMap: Record<string, number> = { DC: 1, CAPT: 2, LT: 3, FF: 4, PROB: 5 };
  const [row] = await db
    .insert(ranks)
    .values({
      code,
      label: code,
      sortOrder: sortMap[code] ?? 99,
      isOfficer: ['DC', 'CAPT', 'LT'].includes(code),
    })
    .returning({ id: ranks.id });
  if (!row) throw new Error('failed to insert rank');
  return row.id;
}

async function ensureADayGroup(db: Database, code: string): Promise<string> {
  const [existing] = await db
    .select({ id: aDayGroups.id })
    .from(aDayGroups)
    .where(eq(aDayGroups.code, code))
    .limit(1);
  if (existing) return existing.id;
  const [row] = await db
    .insert(aDayGroups)
    .values({ code, label: code })
    .returning({ id: aDayGroups.id });
  if (!row) throw new Error('failed to insert a_day_group');
  return row.id;
}

/**
 * Upsert a member by employee_id. Returns the member.
 *
 * If the member exists we patch any newly-known fields (rank, shift, A-day
 * group, badge, hire date) but never overwrite a non-empty value with empty.
 */
export async function upsertMember(
  db: Database,
  m: IncomingMember,
  sourceImportRunId: string,
): Promise<Member> {
  const [existing] = await db
    .select()
    .from(members)
    .where(eq(members.employeeId, m.employeeId))
    .limit(1);

  const rankId = m.rankCode ? await ensureRank(db, m.rankCode.toUpperCase()) : null;
  const aDayId = m.aDayGroupCode ? await ensureADayGroup(db, m.aDayGroupCode.toUpperCase()) : null;

  if (!existing) {
    const [row] = await db
      .insert(members)
      .values({
        employeeId: m.employeeId,
        lastName: m.lastName ?? 'Unknown',
        firstName: m.firstName ?? 'Unknown',
        rankId,
        shift: m.shift?.toUpperCase() ?? null,
        aDayGroupId: aDayId,
        badgeNumber: m.badgeNumber ?? null,
        hireDate: m.hireDate ?? null,
        isProbationary: m.rankCode?.toUpperCase() === 'PROB',
        sourceImportRunId,
      })
      .returning();
    if (!row) throw new Error('failed to insert member');
    return row;
  }

  const patch: Partial<Member> = {};
  if (m.lastName && m.lastName !== existing.lastName) patch.lastName = m.lastName;
  if (m.firstName && m.firstName !== existing.firstName) patch.firstName = m.firstName;
  if (rankId && rankId !== existing.rankId) patch.rankId = rankId;
  if (m.shift && m.shift.toUpperCase() !== existing.shift) patch.shift = m.shift.toUpperCase();
  if (aDayId && aDayId !== existing.aDayGroupId) patch.aDayGroupId = aDayId;
  if (m.badgeNumber && m.badgeNumber !== existing.badgeNumber) patch.badgeNumber = m.badgeNumber;
  if (m.hireDate && m.hireDate !== existing.hireDate) patch.hireDate = m.hireDate;
  patch.updatedAt = new Date();

  const [row] = await db
    .update(members)
    .set(patch)
    .where(eq(members.id, existing.id))
    .returning();
  return row ?? existing;
}
