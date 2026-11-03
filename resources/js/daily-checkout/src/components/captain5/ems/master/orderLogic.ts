/**
 * Pure rules for ordering: what the On hand badge says, the summary strip, the filter, and the words in the item sheet.
 * No React and no fetch, so `node --test` loads it directly (tests/Node/ems-master-inventory-logic.test.mjs).
 * Everything returns new values; nothing here changes its arguments.
 */
import { formatQty, unitWord } from './logic.ts';
import { isStockLevel, itemLines, levelFor } from './stockLevel.ts';
import type { CountUnit, MasterItem, OpenOrderStatus, OrderState, OrderSummary, UnitOption } from './types.ts';

/** The count units in the server's order, used when an older server sends no `units` list. */
export const DEFAULT_UNITS: readonly UnitOption[] = [
  { value: 'each', label: 'each', plural: 'each' },
  { value: 'box', label: 'box', plural: 'boxes' },
  { value: 'case', label: 'case', plural: 'cases' },
  { value: 'pack', label: 'pack', plural: 'packs' },
  { value: 'bag', label: 'bag', plural: 'bags' },
  { value: 'bottle', label: 'bottle', plural: 'bottles' },
  { value: 'vial', label: 'vial', plural: 'vials' },
  { value: 'kit', label: 'kit', plural: 'kits' },
  { value: 'roll', label: 'roll', plural: 'rolls' },
  { value: 'pair', label: 'pair', plural: 'pairs' },
  { value: 'tube', label: 'tube', plural: 'tubes' },
  { value: 'carton', label: 'carton', plural: 'cartons' },
  { value: 'can', label: 'can', plural: 'cans' },
  { value: 'set', label: 'set', plural: 'sets' },
  { value: 'sleeve', label: 'sleeve', plural: 'sleeves' },
  { value: 'tray', label: 'tray', plural: 'trays' },
];

export const ALL_COUNT_UNITS: readonly CountUnit[] = DEFAULT_UNITS.map((unit) => unit.value);

/** The singular and plural words for a unit, from the server's list when it has them. */
export function unitOption(units: readonly UnitOption[], value: CountUnit): UnitOption {
  return units.find((unit) => unit.value === value) ?? DEFAULT_UNITS.find((unit) => unit.value === value) ?? { value, label: value, plural: `${value}s` };
}

/** "single units" for each, otherwise the plural ("boxes"). */
export const unitPlural = (units: readonly UnitOption[], value: CountUnit): string => (value === 'each' ? 'single units' : unitOption(units, value).plural);

const STATES: readonly OrderState[] = ['ok', 'needs_order', 'pending', 'ordered', 'backordered', 'no_level', 'not_counted'];
const OPEN: readonly OpenOrderStatus[] = ['pending', 'ordered', 'backordered'];
export const isOpenStatus = (value: unknown): value is OpenOrderStatus => OPEN.includes(value as OpenOrderStatus);

/** Whole count units to order: ceil(max(0, expected - on hand)). Null when either side is unknown. */
export function toOrderFor(onHand: number | null, expected: number | null): number | null {
  if (onHand === null || expected === null || !Number.isFinite(onHand) || !Number.isFinite(expected)) return null;
  return Math.max(0, Math.ceil(expected - onHand - 1e-9));
}

export function computeState(item: Pick<MasterItem, 'on_hand' | 'expected' | 'open_order'>): OrderState {
  if (item.open_order && isOpenStatus(item.open_order.status)) return item.open_order.status;
  if (item.on_hand === null) return 'not_counted';
  if (item.expected === null) return 'no_level';
  return (toOrderFor(item.on_hand, item.expected) ?? 0) > 0 ? 'needs_order' : 'ok';
}

/**
 * Fills the order fields when a server (or an older cached answer) leaves them out: the expected level falls back to
 * the old "order when fewer than" level, and the state is worked out the same way the server does.
 */
export function normalizeItem(raw: MasterItem): MasterItem {
  const legacy = raw.required_base === null || raw.required_base === undefined || !(raw.units_per_count_unit > 0)
    ? null
    : Math.round((raw.required_base / raw.units_per_count_unit) * 10) / 10;
  const expected = raw.expected === undefined ? legacy : raw.expected;
  const openOrder = raw.open_order && isOpenStatus(raw.open_order.status) ? raw.open_order : null;
  const toOrder = raw.to_order === undefined ? toOrderFor(raw.on_hand, expected) : raw.to_order;
  const base = {
    ...raw,
    aliases: Array.isArray(raw.aliases) ? raw.aliases : [],
    review_reasons: Array.isArray(raw.review_reasons) ? raw.review_reasons : [],
    expected,
    to_order: toOrder,
    open_order: openOrder,
    ...levelFields(raw, expected),
  };
  return { ...base, order_state: STATES.includes(raw.order_state) ? raw.order_state : computeState(base) };
}

type LevelFields = Pick<MasterItem, 'level' | 'reorder_at' | 'critical_at' | 'reorder_at_auto' | 'critical_at_auto'>;

/** The stock level fields as the server sent them; an older answer without them gets the presets and a worked-out level. */
function levelFields(raw: MasterItem, expected: number | null): LevelFields {
  const lines = itemLines({ expected, reorder_at: raw.reorder_at, critical_at: raw.critical_at });
  return {
    reorder_at: lines?.reorder ?? null,
    critical_at: lines?.critical ?? null,
    reorder_at_auto: typeof raw.reorder_at_auto === 'boolean' ? raw.reorder_at_auto : true,
    critical_at_auto: typeof raw.critical_at_auto === 'boolean' ? raw.critical_at_auto : true,
    level: raw.level === null || isStockLevel(raw.level) ? raw.level : levelFor(raw.on_hand, lines),
  };
}

export function summarize(items: readonly Pick<MasterItem, 'order_state'>[]): OrderSummary {
  const summary: OrderSummary = { needs_order: 0, pending: 0, ordered: 0, backordered: 0 };
  for (const item of items) if (item.order_state in summary) summary[item.order_state as keyof OrderSummary] += 1;
  return summary;
}

// ---------------------------------------------------------------- the filter

export type OrderFilter = keyof OrderSummary;
export const ORDER_FILTERS: readonly OrderFilter[] = ['needs_order', 'pending', 'ordered', 'backordered'];

/** "3 to order", "1 back ordered". */
export function summaryLabel(filter: OrderFilter, count: number): string {
  switch (filter) {
    case 'needs_order': return `${count} to order`;
    case 'pending': return `${count} pending`;
    case 'ordered': return `${count} ordered`;
    default: return `${count} back ordered`;
  }
}

/** The heading over a filtered list. */
export function filterHeading(filter: OrderFilter, count: number): string {
  const noun = count === 1 ? 'item' : 'items';
  switch (filter) {
    case 'needs_order': return `${count} ${noun} to order`;
    case 'pending': return `${count} ${noun} with a pending order`;
    case 'ordered': return `${count} ${noun} ordered`;
    default: return `${count} ${noun} back ordered`;
  }
}

export const applyFilter = <T extends Pick<MasterItem, 'order_state'>>(items: readonly T[], filter: OrderFilter | null): T[] => (
  filter === null ? [...items] : items.filter((item) => item.order_state === filter)
);

// ---------------------------------------------------------------- wording

type UnitWords = Pick<MasterItem, 'count_unit' | 'count_unit_label' | 'count_unit_label_plural'>;

/** "3 boxes", or just "3" for items counted one by one. */
export function quantityText(item: UnitWords, quantity: number): string {
  const word = unitWord(item, quantity);
  const amount = formatQty(quantity);
  return word === '' ? amount : `${amount} ${word}`;
}

export const STATUS_WORD: Record<OpenOrderStatus, string> = { pending: 'Pending', ordered: 'Ordered', backordered: 'Back ordered' };

export type BadgeTone = 'danger' | 'warning' | 'info' | 'neutral';

type BadgeBasis = Pick<MasterItem, 'order_state' | 'to_order' | 'open_order'> & UnitWords;

/** The one short badge under the on-hand number, or null when nothing needs attention. */
export function orderBadge(item: BadgeBasis): { text: string; tone: BadgeTone } | null {
  if (item.order_state === 'needs_order' && item.to_order !== null && item.to_order > 0) return { text: `Order ${quantityText(item, item.to_order)}`, tone: 'danger' };
  if (item.open_order && isOpenStatus(item.order_state)) {
    const tone: BadgeTone = item.order_state === 'backordered' ? 'warning' : item.order_state === 'ordered' ? 'info' : 'neutral';
    return { text: `${STATUS_WORD[item.order_state]} ${quantityText(item, item.open_order.quantity)}`, tone };
  }
  return null;
}

/** Short words for the state when there is no badge (Edit mode shows one for every row). */
export function stateShort(item: BadgeBasis): string {
  const badge = orderBadge(item);
  if (badge) return badge.text;
  switch (item.order_state) {
    case 'no_level': return 'No expected amount';
    case 'not_counted': return 'Not counted';
    case 'needs_order': return 'Needs ordering';
    default: return 'Stocked';
  }
}

/** Plain words for the state in the item sheet. */
export function stateSentence(item: BadgeBasis): string {
  switch (item.order_state) {
    case 'needs_order': return `Below the expected amount. Order ${quantityText(item, item.to_order ?? 1)}.`;
    case 'pending':
    case 'ordered':
    case 'backordered': return `${STATUS_WORD[item.order_state]}: ${quantityText(item, item.open_order?.quantity ?? 0)}.`;
    case 'no_level': return 'No expected amount is set, so nothing is worked out to order.';
    case 'not_counted': return 'Not counted yet, so nothing is worked out to order.';
    default: return 'At or above the expected amount. Nothing to order.';
  }
}

/** "Nov 5, 2026" for a plain YYYY-MM-DD date, with no time zone shift. */
export function dayText(day: string | null): string {
  if (!day || !/^\d{4}-\d{2}-\d{2}$/.test(day)) return '';
  const [year, month, date] = day.split('-').map(Number);
  return new Intl.DateTimeFormat('en-US', { timeZone: 'UTC', month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(Date.UTC(year, month - 1, date)));
}

/** A whole number of 1 or more typed in an order box, or null. */
export function orderQuantity(text: string): number | null {
  const trimmed = text.trim();
  return /^\d{1,6}$/.test(trimmed) && Number(trimmed) >= 1 ? Number(trimmed) : null;
}

/** The quantity an order form starts with: what is short, or 1. */
export const suggestedQuantity = (item: Pick<MasterItem, 'to_order'>): number => (item.to_order !== null && item.to_order > 0 ? item.to_order : 1);
