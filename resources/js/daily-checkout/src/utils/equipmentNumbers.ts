import type {
  ChecklistFieldValue,
  Compartment,
  EquipmentExtra,
  EquipmentKind,
  EquipmentNumbers,
  EquipmentSlot,
  SeenNumber,
} from '../types';

const text = (value: unknown): string => (typeof value === 'string' ? value : typeof value === 'number' ? String(value) : '');

const kindOf = (value: unknown): EquipmentKind | null => (value === 'scba' || value === 'radio' ? value : null);

/** The server's block, rebuilt row by row. A bad row is dropped and the rest still work; a missing block means none. */
export function parseEquipmentNumbers(raw: unknown): EquipmentNumbers | undefined {
  if (raw === null || typeof raw !== 'object') return undefined;
  const block = raw as { slots?: unknown; extras?: unknown };
  if (!Array.isArray(block.slots)) return undefined;
  const slots = block.slots.flatMap((entry): EquipmentSlot[] => {
    const row = (entry ?? {}) as Record<string, unknown>;
    const kind = kindOf(row.kind);
    const fieldId = text(row.field_id);
    const compartmentId = text(row.compartment_id);
    const itemId = text(row.item_id);
    if (!kind || text(row.slot) === '' || text(row.label) === '' || (fieldId === '' && (compartmentId === '' || itemId === ''))) return [];

    return [{
      slot: text(row.slot),
      kind,
      label: text(row.label),
      field_id: fieldId === '' ? null : fieldId,
      compartment_id: compartmentId === '' ? null : compartmentId,
      item_id: itemId === '' ? null : itemId,
      boxes: typeof row.boxes === 'number' && Number.isInteger(row.boxes) && row.boxes > 0 ? row.boxes : 1,
      number: text(row.number),
    }];
  });
  const extras = (Array.isArray(block.extras) ? block.extras : []).flatMap((entry): EquipmentExtra[] => {
    const row = (entry ?? {}) as Record<string, unknown>;
    const kind = kindOf(row.kind);

    return kind && text(row.client_id) !== '' ? [{ client_id: text(row.client_id), kind, label: text(row.label) || null, number: text(row.number) }] : [];
  });

  return { slots, extras };
}

/**
 * The numbers in a box that holds several. Crews have typed "2103, 2161, 2189", "2189-2161-2103" and "2175 2115 2184".
 * Commas are what the app writes, so they win. Otherwise only bare digits split, so an ID such as "R-14" stays whole.
 */
export function splitNumbers(value: string): string[] {
  const trimmed = value.trim();
  if (trimmed === '') return [];
  if (trimmed.includes(',')) return trimmed.split(',').map(part => part.trim()).filter(part => part !== '');
  const parts = trimmed.split(/[\s;/=-]+/).filter(part => part !== '');

  return parts.every(part => /^\d+$/.test(part)) ? parts : [trimmed];
}

export const joinNumbers = (parts: string[]): string => parts.map(part => part.trim()).filter(part => part !== '').join(', ');

/** One input per expected number, and one for each number already there if that is more. */
export function boxesFor(slot: Pick<EquipmentSlot, 'boxes'>, value: string): string[] {
  if (slot.boxes <= 1) return [value];
  const parts = splitNumbers(value);

  return [...parts, ...Array.from({ length: Math.max(0, slot.boxes - parts.length) }, () => '')];
}

const prefillValue = (slot: EquipmentSlot): string => (slot.boxes > 1 ? joinNumbers(splitNumbers(slot.number)) : slot.number);

/** A new checkout's field answers with the numbers the last member left. */
export function prefillFieldValues<T extends { id: string; value: ChecklistFieldValue }>(fields: T[], block: EquipmentNumbers | undefined): T[] {
  const known = new Map((block?.slots ?? []).filter(slot => slot.field_id !== null && slot.number !== '').map(slot => [slot.field_id, prefillValue(slot)]));

  return fields.map(field => (known.has(field.id) ? { ...field, value: known.get(field.id) as string } : field));
}

/** The same for write-in items. They still need the member's own look, so nothing is marked inspected. */
export function prefillCompartments(compartments: Compartment[], block: EquipmentNumbers | undefined): Compartment[] {
  const known = new Map((block?.slots ?? []).filter(slot => slot.item_id !== null && slot.number !== '').map(slot => [`${slot.compartment_id}/${slot.item_id}`, prefillValue(slot)]));
  if (known.size === 0) return compartments;

  return compartments.map(compartment => ({
    ...compartment,
    items: compartment.items.map((item) => {
      const value = known.get(`${compartment.id}/${item.id}`);

      return value === undefined ? item : { ...item, value };
    }),
  }));
}

const isBlank = (value: unknown): boolean => value === null || value === undefined || value === '';

/** A draft from before numbers carried over was never shown them, so its blanks are not a member clearing anything. */
export const prefillBlankFieldValues = <T extends { id: string; value: ChecklistFieldValue }>(fields: T[], block: EquipmentNumbers | undefined): T[] => {
  const filled = prefillFieldValues(fields, block);

  return fields.map((field, index) => (isBlank(field.value) ? filled[index] : field));
};

export function prefillBlankCompartments(compartments: Compartment[], block: EquipmentNumbers | undefined): Compartment[] {
  const filled = prefillCompartments(compartments, block);

  return compartments.map((compartment, position) => ({
    ...compartment,
    items: compartment.items.map((item, index) => (isBlank(item.value) ? filled[position].items[index] : item)),
  }));
}

/**
 * The numbers this member was shown, in the form the answers go back in. Sent with the inspection, so only what they
 * changed is applied and a change someone else made in the meantime stands.
 */
export const seenNumbers = (block: EquipmentNumbers | undefined): SeenNumber[] => (block ? [
  ...block.slots.filter(slot => slot.number !== '').map(slot => ({ slot: slot.slot, number: prefillValue(slot) })),
  ...block.extras.filter(extra => extra.number !== '').map(extra => ({ slot: `extra:${extra.client_id}`, number: extra.number })),
] : []);

/** Fields shown on the numbers card instead of on the details step. */
export const equipmentFieldIds = (block: EquipmentNumbers | undefined): Set<string> => (
  new Set((block?.slots ?? []).flatMap(slot => (slot.field_id !== null ? [slot.field_id] : [])))
);

/** A slot the issued checklist really has: a session frozen on an older template can lack one. */
export function slotsOnChecklist(block: EquipmentNumbers | undefined, fields: Array<{ id: string }>, compartments: Compartment[]): EquipmentSlot[] {
  const fieldIds = new Set(fields.map(field => field.id));

  return (block?.slots ?? []).filter(slot => (slot.field_id !== null
    ? fieldIds.has(slot.field_id)
    : compartments.some(compartment => compartment.id === slot.compartment_id && compartment.items.some(item => item.id === slot.item_id))));
}

/** The added numbers as they are sent. One left blank is not sent, which removes it. */
export const toExtraSubmissions = (extras: EquipmentExtra[]): EquipmentExtra[] => extras
  .map(extra => ({ client_id: extra.client_id, kind: extra.kind, label: extra.label, number: extra.number.trim() }))
  .filter(extra => extra.number !== '');

export const MAX_EXTRA_NUMBERS = 30;
export const MAX_NUMBER_LENGTH = 64;
