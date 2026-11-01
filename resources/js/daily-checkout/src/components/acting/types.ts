/**
 * Shapes shared by every "who is filling this role today" surface: the Captain 5 workspace
 * (/api/captain5/*) and the 300 page (/api/command-300/*).
 */

export type ShiftLetter = 'A' | 'B' | 'C';

export type DutyFreshness = 'current' | 'awaiting' | 'delayed' | 'not_applicable';

export type DutyReason = 'conflict' | 'needs_review' | 'no_working_row' | 'section_missing';

export interface OnDuty {
  duty_date: string;
  shift: ShiftLetter | null;
  /** 'telestaff' is today's roster; 'bid_holder' is the shift's bid holder. */
  source: 'bid_holder' | 'telestaff' | 'unknown';
  resolved: boolean;
  name: string | null;
  rank: string | null;
  // The next four are absent from a server that does not read the roster, and then no note is shown.
  /** ISO time of the roster behind this answer. */
  as_of?: string | null;
  freshness?: DutyFreshness;
  /** Whether the roster's person matched a Hub member. */
  identity?: 'linked' | 'unlinked' | null;
  /** Why the roster did not name one person, or why the seat is shown from a valid roster. */
  reason?: DutyReason | null;
}

/** Answer of `GET /api/command-300/shift`. */
export interface ShiftResult {
  on_duty: OnDuty;
}

/** How a role reads on the shift line. */
export interface DutyRole {
  /** How the role reads in a sentence: "Captain 5" or "300". */
  label: string;
  /** Show the member's rank before the name on the shift line. */
  withRank: boolean;
}
