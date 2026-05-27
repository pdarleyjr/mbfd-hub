import { ColumnTarget, type ColumnMapping } from '@mbfd-vacation/shared';

type Pattern = { target: ColumnTarget; substrings: string[] };

/**
 * The patterns we look for, in priority order. Higher entries win.
 */
const PATTERNS: Pattern[] = [
  { target: 'employee_id',        substrings: ['employee_id', 'employeeid', 'emp_id', 'empid', 'emp id', 'employee number', 'employee #', 'payroll id', 'payroll #', 'pernr'] },
  { target: 'badge_number',       substrings: ['badge', 'badge_num', 'badge number'] },
  { target: 'last_name',          substrings: ['last name', 'last_name', 'lastname', 'surname'] },
  { target: 'first_name',         substrings: ['first name', 'first_name', 'firstname', 'given name'] },
  { target: 'full_name',          substrings: ['name', 'full name', 'full_name', 'employee name'] },
  { target: 'rank',               substrings: ['position rank', 'reporting rank', 'rank', 'position', 'title', 'class'] },
  { target: 'shift',              substrings: ['shift', 'platoon', 'crew'] },
  { target: 'a_day_group',        substrings: ['a-day', 'a day', 'aday', 'r-day', 'r day', 'rday', 'cycle'] },
  { target: 'hire_date',          substrings: ['hire date', 'hire_date', 'seniority date', 'start date'] },
  { target: 'event_datetime',     substrings: ['start datetime', 'start_datetime', 'start time', 'start_time', 'event start', 'start', 'from datetime', 'from_datetime', 'datetime', 'date_time', 'date'] },
  { target: 'event_end_datetime', substrings: ['end datetime', 'end_datetime', 'end time', 'end_time', 'event end', 'end', 'thru', 'to datetime'] },
  { target: 'event_description',  substrings: ['description', 'work code description', 'work description', 'paycode description', 'event description', 'reason'] },
  { target: 'event_work_code',    substrings: ['work code', 'work_code', 'paycode', 'pay code', 'event code', 'code'] },
];

function normalize(s: string): string {
  return s.toLowerCase().replace(/[_\s-]+/g, ' ').trim();
}

function score(header: string, pattern: Pattern): number {
  const h = normalize(header);
  let best = 0;
  // Earlier needles within a pattern carry higher priority — this lets us
  // disambiguate between two headers that both score equally on substring
  // match (e.g. Telestaff exports include both "Date" and "Start"; the
  // shift block math needs the Start column, so 'start' precedes 'date').
  for (let i = 0; i < pattern.substrings.length; i++) {
    const n = normalize(pattern.substrings[i]!);
    const priorityBonus = pattern.substrings.length - i;
    if (h === n) return 100 + priorityBonus;
    if (h.includes(n)) {
      best = Math.max(
        best,
        80 - Math.max(0, h.length - n.length) + priorityBonus,
      );
    }
  }
  return best;
}

/**
 * Infer a ColumnMapping for the given header list.
 *
 * Each header is assigned to its highest-scoring target. If two headers want
 * the same target, the higher-scoring one wins; the loser becomes 'ignore'.
 * Headers with no good match become 'ignore'.
 */
export function inferColumnMapping(headers: string[]): ColumnMapping {
  type Choice = { idx: number; target: ColumnTarget; score: number };
  const choices: Choice[] = [];

  for (let i = 0; i < headers.length; i++) {
    const header = headers[i];
    if (!header) continue;
    let bestTarget: ColumnTarget = 'ignore';
    let bestScore = 0;
    for (const pattern of PATTERNS) {
      const s = score(header, pattern);
      if (s > bestScore) {
        bestScore = s;
        bestTarget = pattern.target;
      }
    }
    choices.push({ idx: i, target: bestTarget, score: bestScore });
  }

  // Resolve conflicts: at most one header per target (except 'ignore').
  const usedTargets = new Set<ColumnTarget>();
  const ordered = [...choices].sort((a, b) => b.score - a.score);
  const finalTargets: ColumnTarget[] = headers.map(() => 'ignore');
  for (const c of ordered) {
    if (c.target === 'ignore' || c.score < 30) {
      finalTargets[c.idx] = 'ignore';
      continue;
    }
    if (usedTargets.has(c.target)) {
      finalTargets[c.idx] = 'ignore';
    } else {
      finalTargets[c.idx] = c.target;
      usedTargets.add(c.target);
    }
  }

  return {
    columns: headers.map((h, i) => ({
      sourceHeader: h,
      target: finalTargets[i] ?? 'ignore',
    })),
  };
}
