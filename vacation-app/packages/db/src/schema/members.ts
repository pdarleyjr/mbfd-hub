import { sql } from 'drizzle-orm';
import {
  boolean,
  date,
  index,
  jsonb,
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
    /**
     * Admin-tagged station assignment (e.g. "S1", "S2", "M6" for Marine
     * Station 6, "FLOAT" for floating personnel, "HQ" for non-station
     * civilian roles). Drives the Marine Station vacation cap and the
     * specialty-pay fill order. Telestaff doesn't export this so it's
     * managed via the member drawer.
     */
    station: text('station'),
    /**
     * Admin-tagged certification slugs from `staffing_rules.rules_json.
     * certificationOptions` (e.g. "DE", "AT", "M6_QUAL", "PROMO_CAPT").
     * Used for rank-pairing rules (DE-assigned can exchange with
     * DE-certified FF; AT-assigned with AT+DE-certified FF).
     */
    certifications: jsonb('certifications').notNull().default(sql`'[]'::jsonb`),
    isProbationary: boolean('is_probationary').notNull().default(false),
    isActive: boolean('is_active').notNull().default(true),
    sourceImportRunId: uuid('source_import_run_id'),
    createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
    updatedAt: timestamp('updated_at', { withTimezone: true }).notNull().defaultNow(),
  },
  (t) => [
    index('members_shift_lastname_idx').on(t.shift, t.lastName),
    index('members_active_idx').on(t.isActive),
    index('members_station_idx').on(t.station),
  ],
);

export type Member = typeof members.$inferSelect;
export type NewMember = typeof members.$inferInsert;
