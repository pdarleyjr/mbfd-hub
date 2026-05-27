import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import {
  importRuns,
  leaveCodes,
  leaveEntries,
  members,
  calendarDays,
  shiftBlocks,
} from '@mbfd-vacation/db';
import { rollbackImportRun } from '@mbfd-vacation/db/operations/rollback';
import { eq, isNull } from 'drizzle-orm';
import { startTestPostgres, type TestEnv } from './setup';

let env: TestEnv;

beforeAll(async () => {
  env = await startTestPostgres();
});

afterAll(async () => {
  await env?.close();
});

describe('rollbackImportRun', () => {
  it('restores prior entries that were superseded by the rolled-back run', async () => {
    const { db } = env;

    // Seed: rank, leave code, member, calendar day, shift block.
    const [code] = await db
      .insert(leaveCodes)
      .values({ code: 'TEST_V', label: 'Vacation' })
      .returning();
    if (!code) throw new Error('no code');

    const [day] = await db
      .insert(calendarDays)
      .values({
        date: '2026-06-01',
        fiscalYear: 2026,
        calendarYear: 2026,
        dayOfWeek: 1,
      })
      .returning();
    if (!day) throw new Error('no day');

    const [block] = await db
      .insert(shiftBlocks)
      .values({
        calendarDayId: day.id,
        blockIndex: 0,
        startAt: new Date('2026-06-01T08:00:00Z'),
        endAt: new Date('2026-06-01T20:00:00Z'),
      })
      .returning();
    if (!block) throw new Error('no block');

    const [member] = await db
      .insert(members)
      .values({ employeeId: 'EMP01', lastName: 'Test', firstName: 'User' })
      .returning();
    if (!member) throw new Error('no member');

    const [run1] = await db
      .insert(importRuns)
      .values({
        fileName: 'a.csv',
        fileSize: 100,
        fileSha256: 'aaaa',
        r2Key: 'k1',
        status: 'committed',
      })
      .returning();
    if (!run1) throw new Error('no run1');

    const [original] = await db
      .insert(leaveEntries)
      .values({
        memberId: member.id,
        shiftBlockId: block.id,
        leaveCodeId: code.id,
        sourceImportRunId: run1.id,
        rawTelestaffRow: { source: 'run1' },
      })
      .returning();
    if (!original) throw new Error('no original');

    const [run2] = await db
      .insert(importRuns)
      .values({
        fileName: 'b.csv',
        fileSize: 100,
        fileSha256: 'bbbb',
        r2Key: 'k2',
        status: 'committed',
      })
      .returning();
    if (!run2) throw new Error('no run2');

    const [replacement] = await db
      .insert(leaveEntries)
      .values({
        memberId: member.id,
        shiftBlockId: block.id,
        leaveCodeId: code.id,
        sourceImportRunId: run2.id,
        rawTelestaffRow: { source: 'run2' },
      })
      .returning();
    if (!replacement) throw new Error('no replacement');

    await db
      .update(leaveEntries)
      .set({ supersededByEntryId: replacement.id })
      .where(eq(leaveEntries.id, original.id));

    // Before rollback: replacement is the active entry.
    const active1 = await db
      .select()
      .from(leaveEntries)
      .where(isNull(leaveEntries.supersededByEntryId));
    expect(active1.length).toBe(1);
    expect(active1[0]?.sourceImportRunId).toBe(run2.id);

    // Roll back run2
    const result = await rollbackImportRun(db, run2.id);
    expect(result.rolledBack).toBe(true);
    expect(result.restoredCount).toBe(1);

    // Original should now be active again.
    const active2 = await db
      .select()
      .from(leaveEntries)
      .where(isNull(leaveEntries.supersededByEntryId));
    expect(active2.length).toBe(1);
    expect(active2[0]?.id).toBe(original.id);
  });
});
