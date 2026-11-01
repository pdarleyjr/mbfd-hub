import type { OpenFinding } from '../types';
import type { FindingsSnapshot, IssueSummary, LiveFindings, LiveFindingsState } from '../types/issueHistory.ts';

/**
 * Pure logic behind useLiveFindings: the state machine for the last answer, the cache key, and the merge of live
 * issues into the checklist's open findings. No browser APIs, so it is tested in plain Node.
 */

export const LIVE_FINDINGS_CACHE_PREFIX = 'live-findings-v1:';

/** One cached answer per apparatus and member, so one member on a shared device never reads another's. */
export const liveFindingsCacheKey = (apparatusId: number, userId: number): string => `${LIVE_FINDINGS_CACHE_PREFIX}${apparatusId}:${userId}`;

export interface LiveFindingsStore {
  snapshot: FindingsSnapshot | null;
  state: LiveFindingsState;
}

export type LiveFindingsEvent =
  | { type: 'reset' }
  /** The answer kept on this device, read at mount. It never replaces an answer already in hand. */
  | { type: 'cached'; snapshot: FindingsSnapshot }
  | { type: 'request' }
  | { type: 'success'; snapshot: FindingsSnapshot }
  | { type: 'failure' };

export const initialLiveFindings: LiveFindingsStore = { snapshot: null, state: 'loading' };

export function liveFindingsReducer(store: LiveFindingsStore, event: LiveFindingsEvent): LiveFindingsStore {
  switch (event.type) {
    case 'reset':
      return initialLiveFindings;
    case 'cached':
      // Read after a request already failed: the answer on hand is old, not missing.
      return store.snapshot === null ? { snapshot: event.snapshot, state: store.state === 'unavailable' ? 'stale' : store.state } : store;
    case 'request':
      // A refresh never replaces what is on screen with a spinner; only an empty screen shows loading.
      return store.snapshot === null && store.state === 'unavailable' ? { ...store, state: 'loading' } : store;
    case 'success':
      return { snapshot: event.snapshot, state: 'live' };
    case 'failure':
      // A failed refresh keeps the last good answer and says it is old.
      if (store.snapshot === null) return store.state === 'unavailable' ? store : { snapshot: null, state: 'unavailable' };

      return store.state === 'stale' ? store : { ...store, state: 'stale' };
  }
}

/** What the wizard hands the Apparatus step: the live list as it stands and its merge with the checklist. */
export interface LiveIssues {
  live: LiveFindings;
  known: KnownFindings;
  apparatusId: number | undefined;
}

export interface KnownFindings {
  /** The checklist's open findings, enriched, plus live issues the checklist did not list. */
  findings: OpenFinding[];
  liveById: Map<number, IssueSummary>;
  /** Only when the answer is current: checklist findings the live list no longer has open. They stay visible, marked. */
  noLongerOpen: Set<number>;
}

/** The checklist ids only when the server has them; null never overwrites an id the checklist carried. */
const idKeys = (issue: IssueSummary): Pick<OpenFinding, 'compartment_id' | 'item_id'> => ({
  ...(issue.compartment_id ? { compartment_id: issue.compartment_id } : {}),
  ...(issue.item_id ? { item_id: issue.item_id } : {}),
});

/** Live status and current names over the checklist's own record. What only the checklist carries is kept. */
const enrich = (finding: OpenFinding, issue: IssueSummary): OpenFinding => ({
  ...finding,
  compartment: issue.compartment !== '' ? issue.compartment : finding.compartment,
  item: issue.item !== '' ? issue.item : finding.item,
  ...idKeys(issue),
  issue_type: issue.issue_type,
  last_observation: issue.last_observation,
  last_observed_at: issue.last_observed_at,
  service_status: issue.service_status,
  action_status: issue.action_status,
  action_label: issue.action_label,
  severity: issue.severity,
  first_reported_at: issue.first_reported_at ?? finding.first_reported_at ?? null,
});

const fromLive = (issue: IssueSummary): OpenFinding => ({
  id: issue.id,
  compartment: issue.compartment,
  item: issue.item,
  issue_type: issue.issue_type,
  operational_impact: 'unclassified',
  last_observation: issue.last_observation,
  last_observed_at: issue.last_observed_at,
  service_status: issue.service_status,
  ...idKeys(issue),
  action_status: issue.action_status,
  action_label: issue.action_label,
  severity: issue.severity,
  first_reported_at: issue.first_reported_at,
  latest_member_update: null,
});

/**
 * Live data enriches the checklist and never replaces it. A checklist finding keeps its place offline and when the
 * live list is missing or old; with a current answer that no longer lists it, it stays and is marked so the member
 * knows to check. A live issue the checklist did not carry (reported after it loaded) is added.
 */
export function mergeKnownFindings(checklist: OpenFinding[] | undefined, live: IssueSummary[], current: boolean): KnownFindings {
  const liveById = new Map(live.map((issue): [number, IssueSummary] => [issue.id, issue]));
  const checklistFindings = checklist ?? [];
  const listed = new Set(checklistFindings.map(finding => finding.id));
  const noLongerOpen = new Set<number>();
  const findings = checklistFindings.map(finding => {
    const issue = liveById.get(finding.id);
    if (issue) return enrich(finding, issue);
    if (current) noLongerOpen.add(finding.id);

    return finding;
  });

  return { findings: [...findings, ...live.filter(issue => !listed.has(issue.id)).map(fromLive)], liveById, noLongerOpen };
}
