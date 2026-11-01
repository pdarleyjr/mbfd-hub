import type { OpenFinding } from '../types';
import type { ConcernDraft, ConcernIssueRef, ConcernSubmission, IssueSummary } from '../types/issueHistory.ts';

/** Concerns, damage and notes the member adds to a checkout (docs/apparatus-issue-history.md section 3). Pure. */

export const MAX_CONCERNS = 20;
export const MAX_CONCERN_DESCRIPTION = 2000;
export const MAX_CONCERN_LOCATION = 255;

export const newConcernDraft = (clientId: string): ConcernDraft => ({
  client_id: clientId,
  kind: null,
  description: '',
  location: '',
  existing_issue_id: null,
});

/** Entries read back from a saved draft: anything that is not a usable entry is dropped, and the list is capped. */
export function restoreConcernDrafts(value: unknown): ConcernDraft[] {
  if (!Array.isArray(value)) return [];

  return value.flatMap((entry): ConcernDraft[] => {
    if (typeof entry !== 'object' || entry === null) return [];
    const draft = entry as Record<string, unknown>;
    if (typeof draft.client_id !== 'string' || draft.client_id === '') return [];

    return [{
      client_id: draft.client_id,
      kind: draft.kind === 'note' || draft.kind === 'issue' ? draft.kind : null,
      description: typeof draft.description === 'string' ? draft.description : '',
      location: typeof draft.location === 'string' ? draft.location : '',
      ...(typeof draft.photo === 'string' && draft.photo !== '' ? { photo: draft.photo } : {}),
      existing_issue_id: Number.isInteger(draft.existing_issue_id) ? draft.existing_issue_id as number : null,
    }];
  }).slice(0, MAX_CONCERNS);
}

/** An entry the member has started. Choosing note or problem alone does not count: nothing was said. */
export const concernStarted = (draft: ConcernDraft): boolean => (
  draft.description.trim() !== '' || draft.location.trim() !== '' || Boolean(draft.photo) || draft.existing_issue_id !== null
);

export interface ConcernProblem {
  client_id: string;
  field: 'description' | 'kind';
  message: string;
}

export const DESCRIPTION_REQUIRED = 'Describe what you noticed.';
export const KIND_REQUIRED = 'Choose note for the record or problem that needs attention.';

/** What stops a started entry from being sent. Entries never started are ignored, not errors. */
export function concernDraftProblems(drafts: ConcernDraft[]): ConcernProblem[] {
  return drafts.filter(concernStarted).flatMap((draft): ConcernProblem[] => {
    if (draft.description.trim() === '') return [{ client_id: draft.client_id, field: 'description', message: DESCRIPTION_REQUIRED }];
    // An update to a known issue is a problem report by definition, so it needs no second choice.
    if (draft.kind === null && draft.existing_issue_id === null) return [{ client_id: draft.client_id, field: 'kind', message: KIND_REQUIRED }];

    return [];
  });
}

/**
 * The payload entries. Complete entries only, at most 20, with optional keys left out when empty so a checkout
 * without concerns is unchanged. Choosing an existing issue makes the entry an update to that issue.
 */
export function toConcernSubmissions(drafts: ConcernDraft[]): ConcernSubmission[] {
  const problems = new Set(concernDraftProblems(drafts).map(problem => problem.client_id));

  return drafts
    .filter(draft => concernStarted(draft) && !problems.has(draft.client_id))
    .slice(0, MAX_CONCERNS)
    .map((draft): ConcernSubmission => {
      const location = draft.location.trim().slice(0, MAX_CONCERN_LOCATION);

      return {
        client_id: draft.client_id,
        kind: draft.existing_issue_id !== null ? 'issue' : draft.kind ?? 'note',
        description: draft.description.trim().slice(0, MAX_CONCERN_DESCRIPTION),
        ...(location !== '' ? { location } : {}),
        ...(draft.photo ? { photo: draft.photo } : {}),
        ...(draft.existing_issue_id !== null ? { existing_issue_id: draft.existing_issue_id } : {}),
      };
    });
}

/** The open issues the form offers ("Is this one of these?"). Findings the live list no longer has open are left out. */
export function concernIssueRefs(findings: OpenFinding[], liveById: Map<number, IssueSummary>, noLongerOpen: Set<number>): ConcernIssueRef[] {
  return findings.filter(finding => !noLongerOpen.has(finding.id)).map(finding => ({
    id: finding.id,
    item: finding.item,
    compartment: finding.compartment,
    issue_type: finding.issue_type,
    status: finding.action_label?.trim() || 'Awaiting verification',
    description: liveById.get(finding.id)?.description ?? null,
  }));
}

export const issueRefLabel = (issue: ConcernIssueRef): string => [issue.item, issue.compartment, issue.status].filter(part => part !== '').join(' · ');

const STOP_WORDS = new Set([
  'the', 'and', 'for', 'with', 'that', 'this', 'was', 'are', 'not', 'has', 'have', 'had', 'been', 'from', 'its', 'but', 'too',
  'out', 'you', 'our', 'there', 'they', 'them', 'into', 'some', 'one', 'all', 'can', 'still', 'again', 'also', 'just', 'very',
]);

/** Lowercase words of 3 or more letters, without filler, and a trailing "s" dropped so "doors" and "door" agree. */
const significantWords = (value: string): Set<string> => new Set(
  value.toLowerCase().split(/[^a-z0-9]+/)
    .filter(word => word.length >= 3 && !STOP_WORDS.has(word))
    .map(word => (word.length > 3 && word.endsWith('s') ? word.slice(0, -1) : word)),
);

export const DUPLICATE_MIN_SHARED_WORDS = 2;
export const DUPLICATE_MIN_SHARE = 0.6;

/**
 * A light hint, never a block: a new description that shares most of its words with an open issue (its item, area,
 * type and original report) probably describes that issue. Returns the best match, or null.
 */
export function suggestExistingIssue(description: string, issues: ConcernIssueRef[]): ConcernIssueRef | null {
  const words = significantWords(description);
  if (words.size < DUPLICATE_MIN_SHARED_WORDS) return null;
  let best: { issue: ConcernIssueRef; share: number } | null = null;
  for (const issue of issues) {
    const known = significantWords([issue.item, issue.compartment, issue.issue_type, issue.description ?? ''].join(' '));
    const shared = [...words].filter(word => known.has(word)).length;
    const share = shared / words.size;
    if (shared >= DUPLICATE_MIN_SHARED_WORDS && share >= DUPLICATE_MIN_SHARE && (best === null || share > best.share)) best = { issue, share };
  }

  return best?.issue ?? null;
}
