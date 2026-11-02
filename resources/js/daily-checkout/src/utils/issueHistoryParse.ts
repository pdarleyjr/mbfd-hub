import type {
  ClosedIssuesPage,
  ClosureKind,
  ConcernNote,
  FindingsSnapshot,
  IssueHistory,
  IssueSummary,
  LatestAction,
  TimelineEntry,
} from '../types/issueHistory.ts';
import { humanize } from '../components/ui/status.ts';

/**
 * Defensive readers for the apparatus issue history API (docs/apparatus-issue-history.md). Keys that are not part of the
 * contract are dropped, a malformed entry is skipped instead of failing the list, and nothing here throws on a bad row.
 * Only a wrong overall shape returns null, so the caller can keep what is already on screen.
 */

type Row = Record<string, unknown>;

const isRow = (value: unknown): value is Row => typeof value === 'object' && value !== null && !Array.isArray(value);
const rows = (value: unknown): Row[] => (Array.isArray(value) ? value.filter(isRow) : []);
const text = (value: unknown): string | null => (typeof value === 'string' && value.trim() !== '' ? value : null);
const id = (value: unknown): number | null => (Number.isInteger(value) && (value as number) > 0 ? value as number : null);
const count = (value: unknown): number => (Number.isInteger(value) && (value as number) >= 0 ? value as number : 0);
/** A photo is only ever loaded through the server's authorized route: a path on this site or an https address. */
const photoUrl = (value: unknown): string | null => {
  const url = text(value);

  return url !== null && /^(?:\/(?!\/)|https?:\/\/)/i.test(url.trim()) ? url.trim() : null;
};

const closureKind = (value: unknown): ClosureKind | null => (value === 'resolved' || value === 'closed_no_action' ? value : null);

const parseLatestAction = (value: unknown): LatestAction | null => {
  if (!isRow(value)) return null;
  const label = text(value.label);
  if (label === null) return null;

  return { label, actor_name: text(value.actor_name), at: text(value.at), text: text(value.text) };
};

export const parseIssueSummary = (value: unknown): IssueSummary | null => {
  if (!isRow(value)) return null;
  const issueId = id(value.id);
  if (issueId === null) return null;
  const status = text(value.action_status) ?? '';

  return {
    id: issueId,
    compartment: text(value.compartment) ?? '',
    item: text(value.item) ?? '',
    compartment_id: text(value.compartment_id),
    item_id: text(value.item_id),
    issue_type: text(value.issue_type) ?? 'issue',
    severity: value.severity === 'significant' ? 'significant' : 'routine',
    description: text(value.description),
    reporter_name: text(value.reporter_name),
    first_reported_at: text(value.first_reported_at),
    action_status: status,
    action_label: text(value.action_label) ?? humanize(status, ''),
    closure_kind: closureKind(value.closure_kind),
    latest_action: parseLatestAction(value.latest_action),
    last_observation: text(value.last_observation),
    last_observed_at: text(value.last_observed_at),
    service_status: text(value.service_status),
    recurrence_of_defect_id: id(value.recurrence_of_defect_id),
    history_count: count(value.history_count),
  };
};

/** Skips malformed rows and repeats of an id, keeping the server's order. */
export const parseIssueList = (value: unknown): IssueSummary[] => {
  const seen = new Set<number>();

  return (Array.isArray(value) ? value : []).flatMap((entry): IssueSummary[] => {
    const issue = parseIssueSummary(entry);
    if (issue === null || seen.has(issue.id)) return [];
    seen.add(issue.id);

    return [issue];
  });
};

export const parseTimelineEntry = (value: unknown): TimelineEntry | null => {
  if (!isRow(value)) return null;
  const entryId = typeof value.id === 'number' ? String(value.id) : text(value.id);
  if (entryId === null || (value.kind !== 'event' && value.kind !== 'message')) return null;
  const type = text(value.type) ?? (value.kind === 'message' ? 'update' : 'event');

  return {
    id: entryId,
    kind: value.kind,
    type,
    label: text(value.label) ?? humanize(type, 'Update'),
    lines: (Array.isArray(value.lines) ? value.lines : []).flatMap((line): string[] => (typeof line === 'string' && line.trim() !== '' ? [line] : [])),
    text: text(value.text),
    actor_name: text(value.actor_name),
    at: text(value.at),
    photo_url: photoUrl(value.photo_url),
  };
};

export const parseConcernNote = (value: unknown): ConcernNote | null => {
  if (!isRow(value)) return null;
  const noteId = id(value.id);
  const description = text(value.description);
  if (noteId === null || description === null) return null;

  return {
    id: noteId,
    description,
    location: text(value.location),
    reporter_name: text(value.reporter_name),
    reported_at: text(value.reported_at),
    inspection_reference: text(value.inspection_reference),
    photo_url: photoUrl(value.photo_url),
  };
};

/** `fallbackAsOf` stands in when the server sent no `as_of`, so the screen always has a time to show. */
export const parseFindingsResponse = (payload: unknown, fallbackAsOf: string | null = null): FindingsSnapshot | null => {
  if (!isRow(payload) || !Array.isArray(payload.open)) return null;

  return {
    as_of: text(payload.as_of) ?? fallbackAsOf,
    open: parseIssueList(payload.open),
    notes: rows(payload.notes).flatMap((row) => parseConcernNote(row) ?? []),
    closed_count: count(payload.closed_count),
  };
};

export const parseClosedPage = (payload: unknown): ClosedIssuesPage | null => {
  if (!isRow(payload) || !Array.isArray(payload.data)) return null;

  return { data: parseIssueList(payload.data), next_cursor: text(payload.next_cursor) };
};

export const parseIssueHistory = (payload: unknown): IssueHistory | null => {
  if (!isRow(payload) || !Array.isArray(payload.timeline)) return null;

  return {
    issue: parseIssueSummary(payload.issue),
    timeline: payload.timeline.flatMap((entry) => parseTimelineEntry(entry) ?? []),
  };
};
