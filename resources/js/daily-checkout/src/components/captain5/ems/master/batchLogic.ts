/**
 * Pure rules for Edit mode: one draft per touched row, what changed, what must be fixed, the live "to order", and
 * where the server's field errors belong. No React and no fetch, so `node --test` loads it directly.
 *
 * The on-hand and expected boxes follow the item's own numbers until the person types in them. Changing the count unit
 * or size clears anything typed in those two boxes (`withCounting`): a number typed in the old unit must never be sent
 * as if it were in the new one, because an `on_hand` is a physical count. The boxes then show the saved amount in the new
 * unit, nothing is sent for them, and the row says so, so the person can type the count again in the new unit.
 * An empty on-hand box means "no change"; nothing in Edit mode is required.
 */
import { MAX_NAME, MAX_SHELF_LABEL, NEW_SHELF } from './editLogic.ts';
import { formatQty } from './logic.ts';
import { itemLines, levelFor, presetReorder, stockLines, type StockLevel, type StockLines } from './stockLevel.ts';
import type { BatchChange, CountUnit, MasterItem } from './types.ts';

export interface RowDraft {
  name: string;
  shelf: string;
  newShelf: string;
  countUnit: CountUnit;
  perPackage: string;
  onHand: string;
  expected: string;
  /** The "Yellow at" box. Blank means the preset (half the expected amount). */
  reorder: string;
  /** The "Red at" box. Blank means the preset (a quarter of the expected amount). */
  critical: string;
  onHandTouched: boolean;
  expectedTouched: boolean;
  reorderTouched: boolean;
  criticalTouched: boolean;
  /** A unit or size change threw away numbers typed in the old unit. Cleared when a number is typed again. */
  numbersReset: boolean;
}

export type RowField = 'name' | 'shelf' | 'countUnit' | 'perPackage' | 'onHand' | 'expected' | 'reorder' | 'critical' | 'row';
export type RowErrors = Partial<Record<RowField, string>>;
export type Drafts = Readonly<Record<string, RowDraft>>;

/** The two editable stock lines. */
export type LineField = 'reorder' | 'critical';

// The level fields are optional here so an older answer (or a test item) without them still plans and colours.
type DraftBasis = Pick<MasterItem,
  'id' | 'name' | 'location_code' | 'count_unit' | 'units_per_package' | 'units_per_count_unit' | 'on_hand' | 'on_hand_base' | 'expected' | 'required_base' | 'to_order'>
  & Partial<Pick<MasterItem, 'level' | 'reorder_at' | 'critical_at' | 'reorder_at_auto' | 'critical_at_auto'>>;

export const REORDER_ABOVE_EXPECTED = 'Yellow at cannot be above the expected amount.';
export const CRITICAL_ABOVE_REORDER = 'Red at cannot be above Yellow at.';
export const LINE_NEEDS_EXPECTED = 'Set the expected amount first.';
export const LINE_MESSAGE = 'Type a whole number, 0 or more. Leave it blank for the automatic amount.';

const WHOLE = /^\d{1,6}$/;
const DECIMAL = /^\d{1,6}(\.\d)?$/;
export const WHOLE_MESSAGE = 'Type a whole number, 0 or more.';

/** The size box as the item has it now: blank for each. */
export const startSize = (item: Pick<MasterItem, 'count_unit' | 'units_per_package' | 'units_per_count_unit'>): string => (
  item.count_unit === 'each' ? '' : String(item.units_per_package ?? item.units_per_count_unit ?? '')
);

export function startDraft(item: DraftBasis): RowDraft {
  return {
    name: item.name,
    shelf: item.location_code ?? '',
    newShelf: '',
    countUnit: item.count_unit,
    perPackage: startSize(item),
    onHand: '',
    expected: '',
    reorder: '',
    critical: '',
    onHandTouched: false,
    expectedTouched: false,
    reorderTouched: false,
    criticalTouched: false,
    numbersReset: false,
  };
}

/**
 * A new count unit or size. Numbers typed in the old unit are cleared, never carried over: the boxes go back to the
 * saved amounts shown in the new unit, and only a number typed after this change can be sent.
 */
export function withCounting(draft: RowDraft, patch: { countUnit?: CountUnit; perPackage?: string }): RowDraft {
  const next = { ...draft, ...patch };
  if (next.countUnit === draft.countUnit && next.perPackage === draft.perPackage) return next;
  const hadTyped = draft.onHandTouched || draft.expectedTouched || draft.reorderTouched || draft.criticalTouched;
  return {
    ...next,
    onHand: '', expected: '', reorder: '', critical: '',
    onHandTouched: false, expectedTouched: false, reorderTouched: false, criticalTouched: false,
    numbersReset: draft.numbersReset || hadTyped,
  };
}

/** A number typed in the on-hand or expected box, in the row's current unit. */
export function withNumber(draft: RowDraft, field: 'onHand' | 'expected', text: string): RowDraft {
  return field === 'onHand'
    ? { ...draft, onHand: text, onHandTouched: true, numbersReset: false }
    : { ...draft, expected: text, expectedTouched: true, numbersReset: false };
}

/** A number typed in the Yellow at or Red at box, in the row's current unit. Blank means back to the preset. */
export function withLine(draft: RowDraft, field: LineField, text: string): RowDraft {
  return field === 'reorder'
    ? { ...draft, reorder: text, reorderTouched: true, numbersReset: false }
    : { ...draft, critical: text, criticalTouched: true, numbersReset: false };
}
/** Single units in one count unit, or null while the size box is not a whole number of 1 or more. */
export function sizeOf(unit: CountUnit, perPackage: string): number | null {
  if (unit === 'each') return 1;
  return WHOLE.test(perPackage.trim()) && Number(perPackage) >= 1 ? Number(perPackage) : null;
}

const sameCounting = (item: DraftBasis, draft: RowDraft): boolean => (
  draft.countUnit === item.count_unit && (draft.countUnit === 'each' || draft.perPackage.trim() === startSize(item))
);

/** An amount the item holds in its current unit, shown in the unit the draft has chosen. Blank when unknown. */
function inDraftUnit(amount: number | null, item: DraftBasis, draft: RowDraft): string {
  if (amount === null || amount === undefined) return '';
  if (sameCounting(item, draft)) return formatQty(amount);
  const size = sizeOf(draft.countUnit, draft.perPackage);
  if (size === null || !(item.units_per_count_unit > 0)) return '';
  return formatQty((amount * item.units_per_count_unit) / size);
}

export const shownOnHand = (item: DraftBasis, draft: RowDraft): string => (draft.onHandTouched ? draft.onHand : inDraftUnit(item.on_hand, item, draft));
export const shownExpected = (item: DraftBasis, draft: RowDraft): string => (draft.expectedTouched ? draft.expected : inDraftUnit(item.expected, item, draft));

const sameNumber = (text: string, baseline: string): boolean => text === baseline || (DECIMAL.test(text) && DECIMAL.test(baseline) && Number(text) === Number(baseline));

// ------------------------------------------------------------------ stock lines

const LINE_KEY = { reorder: 'reorder_at', critical: 'critical_at' } as const;
const AUTO_KEY = { reorder: 'reorder_at_auto', critical: 'critical_at_auto' } as const;
const TOUCHED_KEY = { reorder: 'reorderTouched', critical: 'criticalTouched' } as const;

/** A person's own line from the server, in the draft's unit (rounded down). Null when the line is the preset. */
function savedOverride(item: DraftBasis, draft: RowDraft, field: LineField): number | null {
  const value = item[LINE_KEY[field]];
  if (item[AUTO_KEY[field]] !== false || typeof value !== 'number') return null;
  if (sameCounting(item, draft)) return value;
  const size = sizeOf(draft.countUnit, draft.perPackage);
  if (size === null || !(item.units_per_count_unit > 0)) return null;
  return Math.floor((value * item.units_per_count_unit) / size + 1e-9);
}

/** A typed line: null when blank (the preset), NaN when it is not a whole number. */
const typedLine = (text: string): number | null => {
  const trimmed = text.trim();
  if (trimmed === '') return null;
  return WHOLE.test(trimmed) ? Number(trimmed) : Number.NaN;
};

/** What the Yellow at or Red at box shows: the typed text, the person's saved line, or blank for the preset. */
export function shownLine(item: DraftBasis, draft: RowDraft, field: LineField): string {
  if (draft[TOUCHED_KEY[field]]) return draft[field];
  const saved = savedOverride(item, draft, field);
  return saved === null ? '' : String(saved);
}

/** The line a person chose for this row (typed, or saved earlier): null for the preset, NaN when the box is not a number. */
const chosenLine = (item: DraftBasis, draft: RowDraft, field: LineField): number | null => (
  draft[TOUCHED_KEY[field]] ? typedLine(draft[field]) : savedOverride(item, draft, field)
);

/** The expected amount in the draft's unit: null when there is none, NaN when the box is not a number. */
function draftExpected(item: DraftBasis, draft: RowDraft): number | null {
  if (!draft.expectedTouched && sameCounting(item, draft)) return item.expected;
  const size = sizeOf(draft.countUnit, draft.perPackage);
  if (size === null) return Number.NaN;
  const base = draft.expectedTouched ? typedBase(draft.expected, size) : savedExpectedBase(item);
  return base === null ? null : base / size;
}

/**
 * The yellow and red lines for the row as it stands. With the unit and expected amount unchanged, an untouched line is
 * the server's own number, as given; otherwise the presets are worked out again from the expected amount in the boxes.
 */
export function draftLines(item: DraftBasis, draft: RowDraft): StockLines | null {
  const expected = draftExpected(item, draft);
  if (expected === null || Number.isNaN(expected)) return null;
  const asGiven = !draft.expectedTouched && sameCounting(item, draft) && item.reorder_at !== undefined;
  const pick = (field: LineField): number | null => {
    if (!draft[TOUCHED_KEY[field]] && asGiven) return item[LINE_KEY[field]] ?? null;
    const chosen = chosenLine(item, draft, field);
    return chosen === null || Number.isNaN(chosen) ? null : chosen;
  };
  return stockLines(expected, pick('reorder'), pick('critical'));
}

/** The number a blank Yellow at or Red at box stands for (the preset), or null when there is no expected amount yet. */
export function linePreset(item: DraftBasis, draft: RowDraft, field: LineField): number | null {
  return draftLines(item, withLine(draft, field, ''))?.[field] ?? null;
}

/**
 * The colour for a row in Edit mode. Untouched rows show the server's level; a typed on hand, expected amount or line
 * recolours the row straight away. A blank on-hand box keeps the saved number.
 */
export function liveLevel(item: DraftBasis, draft: RowDraft): StockLevel | null {
  const untouched = !draft.onHandTouched && !draft.expectedTouched && !draft.reorderTouched && !draft.criticalTouched && sameCounting(item, draft);
  if (untouched) return item.level !== undefined ? item.level : levelFor(item.on_hand, itemLines(item));
  const size = sizeOf(draft.countUnit, draft.perPackage);
  if (size === null) return null;
  const typed = draft.onHandTouched ? typedBase(draft.onHand, size) : null;
  if (typed !== null && Number.isNaN(typed)) return null;
  const base = typed ?? savedOnHandBase(item);
  if (base === null) return null;
  return levelFor(base / size, draftLines(item, draft));
}

function checkLineBoxes(draft: RowDraft, item: DraftBasis, errors: RowErrors, change: Record<string, unknown>): void {
  for (const field of ['reorder', 'critical'] as const) {
    if (!draft[TOUCHED_KEY[field]]) continue;
    const text = draft[field].trim();
    if (sameNumber(text, shownLine(item, { ...draft, [TOUCHED_KEY[field]]: false }, field))) continue;
    const typed = typedLine(text);
    if (typed !== null && Number.isNaN(typed)) errors[field] = LINE_MESSAGE;
    else change[LINE_KEY[field]] = typed;
  }
}

/**
 * A typed Yellow at never above the expected amount, Red at never above Yellow at, and no line without an expected
 * amount. Lowering only the expected amount is allowed: a higher saved Yellow at then sits at the expected amount, as
 * the server does it.
 */
function checkLines(draft: RowDraft, item: DraftBasis, errors: RowErrors, change: Record<string, unknown>): void {
  checkLineBoxes(draft, item, errors, change);
  if (!(draft.reorderTouched || draft.criticalTouched || draft.expectedTouched) || errors.reorder || errors.critical) return;
  const expected = draftExpected(item, draft);
  if (expected !== null && Number.isNaN(expected)) return;
  const reorder = chosenLine(item, draft, 'reorder');
  const critical = chosenLine(item, draft, 'critical');
  if (expected === null || expected < 1) {
    if (draft.reorderTouched && reorder !== null) errors.reorder = LINE_NEEDS_EXPECTED;
    if (draft.criticalTouched && critical !== null) errors.critical = LINE_NEEDS_EXPECTED;
    return;
  }
  if (draft.reorderTouched && reorder !== null && reorder > expected) errors.reorder = REORDER_ABOVE_EXPECTED;
  const yellow = Math.min(Math.floor(expected), reorder ?? presetReorder(expected));
  if ((draft.reorderTouched || draft.criticalTouched) && critical !== null && critical > yellow) errors.critical = CRITICAL_ABOVE_REORDER;
}

function checkName(draft: RowDraft, item: DraftBasis, errors: RowErrors, change: Record<string, unknown>): void {
  const name = draft.name.trim();
  if (name === '') errors.name = 'Enter a name.';
  else if (name.length > MAX_NAME) errors.name = `Keep the name under ${MAX_NAME} characters.`;
  else if (name !== item.name) change.name = name;
}

function checkShelf(draft: RowDraft, item: DraftBasis, errors: RowErrors, change: Record<string, unknown>): void {
  if (draft.shelf === NEW_SHELF) {
    const label = draft.newShelf.trim();
    if (label === '') errors.shelf = 'Type a name for the new shelf.';
    else if (label.length > MAX_SHELF_LABEL) errors.shelf = `Keep the shelf name under ${MAX_SHELF_LABEL} characters.`;
    else change.new_location_label = label;
  } else if (draft.shelf !== (item.location_code ?? '')) {
    change.location_code = draft.shelf === '' ? null : draft.shelf;
  }
}

function checkCounting(draft: RowDraft, item: DraftBasis, errors: RowErrors, change: Record<string, unknown>): void {
  const unitChanged = draft.countUnit !== item.count_unit;
  if (unitChanged) change.count_unit = draft.countUnit;
  if (draft.countUnit === 'each') return;
  const size = sizeOf(draft.countUnit, draft.perPackage);
  if (size === null) errors.perPackage = `Enter how many single units are in one ${draft.countUnit}, 1 or more.`;
  else if (unitChanged || draft.perPackage.trim() !== startSize(item)) change.units_per_package = size;
}

function checkNumbers(draft: RowDraft, item: DraftBasis, errors: RowErrors, change: Record<string, unknown>): void {
  if (draft.onHandTouched) {
    const text = draft.onHand.trim();
    const baseline = inDraftUnit(item.on_hand, item, draft);
    if (!sameNumber(text, baseline)) {
      // An empty box means "leave the on-hand number as it is".
      if (WHOLE.test(text)) change.on_hand = Number(text);
      else if (text !== '') errors.onHand = WHOLE_MESSAGE;
    }
  }
  if (draft.expectedTouched) {
    const text = draft.expected.trim();
    const baseline = inDraftUnit(item.expected, item, draft);
    if (!sameNumber(text, baseline)) {
      if (WHOLE.test(text)) change.expected = Number(text);
      else if (text === '') change.expected = null;
      else errors.expected = `${WHOLE_MESSAGE} Leave it blank for no expected amount.`;
    }
  }
}

/** What one row sends, if anything, and what must be fixed first. */
export function planRow(item: DraftBasis, draft: RowDraft): { change: BatchChange | null; errors: RowErrors } {
  const errors: RowErrors = {};
  const change: Record<string, unknown> = {};
  checkName(draft, item, errors, change);
  checkShelf(draft, item, errors, change);
  checkCounting(draft, item, errors, change);
  checkNumbers(draft, item, errors, change);
  checkLines(draft, item, errors, change);
  return { change: Object.keys(change).length > 0 ? { id: item.id, ...change } as BatchChange : null, errors };
}

export interface BatchPlan {
  changes: BatchChange[];
  errors: Record<string, RowErrors>;
  /** Rows that differ from the item, including rows that cannot be saved yet. */
  dirtyIds: number[];
}

/** Every draft, in list order. Drafts for items that are gone are ignored. */
export function planBatch(items: readonly DraftBasis[], drafts: Drafts): BatchPlan {
  const changes: BatchChange[] = [];
  const errors: Record<string, RowErrors> = {};
  const dirtyIds: number[] = [];
  for (const item of items) {
    const draft = drafts[String(item.id)];
    if (!draft) continue;
    const plan = planRow(item, draft);
    if (plan.change) changes.push(plan.change);
    if (Object.keys(plan.errors).length > 0) errors[String(item.id)] = plan.errors;
    if (plan.change || Object.keys(plan.errors).length > 0) dirtyIds.push(item.id);
  }
  return { changes, errors, dirtyIds };
}

/** Base (single) units the item holds now, or null when it has never been counted. */
function savedOnHandBase(item: DraftBasis): number | null {
  if (item.on_hand === null) return null;
  return item.on_hand_base ?? item.on_hand * item.units_per_count_unit;
}

/** The expected amount in base units, or null when none is set. */
function savedExpectedBase(item: DraftBasis): number | null {
  if (item.expected === null) return null;
  return item.required_base ?? item.expected * item.units_per_count_unit;
}

/** A typed box in base units: null when blank, NaN when it is not a number. */
const typedBase = (text: string, size: number): number | null => {
  const trimmed = text.trim();
  if (trimmed === '') return null;
  return DECIMAL.test(trimmed) ? Number(trimmed) * size : Number.NaN;
};

/**
 * The live "to order" for a row, in the row's current unit. With nothing typed and the unit unchanged it is the server's
 * own `to_order`, so it always matches the badge. Otherwise it is worked out in base units (never from rounded display
 * numbers) and rounded up to a whole count unit.
 */
export function liveToOrder(item: DraftBasis, draft: RowDraft): number | null {
  if (!draft.onHandTouched && !draft.expectedTouched && sameCounting(item, draft)) return item.to_order;
  const size = sizeOf(draft.countUnit, draft.perPackage);
  if (size === null) return null;
  // A blank on-hand box keeps the saved number; a blank expected box clears the expected amount.
  const onHand = draft.onHandTouched ? typedBase(draft.onHand, size) ?? savedOnHandBase(item) : savedOnHandBase(item);
  const expected = draft.expectedTouched ? typedBase(draft.expected, size) : savedExpectedBase(item);
  if (onHand === null || expected === null || Number.isNaN(onHand) || Number.isNaN(expected)) return null;
  return Math.max(0, Math.ceil((expected - onHand) / size - 1e-9));
}

/** The row differs from the item (something would be sent, or something must be fixed). */
export function rowChanged(item: DraftBasis, draft: RowDraft): boolean {
  const plan = planRow(item, draft);
  return plan.change !== null || Object.keys(plan.errors).length > 0;
}

const SERVER_FIELDS: Record<string, RowField> = {
  name: 'name',
  location_code: 'shelf',
  new_location_label: 'shelf',
  count_unit: 'countUnit',
  units_per_package: 'perPackage',
  expected: 'expected',
  on_hand: 'onHand',
  reorder_at: 'reorder',
  critical_at: 'critical',
};

/**
 * A 422 names fields like `changes.3.units_per_package`, where 3 is the index in the array that was sent. Each lands on
 * its row and box; anything that cannot be placed is returned as `general`.
 */
export function placeServerErrors(fields: Readonly<Record<string, string>>, sent: readonly BatchChange[]): { rows: Record<string, RowErrors>; general: string[] } {
  const rows: Record<string, RowErrors> = {};
  const general: string[] = [];
  for (const [key, message] of Object.entries(fields)) {
    const match = /^changes\.(\d+)(?:\.([a-z_]+))?/.exec(key);
    const row = match ? sent[Number(match[1])] : undefined;
    if (!match || !row) { general.push(message); continue; }
    const field = SERVER_FIELDS[match[2] ?? ''] ?? 'row';
    const id = String(row.id);
    rows[id] = { ...rows[id], [field]: rows[id]?.[field] ?? message };
  }
  return { rows, general };
}

/** "Save 1 change", "Save 3 changes". */
export const saveLabel = (count: number): string => `Save ${count} ${count === 1 ? 'change' : 'changes'}`;
