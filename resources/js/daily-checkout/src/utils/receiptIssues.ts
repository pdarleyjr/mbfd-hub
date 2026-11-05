import type { InspectionSubmission } from '../types';

/**
 * What the member themselves reported on the checkout they just sent. This is the member's own input, not a
 * verdict from the server: whether an item is a NEW issue or one already known is decided after submission, so
 * the receipt never states a count.
 */
export type ReportedIssues = 'none' | 'some';

/** Scheduled duties answered with one of these were done; anything else is something to follow up. */
const DONE_TASK_STATUSES: readonly string[] = ['Completed', 'Present'];

export const reportedIssuesFor = (
  submission: Pick<InspectionSubmission, 'defects' | 'concerns' | 'review_flag' | 'scheduled_tasks'>,
): ReportedIssues => {
  const hasDefects = submission.defects.length > 0;
  // A "note for the record" asks for no action and the server files no finding for it, so it is not an issue.
  const hasConcerns = (submission.concerns ?? []).some((concern) => concern.kind === 'issue');
  const hasUndoneDuty = (submission.scheduled_tasks ?? []).some((task) => !DONE_TASK_STATUSES.includes(task.status));

  return hasDefects || hasConcerns || hasUndoneDuty || submission.review_flag !== undefined ? 'some' : 'none';
};

export const isReportedIssues = (value: unknown): value is ReportedIssues => value === 'none' || value === 'some';
