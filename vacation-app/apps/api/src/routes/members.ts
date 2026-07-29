import {
  aDayGroups,
  calendarDays,
  leaveCodes,
  leaveEntries,
  members,
  ranks,
  shiftBlocks,
} from '@mbfd-vacation/db';
import { and, asc, between, desc, eq, ilike, isNull, or, sql } from 'drizzle-orm';
import { Hono } from 'hono';
import { db } from '../db';
import { z } from 'zod';
import { loadStaffingRules } from './staffing-rules';

export const membersRoute = new Hono();

/**
 * Search members by name or employee ID — drives the board search bar.
 *
 * GET /api/members/search?q=ALMO&limit=20
 *   → returns up to `limit` matches ordered by best match, then last name.
 */
membersRoute.get('/members/search', async (c) => {
  const q = (c.req.query('q') ?? '').trim();
  const limit = Math.min(Math.max(Number(c.req.query('limit') ?? 20), 1), 50);
  if (!q) return c.json({ matches: [] });
  const like = `%${q.replace(/[\\%_]/g, '\\$&')}%`;

  const rows = await db
    .select({
      id: members.id,
      employeeId: members.employeeId,
      lastName: members.lastName,
      firstName: members.firstName,
      shift: members.shift,
      station: members.station,
      rankCode: ranks.code,
      rankLabel: ranks.label,
    })
    .from(members)
    .leftJoin(ranks, eq(ranks.id, members.rankId))
    .where(
      and(
        eq(members.isActive, true),
        or(
          ilike(members.lastName, like),
          ilike(members.firstName, like),
          ilike(members.employeeId, like),
        ),
      ),
    )
    .orderBy(asc(members.lastName), asc(members.firstName))
    .limit(limit);

  return c.json({
    matches: rows.map((r) => ({
      id: r.id,
      employeeId: r.employeeId,
      lastName: r.lastName,
      firstName: r.firstName,
      shift: r.shift,
      station: r.station,
      rank: r.rankCode ? { code: r.rankCode, label: r.rankLabel ?? r.rankCode } : null,
    })),
  });
});

/**
 * Detailed profile for one member, including YTD usage per leave code
 * and the chronological list of their active leave entries.
 *
 * GET /api/members/:id/profile?year=2026
 */
membersRoute.get('/members/:id/profile', async (c) => {
  const id = c.req.param('id');
  const yearParam = Number(c.req.query('year'));
  const today = new Date();
  const year = Number.isFinite(yearParam) && yearParam > 1970 ? yearParam : today.getUTCFullYear();
  const yearStart = `${year}-01-01`;
  const yearEnd = `${year}-12-31`;

  const [member] = await db
    .select({
      id: members.id,
      employeeId: members.employeeId,
      lastName: members.lastName,
      firstName: members.firstName,
      shift: members.shift,
      station: members.station,
      certifications: members.certifications,
      isProbationary: members.isProbationary,
      isActive: members.isActive,
      rankId: ranks.id,
      rankCode: ranks.code,
      rankLabel: ranks.label,
      aDayGroupId: aDayGroups.id,
      aDayGroupCode: aDayGroups.code,
      aDayGroupLabel: aDayGroups.label,
    })
    .from(members)
    .leftJoin(ranks, eq(ranks.id, members.rankId))
    .leftJoin(aDayGroups, eq(aDayGroups.id, members.aDayGroupId))
    .where(eq(members.id, id))
    .limit(1);

  if (!member) return c.json({ error: 'not_found' }, 404);

  // YTD balance per leave code: total entries + sum(hours) for active
  // leave_entries in the year window.
  const balanceRows = await db
    .select({
      leaveCodeId: leaveCodes.id,
      code: leaveCodes.code,
      label: leaveCodes.label,
      uiColor: leaveCodes.uiColor,
      entries: sql<number>`count(*)::int`,
      hours: sql<number>`coalesce(sum(${leaveEntries.hours}), 0)::float`,
    })
    .from(leaveEntries)
    .innerJoin(leaveCodes, eq(leaveCodes.id, leaveEntries.leaveCodeId))
    .innerJoin(shiftBlocks, eq(shiftBlocks.id, leaveEntries.shiftBlockId))
    .innerJoin(calendarDays, eq(calendarDays.id, shiftBlocks.calendarDayId))
    .where(
      and(
        eq(leaveEntries.memberId, id),
        isNull(leaveEntries.supersededByEntryId),
        between(calendarDays.date, yearStart, yearEnd),
      ),
    )
    .groupBy(leaveCodes.id, leaveCodes.code, leaveCodes.label, leaveCodes.uiColor)
    .orderBy(desc(sql<number>`sum(${leaveEntries.hours})`));

  // Chronological active leave entries for the year (used by the drawer).
  const entryRows = await db
    .select({
      id: leaveEntries.id,
      dayDate: calendarDays.date,
      blockIndex: shiftBlocks.blockIndex,
      hours: leaveEntries.hours,
      assignment: leaveEntries.assignment,
      code: leaveCodes.code,
      label: leaveCodes.label,
      uiColor: leaveCodes.uiColor,
      sourceImportRunId: leaveEntries.sourceImportRunId,
    })
    .from(leaveEntries)
    .innerJoin(shiftBlocks, eq(shiftBlocks.id, leaveEntries.shiftBlockId))
    .innerJoin(calendarDays, eq(calendarDays.id, shiftBlocks.calendarDayId))
    .innerJoin(leaveCodes, eq(leaveCodes.id, leaveEntries.leaveCodeId))
    .where(
      and(
        eq(leaveEntries.memberId, id),
        isNull(leaveEntries.supersededByEntryId),
        between(calendarDays.date, yearStart, yearEnd),
      ),
    )
    .orderBy(asc(calendarDays.date), asc(shiftBlocks.blockIndex));

  return c.json({
    member: {
      id: member.id,
      employeeId: member.employeeId,
      lastName: member.lastName,
      firstName: member.firstName,
      shift: member.shift,
      station: member.station,
      certifications: (member.certifications as string[] | null) ?? [],
      isProbationary: member.isProbationary,
      isActive: member.isActive,
      rank: member.rankId
        ? { id: member.rankId, code: member.rankCode ?? '', label: member.rankLabel ?? '' }
        : null,
      aDayGroup: member.aDayGroupId
        ? {
            id: member.aDayGroupId,
            code: member.aDayGroupCode ?? '',
            label: member.aDayGroupLabel ?? '',
          }
        : null,
    },
    year,
    balances: balanceRows.map((r) => ({
      leaveCodeId: r.leaveCodeId,
      code: r.code,
      label: r.label,
      uiColor: r.uiColor,
      entries: Number(r.entries),
      hours: Number(r.hours),
    })),
    entries: entryRows.map((r) => ({
      id: r.id,
      dayDate: r.dayDate,
      blockIndex: r.blockIndex,
      hours: r.hours == null ? null : Number(r.hours),
      assignment: r.assignment,
      leaveCode: { code: r.code, label: r.label, uiColor: r.uiColor },
      sourceImportRunId: r.sourceImportRunId,
    })),
  });
});

/**
 * Inline editor: update station + certifications on a member. Anything
 * outside the configured staffing_rules.stationOptions /
 * certificationOptions is rejected so the engine's lookups stay clean.
 */
const PatchBody = z.object({
  station: z.string().nullable().optional(),
  certifications: z.array(z.string()).optional(),
});

membersRoute.patch('/members/:id', async (c) => {
  const id = c.req.param('id');
  if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(id)) {
    return c.json({ error: 'invalid_id' }, 400);
  }
  const body = await c.req.json().catch(() => null);
  const parsed = PatchBody.safeParse(body);
  if (!parsed.success) {
    return c.json({ error: 'invalid_body', issues: parsed.error.flatten() }, 400);
  }

  // Enforce the configured allowlists so a poisoned station/cert string
  // cannot reach the decision engine, where it would corrupt pairing /
  // Marine-cap logic.
  const { rules } = await loadStaffingRules();
  const allowedStations = new Set(rules.stationOptions);
  const allowedCerts = new Set(rules.certificationOptions);

  if (parsed.data.station != null && parsed.data.station !== '' && !allowedStations.has(parsed.data.station)) {
    return c.json(
      {
        error: 'invalid_station',
        message: `station must be one of: ${[...allowedStations].join(', ')}`,
      },
      400,
    );
  }
  if (parsed.data.certifications) {
    for (const c0 of parsed.data.certifications) {
      if (!allowedCerts.has(c0)) {
        return c.json(
          {
            error: 'invalid_certification',
            message: `certification '${c0}' is not in: ${[...allowedCerts].join(', ')}`,
          },
          400,
        );
      }
    }
  }

  const patch: Record<string, unknown> = { updatedAt: new Date() };
  if (parsed.data.station !== undefined) {
    patch.station = parsed.data.station === '' ? null : parsed.data.station;
  }
  if (parsed.data.certifications !== undefined) patch.certifications = parsed.data.certifications;

  const [row] = await db
    .update(members)
    .set(patch)
    .where(eq(members.id, id))
    .returning({
      id: members.id,
      station: members.station,
      certifications: members.certifications,
    });
  if (!row) return c.json({ error: 'not_found' }, 404);
  return c.json({
    id: row.id,
    station: row.station,
    certifications: (row.certifications as string[] | null) ?? [],
  });
});
