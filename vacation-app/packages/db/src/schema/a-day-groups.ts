import { pgTable, text, uuid } from 'drizzle-orm/pg-core';

export const aDayGroups = pgTable('a_day_groups', {
  id: uuid('id').primaryKey().defaultRandom(),
  code: text('code').notNull().unique(),
  label: text('label').notNull(),
});

export type ADayGroup = typeof aDayGroups.$inferSelect;
export type NewADayGroup = typeof aDayGroups.$inferInsert;
