import type { ClosedIssuesPage, FindingsSnapshot, IssueHistory } from '../types/issueHistory.ts';
import { createRequester } from './captain5Api';
import { parseClosedPage, parseFindingsResponse, parseIssueHistory } from './issueHistoryParse.ts';

/**
 * The member API for apparatus issue history. Same fetch, session and error handling as the other member
 * workspaces (`status` 0 means no answer: offline, dropped or timed out). A reply of the wrong shape is an error,
 * so the caller keeps what is on screen instead of replacing it with nothing.
 */
const request = createRequester('/api/public', 'Your account cannot see the issue history for this apparatus.');

const BAD_ANSWER = 'The issue history came back in a form that could not be read.';

/** Why a read failed, in words for the screen. These are reads: nothing the member typed is at stake. */
export function describeIssueHistoryError(error: unknown, what = 'The issue history'): string {
  const status = (error as { status?: unknown } | null)?.status;
  if (status === 0) return typeof navigator !== 'undefined' && navigator.onLine === false ? 'You are offline. Reconnect to load this.' : 'The server could not be reached. Try again.';
  if (status === 401) return 'Your session has ended. Sign in again.';
  if (status === 403) return 'Your account cannot see the issue history for this apparatus.';

  return `${what} could not be loaded. Try again.`;
}

export async function fetchFindings(apparatusId: number): Promise<FindingsSnapshot> {
  const snapshot = parseFindingsResponse(await request<unknown>(`/apparatuses/${apparatusId}/findings`), new Date().toISOString());
  if (snapshot === null) throw new Error(BAD_ANSWER);

  return snapshot;
}

export async function fetchClosedIssues(apparatusId: number, cursor: string | null): Promise<ClosedIssuesPage> {
  const query = cursor ? `?cursor=${encodeURIComponent(cursor)}` : '';
  const page = parseClosedPage(await request<unknown>(`/apparatuses/${apparatusId}/findings/closed${query}`));
  if (page === null) throw new Error(BAD_ANSWER);

  return page;
}

export async function fetchIssueHistory(issueId: number): Promise<IssueHistory> {
  const history = parseIssueHistory(await request<unknown>(`/apparatus-issues/${issueId}/history`));
  if (history === null) throw new Error(BAD_ANSWER);

  return history;
}
