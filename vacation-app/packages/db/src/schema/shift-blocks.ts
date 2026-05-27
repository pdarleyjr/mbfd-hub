import {
  index,
  integer,
  pgTable,
  timestamp,
  uniqueIndex,
  uuid,
} from 'drizzle-orm/pg-core';
import { calendarDays } from './calendar-days.js';

export const shiftBlocks = pgTable(
  'shift_blocks',
  {
    id: uuid('id').primaryKey().defaultRandom(),
    calendarDayId: uuid('calendar_day_id')
      .notNull()
      .references(() => calendarDays.id),
    blockIndex: integer('block_index').notNull(), // 0 = 08:00–20:00, 1 = 20:00–08:00 next day
    startAt: timestamp('start_at', { withTimezone: true }).notNull(),
    endAt: timestamp('end_at', { withTimezone: true }).notNull(),
  },
  (t) => [
    uniqueIndex('shift_blocks_day_block_uk').on(t.calendarDayId, t.blockIndex),
    index('shift_blocks_start_idx').on(t.startAt),
  ],
);

export type ShiftBlock = typeof shiftBlocks.$inferSelect;
export type NewShiftBlock = typeof shiftBlocks.$inferInsert;
