/**
 * Pure helpers for the Master Supply Room Inventory table and the Monday count form: order maths, search, shelf
 * navigation, display wording, and the merge and batching rules behind draft saving. No React and no fetch, so
 * `node --test` loads this file directly (tests/Node/ems-master-inventory-logic.test.mjs).
 * Everything returns new values; nothing here changes its arguments.
 */
import { etDate } from '../logic.ts';
import type {
  EntryMap, EntryWrite, LastCount, MasterItem, MasterSection, OrderBasis, OrderResult, ReviewMissing,
} from './types.ts';

// ---------------------------------------------------------------- order result

/**
 * What to order for a typed count (contract section 5). Worked in base units and shown in the count unit, rounded
 * up to a whole count unit. No order level means "none set", never a guess.
 */
export function orderFor(typedCountUnits: number, item: OrderBasis): OrderResult {
  const units = item.units_per_count_unit;
  if (item.required_base === null || item.required_base === undefined || !(units > 0) || !Number.isFinite(typedCountUnits)) return { kind: 'none-set' };
  const shortfall = item.required_base - typedCountUnits * units;
  if (shortfall <= 0) return { kind: 'no-order' };
  return { kind: 'order', quantity: Math.max(1, Math.ceil(shortfall / units - 1e-9)) };
}

const isEach = (item: Pick<MasterItem, 'count_unit'>): boolean => item.count_unit === 'each';

/** "1 box" or "2 boxes"; empty for items counted by the each. */
export function unitWord(item: Pick<MasterItem, 'count_unit' | 'count_unit_label' | 'count_unit_label_plural'>, quantity: number): string {
  if (isEach(item)) return '';
  return quantity === 1 ? item.count_unit_label : item.count_unit_label_plural;
}

export function orderText(result: OrderResult, item: Pick<MasterItem, 'count_unit' | 'count_unit_label' | 'count_unit_label_plural'>): string {
  if (result.kind === 'none-set') return 'No order level set';
  if (result.kind === 'no-order') return 'No order needed';
  const word = unitWord(item, result.quantity);
  return word === '' ? `Order ${result.quantity}` : `Order ${result.quantity} ${word}`;
}

// ------------------------------------------------------------------ display

/** A whole number, or one decimal when the count does not divide evenly. */
export function formatQty(value: number): string {
  const rounded = Math.round(value * 10) / 10;
  return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1);
}

/** The red "-5" beside the on-hand number, with the words a screen reader says. Null when nothing was deducted. */
export function deductedDisplay(item: Pick<MasterItem, 'deducted'>): { text: string; label: string } | null {
  if (!(item.deducted > 0)) return null;
  const amount = formatQty(item.deducted);
  return { text: `-${amount}`, label: `minus ${amount} since last count` };
}

export function lastCountText(last: Pick<LastCount, 'finalized_at' | 'finalized_name'> | null): string | null {
  if (!last || !last.finalized_at) return null;
  const date = etDate(last.finalized_at);
  return last.finalized_name ? `Last counted ${date} by ${last.finalized_name}` : `Last counted ${date}`;
}

/** Plain words for a machine reason such as `unknown_conversion`. */
export const humanReason = (reason: string): string => {
  const text = reason.replace(/[_-]+/g, ' ').trim();
  return text === '' ? text : text.charAt(0).toUpperCase() + text.slice(1);
};

export const shelfName = (item: Pick<MasterItem, 'location_code' | 'location_label'>): string => item.location_code ?? 'No shelf';

// -------------------------------------------------------------------- search

/** Lower case, no accents or punctuation, and digits split from letters, so "18g", "18 g" and "18 gauge" share tokens. */
export function normalizeText(text: string): string {
  return text
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/([a-z])(\d)/g, '$1 $2')
    .replace(/(\d)([a-z])/g, '$1 $2')
    .replace(/[^a-z0-9]+/g, ' ')
    .trim();
}

const tokensOf = (text: string): string[] => normalizeText(text).split(' ').filter((token) => token !== '');

export interface SearchEntry {
  item: MasterItem;
  position: number;
  name: string;
  nameTokens: string[];
  aliasTokens: string[];
  placeTokens: string[];
}

export function buildSearchIndex(items: readonly MasterItem[]): SearchEntry[] {
  return items.map((item, position) => ({
    item,
    position,
    name: normalizeText(item.name),
    nameTokens: tokensOf(item.name),
    aliasTokens: item.aliases.flatMap(tokensOf),
    placeTokens: [item.location_code, item.location_label, item.section_label].flatMap((part) => tokensOf(part ?? '')),
  }));
}

const tokenFound = (query: string, haystack: readonly string[]): boolean => (
  haystack.some((token) => token.startsWith(query) || (query.length >= 3 && token.includes(query)))
);
const allFound = (queries: readonly string[], haystack: readonly string[]): boolean => queries.every((query) => tokenFound(query, haystack));

/** Lower is better. Null when the entry does not match. Exact name matches come first. */
function rankOf(entry: SearchEntry, phrase: string, queries: readonly string[]): number | null {
  if (entry.name === phrase) return 0;
  if (entry.aliasTokens.length > 0 && entry.item.aliases.some((alias) => normalizeText(alias) === phrase)) return 1;
  if (entry.name.startsWith(phrase)) return 2;
  if (allFound(queries, entry.nameTokens)) return 3;
  const withAliases = [...entry.nameTokens, ...entry.aliasTokens];
  if (allFound(queries, withAliases)) return 4;
  if (allFound(queries, [...withAliases, ...entry.placeTokens])) return 5;
  return null;
}

/** Every shelf at once: name, aliases, shelf code and label, section label. Word order does not matter. */
export function searchItems(index: readonly SearchEntry[], query: string, limit = 200): MasterItem[] {
  const phrase = normalizeText(query);
  if (phrase === '') return [];
  const queries = phrase.split(' ');
  return index
    .map((entry) => ({ entry, rank: rankOf(entry, phrase, queries) }))
    .filter((row): row is { entry: SearchEntry; rank: number } => row.rank !== null)
    .sort((a, b) => a.rank - b.rank || a.entry.name.localeCompare(b.entry.name) || a.entry.position - b.entry.position)
    .slice(0, limit)
    .map((row) => row.entry.item);
}

// ----------------------------------------------------------- shelf navigation

/** The `shelf=` value for the "No shelf assigned" group. */
export const NO_SHELF_PARAM = 'none';

export const sectionParam = (section: Pick<MasterSection, 'code'>): string => section.code ?? NO_SHELF_PARAM;

export const clampIndex = (index: number, total: number): number => (total <= 0 ? 0 : Math.min(Math.max(0, Math.trunc(index)), total - 1));

/** The shelf a `shelf=` value points at; the first shelf when it is missing or unknown. */
export function sectionIndex(sections: readonly MasterSection[], param: string | null): number {
  if (param === null) return 0;
  const found = sections.findIndex((section) => sectionParam(section) === param);
  return found < 0 ? 0 : found;
}

export const stepIndex = (index: number, delta: number, total: number): number => clampIndex(index + delta, total);

export const shelfPosition = (index: number, total: number): string => `Shelf ${clampIndex(index, total) + 1} of ${total}`;

export const sectionOptionLabel = (section: MasterSection): string => `${section.label} (${section.item_count})`;

/** Items by shelf, in the order the server sent them. An item on an unknown shelf is listed under "No shelf assigned". */
export function groupBySection(items: readonly MasterItem[], sections: readonly MasterSection[]): Map<string, MasterItem[]> {
  const known = new Set(sections.map(sectionParam));
  const groups = new Map<string, MasterItem[]>(sections.map((section) => [sectionParam(section), []]));
  for (const item of items) {
    const key = item.location_code !== null && known.has(item.location_code) ? item.location_code : NO_SHELF_PARAM;
    const list = groups.get(key);
    if (list) list.push(item); else groups.set(key, [item]);
  }
  return groups;
}

/** Where to go when "Next" is pressed on the last item of a shelf: the first item of the next shelf with items. */
export function nextItemPosition(shelves: readonly (readonly MasterItem[])[], shelf: number, row: number): { shelf: number; row: number } | null {
  if (row + 1 < (shelves[shelf]?.length ?? 0)) return { shelf, row: row + 1 };
  for (let next = shelf + 1; next < shelves.length; next += 1) if (shelves[next].length > 0) return { shelf: next, row: 0 };
  return null;
}

export function previousItemPosition(shelves: readonly (readonly MasterItem[])[], shelf: number, row: number): { shelf: number; row: number } | null {
  if (row > 0) return { shelf, row: row - 1 };
  for (let prev = shelf - 1; prev >= 0; prev -= 1) if (shelves[prev].length > 0) return { shelf: prev, row: shelves[prev].length - 1 };
  return null;
}

// ------------------------------------------------------------- count entries

export const MAX_ENTRY = 999_999;

/** What a person may type: digits only, six at most, no leading zeros (a single 0 is a real count). */
export function digitsOnly(text: string): string {
  return text.replace(/\D/g, '').slice(0, 6).replace(/^0+(?=\d)/, '');
}

/** Blank is null (not entered); anything else is a whole number. */
export function parseEntry(text: string): number | null {
  const digits = digitsOnly(text);
  return digits === '' ? null : Number(digits);
}

export const entryText = (entries: Readonly<EntryMap>, id: number): string => (id in entries ? String(entries[String(id)] ?? '') : '');

/** Dirty means typed but not yet confirmed by the server. */
export type Dirty = Readonly<Record<string, true>>;

export function withEntry(entries: Readonly<EntryMap>, id: number, value: number | null): EntryMap {
  const key = String(id);
  if (value === null) {
    const { [key]: _removed, ...rest } = entries;
    void _removed;
    return rest;
  }
  return { ...entries, [key]: value };
}

export const withDirty = (dirty: Dirty, id: number): Dirty => ({ ...dirty, [String(id)]: true });

export const dirtyCount = (dirty: Dirty): number => Object.keys(dirty).length;

/** The server's entries, with this device's unsent numbers on top (they win), including numbers cleared on the device. */
export function mergeEntries(server: Readonly<EntryMap>, local: Readonly<EntryMap>, dirty: Dirty): EntryMap {
  const merged: EntryMap = { ...server };
  for (const id of Object.keys(dirty)) {
    if (id in local) merged[id] = local[id]; else delete merged[id];
  }
  return merged;
}

/** The next PUT: unsent numbers in item order, at most `max` at a time. A cleared number is sent as null. */
export function buildBatch(entries: Readonly<EntryMap>, dirty: Dirty, max = 100): EntryWrite[] {
  return Object.keys(dirty)
    .map(Number)
    .filter((id) => Number.isInteger(id))
    .sort((a, b) => a - b)
    .slice(0, max)
    .map((id) => ({ supply_item_id: id, on_hand: entries[String(id)] ?? null }));
}

/** After a confirmed save: forget what was sent, but keep anything typed again while the request was out. */
export function settleDirty(dirty: Dirty, sent: readonly EntryWrite[], current: Readonly<EntryMap>): Dirty {
  const remaining: Record<string, true> = { ...dirty };
  for (const write of sent) {
    const key = String(write.supply_item_id);
    if ((current[key] ?? null) === write.on_hand) delete remaining[key];
  }
  return remaining;
}

/** The server must now hold exactly what was sent. Anything else is not a save. */
export function savedMatches(sent: readonly EntryWrite[], serverEntries: Readonly<EntryMap> | null | undefined): boolean {
  if (!serverEntries || typeof serverEntries !== 'object') return false;
  return sent.every((write) => (serverEntries[String(write.supply_item_id)] ?? null) === write.on_hand);
}

/** Seconds-scale wait before trying a failed save again: 1, 2, 4, 8, 16, then every 30. */
export const retryDelayMs = (attempt: number): number => Math.min(30_000, 1000 * 2 ** Math.max(0, attempt));

export function enteredTotal(items: readonly Pick<MasterItem, 'id'>[], entries: Readonly<EntryMap>): number {
  return items.reduce((total, item) => total + (String(item.id) in entries ? 1 : 0), 0);
}

// ------------------------------------------------------------ device storage

export const COUNT_STORAGE_PREFIX = 'mbfd-ems-count';

/** One key per open count, shared by whoever uses this device. */
export const countStorageKey = (countId: string): string => `${COUNT_STORAGE_PREFIX}:${countId}:room`;

export function serializeDraft(entries: Readonly<EntryMap>, dirty: Dirty): string {
  return JSON.stringify({ v: 1, entries, dirty: Object.keys(dirty) });
}

/** Unreadable or foreign data is ignored; only whole numbers and ids survive. */
export function parseDraft(raw: string | null): { entries: EntryMap; dirty: Dirty } | null {
  if (!raw) return null;
  try {
    const data = JSON.parse(raw) as { v?: unknown; entries?: unknown; dirty?: unknown };
    if (data.v !== 1 || typeof data.entries !== 'object' || data.entries === null || !Array.isArray(data.dirty)) return null;
    const entries: Record<string, number> = {};
    for (const [id, value] of Object.entries(data.entries as Record<string, unknown>)) {
      if (/^\d+$/.test(id) && Number.isInteger(value) && (value as number) >= 0 && (value as number) <= MAX_ENTRY) entries[id] = value as number;
    }
    const dirty: Record<string, true> = {};
    for (const id of data.dirty) if (typeof id === 'string' && /^\d+$/.test(id)) dirty[id] = true;
    return { entries, dirty };
  } catch {
    return null;
  }
}

// ------------------------------------------------------------------- review

/** Missing items grouped under their shelf, in shelf order. */
export function groupMissing(missing: readonly ReviewMissing[], sections: readonly MasterSection[]): Array<{ code: string | null; label: string; items: ReviewMissing[] }> {
  const groups = new Map<string, { code: string | null; label: string; items: ReviewMissing[] }>();
  for (const section of sections) groups.set(sectionParam(section), { code: section.code, label: section.label, items: [] });
  for (const entry of missing) {
    const key = entry.location_code ?? NO_SHELF_PARAM;
    const group = groups.get(key) ?? { code: entry.location_code, label: entry.location_code ?? 'No shelf assigned', items: [] };
    groups.set(key, { ...group, items: [...group.items, entry] });
  }
  return [...groups.values()].filter((group) => group.items.length > 0);
}

export const missingNote = (count: number): string => `Submit with ${count} ${count === 1 ? 'item' : 'items'} not counted`;
