import { HUB_TIMEZONE } from '../../../shared/timezone.ts';
import type { IssueSummary, LiveFindingsState } from '../types/issueHistory.ts';
import type { Tone } from '../components/ui/status.ts';
import { formatReportedDate } from './previousFindings.ts';

/** What the member reads about an issue and how current it is. Pure, so the wording is tested without a browser. */

export const NOT_RECORDED = 'Not recorded';

const parsed = (value: string | null | undefined): Date | null => {
  if (!value) return null;
  const date = new Date(value);

  return Number.isNaN(date.getTime()) ? null : date;
};

const localDay = (date: Date): string => date.toLocaleDateString('en-US', { timeZone: HUB_TIMEZONE });

/** "Nov 1, 2026" in the department time zone; null when absent or unreadable. */
export const formatEventDate = formatReportedDate;

/** "Nov 1, 2026, 2:14 PM" in the department time zone. */
export function formatEventDateTime(value: string | null | undefined): string | null {
  const date = parsed(value);

  return date ? date.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: HUB_TIMEZONE }) : null;
}

/** The time alone when it is today, otherwise the date and time. */
export function formatWhen(value: string | null | undefined, now: Date = new Date()): string | null {
  const date = parsed(value);
  if (!date) return null;
  const time = date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', timeZone: HUB_TIMEZONE });

  return localDay(date) === localDay(now) ? time : `${date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', timeZone: HUB_TIMEZONE })}, ${time}`;
}

export const openIssueCountText = (count: number): string => (
  count === 0 ? 'No open issues on this apparatus' : `${count} open ${count === 1 ? 'issue' : 'issues'} on this apparatus`
);

export const closedIssuesHeading = (count: number): string => `Earlier closed issues (${count})`;

/** "Reported by Dana Ortiz on Oct 30, 2026". A missing part reads "Not recorded"; nothing is invented. */
export function reportedByLine(issue: Pick<IssueSummary, 'reporter_name' | 'first_reported_at'>): string {
  const date = formatEventDate(issue.first_reported_at);
  if (issue.reporter_name === null && date === null) return `Reported by ${NOT_RECORDED}`;

  return `Reported by ${issue.reporter_name ?? NOT_RECORDED} on ${date ?? NOT_RECORDED}`;
}

export type IssuePhase = 'active' | 'resolved' | 'closed';

export interface IssuePresentation {
  phase: IssuePhase;
  /** The status tag: text first, tone second. */
  label: string;
  tone: Tone;
  /** The server's own wording for how it ended, when it says more than the tag. */
  detail: string | null;
}

const REOPENED = /reopen/i;

/**
 * Resolved and Closed with no action are different outcomes and read differently. An issue the server sends as
 * closed but reopened since is active again, so it never shows as closed.
 */
export function issuePresentation(issue: Pick<IssueSummary, 'closure_kind' | 'action_status' | 'action_label' | 'severity'>): IssuePresentation {
  const wording = issue.action_label.trim();
  const reopened = REOPENED.test(issue.action_status) || REOPENED.test(wording);
  if (issue.closure_kind === null || reopened) {
    return { phase: 'active', label: wording !== '' ? wording : 'Open', tone: issue.severity === 'significant' ? 'danger' : 'warning', detail: null };
  }
  const resolved = issue.closure_kind === 'resolved';
  const own = resolved ? /^resolved$/i : /^closed(?:,? (?:with )?no action(?: taken)?)?$/i;

  return {
    phase: resolved ? 'resolved' : 'closed',
    label: resolved ? 'Resolved' : 'Closed with no action',
    tone: resolved ? 'success' : 'neutral',
    detail: wording !== '' && !own.test(wording) ? wording : null,
  };
}

export interface LiveStatusLine {
  text: string;
  /** warn when the member should not treat the list as current. */
  tone: 'ok' | 'muted' | 'warn';
}

/** The line under the open-issue count: how current the list is, in plain words. */
export function liveStatusLine(state: LiveFindingsState, asOf: string | null, offline: boolean, now: Date = new Date()): LiveStatusLine {
  const when = formatWhen(asOf, now);
  if (state === 'live') return { text: when ? `Updated ${when}` : 'Updated just now', tone: 'ok' };
  if (state === 'loading') return { text: 'Checking for updates...', tone: 'muted' };
  if (state === 'stale') {
    const snapshot = when ? `the issues last saved ${when}` : 'the issues last saved on this device';

    return { text: offline ? `Offline. Showing ${snapshot}.` : `Could not refresh. Showing ${snapshot}.`, tone: 'warn' };
  }

  return { text: offline ? 'Offline. Live issue history is not available.' : 'Live issue history could not be loaded.', tone: 'warn' };
}
