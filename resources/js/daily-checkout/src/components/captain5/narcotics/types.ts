/** Captain 5 side of the narcotics check: what /api/narcotics-checks/staff/* returns. */

export type NarcoticsStatus = 'pending' | 'approved' | 'rejected';

export interface NarcoticsImage {
  available: boolean;
  thumb_url: string | null;
  preview_url: string | null;
  full_url: string | null;
  width: number;
  height: number;
  bytes: number;
  mime: string;
}

export interface NarcoticsCheck {
  id: string;
  status: NarcoticsStatus;
  status_label: string;
  /** A decided check leaves the pending inbox and stays searchable in the archive. */
  archived: boolean;
  archived_at: string | null;
  submitted_at: string;
  decided_at: string | null;
  submitter_name: string;
  submitter_rank: string | null;
  has_note: boolean;
  member_note: string | null;
  /** "R22", or the text the member typed for Other. Null on checks sent before units were asked for. */
  rescue_unit: string | null;
  /** As the member typed it (trimmed). Null on checks sent before seal numbers were asked for. */
  seal_number: string | null;
  decision_note: string | null;
  decided_by_name: string | null;
  decided_by_rank: string | null;
  resubmission_of: string | null;
  resubmitted_as: string | null;
  image: NarcoticsImage;
}

export interface NarcoticsHistoryEntry {
  action: string;
  actor_name: string | null;
  actor_rank: string | null;
  from_status: string | null;
  to_status: string | null;
  at: string | null;
}

export interface NarcoticsCheckDetail extends NarcoticsCheck {
  history: NarcoticsHistoryEntry[];
}

export interface NarcoticsMeta {
  permissions: { review: boolean };
  pending_count: number;
}

export interface NarcoticsListMeta {
  page: number;
  per_page: number;
  total: number;
  last_page: number;
  pending_count: number;
}

export type NarcoticsScope = 'inbox' | 'archive';
export type NarcoticsOutcome = 'all' | 'approved' | 'rejected';

export interface NarcoticsFilters {
  scope: NarcoticsScope;
  outcome: NarcoticsOutcome;
  q: string;
  dateFrom: string;
  dateTo: string;
  page: number;
}
