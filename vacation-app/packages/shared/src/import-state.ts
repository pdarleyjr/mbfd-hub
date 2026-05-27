import { z } from 'zod';
import { ColumnMappingSchema } from './column-mapping';

export const ImportStatus = z.enum([
  'uploaded',
  'parsing',
  'preview_ready',
  'committing',
  'committed',
  'failed',
  'rolled_back',
]);
export type ImportStatus = z.infer<typeof ImportStatus>;

/**
 * Stats produced by the worker during preview + commit.
 *
 * Every field is optional because they accumulate as the worker progresses.
 */
export const ParseStatsSchema = z.object({
  totalRows: z.number().int().nonnegative().optional(),
  parsedRows: z.number().int().nonnegative().optional(),
  errorRows: z.number().int().nonnegative().optional(),
  skippedRows: z.number().int().nonnegative().optional(),
  newMembersInserted: z.number().int().nonnegative().optional(),
  newLeaveCodesInserted: z.number().int().nonnegative().optional(),
  newWorkCodeMappings: z.number().int().nonnegative().optional(),
  shiftBlocksTouched: z.number().int().nonnegative().optional(),
  leaveEntriesInserted: z.number().int().nonnegative().optional(),
  leaveEntriesSuperseded: z.number().int().nonnegative().optional(),
  uniqueEmployees: z.number().int().nonnegative().optional(),
  dateRange: z
    .object({
      from: z.string(),
      to: z.string(),
    })
    .optional(),
});
export type ParseStats = z.infer<typeof ParseStatsSchema>;

/**
 * The payload sent on each SSE event from /api/imports/:id/preview.
 */
export const PreviewEventSchema = z.discriminatedUnion('type', [
  z.object({
    type: z.literal('progress'),
    rowsProcessed: z.number().int().nonnegative(),
    totalBytes: z.number().int().nonnegative().nullable(),
    bytesProcessed: z.number().int().nonnegative(),
  }),
  z.object({
    type: z.literal('preview_ready'),
    columns: z.array(z.string()),
    sampleRows: z.array(z.record(z.string(), z.union([z.string(), z.number(), z.null()]))),
    suggestedMapping: ColumnMappingSchema,
    unknownDescriptions: z.array(z.string()),
    parseStats: ParseStatsSchema,
  }),
  z.object({
    type: z.literal('failed'),
    errorMessage: z.string(),
  }),
]);
export type PreviewEvent = z.infer<typeof PreviewEventSchema>;
