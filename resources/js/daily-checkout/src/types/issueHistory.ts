/** Apparatus issue history shapes. Binding contract: docs/apparatus-issue-history.md. */

export type IssueSeverity = 'routine' | 'significant';

/** How a closed issue ended. Null while it is active (a reopened issue is active again). */
export type ClosureKind = 'resolved' | 'closed_no_action';

export interface LatestAction {
  label: string;
  actor_name: string | null;
  at: string | null;
  text: string | null;
}

export interface IssueSummary {
  id: number;
  /** Current names, resolved like the checklist's open findings. */
  compartment: string;
  item: string;
  compartment_id: string | null;
  item_id: string | null;
  issue_type: string;
  severity: IssueSeverity;
  /** The original report; never rewritten. */
  description: string | null;
  reporter_name: string | null;
  first_reported_at: string | null;
  action_status: string;
  action_label: string;
  closure_kind: ClosureKind | null;
  latest_action: LatestAction | null;
  last_observation: string | null;
  last_observed_at: string | null;
  service_status: string | null;
  recurrence_of_defect_id: number | null;
  history_count: number;
}

export interface TimelineEntry {
  /** "event-12", "message-4" */
  id: string;
  kind: 'event' | 'message';
  /** The event type, or "update" for a message. */
  type: string;
  label: string;
  lines: string[];
  text: string | null;
  actor_name: string | null;
  at: string | null;
  photo_url: string | null;
}

export interface ConcernNote {
  id: number;
  description: string;
  location: string | null;
  reporter_name: string | null;
  reported_at: string | null;
  inspection_reference: string | null;
  photo_url: string | null;
}

/** `GET /api/public/apparatuses/{apparatus}/findings`. `as_of` falls back to the time the answer was received. */
export interface FindingsSnapshot {
  as_of: string | null;
  open: IssueSummary[];
  notes: ConcernNote[];
  closed_count: number;
}

export interface ClosedIssuesPage {
  data: IssueSummary[];
  next_cursor: string | null;
}

export interface IssueHistory {
  issue: IssueSummary | null;
  timeline: TimelineEntry[];
}

/** loading: nothing yet. live: the last request worked. stale: showing an earlier answer. unavailable: nothing to show. */
export type LiveFindingsState = 'loading' | 'live' | 'stale' | 'unavailable';

export interface LiveFindings {
  open: IssueSummary[];
  notes: ConcernNote[];
  closedCount: number;
  asOf: string | null;
  state: LiveFindingsState;
  refresh: () => void;
}

export type ConcernKind = 'note' | 'issue';

/** Sent with the checkout, on every apparatus (max 20). */
export interface ConcernSubmission {
  client_id: string;
  kind: ConcernKind;
  description: string;
  location?: string | null;
  /** A data URL, with the same limits as a defect photo. */
  photo?: string | null;
  existing_issue_id?: number | null;
}

/** One entry the member is writing. Kept in the autosaved draft; `kind` stays null until the member chooses. */
export interface ConcernDraft {
  client_id: string;
  kind: ConcernKind | null;
  description: string;
  location: string;
  photo?: string;
  existing_issue_id: number | null;
}

/** An open issue as the concerns form offers it ("Is this one of these?"). */
export interface ConcernIssueRef {
  id: number;
  item: string;
  compartment: string;
  /** "missing", "damaged" */
  issue_type: string;
  /** The status in words, for example "Replacement available". */
  status: string;
  description: string | null;
}
