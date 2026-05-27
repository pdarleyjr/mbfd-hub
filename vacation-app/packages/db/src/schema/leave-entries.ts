import { sql } from 'drizzle-orm';
import {
  index,
  jsonb,
  pgTable,
  timestamp,
  uniqueIndex,
  uuid,
} from 'drizzle-orm/pg-core';
import { importRuns } from './import-runs';
import { leaveCodes } from './leave-codes';
import { members } from './members';
import { shiftBlocks } from './shift-blocks';

/**
 * leave_entries is the "cell" of the vacation board.
 *
 * Re-importing a newer file does NOT delete existing rows. Instead the old
 * row's superseded_by_entry_id is set to the new row's id. The partial
 * unique index ensures only one active entry exists per (member, block).
 *
 * Rollback reverses the supersede pointers and is implemented in
 * packages/db/src/operations/rollback.ts.
 */
export const leaveEntries = pgTable(
  'leave_entries',
  {
    id: uuid('id').primaryKey().defaultRandom(),
    memberId: uuid('member_id')
      .notNull()
      .references(() => members.id),
    shiftBlockId: uuid('shift_block_id')
      .notNull()
      .references(() => shiftBlocks.id),
    leaveCodeId: uuid('leave_code_id')
      .notNull()
      .references(() => leaveCodes.id),
    sourceImportRunId: uuid('source_import_run_id')
      .notNull()
      .references(() => importRuns.id),
    supersededByEntryId: uuid('superseded_by_entry_id'),
    rawTelestaffRow: jsonb('raw_telestaff_row').notNull(),
    createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
  },
  (t) => [
    uniqueIndex('leave_entries_active_uk')
      .on(t.memberId, t.shiftBlockId)
      .where(sql`${t.supersededByEntryId} IS NULL`),
    index('leave_entries_block_code_idx').on(t.shiftBlockId, t.leaveCodeId),
    index('leave_entries_member_idx').on(t.memberId),
    index('leave_entries_source_idx').on(t.sourceImportRunId),
  ],
);

export type LeaveEntry = typeof leaveEntries.$inferSelect;
export type NewLeaveEntry = typeof leaveEntries.$inferInsert;
