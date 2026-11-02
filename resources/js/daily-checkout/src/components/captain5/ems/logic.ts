import { HUB_TIMEZONE } from '../../../../../shared/timezone.ts';
/**
 * Pure helpers still shared by the Captain 5 request thread and the Submitted Inventory Forms tab: Eastern
 * dates, request and line wording, issuance history, whole-number parsing and idempotency keys. No React or
 * fetch; the shared timezone supports `node --test` loading this file directly (tests/Node/ems-captain5-logic.test.mjs).
 */
import type { HistoryEvent, IssuanceView, LineGroup, RequestLine, RequestSummary } from './types.ts';

const TIMEZONE = HUB_TIMEZONE;

// ------------------------------------------------------------------- dates (Eastern)

export function etDateTime(iso: string | null | undefined): string {
  if (!iso) return 'Unknown time';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return 'Unknown time';
  return new Intl.DateTimeFormat('en-US', { timeZone: TIMEZONE, month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' }).format(date);
}

export function etDate(iso: string | null | undefined): string {
  if (!iso) return 'No date';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return 'No date';
  return new Intl.DateTimeFormat('en-US', { timeZone: TIMEZONE, month: 'short', day: 'numeric', year: 'numeric' }).format(date);
}


// -------------------------------------------------------------- request wording

const BAG_LABELS: Record<string, string> = { 'airway-bag': 'Airway Bag', 'als-med-bag': 'ALS Med Bag or Box', 'als-transport-vehicle': 'ALS Transport Vehicle' };

export function targetText(request: Pick<RequestSummary, 'target_kind' | 'target_label' | 'event_assignment'>): string {
  if (request.target_kind !== 'detail_event') return request.target_label;
  const event = (request.event_assignment ?? '').trim();
  return event === '' ? request.target_label : `${request.target_label}: ${event}`;
}

export const memberText = (request: Pick<RequestSummary, 'submitter_name' | 'submitter_rank'>): string => [request.submitter_rank, request.submitter_name].filter(Boolean).join(' ');

export const LINE_TONE: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  open: 'neutral', prepared: 'info', partially_issued: 'warning', issued: 'success', not_issued: 'danger',
};

// ------------------------------------------------------------------ request lines

export function requestedText(line: RequestLine): string {
  if (line.response_type === 'yes_no') return line.requested_flag ? 'Yes' : 'No';
  return `${line.requested_qty ?? 0}${line.unit && line.unit !== 'each' ? ` ${line.unit}` : ''}`;
}

export function lineFlags(line: Pick<RequestLine, 'refrigerated' | 'controlled_substance'>): string[] {
  return [line.refrigerated ? 'Refrigerated' : null, line.controlled_substance ? 'Controlled item' : null].filter((flag): flag is string => flag !== null);
}

/** Lines grouped by bag, then by the section they sit in on the form, in the order the form lists them. */
export function groupLines(lines: RequestLine[]): LineGroup[] {
  const groups: LineGroup[] = [];
  for (const line of lines) {
    let group = groups.find((entry) => entry.bagKey === line.bag_key);
    if (!group) {
      group = { bagKey: line.bag_key, bagLabel: BAG_LABELS[line.bag_key] ?? line.bag_key, sections: [] };
      groups.push(group);
    }
    const label = line.section_label && line.section_label.trim() !== '' ? line.section_label : 'Other items';
    let section = group.sections.find((entry) => entry.label === label);
    if (!section) {
      section = { label, lines: [] };
      group.sections.push(section);
    }
    section.lines.push(line);
  }
  return groups;
}

const toInt = (value: unknown): number => (typeof value === 'number' && Number.isFinite(value) ? Math.trunc(value) : Number.parseInt(String(value ?? '0'), 10) || 0);
const toNullableInt = (value: unknown): number | null => (value === null || value === undefined || value === '' ? null : toInt(value));

/**
 * Every issuance with the quantity it stands at now. A correction names the issuance it fixes
 * (`corrects_event_id`) and carries the new quantity and, when the item changed, the new item. The
 * original event is never edited, so the current state is found by replaying the corrections in order.
 */
export function currentIssuances(history: HistoryEvent[]): IssuanceView[] {
  const ordered = [...history].sort((a, b) => a.id - b.id);
  const corrections = ordered.filter((event) => event.action === 'issue_corrected');
  return ordered.filter((event) => event.action === 'issued').map((event) => {
    const payload = event.payload ?? {};
    const state = {
      quantity: toInt(payload.quantity),
      supplyItemId: toNullableInt(payload.supply_item_id),
      supplyItemName: typeof payload.supply_item_name === 'string' ? payload.supply_item_name : null,
      stockEffect: typeof payload.stock_effect === 'string' ? payload.stock_effect : null,
      corrected: false,
    };
    for (const correction of corrections) {
      const p = correction.payload ?? {};
      if (toInt(p.corrects_event_id) !== event.id) continue;
      const nextItem = toNullableInt(p.supply_item_id);
      if (nextItem !== state.supplyItemId) state.supplyItemName = null;
      state.quantity = toInt(p.new_quantity);
      state.supplyItemId = nextItem;
      state.stockEffect = typeof p.stock_effect === 'string' ? p.stock_effect : state.stockEffect;
      state.corrected = true;
    }
    return {
      eventId: event.id,
      lineId: event.request_line_id,
      label: typeof payload.label === 'string' ? payload.label : 'Item',
      quantity: state.quantity,
      originalQuantity: toInt(payload.quantity),
      supplyItemId: state.supplyItemId,
      supplyItemName: state.supplyItemName,
      stockEffect: state.stockEffect,
      corrected: state.corrected,
      actor: event.actor_name,
      at: event.created_at,
    };
  });
}

export function stockEffectText(effect: string | null | undefined): string {
  if (effect === 'applied') return 'Stock updated';
  if (effect === 'none') return 'Recorded only (controlled item)';
  return 'Recorded only';
}

/** One plain sentence for a timeline entry (mirrors EmsLabels::eventSentence on the server). */
export function eventSentence(event: HistoryEvent): string {
  const p = event.payload ?? {};
  const label = typeof p.label === 'string' && p.label !== '' ? p.label : 'an item';
  const reason = typeof p.reason === 'string' && p.reason !== '' ? ` Reason: ${p.reason}` : '';
  switch (event.action) {
    case 'submitted': return 'Submitted the request.';
    case 'reviewed': return 'Reviewed the request.';
    case 'prepared': return `Prepared ${p.quantity ?? 'no quantity'} of ${label}.`;
    case 'issued': return `Issued ${p.quantity ?? '?'} of ${label}.${p.over_issue ? ' More than was requested.' : ''}${reason}`;
    case 'issue_corrected': return `Corrected ${label} from ${p.previous_quantity ?? '?'} to ${p.new_quantity ?? '?'}.${reason}`;
    case 'not_issued': return `Marked ${label} not issued.${reason}`;
    case 'closed': return `Closed the request.${reason}`;
    case 'reopened': return `Reopened the request.${reason}`;
    case 'cancelled': return `The member cancelled the request.${reason}`;
    case 'status_changed': return `Status changed to ${String(event.to_status ?? '').replace(/_/g, ' ')}.`;
    default: return `${event.action.replace(/_/g, ' ')}.`;
  }
}

// ------------------------------------------------------------------ parsing and keys

/** A whole number from `min` to `max` (counts and settings run into the thousands), blank = null, anything else = NaN. */
export function parseWhole(text: string, min = 0, max = 999_999): number | null {
  const trimmed = text.trim();
  if (trimmed === '') return null;
  if (!/^\d{1,9}$/.test(trimmed)) return Number.NaN;
  const value = Number(trimmed);
  return value < min || value > max ? Number.NaN : value;
}

/**
 * One idempotency key per user action. The same key is used again for a retry of the same content, so a
 * retry after a dropped connection cannot write twice, and a different key as soon as the content changes
 * or after the server confirmed the action.
 */
export function createKeyKeeper(make: () => string): { keyFor: (signature: string) => string; reset: () => void } {
  let key: string | null = null;
  let signature: string | null = null;
  return {
    keyFor(next) {
      if (key === null || signature !== next) { key = make(); signature = next; }
      return key;
    },
    reset() { key = null; signature = null; },
  };
}
