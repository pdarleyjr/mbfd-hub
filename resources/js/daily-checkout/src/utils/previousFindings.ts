import { HUB_TIMEZONE } from '../../../shared/timezone.ts';
import type { ChecklistItem, Compartment, OpenFinding } from '../types';

/** A previously reported issue resolved to the checklist item it describes. */
export interface FindingTarget {
  finding: OpenFinding;
  compartment: Compartment;
  item: ChecklistItem;
}

const hasIds = (finding: OpenFinding): finding is OpenFinding & { compartment_id: string; item_id: string } =>
  typeof finding.compartment_id === 'string' && finding.compartment_id !== ''
  && typeof finding.item_id === 'string' && finding.item_id !== '';

/**
 * Resolve each open finding to checklist items. Stable checklist ids win when the server sends them and they
 * exist in the issued checklist; otherwise the recorded display names are matched (older findings, renamed ids).
 */
export function resolveFindingTargets(compartments: Compartment[], findings: OpenFinding[] = []): FindingTarget[] {
  return findings.flatMap(finding => {
    if (hasIds(finding)) {
      const compartment = compartments.find(entry => entry.id === finding.compartment_id);
      const item = compartment?.items.find(entry => entry.id === finding.item_id);
      if (compartment && item) return [{ finding, compartment, item }];
    }
    return compartments.flatMap(compartment => compartment.items
      .filter(item => compartment.name === finding.compartment && item.name === finding.item)
      .map(item => ({ finding, compartment, item })));
  });
}

export function targetsForItem(targets: FindingTarget[], compartmentId: string, itemId: string): FindingTarget[] {
  return targets.filter(target => target.compartment.id === compartmentId && target.item.id === itemId);
}

/** Findings that resolve to no current checklist item remain listed so they are not lost. */
export function unlocatedFindings(targets: FindingTarget[], findings: OpenFinding[] = []): OpenFinding[] {
  const located = new Set(targets.map(target => target.finding));
  return findings.filter(finding => !located.has(finding));
}

const capitalize = (value: string) => value === '' ? value : value.charAt(0).toUpperCase() + value.slice(1);

/** "Previously reported: Missing" */
export function previousIssueHeadline(finding: OpenFinding): string {
  return `Previously reported: ${capitalize(finding.issue_type)}`;
}

/** Month day, year in the department's time zone; null when absent or unparseable. */
export function formatReportedDate(value: string | null | undefined): string | null {
  if (!value) return null;
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return null;
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: HUB_TIMEZONE });
}

/** Current status line: the corrective action wording when the server has one, else the verification wait. */
export function previousIssueStatusLine(finding: OpenFinding): string {
  const status = finding.action_label?.trim() ? finding.action_label.trim() : 'Awaiting verification';
  const reported = formatReportedDate(finding.first_reported_at);
  return reported ? `${status} · First reported ${reported}` : status;
}

/** What the member's current observation says about a previously reported item, for Review & Sign. */
export function currentObservationText(item: ChecklistItem): string | null {
  if (item.observed !== true) return null;
  if (item.status === 'Present') return 'You marked present';
  return item.status === 'Missing' ? 'Still missing' : 'Still damaged';
}
