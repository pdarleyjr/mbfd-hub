/**
 * One-shot loader that backfills the `members` table from the FY25
 * Vacation Selection Master's "SENIORTY 2025" sheet. The primary import
 * pipeline only sees employees who had a scheduled Telestaff event in
 * the window, so anyone with a clean schedule (no leave / OT / details
 * to date) is missing from `members`. This loader walks the bid-sheet
 * roster and creates any missing employees + backfills hire date and
 * badge on the ones we already have.
 *
 * Usage:
 *   docker cp "FY25 Vacation Selection Master V6.xlsx" \
 *     vac-worker:/tmp/fy25.xlsx
 *   docker exec -e DATABASE_URL="…" -w /app vac-worker \
 *     node --import tsx/esm \
 *       apps/worker/src/scripts/bootstrap-seniority-xlsx.ts /tmp/fy25.xlsx
 *
 * Idempotent. Safe to re-run after a new bid-master version.
 */
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

import { aDayGroups, importRuns, members, ranks } from '@mbfd-vacation/db';
import { getDb } from '@mbfd-vacation/db';
import { normalizeRankCode, rankLabelFor } from '@mbfd-vacation/shared';
// eslint-disable-next-line @typescript-eslint/no-var-requires
import * as XLSX from 'xlsx';
import { eq, sql } from 'drizzle-orm';

const connectionString = process.env.DATABASE_URL ?? process.env.DATABASE_URL_HOST;
if (!connectionString) {
  console.error('DATABASE_URL or DATABASE_URL_HOST must be set');
  process.exit(1);
}

const inputPath = process.argv[2];
if (!inputPath) {
  console.error('Usage: tsx scripts/bootstrap-seniority-xlsx.ts <path/to.xlsx>');
  process.exit(1);
}

const absPath = resolve(inputPath);
const SHEET_NAME = process.env.SENIORITY_SHEET ?? 'SENIORTY 2025';

const { db, close } = getDb(connectionString);

type SeniorityRow = {
  employeeId: string;
  lastName: string;
  firstName: string;
  rankLabel: string;
  rankCode: string;
  shift: string | null;
  aDayGroup: string | null;
  hireDate: string | null;
  badgeNumber: string | null;
  isProbationary: boolean;
};

function titleCase(raw: string): string {
  return raw
    .toLowerCase()
    .split(/(\s|-)/)
    .map((tok) =>
      tok === ' ' || tok === '-' || tok === ''
        ? tok
        : tok
            .split("'")
            .map((p) => (p ? p[0]!.toUpperCase() + p.slice(1) : p))
            .join("'"),
    )
    .join('');
}

function readSheet(buf: Buffer): SeniorityRow[] {
  const wb = XLSX.read(buf, { type: 'buffer', cellDates: true });
  const ws = wb.Sheets[SHEET_NAME];
  if (!ws) {
    throw new Error(`Sheet "${SHEET_NAME}" not found. Sheets: ${wb.SheetNames.join(', ')}`);
  }
  // Header is on row index 1 (zero-indexed), per inspection: row 0 is a
  // column-number sentinel, row 1 has the human header.
  const raw = XLSX.utils.sheet_to_json<unknown[]>(ws, {
    header: 1,
    blankrows: false,
    raw: true,
  }) as unknown[][];
  if (raw.length < 3) return [];
  const headerRow = (raw[1] ?? []) as unknown[];
  const headers = headerRow.map((h) => (h ?? '').toString().trim());

  const idxOf = (name: string): number =>
    headers.findIndex((h) => h.toLowerCase() === name.toLowerCase());

  const idEmp = idxOf('City Id');
  const idLast = idxOf('Last Name');
  const idFirst = idxOf('First Name');
  const idRank = idxOf('Bid Rank');
  const idHire = idxOf('Date of Employment');
  const idShift = idxOf('Shift');
  const idGroup = idxOf('Group');
  const idProb = idxOf('Probation');
  const idBadge = idxOf('Badge');

  const out: SeniorityRow[] = [];
  for (let i = 2; i < raw.length; i++) {
    const r = raw[i];
    if (!r || r.length === 0) continue;
    const empRaw = r[idEmp];
    if (empRaw == null || empRaw === '') continue;
    const employeeId = String(empRaw).trim();
    if (!/^\d+$/.test(employeeId)) continue;
    const lastRaw = String(r[idLast] ?? '').trim();
    const firstRaw = String(r[idFirst] ?? '').trim();
    if (!lastRaw && !firstRaw) continue;
    const rankRaw = String(r[idRank] ?? '').trim();
    const rankCode = rankRaw ? normalizeRankCode(rankRaw) : '';
    const hireRaw = r[idHire];
    const hireDate =
      hireRaw instanceof Date
        ? hireRaw.toISOString().slice(0, 10)
        : typeof hireRaw === 'string' && hireRaw
          ? new Date(hireRaw).toISOString().slice(0, 10)
          : null;
    const shift = String(r[idShift] ?? '').trim() || null;
    const aDayGroup = String(r[idGroup] ?? '').trim() || null;
    const badgeNumber = String(r[idBadge] ?? '').trim() || null;
    const probRaw = String(r[idProb] ?? '').trim().toLowerCase();
    const isProbationary = probRaw === 'yes' || probRaw === 'y';

    out.push({
      employeeId,
      lastName: titleCase(lastRaw),
      firstName: titleCase(firstRaw),
      rankLabel: rankLabelFor(rankRaw, rankCode),
      rankCode,
      shift: shift ? shift.toUpperCase() : null,
      aDayGroup: aDayGroup ? aDayGroup.toUpperCase() : null,
      hireDate,
      badgeNumber,
      isProbationary,
    });
  }
  return out;
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
    CHIEF: 0,
    DDC: 1,
    DC: 2,
    CAPT: 3,
    LT: 4,
    'FF-DE': 5,
    FF: 6,
    PROB: 7,
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
  const [re] = await db.select({ id: ranks.id }).from(ranks).where(eq(ranks.code, upper)).limit(1);
  if (!re) throw new Error(`rank ${upper} missing after insert`);
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
  if (!re) throw new Error(`a-day group ${upper} missing after insert`);
  return re.id;
}

async function main(): Promise<void> {
  console.log(`Loading seniority from ${absPath} (sheet "${SHEET_NAME}")`);
  const buf = await readFile(absPath);
  const rows = readSheet(buf);
  console.log(`Parsed ${rows.length} seniority rows.`);

  // Synthetic import_runs row so the loader fits the rollback model.
  const [run] = await db
    .insert(importRuns)
    .values({
      fileName: 'FY25 Vacation Selection Master V6.xlsx',
      fileSize: buf.length,
      fileSha256: 'bootstrap-seniority',
      r2Key: 'bootstrap/seniority/' + new Date().toISOString(),
      status: 'committing',
      startedAt: new Date(),
    })
    .returning({ id: importRuns.id });
  if (!run) throw new Error('import_runs insert failed');
  const runId = run.id;

  const rankIdByCode = new Map<string, string>();
  const groupIdByCode = new Map<string, string>();

  let inserted = 0;
  let updated = 0;
  let skipped = 0;

  for (const r of rows) {
    if (!r.rankCode) {
      skipped++;
      continue;
    }
    let rankId = rankIdByCode.get(r.rankCode);
    if (!rankId) {
      rankId = await ensureRank(r.rankCode, r.rankLabel);
      rankIdByCode.set(r.rankCode, rankId);
    }
    let aDayGroupId: string | null = null;
    if (r.aDayGroup) {
      let id = groupIdByCode.get(r.aDayGroup);
      if (!id) {
        id = await ensureADayGroup(r.aDayGroup);
        groupIdByCode.set(r.aDayGroup, id);
      }
      aDayGroupId = id;
    }

    const [existing] = await db
      .select({
        id: members.id,
        firstName: members.firstName,
        lastName: members.lastName,
        rankId: members.rankId,
        shift: members.shift,
        hireDate: members.hireDate,
        badgeNumber: members.badgeNumber,
        aDayGroupId: members.aDayGroupId,
      })
      .from(members)
      .where(eq(members.employeeId, r.employeeId))
      .limit(1);

    if (!existing) {
      await db.insert(members).values({
        employeeId: r.employeeId,
        firstName: r.firstName || 'Unknown',
        lastName: r.lastName || 'Unknown',
        rankId,
        shift: r.shift,
        aDayGroupId,
        hireDate: r.hireDate,
        badgeNumber: r.badgeNumber,
        isProbationary: r.isProbationary,
        isActive: true,
        sourceImportRunId: runId,
      });
      inserted++;
      continue;
    }

    // Selective backfill of missing fields. We don't overwrite a
    // present name / shift because the Telestaff import is authoritative
    // for those during the FY window.
    const patch: Record<string, unknown> = {};
    if (!existing.hireDate && r.hireDate) patch.hireDate = r.hireDate;
    if (!existing.badgeNumber && r.badgeNumber) patch.badgeNumber = r.badgeNumber;
    if (!existing.aDayGroupId && aDayGroupId) patch.aDayGroupId = aDayGroupId;
    if (!existing.rankId && rankId) patch.rankId = rankId;
    if (Object.keys(patch).length > 0) {
      patch.updatedAt = new Date();
      await db.update(members).set(patch).where(eq(members.id, existing.id));
      updated++;
    }
  }

  await db
    .update(importRuns)
    .set({
      status: 'committed',
      finishedAt: new Date(),
      parseStats: {
        totalRows: rows.length,
        parsedRows: inserted + updated,
        errorRows: 0,
        skippedRows: skipped,
        uniqueEmployees: inserted + updated,
        leaveEntriesInserted: 0,
        leaveEntriesSuperseded: 0,
        source: 'bootstrap-seniority-xlsx',
      },
    })
    .where(eq(importRuns.id, runId));

  const counts = await db.select({ c: sql<number>`count(*)::int` }).from(members);
  const total = counts[0]?.c ?? 0;
  console.log(
    `Done: inserted=${inserted}, updated=${updated}, skipped=${skipped}, total members now=${total}`,
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
