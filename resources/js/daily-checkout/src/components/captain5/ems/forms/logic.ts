/**
 * Pure helpers for the Submitted Inventory Forms tab: list queries, card wording, selection, the reject reason check,
 * item search and history sentences. No React or fetch; imports use .ts so `node --test` loads the file directly
 * (tests/Node/ems-submitted-forms-logic.test.mjs). The reconcile maths lives in reconcileMath.ts and is re-exported here.
 */
import { etDateTime, eventSentence } from '../logic.ts';
import type { HistoryEvent } from '../types.ts';
import type { FormDetail, FormStatus, FormSummary, MasterItem, ReconcileResult } from './types.ts';

export * from './reconcileMath.ts';
export * from './families.ts';

export const ACTIVE_PER_PAGE = 50;
export const ARCHIVED_PER_PAGE = 25;
/** The preview and the reconcile call both take at most 50 forms. */
export const MAX_SELECTED = 50;
export const REASON_MIN = 3;
export const REASON_MAX = 500;

const plural = (count: number, one: string, many = `${one}s`): string => `${count} ${count === 1 ? one : many}`;

// ------------------------------------------------------------------ statuses

export const FORM_STATUS_TONE: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  submitted: 'warning', reviewed: 'info', prepared: 'info', returned: 'danger', reconciled: 'success', superseded: 'neutral',
  issued: 'success', partially_issued: 'warning', closed: 'neutral', cancelled: 'neutral',
};

/** The three statuses a form can be reconciled from. */
export const isWorkingStatus = (status: FormStatus): boolean => status === 'submitted' || status === 'reviewed' || status === 'prepared';

export const isReturned = (form: Pick<FormSummary, 'status'>): boolean => form.status === 'returned';

/** Closed with nothing issued: Captain 5 cleared it (supplies handed over), and Restore can bring it back. */
export const isCleared = (form: Pick<FormSummary, 'status' | 'can_restore'>): boolean => form.status === 'closed' && form.can_restore === true;

/** The status tag: a cleared form says Cleared, everything else keeps the server's label. */
export const formStatusLabel = (form: Pick<FormSummary, 'status' | 'status_label' | 'can_restore'>): string => (isCleared(form) ? 'Cleared' : form.status_label);

/** Forms made before this workflow: issued by hand, kept as records. */
export const isOlderRecord = (form: Pick<FormSummary, 'status'>): boolean => form.status === 'issued' || form.status === 'partially_issued' || form.status === 'closed';

// ------------------------------------------------------------------ list

export function listKey(view: string, q: string, page: number, family = 'all'): string {
  return `${view}|${q.trim()}|${page}${family === 'all' ? '' : `|${family}`}`;
}

export const ackText = (form: Pick<FormSummary, 'acknowledged' | 'acknowledged_at' | 'acknowledged_by'>): string => {
  if (!form.acknowledged) return 'Waiting for acknowledgment';
  const who = form.acknowledged_by ? ` by ${form.acknowledged_by}` : '';
  return form.acknowledged_at ? `Acknowledged${who}, ${etDateTime(form.acknowledged_at)}` : `Acknowledged${who}`;
};

export const returnedText = (form: Pick<FormSummary, 'submitter_name' | 'return_reason'>): string => (
  `Returned to ${form.submitter_name}${form.return_reason ? `: ${form.return_reason}` : ''}`
);

/** What an archived card says in place of the acknowledgment line. */
export function archiveText(form: Pick<FormSummary, 'status' | 'status_label' | 'reconciled_at' | 'reconciled_by' | 'last_activity_at' | 'closed_at' | 'can_restore' | 'closed_by' | 'close_reason'>): string {
  if (form.status === 'reconciled') {
    const who = form.reconciled_by ? ` by ${form.reconciled_by}` : '';
    return `Reconciled${who}${form.reconciled_at ? `, ${etDateTime(form.reconciled_at)}` : ''}`;
  }
  if (isCleared(form)) {
    const who = form.closed_by ? ` by ${form.closed_by}` : '';
    const when = form.closed_at ? `, ${etDateTime(form.closed_at)}` : '';
    const why = form.close_reason ? `: ${form.close_reason}` : '';
    return `Cleared${who}${when}${why}`;
  }
  if (form.status === 'superseded') return 'Replaced by a corrected form';
  if (isOlderRecord(form)) return `Older record: ${form.status_label}`;
  return form.status_label;
}

export const revisionTag = (form: Pick<FormSummary, 'revision_number'>): string | null => (form.revision_number > 1 ? `Revision ${form.revision_number}` : null);

function previewEntry(item: FormSummary['items_preview'][number]): string {
  if (item.quantity === null || item.quantity === undefined) return item.label;
  const unit = item.unit && item.unit.trim().toLowerCase() !== 'each' ? ` ${item.unit.trim()}` : '';
  return `${item.label} (${item.quantity}${unit})`;
}

/** First three items, then "+N more", and the total quantity on its own line. */
export function itemSummary(form: Pick<FormSummary, 'items_preview' | 'line_count' | 'quantity_total'>): { list: string; total: string | null } {
  const preview = form.items_preview ?? [];
  if (preview.length === 0) return { list: form.line_count > 0 ? plural(form.line_count, 'item') : 'No items listed', total: null };
  const more = Math.max(0, form.line_count - preview.length);
  const list = `${preview.map(previewEntry).join(', ')}${more > 0 ? `, +${more} more` : ''}`;
  return { list, total: form.quantity_total > 0 ? `${form.quantity_total} in all` : null };
}

// ------------------------------------------------------------------ selection

export type SelectionControl = 'enabled' | 'needs_acknowledgment' | 'none';

/** A tick box only where the form is, or soon will be, ready to reconcile. */
export function selectionControl(form: FormSummary, canAct: boolean): SelectionControl {
  if (!canAct) return 'none';
  if (form.can_reconcile) return 'enabled';
  if (isWorkingStatus(form.status) && !form.acknowledged) return 'needs_acknowledgment';
  return 'none';
}

export const readyIds = (forms: readonly FormSummary[], canAct = true): string[] => (
  canAct ? forms.filter((form) => form.can_reconcile).map((form) => form.id).slice(0, MAX_SELECTED) : []
);

export function toggleId(selected: ReadonlySet<string>, id: string): ReadonlySet<string> {
  const next = new Set(selected);
  if (next.has(id)) next.delete(id); else if (next.size < MAX_SELECTED) next.add(id);
  return next;
}

/** A form that is on the page and no longer eligible (acknowledgment withdrawn, returned, reconciled by someone else) leaves the selection. */
export function pruneSelection(selected: ReadonlySet<string>, forms: readonly FormSummary[]): ReadonlySet<string> {
  const blocked = new Set(forms.filter((form) => !form.can_reconcile).map((form) => form.id));
  const kept = [...selected].filter((id) => !blocked.has(id));
  return kept.length === selected.size ? selected : new Set(kept);
}

/** The list row after an action: the server's fresh answer for that form, the rest as it was. */
export function mergeSummary(row: FormSummary, detail: FormDetail): FormSummary {
  const { lines: _lines, history: _history, messages: _messages, member_note: _note, cancel_reason: _cancel, revisions: _revisions, ...summary } = detail;
  void _lines; void _history; void _messages; void _note; void _cancel; void _revisions;
  return { ...row, ...summary };
}

// ------------------------------------------------------------------ clear and restore

/** The reason a one-tap Clear records. Captain 5 can add a note; it follows the default words. */
export const CLEAR_REASON = 'Supplies provided';
export const RESTORE_REASON = 'Restored to Active by Captain 5';
/** The server keeps at most 255 characters of a close reason. */
export const CLEAR_NOTE_MAX = 200;

export function clearReason(note = ''): string {
  const extra = note.trim().replace(/\s+/g, ' ').slice(0, CLEAR_NOTE_MAX);
  return extra === '' ? CLEAR_REASON : `${CLEAR_REASON}. ${extra}`;
}

/** What the page says after a Clear, single or several. Failures are named so nothing is silently skipped. */
export function clearDoneText(cleared: number, failed: number, firstError: string | null = null): string {
  const done = cleared > 0 ? `Cleared ${plural(cleared, 'form')} and moved ${cleared === 1 ? 'it' : 'them'} to Archived. Stock did not change.` : '';
  if (failed === 0) return done;
  const miss = `${plural(failed, 'form')} could not be cleared${firstError ? `: ${firstError}` : '.'}`;
  return done === '' ? miss : `${done} ${miss}`;
}

// ------------------------------------------------------------------ reject

export function reasonError(reason: string): string | null {
  const length = reason.trim().length;
  if (length === 0) return 'Say what needs to be fixed.';
  if (length < REASON_MIN) return `Write at least ${REASON_MIN} characters.`;
  if (length > REASON_MAX) return `Keep it to ${REASON_MAX} characters or fewer.`;
  return null;
}

// ------------------------------------------------------------------ confirmation text

export function reconcileDoneText(result: Pick<ReconcileResult, 'reconciled' | 'deducted'>): string {
  const forms = result.reconciled.length;
  const items = (result.deducted ?? []).reduce((sum, entry) => sum + Math.max(0, entry.deduct_base), 0);
  const shelf = items === 0 ? 'Nothing came off the shelf.' : `${plural(items, 'item')} came off the shelf.`;
  return `Reconciled ${plural(forms, 'form')}. ${shelf}`;
}

// ------------------------------------------------------------------ supply item search

/** Every typed word must appear in the name, a nickname or the shelf. Names that start with the first word come first. */
export function searchItems(items: readonly MasterItem[], query: string, limit = 8): MasterItem[] {
  const words = query.toLowerCase().split(/\s+/).filter(Boolean);
  if (words.length === 0) return [];
  const ranked: Array<{ item: MasterItem; score: number }> = [];
  for (const item of items) {
    const name = item.name.toLowerCase();
    const haystack = [name, ...item.aliases.map((alias) => alias.toLowerCase()), (item.location_code ?? '').toLowerCase(), (item.location_label ?? '').toLowerCase()].join(' | ');
    if (!words.every((word) => haystack.includes(word))) continue;
    ranked.push({ item, score: name.startsWith(words[0]) ? 0 : name.includes(words[0]) ? 1 : 2 });
  }
  return ranked.sort((a, b) => a.score - b.score || a.item.name.localeCompare(b.item.name)).slice(0, limit).map((entry) => entry.item);
}

export const shelfText = (code: string | null): string => (code ? `Shelf ${code}` : 'No shelf assigned');

// ------------------------------------------------------------------ history

/** History in plain words, including the events this workflow adds. */
export function formEventSentence(event: HistoryEvent): string {
  const reason = typeof event.payload?.reason === 'string' && event.payload.reason !== '' ? ` Reason: ${event.payload.reason}` : '';
  switch (event.action) {
    case 'submitted': return 'Submitted the form.';
    case 'reviewed':
    case 'acknowledged': return 'Acknowledged the form.';
    case 'rejected': return `Sent the form back to the member.${reason}`;
    case 'reconciled': return 'Reconciled the form. Its items came off the supply room shelf.';
    case 'revised': return 'Sent a corrected form.';
    case 'superseded': return 'Replaced by a corrected form.';
    case 'closed': return `Cleared the form and moved it to Archived. Stock did not change.${reason}`;
    case 'reopened': return `Restored the form to Active.${reason}`;
    default: return eventSentence(event);
  }
}
