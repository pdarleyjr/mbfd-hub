import type { Tone } from '../ui/status';
import type { IssueSummary } from './types';

export interface IssueGroup {
  apparatusId: number;
  unit: string;
  /** In queue order, so the most urgent issue of the unit comes first. */
  issues: IssueSummary[];
  worst: { label: string; tone: Tone };
  oldestReportedAt: string | null;
}

const WORST_ORDER: Array<{ test: (issue: IssueSummary) => boolean; label: string; tone: Tone }> = [
  { test: (issue) => issue.overdue, label: 'Overdue', tone: 'danger' },
  { test: (issue) => issue.severity === 'significant', label: 'Significant damage', tone: 'danger' },
  { test: (issue) => issue.awaiting_verification, label: 'Awaiting verification', tone: 'warning' },
  { test: (issue) => issue.action_status === 'reported', label: 'Needs acknowledgement', tone: 'warning' },
  { test: (issue) => issue.action_status === 'replacement_available', label: 'Ready for pickup', tone: 'success' },
  { test: (issue) => issue.action_status === 'action_pending', label: 'Ordered', tone: 'info' },
];

function worstOf(issues: IssueSummary[]): IssueGroup['worst'] {
  const found = WORST_ORDER.find((rule) => issues.some(rule.test));
  return found ? { label: found.label, tone: found.tone } : { label: 'In progress', tone: 'info' };
}

function oldestOf(issues: IssueSummary[]): string | null {
  const times = issues.map((issue) => issue.first_reported_at).filter((at): at is string => at !== null && !Number.isNaN(Date.parse(at)));
  return times.length === 0 ? null : times.reduce((oldest, at) => (Date.parse(at) < Date.parse(oldest) ? at : oldest));
}

/**
 * One group per unit, in the order the sorted queue first reaches each unit: the unit holding the most urgent
 * issue leads, and a filter that empties a unit removes its group.
 */
export function groupByUnit(sorted: IssueSummary[]): IssueGroup[] {
  const byUnit = new Map<number, IssueSummary[]>();
  for (const issue of sorted) {
    byUnit.set(issue.apparatus_id, [...(byUnit.get(issue.apparatus_id) ?? []), issue]);
  }
  return [...byUnit.entries()].map(([apparatusId, issues]) => ({
    apparatusId,
    unit: issues[0].unit,
    issues,
    worst: worstOf(issues),
    oldestReportedAt: oldestOf(issues),
  }));
}
