// Pure cart and member helpers. Every function returns new arrays; nothing is changed in place.
import type { CartLine, ItemUnit, Member, SearchRecord, SupplyItem, UnitChoice, WithdrawalBody } from './types.ts';

export const MAX_QUANTITY = 9999;
export const MAX_LINES = 40;

export function unitOf(item: SupplyItem, key: string): ItemUnit {
    return item.units.find(unit => unit.key === key) ?? item.units.find(unit => unit.key === 'each') ?? { key: 'each', label: 'each', plural: 'each', per: 1 };
}

export function defaultUnit(item: SupplyItem): string {
    return item.units.some(unit => unit.key === item.default_unit) ? item.default_unit : unitOf(item, 'each').key;
}

export function unitWords(item: SupplyItem, key: string, quantity: number): string {
    const unit = unitOf(item, key);
    return quantity === 1 ? unit.label : unit.plural;
}

/** A quantity above half the expected on-hand amount (in base units) needs a second tap. */
export function needsConfirm(item: SupplyItem, quantity: number, unitKey: string): boolean {
    return item.confirm_above_base !== null && item.confirm_above_base !== undefined && quantity * unitOf(item, unitKey).per > item.confirm_above_base;
}

export function clampQuantity(quantity: number): number {
    return Math.max(1, Math.min(MAX_QUANTITY, Math.round(quantity) || 1));
}

const lineKey = (itemId: number, unit: string) => `${itemId}:${unit}`;

export function addLine(lines: readonly CartLine[], item: SupplyItem, quantity: number, unit: string): CartLine[] {
    const key = lineKey(item.id, unit);
    const existing = lines.find(line => line.key === key);
    if (existing) return lines.map(line => (line.key === key ? { ...line, quantity: clampQuantity(line.quantity + quantity) } : line));
    return [...lines, { key, item, quantity: clampQuantity(quantity), unit }];
}

export function setLineQuantity(lines: readonly CartLine[], key: string, quantity: number): CartLine[] {
    return lines.map(line => (line.key === key ? { ...line, quantity: clampQuantity(quantity) } : line));
}

/** Change a line's unit; if another line already has that item and unit, the two merge. */
export function setLineUnit(lines: readonly CartLine[], key: string, unit: string): CartLine[] {
    const line = lines.find(candidate => candidate.key === key);
    if (!line || line.unit === unit) return [...lines];
    const others = lines.filter(candidate => candidate.key !== key);
    const target = lineKey(line.item.id, unit);
    if (others.some(candidate => candidate.key === target)) return addLine(others, line.item, line.quantity, unit);
    return lines.map(candidate => (candidate.key === key ? { ...candidate, key: target, unit } : candidate));
}

export function removeLine(lines: readonly CartLine[], key: string): CartLine[] {
    return lines.filter(line => line.key !== key);
}

export function memberIndex(members: readonly Member[]): Map<string, Member> {
    const map = new Map<string, Member>();
    for (const member of members) {
        const id = String(member.employee_id).trim();
        map.set(id, member);
        const stripped = id.replace(/^0+/, '');
        if (stripped !== '' && !map.has(`#${stripped}`)) map.set(`#${stripped}`, member);
    }
    return map;
}

/** Employee IDs match exactly, or as numbers when leading zeros differ ("0412" and "412"). */
export function findMember(byId: ReadonlyMap<string, Member>, typed: string): Member | null {
    if (typed === '') return null;
    const exact = byId.get(typed);
    if (exact) return exact;
    const stripped = typed.replace(/^0+/, '');
    return stripped === '' ? null : byId.get(`#${stripped}`) ?? null;
}

export function newUuid(): string {
    try {
        if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    } catch {
        // Not a secure context; fall through to getRandomValues.
    }
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map(b => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function buildWithdrawal(uuid: string, member: Member, unit: UnitChoice, lines: readonly CartLine[], note: string, searches: readonly SearchRecord[]): WithdrawalBody {
    const itemIds = new Set(lines.map(line => line.item.id));
    const trimmed = note.trim();
    return {
        client_uuid: uuid,
        employee_id: member.employee_id,
        unit: unit ? { apparatus_id: unit.apparatus_id, label: unit.label } : null,
        lines: lines.map(line => ({ supply_item_id: line.item.id, quantity: line.quantity, unit: line.unit })),
        note: trimmed === '' ? null : trimmed,
        // Only the words that led to an item still in the cart.
        searches: searches.filter(record => itemIds.has(record.supply_item_id)),
    };
}

/** Remember the words typed before picking an item from search (trimmed, lowercased, one record per word and item). */
export function recordSearch(searches: readonly SearchRecord[], query: string, itemId: number): SearchRecord[] {
    const term = query.trim().toLowerCase().replace(/\s+/g, ' ').slice(0, 100);
    if (term === '' || searches.some(record => record.term === term && record.supply_item_id === itemId)) return [...searches];
    return [...searches, { term, supply_item_id: itemId }];
}
