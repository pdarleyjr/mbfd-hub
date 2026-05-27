import { index, integer, jsonb, pgTable, text, uuid } from 'drizzle-orm/pg-core';
import { importRuns } from './import-runs.js';

export const importRunRows = pgTable(
  'import_run_rows',
  {
    id: uuid('id').primaryKey().defaultRandom(),
    importRunId: uuid('import_run_id')
      .notNull()
      .references(() => importRuns.id),
    rowIndex: integer('row_index').notNull(),
    rawRowJson: jsonb('raw_row_json').notNull(),
    parsedStatus: text('parsed_status').notNull(),
    // 'ok' | 'skipped' | 'error'
    errorMessage: text('error_message'),
  },
  (t) => [
    index('import_run_rows_run_idx').on(t.importRunId),
    index('import_run_rows_status_idx').on(t.parsedStatus),
  ],
);

export type ImportRunRow = typeof importRunRows.$inferSelect;
export type NewImportRunRow = typeof importRunRows.$inferInsert;
