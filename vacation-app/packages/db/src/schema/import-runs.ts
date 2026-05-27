import {
  bigint,
  index,
  jsonb,
  pgTable,
  text,
  timestamp,
  uuid,
} from 'drizzle-orm/pg-core';

export const importRuns = pgTable(
  'import_runs',
  {
    id: uuid('id').primaryKey().defaultRandom(),
    fileName: text('file_name').notNull(),
    fileSize: bigint('file_size', { mode: 'number' }).notNull(),
    fileSha256: text('file_sha256').notNull(),
    r2Key: text('r2_key').notNull(),
    uploadedAt: timestamp('uploaded_at', { withTimezone: true }).notNull().defaultNow(),
    uploadedByPinHash: text('uploaded_by_pin_hash'),
    status: text('status').notNull().default('uploaded'),
    // 'uploaded' | 'parsing' | 'preview_ready' | 'committing' | 'committed' | 'failed' | 'rolled_back'
    columnMappingJson: jsonb('column_mapping_json'),
    workCodeDecisionsJson: jsonb('work_code_decisions_json'),
    /**
     * Full preview_ready event payload (columns, sampleRows, suggestedMapping,
     * unknownDescriptions). Persisted by the worker when preview completes so
     * the SSE endpoint can replay it after a client reconnect.
     */
    previewPayloadJson: jsonb('preview_payload_json'),
    parseStats: jsonb('parse_stats'),
    errorMessage: text('error_message'),
    startedAt: timestamp('started_at', { withTimezone: true }),
    finishedAt: timestamp('finished_at', { withTimezone: true }),
  },
  (t) => [
    index('import_runs_status_idx').on(t.status),
    index('import_runs_uploaded_at_idx').on(t.uploadedAt),
    index('import_runs_sha_idx').on(t.fileSha256),
  ],
);

export type ImportRun = typeof importRuns.$inferSelect;
export type NewImportRun = typeof importRuns.$inferInsert;
