import type { Tone } from '../ui/status';
import { humanize } from '../ui/status';
import { dutyLine as roleDutyLine, dutyUnset } from '../acting/format';
import { NO_ACTION_STATUS } from './actionForms';
import type { IssueSummary, OnDuty } from './types';
import { hubDateInput } from '../../../../shared/timezone.ts';

/** Status wording for Captain 5. `reported` is "needs acknowledgement" here, not the member-facing label. */
const STATUS: Record<string, { label: string; tone: Tone }> = {
  reported: { label: 'Needs acknowledgement', tone: 'warning' },
  acknowledged: { label: 'Acknowledged', tone: 'info' },
  action_pending: { label: 'Ordered', tone: 'info' },
  replacement_available: { label: 'Ready for pickup', tone: 'success' },
  repair_scheduled: { label: 'Repair scheduled', tone: 'info' },
  resolved: { label: 'Resolved', tone: 'success' },
  // Not 'Resolved': nothing was repaired or replaced.
  dismissed: { label: NO_ACTION_STATUS, tone: 'neutral' },
};

export function statusOf(issue: Pick<IssueSummary, 'action_status' | 'action_label' | 'action_by'>): { label: string; tone: Tone } {
  const status = STATUS[issue.action_status] ?? { label: issue.action_label || humanize(issue.action_status), tone: 'neutral' };
  const who = issue.action_by?.trim();
  if (!who) return status;
  if (issue.action_status === 'reported') return { ...status, label: `${status.label} · reported by ${who}` };
  return { ...status, label: READS_AS_BY.has(issue.action_status) ? `${status.label} by ${who}` : `${status.label} · ${who}` };
}

/** Statuses that read naturally as "<status> by <name>"; the rest show "<status> · <name>". */
const READS_AS_BY = new Set(['acknowledged', 'action_pending', 'repair_scheduled', 'resolved']);

export const statusLabel = (status: string | null | undefined): string => (status ? STATUS[status]?.label ?? humanize(status) : 'Not recorded');

export const kindLabel = (kind: string): string => (kind === 'medical' ? 'Medical' : 'Rescue');

const DAY = 24 * 60 * 60_000;

// Time wording, the idempotency key and the tone of the shift line are shared with the 300 page (../acting/format).
export { ageText, dateTimeText, dutyTone, newKey } from '../acting/format';

/** A Y-m-d duty or delivery date, shown without any time-zone shift. */
export function dayText(ymd: string | null | undefined): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd ?? '');
  if (!match) return ymd || 'No date';
  const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
  return date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
}

export function todayYmd(now = new Date()): string {
  return hubDateInput(now);
}

/** Whole days between two Y-m-d dates (b - a). */
export function daysBetween(a: string, b: string): number {
  const toUtc = (ymd: string) => { const [y, m, d] = ymd.split('-').map(Number); return Date.UTC(y, m - 1, d); };
  return Math.round((toUtc(b) - toUtc(a)) / DAY);
}

const CAPTAIN_5_DUTY = { label: 'Captain 5', withRank: false } as const;

export const DUTY_UNSET = dutyUnset(CAPTAIN_5_DUTY);

/**
 * Captain 5's shift line: "A Shift Captain 5: Oscar Lima". The wording is built in ../acting/format, shared
 * with the 300 page; Captain 5 shows no rank.
 */
export const dutyLine = (onDuty: OnDuty): string => roleDutyLine(onDuty, CAPTAIN_5_DUTY);

/** Rescue-first, then newest: the order the queue is read in. */
export function sortQueue(issues: IssueSummary[]): IssueSummary[] {
  const weight = (issue: IssueSummary) => (issue.overdue ? 0 : issue.severity === 'significant' ? 1 : issue.action_status === 'reported' ? 2 : 3);
  return [...issues].sort((a, b) => weight(a) - weight(b) || b.id - a.id);
}
