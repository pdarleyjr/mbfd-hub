import { calendarDays, shiftBlocks } from '@mbfd-vacation/db';
import type { Database } from '@mbfd-vacation/db';
import { and, eq } from 'drizzle-orm';

/**
 * Resolve the Miami (America/New_York) UTC offset for a given local
 * date. Returns -5 in winter (EST) and -4 in summer (EDT). Uses
 * `Intl.DateTimeFormat` so it tracks any future DST rule changes without
 * needing a TZ database in the bundle.
 */
function miamiUtcOffsetHours(isoDate: string): number {
  // Construct an arbitrary UTC instant on the target date — noon avoids
  // the DST spring-forward/fall-back ambiguity for the offset query.
  const utc = new Date(`${isoDate}T12:00:00Z`);
  const fmt = new Intl.DateTimeFormat('en-US', {
    timeZone: 'America/New_York',
    timeZoneName: 'shortOffset',
  });
  const parts = fmt.formatToParts(utc);
  const tz = parts.find((p) => p.type === 'timeZoneName')?.value ?? '';
  // tz looks like "GMT-4" (EDT) or "GMT-5" (EST). Parse the integer.
  const m = /GMT([+-]\d+)/.exec(tz);
  if (!m) return -5;
  return Number(m[1]);
}

function miamiOffsetString(isoDate: string): string {
  const hours = miamiUtcOffsetHours(isoDate);
  const sign = hours <= 0 ? '-' : '+';
  return `${sign}${String(Math.abs(hours)).padStart(2, '0')}:00`;
}

/**
 * Look up the shift block for the given ISO datetime, lazy-creating the
 * calendar day and the two 12-hour blocks for that date if needed.
 *
 * MBFD shift blocks:
 *   block_index 0 = 08:00 → 20:00 local
 *   block_index 1 = 20:00 → 08:00 next day
 *
 * If the event starts at or after 20:00 we use block 1 (PM); otherwise
 * block 0 (AM). If the event is at exactly 08:00 it's block 0.
 */
export async function ensureShiftBlock(
  db: Database,
  isoDateTime: string,
): Promise<{ shiftBlockId: string; calendarDayId: string; blockIndex: number }> {
  const dt = new Date(isoDateTime);
  if (Number.isNaN(dt.getTime())) throw new Error(`invalid datetime: ${isoDateTime}`);

  // Block index: prefer hour-of-day in UTC for stability. The Telestaff
  // export is exported in local Miami time but stored as ISO; we treat
  // hours 8..19 as AM block and 20..7 as PM block (PM block belongs to the
  // calendar day that started in the AM).
  const hour = dt.getUTCHours();
  const blockIndex = hour >= 8 && hour < 20 ? 0 : 1;

  // For PM block that falls in 00:00–07:59 we assign it to the previous day.
  const refDate = new Date(dt);
  if (blockIndex === 1 && hour < 8) {
    refDate.setUTCDate(refDate.getUTCDate() - 1);
  }
  const isoDate = refDate.toISOString().slice(0, 10);

  // Lookup or insert calendar day
  let [day] = await db
    .select()
    .from(calendarDays)
    .where(eq(calendarDays.date, isoDate))
    .limit(1);
  if (!day) {
    const d = new Date(`${isoDate}T12:00:00Z`);
    const fy = d.getUTCMonth() + 1 >= 10 ? d.getUTCFullYear() + 1 : d.getUTCFullYear();
    const [created] = await db
      .insert(calendarDays)
      .values({
        date: isoDate,
        fiscalYear: fy,
        calendarYear: d.getUTCFullYear(),
        dayOfWeek: d.getUTCDay(),
        payPeriod: null,
      })
      .onConflictDoNothing({ target: calendarDays.date })
      .returning();
    day = created;
    if (!day) {
      // race lost — re-select
      [day] = await db
        .select()
        .from(calendarDays)
        .where(eq(calendarDays.date, isoDate))
        .limit(1);
    }
  }
  if (!day) throw new Error(`failed to ensure calendar day ${isoDate}`);

  // Lookup or insert shift block
  let [block] = await db
    .select()
    .from(shiftBlocks)
    .where(and(eq(shiftBlocks.calendarDayId, day.id), eq(shiftBlocks.blockIndex, blockIndex)))
    .limit(1);
  if (!block) {
    // Resolve Miami's UTC offset for this date so DST transitions don't
    // store start/end timestamps one hour off across April-November.
    const offset = miamiOffsetString(isoDate);
    const startAt = new Date(`${isoDate}T${blockIndex === 0 ? '08' : '20'}:00:00${offset}`);
    const endAt = new Date(startAt);
    endAt.setHours(endAt.getHours() + 12);
    const [created] = await db
      .insert(shiftBlocks)
      .values({ calendarDayId: day.id, blockIndex, startAt, endAt })
      .onConflictDoNothing({ target: [shiftBlocks.calendarDayId, shiftBlocks.blockIndex] })
      .returning();
    block = created;
    if (!block) {
      [block] = await db
        .select()
        .from(shiftBlocks)
        .where(and(eq(shiftBlocks.calendarDayId, day.id), eq(shiftBlocks.blockIndex, blockIndex)))
        .limit(1);
    }
  }
  if (!block) throw new Error(`failed to ensure shift block ${isoDate}/${blockIndex}`);

  return { shiftBlockId: block.id, calendarDayId: day.id, blockIndex };
}
