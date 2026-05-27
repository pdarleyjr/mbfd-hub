import { z } from 'zod';

/**
 * The target fields a Telestaff column can be mapped to.
 *
 * 'ignore' is explicit so the admin can tell us not to use a column rather
 * than leaving it ambiguous.
 */
export const ColumnTarget = z.enum([
  'employee_id',
  'badge_number',
  'full_name',
  'last_name',
  'first_name',
  'rank',
  'shift',
  'a_day_group',
  'hire_date',
  'event_datetime',
  'event_end_datetime',
  'event_description',
  'event_work_code',
  'ignore',
]);
export type ColumnTarget = z.infer<typeof ColumnTarget>;

/**
 * The full mapping for one import: each source column header maps to one
 * target. Columns the admin doesn't pick map to 'ignore'.
 */
export const ColumnMappingSchema = z.object({
  // Header string from the file (case-preserved as it appeared).
  // For headerless files we synthesize 'col_0', 'col_1', …
  columns: z.array(
    z.object({
      sourceHeader: z.string(),
      target: ColumnTarget,
    }),
  ),
  // The minimum required targets for a useful import. Validated on commit.
  // V1 we require employee_id, event_datetime, and one of
  // event_description/event_work_code.
});
export type ColumnMapping = z.infer<typeof ColumnMappingSchema>;

export const REQUIRED_TARGETS: ColumnTarget[] = [
  'employee_id',
  'event_datetime',
];

export function findTarget(
  mapping: ColumnMapping,
  target: ColumnTarget,
): string | undefined {
  return mapping.columns.find((c) => c.target === target)?.sourceHeader;
}
