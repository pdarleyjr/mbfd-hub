import { HUB_TIMEZONE } from '../../../../../shared/timezone.ts';
/**
 * Pure helpers for the Narcotics Checks tab: wording, the list query and the checks that run before
 * anything is sent. No React or fetch, so `node --test` loads this file directly
 * (tests/Node/narcotics-captain5-logic.test.mjs).
 */
import type { NarcoticsCheck, NarcoticsFilters, NarcoticsStatus } from './types.ts';

export const REJECT_NOTE_MAX = 500;
export const EMPTY_FILTERS: NarcoticsFilters = { scope: 'inbox', outcome: 'all', q: '', dateFrom: '', dateTo: '', page: 1 };
export const NARCOTICS_TAB = 'narcotics';

type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

export const STATUS_TONE: Record<NarcoticsStatus, Tone> = { pending: 'info', approved: 'success', rejected: 'danger' };

export function etDateTime(iso: string | null | undefined): string {
  if (!iso) return 'Unknown time';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return 'Unknown time';
  return new Intl.DateTimeFormat('en-US', { timeZone: HUB_TIMEZONE, month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' }).format(date);
}

export const memberText = (check: Pick<NarcoticsCheck, 'submitter_name' | 'submitter_rank'>): string => [check.submitter_rank, check.submitter_name].filter(Boolean).join(' ');

/** The rescue unit the member named; old checks, sent before units were asked for, say so plainly. */
export const rescueUnitText = (check: Pick<NarcoticsCheck, 'rescue_unit'>): string => {
  const unit = (check.rescue_unit ?? '').trim();
  return unit === '' ? 'Not recorded' : unit;
};

/** "Rescue unit: R22" for a list row, or null when the check predates units (rows stay uncluttered). */
export const rescueUnitLine = (check: Pick<NarcoticsCheck, 'rescue_unit'>): string | null => ((check.rescue_unit ?? '').trim() === '' ? null : `Rescue unit: ${rescueUnitText(check)}`);

/** The seal number the member typed; old checks say so plainly. */
export const sealNumberText = (check: Pick<NarcoticsCheck, 'seal_number'>): string => {
  const seal = (check.seal_number ?? '').trim();
  return seal === '' ? 'Not recorded' : seal;
};

/** "Seal number: ZX-4471" for a list row, or null when the check predates seal numbers. */
export const sealNumberLine = (check: Pick<NarcoticsCheck, 'seal_number'>): string | null => ((check.seal_number ?? '').trim() === '' ? null : `Seal number: ${sealNumberText(check)}`);

export const deciderText = (check: Pick<NarcoticsCheck, 'decided_by_name' | 'decided_by_rank'>): string => [check.decided_by_rank, check.decided_by_name].filter(Boolean).join(' ') || 'Captain 5';

export const dateRangeError = (from: string, to: string): string | null => (from !== '' && to !== '' && to < from ? 'The end date cannot be before the start date.' : null);

/** The list request for a set of filters. Dates are Eastern calendar days; the server turns them into instants. */
export function checksQuery(filters: NarcoticsFilters, perPage = 25): string {
  const params = new URLSearchParams();
  params.set('scope', filters.scope);
  if (filters.scope === 'archive' && filters.outcome !== 'all') params.set('status', filters.outcome);
  const q = filters.q.trim();
  if (q !== '') params.set('q', q.slice(0, 100));
  if (filters.dateFrom !== '') params.set('date_from', filters.dateFrom);
  if (filters.dateTo !== '') params.set('date_to', filters.dateTo);
  params.set('per_page', String(perPage));
  params.set('page', String(Math.max(1, filters.page)));
  return params.toString();
}

/** A short line for the list row: what the member wrote, or what was decided. */
export function rowNote(check: NarcoticsCheck): string | null {
  if (check.status === 'rejected') return check.decision_note ? `Rejected: ${check.decision_note}` : 'Rejected';
  if (check.status === 'approved') return `Approved by ${deciderText(check)}`;
  return check.has_note && check.member_note ? `Note: ${check.member_note}` : null;
}

/** The sentence that says where a decided check stands, for the sheet banner and screen readers. */
export function decisionText(check: Pick<NarcoticsCheck, 'status' | 'decided_at' | 'decided_by_name' | 'decided_by_rank'>): string | null {
  if (check.status === 'pending') return null;
  const verb = check.status === 'approved' ? 'Approved and archived' : 'Rejected and archived';

  return `${verb} by ${deciderText(check)} on ${etDateTime(check.decided_at)}.`;
}

export function rejectError(explanation: string): string | null {
  return explanation.length > REJECT_NOTE_MAX ? `Keep the explanation to ${REJECT_NOTE_MAX} characters or fewer.` : null;
}

/** Zoom levels of the photo viewer; Fit is the first. */
export const ZOOM_LEVELS = [1, 1.5, 2, 3, 4];
