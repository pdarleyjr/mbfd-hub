import { boolean, integer, pgTable, text, uuid } from 'drizzle-orm/pg-core';

export const ranks = pgTable('ranks', {
  id: uuid('id').primaryKey().defaultRandom(),
  code: text('code').notNull().unique(),
  label: text('label').notNull(),
  sortOrder: integer('sort_order').notNull(),
  isOfficer: boolean('is_officer').notNull().default(false),
});

export type Rank = typeof ranks.$inferSelect;
export type NewRank = typeof ranks.$inferInsert;
