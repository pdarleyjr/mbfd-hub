/**
 * One-shot bootstrap loader that ingests a Telestaff "(EX) Export All
 * Records" SpreadsheetML 2003 XML file directly into the vacation-app
 * Postgres so the board comes up pre-populated. Bypasses R2 + the BullMQ
 * queue and writes a synthetic import_runs row so rollback semantics still
 * apply (the bootstrap is just another import that can be rolled back).
 *
 * Usage (from the vacation-app workspace root):
 *   DATABASE_URL=postgres://… \
 *     pnpm --filter @mbfd-vacation/worker exec \
 *       tsx src/scripts/bootstrap-telestaff-xml.ts <path/to.xml>
 *
 * Or via the deploy host wrapper:
 *   ssh gmktec '/opt/mbfd-vacation/scripts/bootstrap-telestaff.sh /path/to.xml'
 */
import { createHash } from 'node:crypto';
import { createReadStream, statSync } from 'node:fs';
import { basename, resolve } from 'node:path';

import {
  aDayGroups,
  importRuns,
  leaveCodes,
  leaveEntries,
  members,
  ranks,
  shiftBlocks,
} from '@mbfd-vacation/db';
import { getDb } from '@mbfd-vacation/db';
import {
  BOOTSTRAP_NEW_LEAVE_CODES,
  SKIP_CATEGORIES,
  classifyDescription,
  normalizeRankCode,
  rankLabelFor,
  splitTelestaffName,
  type TelestaffCategory,
} from '@mbfd-vacation/shared';
import { and, eq, isNull, sql } from 'drizzle-orm';

import { parseSpreadsheetXml } from '../parse/spreadsheet-xml';
import { ensureShiftBlock } from '../commit/ensure-blocks';

const connectionString = process.env.DATABASE_URL ?? process.env.DATABASE_URL_HOST;
if (!connectionString) {
  console.error('DATABASE_URL or DATABASE_URL_HOST must be set');
  process.exit(1);
}

const inputPath = process.argv[2];
if (!inputPath) {
  console.error('Usage: tsx scripts/bootstrap-telestaff-xml.ts <path/to.xml>');
  process.exit(1);
}

const absPath = resolve(inputPath);
const fileStat = statSync(absPath);
const fileName = basename(absPath);

const { db, close } = getDb(connectionString);

async function sha256OfFile(path: string): Promise<string> {
  const hash = createHash('sha256');
  for await (const chunk of createReadStream(path)) {
    hash.update(chunk as Buffer);
  }
  return hash.digest('hex');
}

async function ensureBootstrapLeaveCodes(): Promise<Map<string, string>> {
  const codeMap = new Map<string, string>();
  const existing = await db.select({ id: leaveCodes.id, code: leaveCodes.code }).from(leaveCodes);
  for (const e of existing) codeMap.set(e.code.toUpperCase(), e.id);

  for (const seed of BOOTSTRAP_NEW_LEAVE_CODES) {
    if (codeMap.has(seed.code.toUpperCase())) continue;
    const [row] = await db
      .insert(leaveCodes)
      .values(seed)
      .onConflictDoNothing({ target: leaveCodes.code })
      .returning({ id: leaveCodes.id });
    if (row) {
      codeMap.set(seed.code.toUpperCase(), row.id);
    } else {
      // race; re-read
      const [re] = await db
        .select({ id: leaveCodes.id })
        .from(leaveCodes)
        .where(eq(leaveCodes.code, seed.code))
        .limit(1);
      if (re) codeMap.set(seed.code.toUpperCase(), re.id);
    }
  }
  return codeMap;
}

async function ensureRank(code: string, label: string): Promise<string> {
  const upper = code.toUpperCase();
  const [existing] = await db
    .select({ id: ranks.id })
    .from(ranks)
    .where(eq(ranks.code, upper))
    .limit(1);
  if (existing) return existing.id;
  const sortMap: Record<string, number> = {
    CHIEF: 0, DDC: 1, DC: 2, CAPT: 3, LT: 4, 'FF-DE': 5, FF: 6, PROB: 7,
  };
  const [row] = await db
    .insert(ranks)
    .values({
      code: upper,
      label,
      sortOrder: sortMap[upper] ?? 50,
      isOfficer: ['CHIEF', 'DDC', 'DC', 'CAPT', 'LT'].includes(upper),
    })
    .onConflictDoNothing({ target: ranks.code })
    .returning({ id: ranks.id });
  if (row) return row.id;
  const [re] = await db
    .select({ id: ranks.id })
    .from(ranks)
    .where(eq(ranks.code, upper))
    .limit(1);
  if (!re) throw new Error(`rank ${upper} not found after insert`);
  return re.id;
}

async function ensureADayGroup(code: string): Promise<string> {
  const upper = code.toUpperCase();
  const [existing] = await db
    .select({ id: aDayGroups.id })
    .from(aDayGroups)
    .where(eq(aDayGroups.code, upper))
    .limit(1);
  if (existing) return existing.id;
  const [row] = await db
    .insert(aDayGroups)
    .values({ code: upper, label: upper })
    .onConflictDoNothing({ target: aDayGroups.code })
    .returning({ id: aDayGroups.id });
  if (row) return row.id;
  const [re] = await db
    .select({ id: aDayGroups.id })
    .from(aDayGroups)
    .where(eq(aDayGroups.code, upper))
    .limit(1);
  if (!re) throw new Error(`a_day_group ${upper} not found after insert`);
  return re.id;
}

type MemberSeed = {
  employeeId: string;
  firstName: string;
  lastName: string;
  rankCode: string;
  rankLabel: string;
  shift: string;
};

async function main(): Promise<void> {
  console.log(`Bootstrap from ${absPath} (${fileStat.size} bytes)`);

  // 1. SHA + import_run row
  const sha = await sha256OfFile(absPath);
  const [run] = await db
    .insert(importRuns)
    .values({
      fileName,
      fileSize: fileStat.size,
      fileSha256: sha,
      r2Key: `bootstrap/${sha}/${fileName}`,
      status: 'committing',
      startedAt: new Date(),
    })
    .returning({ id: importRuns.id });
  if (!run) throw new Error('failed to insert import_runs row');
  const runId = run.id;
  console.log(`import_runs id=${runId} sha=${sha.slice(0, 12)}…`);

  // 2. Pre-seed leave codes the Telestaff data references
  const codeMap = await ensureBootstrapLeaveCodes();
  console.log(`leave_codes ready: ${codeMap.size} codes`);

  // 3. First pass — pick primary shift per employee (most common in file).
  //    The Telestaff "All Records" export can include an employee in
  //    multiple shifts (cross-shift overtime), so we tally and pick the
  //    plurality winner as their canonical shift.
  const shiftTally = new Map<string, Map<string, number>>(); // empId -> shift -> count
  const memberInfo = new Map<string, MemberSeed>();
  const stream1 = createReadStream(absPath);
  let totalRows = 0;
  for await (const ev of parseSpreadsheetXml(stream1)) {
    totalRows++;
    const row = ev.row;
    const empId = String(row['Emp ID'] ?? '').trim();
    if (!empId) continue;
    const shift = String(row['Shift'] ?? '').trim().toUpperCase();
    if (!shiftTally.has(empId)) shiftTally.set(empId, new Map());
    if (shift) {
      const m = shiftTally.get(empId)!;
      m.set(shift, (m.get(shift) ?? 0) + 1);
    }
    if (!memberInfo.has(empId)) {
      const rawName = String(row['Name'] ?? '');
      const { firstName, lastName } = splitTelestaffName(rawName);
      const rankRaw = String(row['Position Rank'] ?? '').trim();
      const rankCode = normalizeRankCode(rankRaw);
      memberInfo.set(empId, {
        employeeId: empId,
        firstName,
        lastName,
        rankCode,
        rankLabel: rankLabelFor(rankRaw, rankCode),
        shift,
      });
    }
  }
  console.log(`Pass 1: ${totalRows} rows, ${memberInfo.size} distinct employees`);

  // 4. Upsert ranks and members
  const rankIdByCode = new Map<string, string>();
  for (const m of memberInfo.values()) {
    if (!m.rankCode) continue;
    if (!rankIdByCode.has(m.rankCode)) {
      const id = await ensureRank(m.rankCode, m.rankLabel);
      rankIdByCode.set(m.rankCode, id);
    }
  }

  const memberIdByEmpId = new Map<string, string>();
  for (const m of memberInfo.values()) {
    const tally = shiftTally.get(m.employeeId);
    let primaryShift = m.shift;
    if (tally && tally.size > 0) {
      primaryShift = [...tally.entries()].sort((a, b) => b[1] - a[1])[0]![0];
    }
    const rankId = rankIdByCode.get(m.rankCode) ?? null;
    const [existing] = await db
      .select({ id: members.id, shift: members.shift })
      .from(members)
      .where(eq(members.employeeId, m.employeeId))
      .limit(1);
    if (existing) {
      const patch: Record<string, unknown> = {
        firstName: m.firstName || 'Unknown',
        lastName: m.lastName || 'Unknown',
        rankId,
        shift: primaryShift || null,
        updatedAt: new Date(),
      };
      await db.update(members).set(patch).where(eq(members.id, existing.id));
      memberIdByEmpId.set(m.employeeId, existing.id);
    } else {
      const [row] = await db
        .insert(members)
        .values({
          employeeId: m.employeeId,
          firstName: m.firstName || 'Unknown',
          lastName: m.lastName || 'Unknown',
          rankId,
          shift: primaryShift || null,
          isProbationary: false,
          isActive: true,
          sourceImportRunId: runId,
        })
        .returning({ id: members.id });
      if (!row) throw new Error(`failed to insert member ${m.employeeId}`);
      memberIdByEmpId.set(m.employeeId, row.id);
    }
  }
  console.log(`Members upserted: ${memberIdByEmpId.size}`);

  // 5. Pass 2 — insert leave entries (chunked).
  const CHUNK_SIZE = 250;
  let inserted = 0;
  let superseded = 0;
  let skipped = 0;
  let errors = 0;
  let earliestDate: string | null = null;
  let latestDate: string | null = null;

  type Pending = {
    memberId: string;
    isoDt: string;
    leaveCodeId: string;
    rawRow: Record<string, unknown>;
  };
  /**
   * `chunk` is keyed by `${memberId}|${precomputedBlockKey}` so two source
   * rows pointing at the same (member, block) deduplicate within the chunk
   * before we ever touch the DB. Later rows in file order win — they
   * usually represent corrections to earlier ones.
   */
  let chunk = new Map<string, Pending>();

  const flush = async (): Promise<void> => {
    if (chunk.size === 0) return;
    const items = [...chunk.values()];
    await db.transaction(async (tx) => {
      for (const p of items) {
        const block = await ensureShiftBlock(tx, p.isoDt);
        const day = new Date(p.isoDt);
        const isoDate = day.toISOString().slice(0, 10);
        if (!earliestDate || isoDate < earliestDate) earliestDate = isoDate;
        if (!latestDate || isoDate > latestDate) latestDate = isoDate;

        const existing = await tx
          .select({ id: leaveEntries.id })
          .from(leaveEntries)
          .where(
            and(
              eq(leaveEntries.memberId, p.memberId),
              eq(leaveEntries.shiftBlockId, block.shiftBlockId),
              isNull(leaveEntries.supersededByEntryId),
            ),
          )
          .limit(1);

        // Mark any prior active entry as superseded BEFORE the insert so
        // the partial unique index `(member_id, shift_block_id) WHERE
        // superseded_by_entry_id IS NULL` doesn't bounce us. We point the
        // prior at itself as a sentinel; step 3 fixes the pointer once we
        // know the new id.
        if (existing[0]) {
          await tx
            .update(leaveEntries)
            .set({ supersededByEntryId: existing[0].id })
            .where(eq(leaveEntries.id, existing[0].id));
        }

        const [created] = await tx
          .insert(leaveEntries)
          .values({
            memberId: p.memberId,
            shiftBlockId: block.shiftBlockId,
            leaveCodeId: p.leaveCodeId,
            sourceImportRunId: runId,
            rawTelestaffRow: p.rawRow,
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
      }
    });
    process.stdout.write(`  ${inserted} entries inserted (skipped=${skipped})\r`);
    chunk = new Map();
  };

  /**
   * Compute the (calendarDate, blockIndex) the same way ensureShiftBlock
   * does, but locally so we can dedup before touching the DB.
   * blockIndex 0 = AM (08:00–20:00); blockIndex 1 = PM (20:00 → 08:00 next).
   */
  function blockKey(isoDt: string): { isoDate: string; blockIndex: 0 | 1 } {
    const dt = new Date(isoDt);
    const hour = dt.getUTCHours();
    const blockIndex: 0 | 1 = hour >= 8 && hour < 20 ? 0 : 1;
    const ref = new Date(dt);
    if (blockIndex === 1 && hour < 8) ref.setUTCDate(ref.getUTCDate() - 1);
    return { isoDate: ref.toISOString().slice(0, 10), blockIndex };
  }

  /**
   * For each Telestaff row produce one or two (memberId, isoDt) targets.
   * A 24-hour combat shift block (08:00 → next-day 08:00) covers both AM
   * and PM blocks on the start day, so we emit two entries.
   */
  function expandRow(row: Record<string, unknown>): string[] {
    const startRaw = String(row['Start'] ?? row['Date'] ?? '');
    if (!startRaw) return [];
    const hoursRaw = row['Hours'];
    const hoursNum = Number(hoursRaw);
    const hours = Number.isFinite(hoursNum) ? hoursNum : 0;
    if (hours < 23.5) return [startRaw];
    const dt = new Date(startRaw);
    if (Number.isNaN(dt.getTime())) return [startRaw];
    const pm = new Date(dt);
    pm.setUTCHours(20, 0, 0, 0); // PM block start at 20:00 the same calendar day
    return [startRaw, pm.toISOString()];
  }

  const stream2 = createReadStream(absPath);
  for await (const ev of parseSpreadsheetXml(stream2)) {
    try {
      const row = ev.row;
      const empId = String(row['Emp ID'] ?? '').trim();
      if (!empId) {
        skipped++;
        continue;
      }
      const memberId = memberIdByEmpId.get(empId);
      if (!memberId) {
        skipped++;
        continue;
      }
      const cat = String(row['Work Code Category'] ?? '').trim();
      if (cat && SKIP_CATEGORIES.has(cat as TelestaffCategory)) {
        skipped++;
        continue;
      }
      const desc = String(row['Description'] ?? '').trim();
      const code = classifyDescription(desc, cat);
      if (!code) {
        skipped++;
        continue;
      }
      const leaveCodeId = codeMap.get(code.toUpperCase());
      if (!leaveCodeId) {
        skipped++;
        continue;
      }
      // One Telestaff row may cover multiple shift blocks. A 24-hour
      // combat shift starting at 08:00 spans the AM block (08-20) and the
      // PM block (20-08) of the same day, so the loader emits both.
      const isoDts = expandRow(row as Record<string, unknown>);
      if (isoDts.length === 0) {
        skipped++;
        continue;
      }
      for (const isoDt of isoDts) {
        const { isoDate, blockIndex } = blockKey(isoDt);
        const key = `${memberId}|${isoDate}|${blockIndex}`;
        chunk.set(key, {
          memberId,
          isoDt,
          leaveCodeId,
          rawRow: row as Record<string, unknown>,
        });
        if (chunk.size >= CHUNK_SIZE) await flush();
      }
    } catch (err) {
      errors++;
      console.error('row failed:', err);
    }
  }
  await flush();
  process.stdout.write('\n');

  // 6. Finalise import_runs
  await db
    .update(importRuns)
    .set({
      status: 'committed',
      finishedAt: new Date(),
      parseStats: {
        totalRows,
        parsedRows: inserted,
        errorRows: errors,
        skippedRows: skipped,
        uniqueEmployees: memberIdByEmpId.size,
        leaveEntriesInserted: inserted,
        leaveEntriesSuperseded: superseded,
        source: 'bootstrap-telestaff-xml',
        dateRange:
          earliestDate && latestDate ? { from: earliestDate, to: latestDate } : undefined,
      },
    })
    .where(eq(importRuns.id, runId));

  console.log(
    `Done: inserted=${inserted} superseded=${superseded} skipped=${skipped} errors=${errors}`,
  );
  console.log(
    `Coverage: members=${memberIdByEmpId.size}, date range=${earliestDate}..${latestDate}`,
  );

  // 7. Final summary
  const memberCount = await db
    .select({ c: sql<number>`count(*)::int` })
    .from(members);
  const entryCount = await db
    .select({ c: sql<number>`count(*)::int` })
    .from(leaveEntries)
    .where(isNull(leaveEntries.supersededByEntryId));
  const blockCount = await db
    .select({ c: sql<number>`count(*)::int` })
    .from(shiftBlocks);
  console.log(
    `DB totals: members=${memberCount[0]?.c}, active leave_entries=${entryCount[0]?.c}, shift_blocks=${blockCount[0]?.c}`,
  );
}

try {
  await main();
} catch (err) {
  console.error('Bootstrap failed:', err);
  process.exitCode = 1;
} finally {
  await close();
}
