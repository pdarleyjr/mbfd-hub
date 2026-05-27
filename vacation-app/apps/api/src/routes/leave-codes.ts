import { leaveCodes } from '@mbfd-vacation/db';
import { asc } from 'drizzle-orm';
import { Hono } from 'hono';
import { db } from '../db';

export const leaveCodesRoute = new Hono();

leaveCodesRoute.get('/leave-codes', async (c) => {
  const rows = await db
    .select({
      id: leaveCodes.id,
      code: leaveCodes.code,
      label: leaveCodes.label,
      uiColor: leaveCodes.uiColor,
      isADayMarker: leaveCodes.isADayMarker,
    })
    .from(leaveCodes)
    .orderBy(asc(leaveCodes.code));
  return c.json({ leaveCodes: rows });
});
