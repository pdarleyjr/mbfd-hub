import { boolean, pgTable, text, uuid } from 'drizzle-orm/pg-core';

export const leaveCodes = pgTable('leave_codes', {
  id: uuid('id').primaryKey().defaultRandom(),
  code: text('code').notNull().unique(),
  label: text('label').notNull(),
  description: text('description'),
  uiColor: text('ui_color').notNull().default('#78716C'),
  countsAgainstVacationBalance: boolean('counts_against_vacation_balance')
    .notNull()
    .default(false),
  countsAgainstFloatingBalance: boolean('counts_against_floating_balance')
    .notNull()
    .default(false),
  countsAgainstDailyVacationCapacity: boolean('counts_against_daily_vacation_capacity')
    .notNull()
    .default(false),
  countsAgainstTotalOffCapacity: boolean('counts_against_total_off_capacity')
    .notNull()
    .default(true),
  countsAgainstMinimumStaffing: boolean('counts_against_minimum_staffing')
    .notNull()
    .default(false),
  isADayMarker: boolean('is_a_day_marker').notNull().default(false),
});

export type LeaveCode = typeof leaveCodes.$inferSelect;
export type NewLeaveCode = typeof leaveCodes.$inferInsert;
