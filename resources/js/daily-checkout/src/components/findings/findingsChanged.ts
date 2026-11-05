import type { IssueOwner } from '../../types/issueHistory.ts';

/**
 * A member action on an issue (Mark fixed) changed what the server knows. The card that did it may disappear from
 * the list once the list is read again, so the page that owns the list listens for this, shows the server's words
 * and reads the list. A plain window event keeps IssueCard free of a refresh prop threaded through the summary.
 */
export const FINDINGS_CHANGED_EVENT = 'mbfd:findings-changed';

export interface FindingsChangedDetail {
  /** The server's confirmation, kept on the page after the card is gone. */
  message?: string;
  /** False when only the message should show now and the list is read later. */
  refresh: boolean;
}

export function announceFindingsChanged(detail: FindingsChangedDetail): void {
  if (typeof window === 'undefined') return;
  window.dispatchEvent(new CustomEvent<FindingsChangedDetail>(FINDINGS_CHANGED_EVENT, { detail }));
}

/** Who verifies, in the words members use. */
export function ownerName(owner: IssueOwner | undefined): string {
  if (owner === 'captain5') return 'Captain 5';
  if (owner === 'logistics') return 'Support Services';

  return 'Captain 5 or Support Services';
}
