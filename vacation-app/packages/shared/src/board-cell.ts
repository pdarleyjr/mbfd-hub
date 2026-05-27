import { z } from 'zod';

export const BoardCellSchema = z.object({
  memberId: z.string().uuid(),
  shiftBlockId: z.string().uuid(),
  blockIndex: z.number().int().min(0).max(1),
  /**
   * Authoritative calendar day `YYYY-MM-DD` for this block. Use this for
   * grid keying — NOT a UTC slice of startAt (PM blocks cross midnight
   * UTC and would otherwise hash to the wrong day).
   */
  dayDate: z.string(),
  startAt: z.string(), // ISO
  endAt: z.string(),   // ISO
  leaveCode: z.object({
    id: z.string().uuid(),
    code: z.string(),
    label: z.string(),
    uiColor: z.string(),
  }),
  sourceImportRunId: z.string().uuid(),
});
export type BoardCell = z.infer<typeof BoardCellSchema>;

export const BoardMemberSchema = z.object({
  id: z.string().uuid(),
  employeeId: z.string(),
  lastName: z.string(),
  firstName: z.string(),
  rank: z
    .object({ id: z.string().uuid(), code: z.string(), label: z.string() })
    .nullable(),
  shift: z.string().nullable(),
  aDayGroup: z
    .object({ id: z.string().uuid(), code: z.string(), label: z.string() })
    .nullable(),
  isProbationary: z.boolean(),
});
export type BoardMember = z.infer<typeof BoardMemberSchema>;

export const BoardResponseSchema = z.object({
  members: z.array(BoardMemberSchema),
  cells: z.array(BoardCellSchema),
  dateRange: z.object({ from: z.string(), to: z.string() }),
  pagination: z.object({
    page: z.number().int().min(1),
    pageSize: z.number().int().min(1),
    totalMembers: z.number().int().nonnegative(),
  }),
});
export type BoardResponse = z.infer<typeof BoardResponseSchema>;

export const BoardFiltersSchema = z.object({
  shift: z.array(z.string()).optional(),
  rank: z.array(z.string()).optional(),
  from: z.string().optional(),
  to: z.string().optional(),
  onlyWithLeave: z.boolean().optional(),
  page: z.coerce.number().int().min(1).default(1),
  pageSize: z.coerce.number().int().min(1).max(500).default(100),
});
export type BoardFilters = z.infer<typeof BoardFiltersSchema>;
