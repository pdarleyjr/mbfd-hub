/**
 * Canonical map from the descriptions Telestaff emits in the `Description`
 * column of its "Export All Records" report to the short leave-code values
 * we seed in `leave_codes`. Used by both the bootstrap loader (one-shot
 * load on first deploy) and the commit pipeline so admin uploads don't
 * have to re-classify codes that are already well-known to us.
 *
 * Categories we ingest into `leave_entries`:
 *   - "Leave"        — the person is OFF this block. Always ingested.
 *   - "Straight Pay" — depending on description, may be off-shift in school
 *                      or training. Ingested when it represents off-shift.
 *
 * Categories we deliberately SKIP for the vacation board (they represent
 * people working extra, not time off):
 *   - "Overtime"     — OT shift. Does not represent a leave entry.
 *   - "Incentive"    — IAFF OOC/ROC/etc — these are pay codes for an
 *                       on-duty assignment, not absences.
 */

export type TelestaffCategory = 'Leave' | 'Straight Pay' | 'Overtime' | 'Incentive';

export const SKIP_CATEGORIES: ReadonlySet<TelestaffCategory> = new Set([
  'Overtime',
  'Incentive',
]);

/**
 * Description → seeded leave-code `code`. If a description is missing we
 * fall back to one of two rules (see classifyDescription) before giving up.
 */
export const TELESTAFF_DESCRIPTION_MAP: ReadonlyMap<string, string> = new Map(
  Object.entries({
    // Vacation family (work code 300/301)
    'LV - Vacation': 'V',
    'LV - Vacation Annual': 'V',
    'LV - FML Vacation': 'V',
    'LV - Emergency Vacation': 'EV',

    // Sick family (305/306/317)
    'LV - Sick': 'S',
    'LV - Sick Pending FML': 'S',
    'LV - FML Sick': 'S',
    'LV - Parental Leave Paid 50': 'PL',

    // Floating Holiday family (310)
    'LV - Floating Holiday 08 Hour': 'FH',
    'LV - Floating Holiday 10 Hour': 'FH',
    'LV - Floating Holiday 24 Hour': 'FH',
    'LV - Floating Holiday Annual': 'FH',
    'LV - Emergency Floater': 'EF',

    // Alternate Holiday family (330/335)
    'LV - Alternate Holiday Days Accrued': 'AH',
    'LV - Alternate Holiday Days Taken': 'AH',
    'LV - Alternate Holiday Shift Taken': 'AH',
    'LV - Alternate Holiday Taken Annual': 'AH',

    // Administrative / Misc Leave
    'LV - Administrative Leave Shift': 'ADMIN',
    'LV - Holiday Off': 'HO',
    'LV - Bereavement': 'BRV',
    'LV - Union Leave IAFF': 'UNION',

    // Straight Pay we treat as off-shift (member is in training)
    'SP - Paramedic School': 'PMSCH',
  }),
);

/**
 * Seed metadata for leave codes the bootstrap may need to create on first
 * run if they aren't already in the table. Matches the shape used by the
 * existing seed script.
 */
export type BootstrapLeaveCode = {
  code: string;
  label: string;
  uiColor: string;
  countsAgainstVacationBalance: boolean;
  countsAgainstFloatingBalance: boolean;
  countsAgainstDailyVacationCapacity: boolean;
  countsAgainstMinimumStaffing: boolean;
  isADayMarker: boolean;
};

export const BOOTSTRAP_NEW_LEAVE_CODES: ReadonlyArray<BootstrapLeaveCode> = [
  {
    code: 'PL',
    label: 'Parental Leave (Paid 50)',
    uiColor: '#0EA5E9',
    countsAgainstVacationBalance: false,
    countsAgainstFloatingBalance: false,
    countsAgainstDailyVacationCapacity: false,
    countsAgainstMinimumStaffing: true,
    isADayMarker: false,
  },
  {
    code: 'ADMIN',
    label: 'Administrative Leave',
    uiColor: '#6366F1',
    countsAgainstVacationBalance: false,
    countsAgainstFloatingBalance: false,
    countsAgainstDailyVacationCapacity: false,
    countsAgainstMinimumStaffing: true,
    isADayMarker: false,
  },
  {
    code: 'BRV',
    label: 'Bereavement',
    uiColor: '#64748B',
    countsAgainstVacationBalance: false,
    countsAgainstFloatingBalance: false,
    countsAgainstDailyVacationCapacity: false,
    countsAgainstMinimumStaffing: true,
    isADayMarker: false,
  },
  {
    code: 'UNION',
    label: 'Union Leave (IAFF)',
    uiColor: '#9333EA',
    countsAgainstVacationBalance: false,
    countsAgainstFloatingBalance: false,
    countsAgainstDailyVacationCapacity: false,
    countsAgainstMinimumStaffing: true,
    isADayMarker: false,
  },
  {
    code: 'PMSCH',
    label: 'Paramedic School',
    uiColor: '#0891B2',
    countsAgainstVacationBalance: false,
    countsAgainstFloatingBalance: false,
    countsAgainstDailyVacationCapacity: false,
    countsAgainstMinimumStaffing: true,
    isADayMarker: false,
  },
];

/**
 * Resolve a Telestaff description to a known leave_codes.code, falling back
 * to a heuristic on the LV/OT/SP prefix. Returns null if unresolvable so the
 * commit pipeline can surface it for admin review.
 */
export function classifyDescription(
  description: string,
  category?: string,
): string | null {
  const exact = TELESTAFF_DESCRIPTION_MAP.get(description);
  if (exact) return exact;
  // SKIP categories explicitly: caller should drop the row.
  if (category && SKIP_CATEGORIES.has(category as TelestaffCategory)) return null;
  // Heuristic on the prefix.
  const m = /^(LV|OT|SP)\s*-\s*(.+)$/i.exec(description.trim());
  if (!m) return null;
  const tail = m[2]!.toLowerCase();
  if (tail.includes('vacation')) return 'V';
  if (tail.includes('sick')) return 'S';
  if (tail.includes('floating') || tail.includes('floater')) return 'FH';
  if (tail.includes('holiday off')) return 'HO';
  if (tail.includes('alternate holiday')) return 'AH';
  if (tail.includes('bereav')) return 'BRV';
  if (tail.includes('union')) return 'UNION';
  if (tail.includes('admin')) return 'ADMIN';
  return null;
}
