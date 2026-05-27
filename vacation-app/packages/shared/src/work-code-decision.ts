import { z } from 'zod';

/**
 * Sent on commit. The admin's resolution for each unknown event_description
 * we encountered during preview.
 *
 * - 'use_existing' attaches the description to an existing leave_code by id
 * - 'create_new' creates a new leave_code with the provided spec
 * - 'skip' ignores any row carrying this description
 */
export const WorkCodeDecisionSchema = z.discriminatedUnion('kind', [
  z.object({
    kind: z.literal('use_existing'),
    telestaffDescription: z.string(),
    leaveCodeId: z.string().uuid(),
  }),
  z.object({
    kind: z.literal('create_new'),
    telestaffDescription: z.string(),
    newCode: z.object({
      code: z.string().min(1).max(8),
      label: z.string().min(1).max(64),
      uiColor: z
        .string()
        .regex(/^#[0-9A-Fa-f]{6}$/, 'must be a 6-digit hex color')
        .default('#78716C'),
      countsAgainstVacationBalance: z.boolean().default(false),
      countsAgainstFloatingBalance: z.boolean().default(false),
      countsAgainstDailyVacationCapacity: z.boolean().default(false),
      countsAgainstMinimumStaffing: z.boolean().default(false),
      isADayMarker: z.boolean().default(false),
    }),
  }),
  z.object({
    kind: z.literal('skip'),
    telestaffDescription: z.string(),
  }),
]);
export type WorkCodeDecision = z.infer<typeof WorkCodeDecisionSchema>;

export const WorkCodeDecisionsSchema = z.array(WorkCodeDecisionSchema);
export type WorkCodeDecisions = z.infer<typeof WorkCodeDecisionsSchema>;
