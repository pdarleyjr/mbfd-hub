import type { Tone } from '../ui/status';
import { humanize } from '../ui/status';
import { NO_ACTION_STATUS } from './actionForms';
import type { IssueSummary, OnDuty } from './types';

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

export function statusOf(issue: Pick<IssueSummary, 'action_status' | 'action_label'>): { label: string; tone: Tone } {
  return STATUS[issue.action_status] ?? { label: issue.action_label || humanize(issue.action_status), tone: 'neutral' };
}

export const statusLabel = (status: string | null | undefined): string => (status ? STATUS[status]?.label ?? humanize(status) : 'Not recorded');

export const kindLabel = (kind: string): string => (kind === 'medical' ? 'Medical' : 'Rescue');

const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

/** "5 min ago", "3 h ago", "2 d ago": coarse on purpose, with the exact time in a title/aria-label. */
export function ageText(iso: string | null | undefined, now = Date.now()): string {
  if (!iso) return 'Unknown time';
  const elapsed = now - new Date(iso).getTime();
  if (!Number.isFinite(elapsed)) return 'Unknown time';
  if (elapsed < MINUTE) return 'just now';
  if (elapsed < HOUR) return `${Math.floor(elapsed / MINUTE)} min ago`;
  if (elapsed < DAY) return `${Math.floor(elapsed / HOUR)} h ago`;
  return `${Math.floor(elapsed / DAY)} d ago`;
}

export function dateTimeText(iso: string | null | undefined): string {
  if (!iso) return 'Unknown time';
  const date = new Date(iso);
  return Number.isNaN(date.getTime())
    ? 'Unknown time'
    : date.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

/** A Y-m-d duty or delivery date, shown without any time-zone shift. */
export function dayText(ymd: string | null | undefined): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd ?? '');
  if (!match) return ymd || 'No date';
  const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
  return date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
}

export function todayYmd(now = new Date()): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/** Whole days between two Y-m-d dates (b - a). */
export function daysBetween(a: string, b: string): number {
  const toUtc = (ymd: string) => { const [y, m, d] = ymd.split('-').map(Number); return Date.UTC(y, m - 1, d); };
  return Math.round((toUtc(b) - toUtc(a)) / DAY);
}

export const DUTY_UNSET = 'Shift Captain 5 not set';

/**
 * The line at the top of the page and on the station-list card: "A Shift Captain 5: Oscar Lima", or
 * "A Shift Acting Captain 5: Oscar Lima until Oct 24, 8:00 AM" for an approved acting member. The name
 * is whatever the server resolved; nothing here knows a person.
 */
export function dutyLine(onDuty: OnDuty): string {
  if (!onDuty.resolved || !onDuty.name) return DUTY_UNSET;
  const shift = onDuty.shift ? `${onDuty.shift} Shift ` : '';
  if (onDuty.source === 'acting') {
    const until = onDuty.acting_until ? ` until ${dateTimeText(onDuty.acting_until)}` : '';
    return `${shift}Acting Captain 5: ${onDuty.name}${until}`;
  }
  return `${shift}Captain 5: ${onDuty.name}`;
}

const SHIFT_TONE: Record<string, Tone> = { A: 'success', B: 'info', C: 'danger' };

/** A is green, B is blue, C is red. The text always names the shift, so colour never carries it alone. */
export function dutyTone(onDuty: OnDuty): Tone {
  if (!onDuty.resolved || !onDuty.name || !onDuty.shift) return 'neutral';
  return SHIFT_TONE[onDuty.shift] ?? 'neutral';
}

/** Rescue-first, then newest: the order the queue is read in. */
export function sortQueue(issues: IssueSummary[]): IssueSummary[] {
  const weight = (issue: IssueSummary) => (issue.overdue ? 0 : issue.severity === 'significant' ? 1 : issue.action_status === 'reported' ? 2 : 3);
  return [...issues].sort((a, b) => weight(a) - weight(b) || b.id - a.id);
}

/** Replacement tracking: overdue first, then ready for pickup, then soonest expected delivery. */
export function sortReplacements(issues: IssueSummary[]): IssueSummary[] {
  const rank = (issue: IssueSummary) => (issue.overdue ? 0 : issue.action_status === 'replacement_available' ? 1 : 2);
  return [...issues].sort((a, b) => rank(a) - rank(b)
    || (a.expected_delivery ?? '9999-12-31').localeCompare(b.expected_delivery ?? '9999-12-31')
    || b.id - a.id);
}

export const isReplacementTracked = (issue: IssueSummary): boolean => issue.open
  && (issue.action_status === 'action_pending' || issue.action_status === 'replacement_available' || issue.overdue);

/** Version 4 UUID; crypto.randomUUID needs a secure context, so fall back to getRandomValues. */
export function newKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
