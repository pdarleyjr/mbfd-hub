import { z } from 'zod';

/**
 * The full set of configurable thresholds + lookup tables that drive the
 * Daily Shift Staffing Guidelines (v1.13, 12/1/25) decision engine.
 *
 * Stored as a single JSONB blob in `staffing_rules.rules_json` so admins
 * can edit any setting from /admin/rules without a redeploy. The default
 * values match the published guidelines exactly.
 */
export const StaffingRulesSchema = z.object({
  /** Hard floor for total on-duty personnel (incl. Marine 6). */
  minDailyStaffing: z.number().int().nonnegative(),
  /**
   * Above this count the detached Air Technician position gets staffed
   * and prescheduled leave becomes restricted until the local hour given
   * by `airTechNoLeaveUntilLocalHour`.
   */
  airTechTrigger: z.number().int().nonnegative(),
  /** Personnel must be at or above this for prescheduled leave to be granted. */
  minPreScheduleStaffing: z.number().int().nonnegative(),
  /**
   * When the Air Tech position is staffed, no scheduled leave is
   * permitted until this local Miami hour (default 20 = 8 PM).
   */
  airTechNoLeaveUntilLocalHour: z.number().int().min(0).max(23),
  /**
   * Marine Station Firefighter vacation cap — max FFs off at the same
   * time on a given day, including A-Days. From the Marine Station
   * Vacation Caps table.
   */
  marineFirefighterOffCap: z.number().int().nonnegative(),
  /** Calendar-year cap on exchange shifts (banked + owed combined). */
  exchangeShiftsCapPerYear: z.number().int().nonnegative(),
  /** Calendar-year cap on exchange hours (banked + owed combined). */
  exchangeHoursCapPerYear: z.number().int().nonnegative(),
  /**
   * Per-rank allow-list: who can a member of rank `K` exchange a regular
   * shift with? Driven off the rank codes used elsewhere in the schema
   * (FF, FF-DE, LT, CAPT, DC, DDC, CHIEF).
   */
  rankPairingRules: z.record(z.string(), z.array(z.string())),
  /**
   * Per-bucket allow-list for A-Day exchanges (officers vs firefighters).
   */
  aDayExchangePairingRules: z.object({
    officers: z.array(z.string()),
    firefighters: z.array(z.string()),
  }),
  /**
   * Leave-code `code` values the engine treats as an exchange when
   * computing the per-year exchange caps. Default ["XOFF","EON"].
   */
  exchangeLeaveCodes: z.array(z.string()),
  /**
   * Which `station` value identifies Marine Station 6 personnel. Lets
   * admins rename the station label without touching the engine.
   */
  marineStationKey: z.string(),
  /**
   * Enumerated `station` values offered in the member-edit UI. Free-text
   * is rejected so we don't end up with typos splitting the FF roster.
   */
  stationOptions: z.array(z.string()),
  /** Enumerated certification slugs offered in the member-edit UI. */
  certificationOptions: z.array(z.string()),
});

export type StaffingRules = z.infer<typeof StaffingRulesSchema>;

/**
 * Defaults shipped in migration 0002. Kept in code so the engine has a
 * sane fallback if `staffing_rules` is somehow empty (e.g. fresh DB
 * before migration completes).
 */
export const DEFAULT_STAFFING_RULES: StaffingRules = {
  minDailyStaffing: 51,
  airTechTrigger: 52,
  minPreScheduleStaffing: 53,
  airTechNoLeaveUntilLocalHour: 20,
  marineFirefighterOffCap: 2,
  exchangeShiftsCapPerYear: 10,
  exchangeHoursCapPerYear: 240,
  rankPairingRules: {
    FF: ['FF', 'FF-DE'],
    'FF-DE': ['FF-DE', 'FF'],
    LT: ['LT'],
    CAPT: ['CAPT'],
    DC: ['DC', 'CAPT'],
    DDC: ['DDC', 'DC', 'CAPT'],
    CHIEF: ['CHIEF', 'DDC', 'DC'],
  },
  aDayExchangePairingRules: {
    officers: ['LT', 'CAPT', 'DC', 'DDC', 'CHIEF'],
    firefighters: ['FF', 'FF-DE'],
  },
  exchangeLeaveCodes: ['XOFF', 'EON'],
  marineStationKey: 'M6',
  stationOptions: ['S1', 'S2', 'S3', 'S4', 'S5', 'M6', 'FLOAT', 'HQ', 'OTHER'],
  certificationOptions: [
    'DE',
    'AT',
    'M6_QUAL',
    'M6_CAPT_QUAL',
    'PARAMEDIC',
    'PROMO_CAPT',
    'PROMO_LT',
  ],
};

/** One reason returned by the engine. Multiple reasons may stack. */
export const DecisionReasonSchema = z.object({
  rule: z.enum([
    'min_preschedule_staffing',
    'air_tech_no_leave_until',
    'marine_firefighter_cap',
    'exchange_shifts_cap',
    'exchange_hours_cap',
    'rank_pairing',
    'a_day_pairing',
    'de_pairing',
    'at_pairing',
    'data_missing',
    'over_chief_only',
  ]),
  ok: z.boolean(),
  message: z.string(),
  /** Free-form numeric/string detail used in tooltips. */
  detail: z.record(z.string(), z.union([z.number(), z.string(), z.boolean(), z.null()])).optional(),
});

export type DecisionReason = z.infer<typeof DecisionReasonSchema>;

/**
 * Output of the engine for one (member, date, block, code) query.
 *
 * - `grant`: all rules pass
 * - `grant_after_2000`: passes if requested after the local hour (Air Tech rule)
 * - `requires_chief_override`: would be denied but the guidelines allow
 *   the Fire Chief to authorize in writing (e.g. over exchange cap)
 * - `deny`: hard deny per the guidelines
 */
export const DecisionResultSchema = z.object({
  decision: z.enum(['grant', 'grant_after_2000', 'requires_chief_override', 'deny']),
  reasons: z.array(DecisionReasonSchema),
  context: z.object({
    memberId: z.string(),
    memberLabel: z.string(),
    shift: z.string().nullable(),
    rankCode: z.string().nullable(),
    station: z.string().nullable(),
    dayDate: z.string(),
    blockIndex: z.number().int().min(0).max(1),
    leaveCode: z.string(),
    staffingForBlock: z.number().int(),
    marineFfOffOnDay: z.number().int(),
  }),
});

export type DecisionResult = z.infer<typeof DecisionResultSchema>;

export const DecisionRequestSchema = z.object({
  memberId: z.string().uuid(),
  dayDate: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
  blockIndex: z.number().int().min(0).max(1),
  leaveCode: z.string().min(1),
  /** For exchanges: the other party. Optional for non-exchange checks. */
  exchangePartnerId: z.string().uuid().optional(),
  /** Local Miami hour the leave would START at (24h). Optional. */
  requestedLocalStartHour: z.number().int().min(0).max(23).optional(),
});

export type DecisionRequest = z.infer<typeof DecisionRequestSchema>;

/** Per-leave-code aggregate for one member, used by the drawer + /grant. */
export const LeaveBalanceLineSchema = z.object({
  leaveCodeId: z.string().uuid(),
  code: z.string(),
  label: z.string(),
  uiColor: z.string(),
  entries: z.number().int().nonnegative(),
  hours: z.number().nonnegative(),
});

export type LeaveBalanceLine = z.infer<typeof LeaveBalanceLineSchema>;
