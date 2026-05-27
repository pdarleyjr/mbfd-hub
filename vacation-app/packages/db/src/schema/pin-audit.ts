import { index, pgTable, text, timestamp, uuid } from 'drizzle-orm/pg-core';

export const pinAudit = pgTable(
  'pin_audit',
  {
    id: uuid('id').primaryKey().defaultRandom(),
    ip: text('ip'),
    userAgent: text('user_agent'),
    outcome: text('outcome').notNull(), // 'success' | 'failure' | 'rate_limited'
    attemptedAt: timestamp('attempted_at', { withTimezone: true }).notNull().defaultNow(),
  },
  (t) => [index('pin_audit_attempted_idx').on(t.attemptedAt)],
);

export type PinAuditEntry = typeof pinAudit.$inferSelect;
export type NewPinAuditEntry = typeof pinAudit.$inferInsert;
