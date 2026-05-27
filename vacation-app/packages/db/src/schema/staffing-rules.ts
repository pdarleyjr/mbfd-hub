import {
  boolean,
  index,
  jsonb,
  pgTable,
  text,
  timestamp,
  uniqueIndex,
  uuid,
} from 'drizzle-orm/pg-core';

/**
 * Singleton row that backs the Daily Shift Staffing Guidelines decision
 * engine. The unique index on `singleton` ensures we never accidentally
 * write a second row; admins update `rules_json` via the /admin/rules UI.
 */
export const staffingRules = pgTable(
  'staffing_rules',
  {
    id: uuid('id').primaryKey().defaultRandom(),
    singleton: boolean('singleton').notNull().default(true),
    rulesJson: jsonb('rules_json').notNull(),
    updatedAt: timestamp('updated_at', { withTimezone: true }).notNull().defaultNow(),
    updatedByPinHash: text('updated_by_pin_hash'),
  },
  (t) => [uniqueIndex('staffing_rules_singleton_uk').on(t.singleton)],
);

export type StaffingRulesRow = typeof staffingRules.$inferSelect;
export type NewStaffingRulesRow = typeof staffingRules.$inferInsert;

export const staffingRulesAudit = pgTable(
  'staffing_rules_audit',
  {
    id: uuid('id').primaryKey().defaultRandom(),
    changedAt: timestamp('changed_at', { withTimezone: true }).notNull().defaultNow(),
    changedByPinHash: text('changed_by_pin_hash'),
    previousRulesJson: jsonb('previous_rules_json'),
    newRulesJson: jsonb('new_rules_json').notNull(),
  },
  (t) => [index('staffing_rules_audit_changed_at_idx').on(t.changedAt)],
);

export type StaffingRulesAuditRow = typeof staffingRulesAudit.$inferSelect;
export type NewStaffingRulesAuditRow = typeof staffingRulesAudit.$inferInsert;
