import {
  aDayGroups,
  calendarDays,
  leaveCodes,
  leaveEntries,
  members,
  ranks,
  shiftBlocks,
} from '@mbfd-vacation/db';
import { BoardFiltersSchema } from '@mbfd-vacation/shared';
import { and, asc, between, eq, inArray, isNull, sql } from 'drizzle-orm';
import { Hono } from 'hono';
import { db } from '../db.js';

export const board = new Hono();

board.get('/board', async (c) => {
  const parsed = BoardFiltersSchema.safeParse({
    shift: c.req.queries('shift'),
    rank: c.req.queries('rank'),
    from: c.req.query('from'),
    to: c.req.query('to'),
    onlyWithLeave: c.req.query('onlyWithLeave') === 'true',
    page: c.req.query('page'),
    pageSize: c.req.query('pageSize'),
  });
  if (!parsed.success) {
    return c.json({ error: 'invalid_filters', issues: parsed.error.flatten() }, 400);
  }
  const f = parsed.data;

  // Default window: 4 weeks centered on today.
  const today = new Date();
  const defaultFrom = new Date(today);
  defaultFrom.setDate(defaultFrom.getDate() - 7);
  const defaultTo = new Date(today);
  defaultTo.setDate(defaultTo.getDate() + 21);

  const fromDate = f.from ?? defaultFrom.toISOString().slice(0, 10);
  const toDate = f.to ?? defaultTo.toISOString().slice(0, 10);

  // Members.
  const memberQuery = db
    .select({
      id: members.id,
      employeeId: members.employeeId,
      lastName: members.lastName,
      firstName: members.firstName,
      shift: members.shift,
      isProbationary: members.isProbationary,
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
    .where(eq(members.isActive, true))
    .orderBy(asc(members.shift), asc(members.lastName), asc(members.firstName))
    .limit(f.pageSize)
    .offset((f.page - 1) * f.pageSize);

  const memberRows = await memberQuery;

  // Apply shift/rank filters in JS to keep the query simple.
  const filtered = memberRows.filter((m) => {
    if (f.shift?.length && !f.shift.includes(m.shift ?? '')) return false;
    if (f.rank?.length && !f.rank.includes(m.rankCode ?? '')) return false;
    return true;
  });
  const memberIds = filtered.map((m) => m.id);

  // Cells.
  const cellRows = memberIds.length
    ? await db
        .select({
          memberId: leaveEntries.memberId,
          shiftBlockId: leaveEntries.shiftBlockId,
          blockIndex: shiftBlocks.blockIndex,
          startAt: shiftBlocks.startAt,
          endAt: shiftBlocks.endAt,
          leaveCodeId: leaveCodes.id,
          leaveCode: leaveCodes.code,
          leaveLabel: leaveCodes.label,
          leaveColor: leaveCodes.uiColor,
          sourceImportRunId: leaveEntries.sourceImportRunId,
        })
        .from(leaveEntries)
        .innerJoin(shiftBlocks, eq(shiftBlocks.id, leaveEntries.shiftBlockId))
        .innerJoin(calendarDays, eq(calendarDays.id, shiftBlocks.calendarDayId))
        .innerJoin(leaveCodes, eq(leaveCodes.id, leaveEntries.leaveCodeId))
        .where(
          and(
            inArray(leaveEntries.memberId, memberIds),
            isNull(leaveEntries.supersededByEntryId),
            between(calendarDays.date, fromDate, toDate),
          ),
        )
    : [];

  const cells = cellRows.map((r) => ({
    memberId: r.memberId,
    shiftBlockId: r.shiftBlockId,
    blockIndex: r.blockIndex,
    startAt: r.startAt.toISOString(),
    endAt: r.endAt.toISOString(),
    leaveCode: {
      id: r.leaveCodeId,
      code: r.leaveCode,
      label: r.leaveLabel,
      uiColor: r.leaveColor,
    },
    sourceImportRunId: r.sourceImportRunId,
  }));

  let finalMembers = filtered;
  if (f.onlyWithLeave) {
    const idsWithLeave = new Set(cells.map((c) => c.memberId));
    finalMembers = finalMembers.filter((m) => idsWithLeave.has(m.id));
  }

  const totalMembersResult = await db
    .select({ count: sql<number>`count(*)::int` })
    .from(members)
    .where(eq(members.isActive, true));
  const totalMembers = totalMembersResult[0]?.count ?? 0;

  return c.json({
    members: finalMembers.map((m) => ({
      id: m.id,
      employeeId: m.employeeId,
      lastName: m.lastName,
      firstName: m.firstName,
      rank: m.rankId
        ? { id: m.rankId, code: m.rankCode ?? '', label: m.rankLabel ?? '' }
        : null,
      shift: m.shift,
      aDayGroup: m.aDayGroupId
        ? {
            id: m.aDayGroupId,
            code: m.aDayGroupCode ?? '',
            label: m.aDayGroupLabel ?? '',
          }
        : null,
      isProbationary: m.isProbationary,
    })),
    cells,
    dateRange: { from: fromDate, to: toDate },
    pagination: { page: f.page, pageSize: f.pageSize, totalMembers },
  });
});
