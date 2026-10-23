import type { IssueKind, IssueSummary, WorkspaceCounts } from './types';

export type QueueFilter = 'all' | 'needs_acknowledgement' | 'ordered' | 'ready_for_pickup' | 'overdue' | 'awaiting_verification' | 'significant';
export type KindFilter = 'all' | IssueKind;

interface FilterDef {
  id: QueueFilter;
  label: string;
  /** Key in the server's counts. */
  count: keyof WorkspaceCounts;
  test: (issue: IssueSummary) => boolean;
}

/** Chips on Today and on the Issues tab are the same list, so a count always opens the rows it counted. */
export const QUEUE_FILTERS: FilterDef[] = [
  { id: 'all', label: 'All open', count: 'open', test: () => true },
  { id: 'needs_acknowledgement', label: 'Needs acknowledgement', count: 'needs_acknowledgement', test: (i) => i.action_status === 'reported' },
  { id: 'ordered', label: 'Ordered', count: 'ordered', test: (i) => i.action_status === 'action_pending' },
  { id: 'ready_for_pickup', label: 'Ready for pickup', count: 'ready_for_pickup', test: (i) => i.action_status === 'replacement_available' },
  { id: 'overdue', label: 'Overdue', count: 'overdue', test: (i) => i.overdue },
  { id: 'awaiting_verification', label: 'Awaiting verification', count: 'awaiting_verification', test: (i) => i.awaiting_verification },
  { id: 'significant', label: 'Significant damage', count: 'significant', test: (i) => i.severity === 'significant' },
];

export const isQueueFilter = (value: string | null): value is QueueFilter => QUEUE_FILTERS.some((filter) => filter.id === value);
export const isKindFilter = (value: string | null): value is KindFilter => value === 'all' || value === 'rescue' || value === 'medical';

export function applyFilters(issues: IssueSummary[], queue: QueueFilter, kind: KindFilter): IssueSummary[] {
  const test = QUEUE_FILTERS.find((filter) => filter.id === queue)?.test ?? (() => true);
  return issues.filter((issue) => test(issue) && (kind === 'all' || issue.kind === kind));
}
