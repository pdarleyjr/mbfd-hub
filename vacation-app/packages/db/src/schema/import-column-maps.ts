import { jsonb, pgTable, text, timestamp, uuid } from 'drizzle-orm/pg-core';

export const importColumnMaps = pgTable('import_column_maps', {
  id: uuid('id').primaryKey().defaultRandom(),
  name: text('name').notNull().unique(),
  mappingJson: jsonb('mapping_json').notNull(),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
});

export type ImportColumnMap = typeof importColumnMaps.$inferSelect;
export type NewImportColumnMap = typeof importColumnMaps.$inferInsert;
