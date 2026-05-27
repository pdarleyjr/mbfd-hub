import { date, index, integer, pgTable, uuid } from 'drizzle-orm/pg-core';

export const calendarDays = pgTable(
  'calendar_days',
  {
    id: uuid('id').primaryKey().defaultRandom(),
    date: date('date').notNull().unique(),
    fiscalYear: integer('fiscal_year').notNull(),
    calendarYear: integer('calendar_year').notNull(),
    dayOfWeek: integer('day_of_week').notNull(),
    payPeriod: integer('pay_period'),
  },
  (t) => [index('calendar_days_fy_idx').on(t.fiscalYear)],
);

export type CalendarDay = typeof calendarDays.$inferSelect;
export type NewCalendarDay = typeof calendarDays.$inferInsert;
