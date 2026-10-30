import { HUB_TIMEZONE } from '../../../../../shared/timezone.ts';
/**
 * Pure helpers for the Rescue Inventory and Supply Room tabs: wording, grouping, payload building and the
 * checks that run before anything is sent. No React or fetch; the shared timezone supports `node --test`
 * loading this file directly (tests/Node/ems-captain5-logic.test.mjs).
 */
import type {
  Advisory, CountItem, HistoryEvent, IssuanceView, LineGroup, ReconcileRow, RequestDetail, RequestFilters, RequestLine,
  RequestSummary, StatisticsReport, SupplyItem,
} from './types.ts';

export const TIMEZONE = HUB_TIMEZONE;

const plural = (count: number, one: string, many = `${one}s`): string => `${count} ${count === 1 ? one : many}`;

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

/** The calendar day in Eastern time as year-month-day, which is what the API's date filters take. */
export function etYmd(date: Date): string {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: TIMEZONE, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(date);
  const pick = (type: string) => parts.find((part) => part.type === type)?.value ?? '';
  return `${pick('year')}-${pick('month')}-${pick('day')}`;
}

export function addDaysYmd(ymd: string, days: number): string {
  const [y, m, d] = ymd.split('-').map(Number);
  const next = new Date(Date.UTC(y, m - 1, d + days));
  return `${next.getUTCFullYear()}-${String(next.getUTCMonth() + 1).padStart(2, '0')}-${String(next.getUTCDate()).padStart(2, '0')}`;
}

/** The default statistics window: the last 90 days ending today (Eastern). */
export function defaultStatsRange(now = new Date()): { from: string; to: string } {
  const to = etYmd(now);
  return { from: addDaysYmd(to, -89), to };
}

export function monthLabel(month: string): string {
  const match = /^(\d{4})-(\d{2})$/.exec(month);
  if (!match) return month;
  return new Intl.DateTimeFormat('en-US', { timeZone: 'UTC', month: 'short', year: 'numeric' }).format(new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, 1)));
}

// -------------------------------------------------------------- request list

export const BAG_LABELS: Record<string, string> = { 'airway-bag': 'Airway Bag', 'als-med-bag': 'ALS Med Bag or Box', 'als-transport-vehicle': 'ALS Transport Vehicle' };

/** Open on the Captain 5 tab means work is left to do: the request is not yet fully issued or closed. */
export const OPEN_STATUSES = ['submitted', 'reviewed', 'prepared', 'partially_issued'] as const;

export const STATUS_CHIPS: Array<{ id: RequestFilters['chip']; label: string; statuses: string[] }> = [
  { id: 'open', label: 'Open', statuses: [...OPEN_STATUSES] },
  { id: 'issued', label: 'Issued', statuses: ['issued'] },
  { id: 'closed', label: 'Closed', statuses: ['closed', 'cancelled'] },
  { id: 'all', label: 'All', statuses: [] },
];

export const isChip = (value: string | null): value is RequestFilters['chip'] => STATUS_CHIPS.some((chip) => chip.id === value);

export const EMPTY_FILTERS: RequestFilters = { chip: 'open', unit: '', dateFrom: '', dateTo: '', q: '', page: 1 };

export function requestListQuery(filters: RequestFilters, perPage = 25): string {
  const params = new URLSearchParams();
  const statuses = STATUS_CHIPS.find((chip) => chip.id === filters.chip)?.statuses ?? [];
  if (statuses.length > 0) params.set('status', statuses.join(','));
  if (filters.unit !== '') params.set('unit', filters.unit);
  if (filters.dateFrom !== '') params.set('date_from', filters.dateFrom);
  if (filters.dateTo !== '') params.set('date_to', filters.dateTo);
  if (filters.q.trim() !== '') params.set('q', filters.q.trim());
  params.set('page', String(Math.max(1, filters.page)));
  params.set('per_page', String(perPage));
  return params.toString();
}

/** The cheap count behind the tab badge: one row asked for, the total read from meta. */
export const OPEN_COUNT_QUERY = `status=${OPEN_STATUSES.join(',')}&per_page=1`;

/** Both dates given and the end is before the start. */
export const dateRangeError = (from: string, to: string): string | null => (from !== '' && to !== '' && to < from ? 'The end date cannot be before the start date.' : null);

export function targetText(request: Pick<RequestSummary, 'target_kind' | 'target_label' | 'event_assignment'>): string {
  if (request.target_kind !== 'detail_event') return request.target_label;
  const event = (request.event_assignment ?? '').trim();
  return event === '' ? request.target_label : `${request.target_label}: ${event}`;
}

export const memberText = (request: Pick<RequestSummary, 'submitter_name' | 'submitter_rank'>): string => [request.submitter_rank, request.submitter_name].filter(Boolean).join(' ');

/** "4 items requested, 1 with something issued". */
export function itemsText(request: Pick<RequestSummary, 'line_count' | 'issued_line_count'>): string {
  return `${plural(request.line_count, 'item')} requested, ${request.issued_line_count} issued`;
}

export const REQUEST_TONE: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  submitted: 'warning', reviewed: 'info', prepared: 'info', partially_issued: 'warning', issued: 'success', closed: 'neutral', cancelled: 'neutral',
};

export const LINE_TONE: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  open: 'neutral', prepared: 'info', partially_issued: 'warning', issued: 'success', not_issued: 'danger',
};

// ------------------------------------------------------------------ request lines

export const wantedQty = (line: Pick<RequestLine, 'response_type' | 'requested_flag' | 'requested_qty'>): number => (
  line.response_type === 'yes_no' ? (line.requested_flag ? 1 : 0) : (line.requested_qty ?? 0)
);

export const outstandingQty = (line: RequestLine): number => (line.line_status === 'not_issued' ? 0 : Math.max(0, wantedQty(line) - line.issued_qty));

export function requestedText(line: RequestLine): string {
  if (line.response_type === 'yes_no') return line.requested_flag ? 'Yes' : 'No';
  return `${line.requested_qty ?? 0}${line.unit && line.unit !== 'each' ? ` ${line.unit}` : ''}`;
}

export const isRequestOpen = (request: Pick<RequestSummary, 'status'>): boolean => request.status !== 'closed' && request.status !== 'cancelled';

/** A line can still take an issue when something is wanted and it was not answered No or marked not issued. */
export const lineCanIssue = (line: RequestLine): boolean => wantedQty(line) > 0 && line.line_status !== 'not_issued';

export const lineCanBeMarkedNotIssued = (line: RequestLine): boolean => wantedQty(line) > 0 && line.line_status !== 'issued' && line.line_status !== 'not_issued';

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

/** The issuances a save just added: issued events in `after` that `before` did not have, with what each did to stock. */
export function newIssuances(before: HistoryEvent[], after: HistoryEvent[]): IssuanceView[] {
  const known = new Set(before.map((event) => event.id));
  const added = new Set(after.filter((event) => event.action === 'issued' && !known.has(event.id)).map((event) => event.id));
  return currentIssuances(after).filter((issuance) => added.has(issuance.eventId));
}

/** "Stock updated: Gauze 4x4 (2)" or "Recorded only: Tape (1)", one per issuance. */
export function outcomeText(issuance: IssuanceView): string {
  const where = issuance.stockEffect === 'applied' && issuance.supplyItemName ? ` from ${issuance.supplyItemName}` : '';
  return `${stockEffectText(issuance.stockEffect)}: ${issuance.label} (${issuance.quantity})${where}`;
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

// ----------------------------------------------------------------- request actions

export type FieldErrors = Record<string, string>;

export const MAX_QTY = 999;

/** A whole number from 0 to 999, or null when the text is blank, or NaN when it is not a number we accept. */
export function parseQty(text: string, min = 0): number | null {
  const trimmed = text.trim();
  if (trimmed === '') return null;
  if (!/^\d{1,4}$/.test(trimmed)) return Number.NaN;
  const value = Number(trimmed);
  return value < min || value > MAX_QTY ? Number.NaN : value;
}

/** A whole number from `min` to `max` (counts and settings run into the thousands), blank = null, anything else = NaN. */
export function parseWhole(text: string, min = 0, max = 999_999): number | null {
  const trimmed = text.trim();
  if (trimmed === '') return null;
  if (!/^\d{1,9}$/.test(trimmed)) return Number.NaN;
  const value = Number(trimmed);
  return value < min || value > max ? Number.NaN : value;
}

export const MAX_COUNT = 999_999;

export interface IssueInput { quantity: string; supplyItemId: string; reason: string }

export function lineNeedsItemChoice(line: RequestLine): boolean {
  return (line.supply_candidates?.length ?? 0) > 1;
}

/** What the person typed to issue, checked line by line. Errors are keyed by line id (and `form` for the whole form). */
export function buildIssuePayload(lines: RequestLine[], inputs: Record<number, IssueInput>): {
  payload: { issuances: Array<{ line_id: number; quantity: number; supply_item_id?: number; reason?: string }> } | null;
  errors: FieldErrors;
} {
  const errors: FieldErrors = {};
  const issuances: Array<{ line_id: number; quantity: number; supply_item_id?: number; reason?: string }> = [];
  for (const line of lines) {
    const input = inputs[line.id];
    if (!input || input.quantity.trim() === '') continue;
    const quantity = parseQty(input.quantity, 1);
    if (quantity === null || Number.isNaN(quantity)) {
      errors[String(line.id)] = `Enter a whole number from 1 to ${MAX_QTY} for ${line.label}.`;
      continue;
    }
    const over = line.issued_qty + quantity > wantedQty(line);
    const reason = input.reason.trim();
    if (over && reason === '') {
      errors[String(line.id)] = `${line.label}: that is more than was requested. Give a reason to issue extra.`;
      continue;
    }
    if (lineNeedsItemChoice(line) && input.supplyItemId === '') {
      errors[String(line.id)] = `${line.label}: choose which supply room item it comes from, or choose to record it without changing stock.`;
      continue;
    }
    const chosen = /^\d+$/.test(input.supplyItemId) ? Number(input.supplyItemId) : null;
    issuances.push({ line_id: line.id, quantity, ...(chosen !== null ? { supply_item_id: chosen } : {}), ...(reason !== '' ? { reason } : {}) });
  }
  if (issuances.length === 0 && Object.keys(errors).length === 0) errors.form = 'Enter a quantity to issue on at least one line.';
  return { payload: Object.keys(errors).length > 0 ? null : { issuances }, errors };
}

/** Only the quantities that changed are sent. A blank clears the prepared quantity. */
export function buildPreparePayload(lines: RequestLine[], inputs: Record<number, string>): {
  payload: { lines: Array<{ line_id: number; prepared_qty: number | null }> } | null;
  errors: FieldErrors;
} {
  const errors: FieldErrors = {};
  const out: Array<{ line_id: number; prepared_qty: number | null }> = [];
  for (const line of lines) {
    const text = inputs[line.id];
    if (text === undefined) continue;
    const parsed = parseQty(text);
    if (Number.isNaN(parsed)) {
      errors[String(line.id)] = `Enter a whole number from 0 to ${MAX_QTY} for ${line.label}.`;
      continue;
    }
    if (parsed === line.prepared_qty) continue;
    out.push({ line_id: line.id, prepared_qty: parsed });
  }
  if (out.length === 0 && Object.keys(errors).length === 0) errors.form = 'Change at least one prepared quantity.';
  return { payload: Object.keys(errors).length > 0 ? null : { lines: out }, errors };
}

export const REASON_MIN = 3;

export function reasonError(reason: string, what: string): string | null {
  return reason.trim().length >= REASON_MIN ? null : `Give a reason ${what}.`;
}

export function buildCorrectionPayload(issuance: IssuanceView, newQuantity: string, supplyItemId: string, reason: string): {
  payload: { event_id: number; new_quantity: number; supply_item_id?: number; reason: string } | null;
  errors: FieldErrors;
} {
  const errors: FieldErrors = {};
  const quantity = parseQty(newQuantity);
  if (quantity === null || Number.isNaN(quantity)) errors.new_quantity = `Enter the correct quantity, from 0 to ${MAX_QTY}.`;
  const why = reasonError(reason, 'for the correction');
  if (why) errors.reason = why;
  const item = /^\d+$/.test(supplyItemId) ? Number(supplyItemId) : null;
  if (quantity !== null && !Number.isNaN(quantity) && quantity === issuance.quantity && (item === null || item === issuance.supplyItemId)) {
    errors.new_quantity = 'That is already the recorded quantity. Change the quantity or the item.';
  }
  if (Object.keys(errors).length > 0) return { payload: null, errors };
  return { payload: { event_id: issuance.eventId, new_quantity: quantity as number, ...(item !== null ? { supply_item_id: item } : {}), reason: reason.trim() }, errors };
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

// ----------------------------------------------------------------- supply room

export function packageText(item: Pick<SupplyItem, 'package_unit' | 'units_per_package' | 'packages_per_case'>): string {
  if (!item.package_unit || item.package_unit === 'each') return 'Each';
  const unit = item.package_unit.charAt(0).toUpperCase() + item.package_unit.slice(1);
  let text = item.units_per_package !== null ? `${unit} of ${item.units_per_package}` : `${unit}, size not stated`;
  if (item.packages_per_case !== null && item.package_unit !== 'case') text += `, ${item.packages_per_case} per case`;
  return text;
}

export function stockText(item: Pick<SupplyItem, 'current_quantity' | 'base_unit'>): string {
  return item.current_quantity === null ? 'Not counted yet' : `${item.current_quantity} ${item.base_unit}`;
}

export function thresholdText(item: Pick<SupplyItem, 'threshold_qty_base' | 'threshold_source_text'>): string {
  if (item.threshold_qty_base === null) return item.threshold_source_text ? `Threshold not worked out (source says ${item.threshold_source_text})` : 'No threshold set';
  return item.threshold_source_text && item.threshold_source_text.trim() !== ''
    ? `Reorder below ${item.threshold_qty_base} (source says ${item.threshold_source_text})`
    : `Reorder below ${item.threshold_qty_base}`;
}

export type StockState = 'controlled' | 'review' | 'below' | 'uncounted' | 'ok';

/** Below threshold is more urgent than needs review; controlled items are never in the stock states. */
export function stockState(item: Pick<SupplyItem, 'controlled_substance' | 'needs_review' | 'below_threshold' | 'current_quantity'>): StockState {
  if (item.controlled_substance) return 'controlled';
  if (item.below_threshold === true) return 'below';
  if (item.needs_review) return 'review';
  if (item.current_quantity === null) return 'uncounted';
  return 'ok';
}

export function splitControlled<T extends { controlled_substance: boolean }>(items: T[]): { tracked: T[]; controlled: T[] } {
  return { tracked: items.filter((item) => !item.controlled_substance), controlled: items.filter((item) => item.controlled_substance) };
}

export function groupByLocation<T extends { location_code: string | null }>(items: T[]): Array<{ code: string; items: T[] }> {
  const groups: Array<{ code: string; items: T[] }> = [];
  for (const item of items) {
    const code = item.location_code ?? 'No location';
    let group = groups.find((entry) => entry.code === code);
    if (!group) { group = { code, items: [] }; groups.push(group); }
    group.items.push(item);
  }
  return groups;
}

export type CountField = 'cases' | 'boxes' | 'each';
type CountShape = Pick<CountItem, 'package_unit' | 'units_per_package' | 'packages_per_case'>;
const BOX_LIKE = ['box', 'pack', 'bag'];

/** Cases and boxes are offered only when the item states the size (same rule as the server's conversion). */
export function countFields(item: CountShape): CountField[] {
  const fields: CountField[] = [];
  const perPackage = item.units_per_package;
  if (perPackage !== null && (item.package_unit === 'case' || item.packages_per_case !== null)) fields.push('cases');
  if (perPackage !== null && item.package_unit !== null && BOX_LIKE.includes(item.package_unit)) fields.push('boxes');
  fields.push('each');
  return fields;
}

export const COUNT_FIELD_LABEL: Record<CountField, string> = { cases: 'Cases', boxes: 'Boxes', each: 'Each' };

/** What the typed numbers come to in single units. A missing size gives null, never a guess. Display only: the server converts and decides. */
export function convertEntry(item: CountShape, entry: Partial<Record<CountField, number>>): number | null {
  let total = entry.each ?? 0;
  const perPackage = item.units_per_package;
  if ((entry.boxes ?? 0) > 0) {
    if (item.package_unit !== null && BOX_LIKE.includes(item.package_unit) && perPackage !== null) total += (entry.boxes as number) * perPackage; else return null;
  }
  if ((entry.cases ?? 0) > 0) {
    if (item.package_unit === 'case' && perPackage !== null) total += (entry.cases as number) * perPackage;
    else if (item.packages_per_case !== null && perPackage !== null) total += (entry.cases as number) * item.packages_per_case * perPackage;
    else return null;
  }
  return total;
}

export type CountDraft = Partial<Record<CountField, string>>;
export type CountLine = { supply_item_id: number } & Partial<Record<CountField, number | null>>;

/** The rows to send: only items whose typed text differs from what the server already holds. A cleared row is sent blank. */
export function countChanges(items: CountItem[], drafts: Record<number, CountDraft>): { lines: CountLine[]; errors: FieldErrors } {
  const lines: CountLine[] = [];
  const errors: FieldErrors = {};
  for (const item of items) {
    const draft = drafts[item.id];
    if (!draft) continue;
    const fields: Partial<Record<CountField, number | null>> = {};
    let invalid = false;
    for (const field of ['cases', 'boxes', 'each'] as CountField[]) {
      const text = draft[field];
      const saved = item.entry?.[field] ?? null;
      const value = text === undefined ? saved : parseWhole(text, 0, MAX_COUNT);
      if (typeof value === 'number' && Number.isNaN(value)) { invalid = true; break; }
      fields[field] = value;
    }
    if (invalid) { errors[String(item.id)] = `Counts for ${item.name} must be whole numbers from 0 to ${MAX_COUNT}.`; continue; }
    const same = (['cases', 'boxes', 'each'] as CountField[]).every((field) => (fields[field] ?? null) === (item.entry?.[field] ?? null));
    if (!same) lines.push({ supply_item_id: item.id, ...fields });
  }
  return { lines, errors };
}

export const WARNING_TEXT: Record<string, string> = {
  no_previous_count: 'No earlier count',
  unknown_conversion: 'Size not stated',
  unmatched_issuances: 'Issued with no matching item',
  negative_result: 'Would be below zero',
};

export const warningText = (code: string): string => WARNING_TEXT[code] ?? code.replace(/_/g, ' ');

export function reconcileDelta(row: ReconcileRow): string {
  const parts = [`issued ${row.issued}`, `received ${row.received}`];
  if (row.adjusted !== 0) parts.push(`adjusted ${row.adjusted > 0 ? '+' : ''}${row.adjusted}`);
  return parts.join(', ');
}

export function suggestedOrderText(advisory: Advisory): string {
  const unit = advisory.package_unit && advisory.package_unit !== 'each' ? advisory.package_unit : 'package';
  if (advisory.suggested_basis === 'unknown_package_size' || advisory.suggested_packages === null) return 'Package size not stated: decide the amount by hand';
  const count = `${advisory.suggested_packages} ${advisory.suggested_packages === 1 ? unit : (unit === 'box' ? 'boxes' : `${unit}s`)}`;
  return advisory.suggested_basis === 'source_order_amount'
    ? `${count} (the order sheet's amount${advisory.order_amount_text ? `: ${advisory.order_amount_text}` : ''})`
    : `${count} (worked out from how far below the threshold it is)`;
}

export const movementText = (movement: { movement_type: string; quantity_base: number }): string => {
  const sign = movement.quantity_base > 0 ? '+' : '';
  const kind = movement.movement_type === 'issued' ? 'Issued' : movement.movement_type === 'received' ? 'Received' : 'Adjusted';
  return `${kind} ${sign}${movement.quantity_base}`;
};

export const snapshotExportUrl = (id: string, format: 'xlsx' | 'pdf'): string => `/ems/api/supply/snapshots/${encodeURIComponent(id)}/export/${format}`;

// ------------------------------------------------------------------- statistics

export function statsQuery(range: { from: string; to: string }, unit: string, targetKind: string): string {
  const params = new URLSearchParams();
  if (range.from !== '') params.set('date_from', range.from);
  if (range.to !== '') params.set('date_to', range.to);
  if (/^\d+$/.test(unit)) params.set('apparatus_id', unit);
  if (targetKind !== '') params.set('target_kind', targetKind);
  return params.toString();
}

export const perWeekText = (value: number): string => `${Number.isInteger(value) ? value : value.toFixed(2).replace(/0+$/, '').replace(/\.$/, '')} per week`;

export const STATS_FOOTNOTE = 'These figures count requests and issuances, not supplies used on calls.';

export const statsIsEmpty = (report: StatisticsReport): boolean => report.totals.requests_counted === 0;

export const detailFor = (request: Pick<RequestDetail, 'status'>): { canReopen: boolean; canClose: boolean; canWork: boolean } => ({
  canReopen: request.status === 'closed',
  canClose: isRequestOpen(request),
  canWork: isRequestOpen(request),
});
