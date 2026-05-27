import { pgTable, text, timestamp, uuid } from 'drizzle-orm/pg-core';
import { leaveCodes } from './leave-codes';

export const workCodeMappings = pgTable('work_code_mappings', {
  id: uuid('id').primaryKey().defaultRandom(),
  telestaffDescription: text('telestaff_description').notNull().unique(),
  leaveCodeId: uuid('leave_code_id')
    .notNull()
    .references(() => leaveCodes.id),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
});

export type WorkCodeMapping = typeof workCodeMappings.$inferSelect;
export type NewWorkCodeMapping = typeof workCodeMappings.$inferInsert;
