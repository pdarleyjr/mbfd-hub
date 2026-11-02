/**
 * Pure maths for the reconcile confirmation: what each line resolves to, how much comes off each shelf item, which items
 * go below zero, how many lines still need a decision, and the decisions the server is sent. The server stays the authority
 * (it recomputes everything and answers 409 stale_preview or 422 needs_correction); this only keeps the screen honest while
 * the person types. No React, no fetch. Imports use .ts so `node --test` loads the file directly.
 */
import { parseWhole } from '../logic.ts';
import type { FormsPreview, MasterItem, PreviewLine, PreviewTotal, ReconcileDecision } from './types.ts';

export interface LineInput {
  /** What the quantity box holds; undefined means "the requested quantity". */
  quantity?: string;
  /** The supply item the person picked for a needs_item line. */
  itemId?: number | null;
  /** The person chose "Not a supply room item". */
  notStocked?: boolean;
  /** Shelf units typed for a needs_unit line (or a picked item whose unit cannot be converted). */
  units?: string;
}

export type LineInputs = Readonly<Record<number, LineInput>>;
export type MasterIndex = ReadonlyMap<number, MasterItem>;

export const isQuietState = (line: Pick<PreviewLine, 'state'>): boolean => line.state === 'no_stock_effect' || line.state === 'not_stocked';

export function indexMaster(items: readonly MasterItem[]): MasterIndex {
  return new Map(items.map((item) => [item.id, item]));
}

/** blank/each/the item's base unit deduct as typed; the package unit multiplies by units_per_package; anything else cannot be converted. */
export function unitFactor(unit: string | null, item: Pick<MasterItem, 'base_unit' | 'package_unit' | 'units_per_package'>): number | null {
  const typed = (unit ?? '').trim().toLowerCase();
  if (typed === '' || typed === 'each' || typed === item.base_unit.trim().toLowerCase()) return 1;
  if (item.package_unit && typed === item.package_unit.trim().toLowerCase() && item.units_per_package !== null && item.units_per_package > 0) return item.units_per_package;
  return null;
}

export interface Resolution {
  line: PreviewLine;
  /** decision = the person must choose something; shelf = comes off a known item; quiet = no stock change. */
  group: 'decision' | 'shelf' | 'quiet';
  itemId: number | null;
  /** Base units that come off the shelf; null while the line is unresolved. */
  baseDeduct: number | null;
  resolved: boolean;
  /** The person types shelf units instead of a quantity. */
  needsUnits: boolean;
  quantityText: string;
  /** Typed quantity (line units) when valid. */
  quantity: number | null;
  /** Typed shelf units when valid. */
  units: number | null;
  problem: string | null;
}

const valid = (value: number | null): value is number => value !== null && !Number.isNaN(value);

/** Base units for a changed quantity: proportional to what the server worked out for the requested one. */
function scaleBase(line: PreviewLine, quantity: number): number {
  if (quantity === line.quantity) return line.quantity_base ?? quantity;
  if (line.quantity > 0 && line.quantity_base !== null) return Math.round((line.quantity_base * quantity) / line.quantity);
  return quantity;
}

const QUANTITY_PROBLEM = 'Enter a whole number. 0 means not taken.';
const UNITS_PROBLEM = 'Enter how many units come off the shelf. 0 means none.';

export function resolveLine(line: PreviewLine, input: LineInput = {}, master: MasterIndex = new Map()): Resolution {
  const quantityText = input.quantity ?? String(line.quantity);
  const typedQuantity = parseWhole(quantityText, 0);
  const typedUnits = parseWhole(input.units ?? '', 0);
  const quantity = valid(typedQuantity) ? typedQuantity : null;
  const units = valid(typedUnits) ? typedUnits : null;
  const base = { line, quantityText, quantity, units, needsUnits: false, problem: null as string | null };

  if (isQuietState(line)) return { ...base, group: 'quiet', itemId: null, baseDeduct: 0, resolved: true };

  if (line.state === 'ready') {
    const ok = quantity !== null;
    return { ...base, group: 'shelf', itemId: line.item?.id ?? null, baseDeduct: ok ? scaleBase(line, quantity) : null, resolved: ok, problem: ok ? null : QUANTITY_PROBLEM };
  }

  if (line.state === 'needs_unit') {
    return { ...base, group: 'decision', itemId: line.item?.id ?? null, needsUnits: true, baseDeduct: units, resolved: units !== null, problem: units === null && input.units !== undefined ? UNITS_PROBLEM : null };
  }

  // needs_item
  if (input.notStocked) return { ...base, group: 'decision', itemId: null, baseDeduct: 0, resolved: true };
  const item = input.itemId === undefined || input.itemId === null ? undefined : master.get(input.itemId);
  if (!item) return { ...base, group: 'decision', itemId: null, baseDeduct: null, resolved: false };
  const factor = unitFactor(line.unit, item);
  if (factor === null) {
    return { ...base, group: 'decision', itemId: item.id, needsUnits: true, baseDeduct: units, resolved: units !== null, problem: units === null && input.units !== undefined ? UNITS_PROBLEM : null };
  }
  const ok = quantity !== null;
  return { ...base, group: 'decision', itemId: item.id, baseDeduct: ok ? quantity * factor : null, resolved: ok, problem: ok ? null : QUANTITY_PROBLEM };
}

export function resolveAll(preview: FormsPreview, inputs: LineInputs, master: MasterIndex): Resolution[] {
  return preview.lines.map((line) => resolveLine(line, inputs[line.line_id], master));
}

export const unresolvedCount = (resolutions: readonly Resolution[]): number => resolutions.filter((entry) => !entry.resolved).length;

// ------------------------------------------------------------------ per item

export interface ItemTake {
  itemId: number;
  name: string;
  shelf: string | null;
  countUnit: string;
  perCount: number;
  deductBase: number;
  beforeBase: number | null;
  afterBase: number | null;
  low: boolean;
  notCounted: boolean;
}

const UNIT_WORDS: Record<string, [string, string]> = { box: ['box', 'boxes'], case: ['case', 'cases'], pack: ['pack', 'packs'], bag: ['bag', 'bags'] };

/** "5", "2.5". One decimal at most, none when it divides evenly. */
export function countText(base: number, perCount: number): string {
  const value = perCount > 0 ? base / perCount : base;
  return Number.isInteger(value) ? String(value) : String(Math.round(value * 10) / 10);
}

/** The unit word for a count: nothing for "each", otherwise "box" or "boxes". */
export function unitWord(countUnit: string, value: number): string {
  const words = UNIT_WORDS[countUnit];
  return words ? (value === 1 ? words[0] : words[1]) : '';
}

export function takeText(take: Pick<ItemTake, 'deductBase' | 'perCount' | 'countUnit'>): string {
  const text = countText(take.deductBase, take.perCount);
  const word = unitWord(take.countUnit, Number(text));
  return `Take ${text}${word ? ` ${word}` : ''}`;
}

/** "-3" or "-1.5 boxes": what a count would show after this. */
export function onBooksText(base: number, take: Pick<ItemTake, 'perCount' | 'countUnit'>): string {
  const text = countText(base, take.perCount);
  const word = unitWord(take.countUnit, Math.abs(Number(text)));
  return `${text}${word ? ` ${word}` : ''}`;
}

export function itemTakes(preview: FormsPreview, resolutions: readonly Resolution[], master: MasterIndex): ItemTake[] {
  const totals = new Map<number, PreviewTotal>(preview.totals.map((total) => [total.supply_item_id, total]));
  const deduct = new Map<number, number>();
  const known = new Map<number, PreviewLine['item']>();
  for (const entry of resolutions) {
    if (entry.itemId === null) continue;
    deduct.set(entry.itemId, (deduct.get(entry.itemId) ?? 0) + (entry.baseDeduct ?? 0));
    if (entry.line.item) known.set(entry.itemId, entry.line.item);
  }
  const takes: ItemTake[] = [];
  for (const [itemId, deductBase] of deduct) {
    const total = totals.get(itemId);
    const item = master.get(itemId);
    const line = known.get(itemId);
    const beforeBase = total?.before_base ?? item?.on_hand_base ?? null;
    const notCounted = total?.not_counted ?? (item ? item.counted_at === null : false);
    const afterBase = beforeBase === null ? null : beforeBase - deductBase;
    takes.push({
      itemId,
      name: total?.name ?? item?.name ?? line?.name ?? 'Supply item',
      shelf: total?.location_code ?? item?.location_code ?? line?.location_code ?? null,
      countUnit: total?.count_unit ?? item?.count_unit ?? line?.count_unit ?? 'each',
      perCount: total?.units_per_count_unit ?? item?.units_per_count_unit ?? 1,
      deductBase,
      beforeBase,
      afterBase,
      low: !notCounted && afterBase !== null && afterBase < 0,
      notCounted,
    });
  }
  return takes.sort((a, b) => (a.shelf ?? '~').localeCompare(b.shelf ?? '~') || a.name.localeCompare(b.name));
}

export const hasLowStock = (takes: readonly ItemTake[]): boolean => takes.some((take) => take.low);

// ------------------------------------------------------------------ what is sent

/** Only what differs from the server's own preview: changed quantities, picked items, typed units, "not a supply room item". */
export function buildDecisions(resolutions: readonly Resolution[]): ReconcileDecision[] {
  const decisions: ReconcileDecision[] = [];
  for (const entry of resolutions) {
    const { line } = entry;
    if (!entry.resolved || entry.group === 'quiet') continue;
    if (line.state === 'ready') {
      if (entry.quantity !== null && entry.quantity !== line.quantity) decisions.push({ line_id: line.line_id, quantity: entry.quantity });
    } else if (line.state === 'needs_unit') {
      if (entry.units !== null) decisions.push({ line_id: line.line_id, quantity_base: entry.units });
    } else if (entry.itemId === null) {
      decisions.push({ line_id: line.line_id, not_stocked: true });
    } else if (entry.needsUnits && entry.units !== null) {
      decisions.push({ line_id: line.line_id, supply_item_id: entry.itemId, quantity_base: entry.units });
    } else if (entry.quantity !== null) {
      decisions.push({ line_id: line.line_id, supply_item_id: entry.itemId, quantity: entry.quantity });
    }
  }
  return decisions;
}

/** Same content, same key: the signature is everything the server would see. */
export function reconcileSignature(ids: readonly string[], expectedLastMovementId: number, acceptLowStock: boolean, decisions: readonly ReconcileDecision[]): string {
  return JSON.stringify({ ids: [...ids].sort(), expectedLastMovementId, acceptLowStock, decisions });
}

/** Typed values survive a reloaded preview, but not for lines that are no longer on it. */
export function keepInputs(inputs: LineInputs, preview: FormsPreview): LineInputs {
  const present = new Set(preview.lines.map((line) => line.line_id));
  return Object.fromEntries(Object.entries(inputs).filter(([id]) => present.has(Number(id))));
}

/** The line ids a 422 needs_correction names, read from the error's fields. */
export function correctionLineIds(fields: Readonly<Record<string, string>>, preview: FormsPreview): number[] {
  const known = new Set(preview.lines.map((line) => line.line_id));
  const ids = new Set<number>();
  for (const [name, message] of Object.entries(fields)) {
    for (const match of `${name} ${message}`.matchAll(/\d+/g)) {
      const id = Number(match[0]);
      if (known.has(id)) ids.add(id);
    }
  }
  return [...ids];
}
