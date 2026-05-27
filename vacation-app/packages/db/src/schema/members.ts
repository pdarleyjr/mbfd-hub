import {
  boolean,
  date,
  index,
  pgTable,
  text,
  timestamp,
  uuid,
} from 'drizzle-orm/pg-core';
import { aDayGroups } from './a-day-groups';
import { ranks } from './ranks';

export const members = pgTable(
  'members',
  {
    id: uuid('id').primaryKey().defaultRandom(),
    employeeId: text('employee_id').notNull().unique(),
    badgeNumber: text('badge_number'),
    lastName: text('last_name').notNull(),
    firstName: text('first_name').notNull(),
    hireDate: date('hire_date'),
    rankId: uuid('rank_id').references(() => ranks.id),
    shift: text('shift'),
    aDayGroupId: uuid('a_day_group_id').references(() => aDayGroups.id),
    isProbationary: boolean('is_probationary').notNull().default(false),
    isActive: boolean('is_active').notNull().default(true),
    sourceImportRunId: uuid('source_import_run_id'),
    createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
    updatedAt: timestamp('updated_at', { withTimezone: true }).notNull().defaultNow(),
  },
  (t) => [
    index('members_shift_lastname_idx').on(t.shift, t.lastName),
    index('members_active_idx').on(t.isActive),
  ],
);

export type Member = typeof members.$inferSelect;
export type NewMember = typeof members.$inferInsert;
