/**
 * Pure rules for the Edit and Add item sheets: starting values, what changed, and what must be fixed before saving.
 * Only changed fields are sent; the on-hand number is a physical count and goes to its own endpoint.
 */
import { formatQty } from './logic.ts';
import type { CountUnit, ItemChanges, MasterItem, NewItemInput } from './types.ts';

export const NEW_SHELF = '__new__';
export const ALL_UNITS: readonly CountUnit[] = ['each', 'box', 'case', 'pack', 'bag'];
export const MAX_NAME = 120;
export const MAX_SHELF_LABEL = 60;

export interface ItemForm {
  name: string;
  shelf: string;
  newShelf: string;
  onHand: string;
  countUnit: CountUnit;
  perPackage: string;
  threshold: string;
}

export interface EditPlan {
  changes: ItemChanges;
  /** A new physical count in count units, or null when the number was not touched. */
  count: number | null;
  errors: Record<string, string>;
  nothingChanged: boolean;
}

/** The "order when fewer than" level in the unit the person counts in. */
export function thresholdInCountUnits(item: Pick<MasterItem, 'required_base' | 'units_per_count_unit'>): number | null {
  if (item.required_base === null || !(item.units_per_count_unit > 0)) return null;
  return Math.round((item.required_base / item.units_per_count_unit) * 10) / 10;
}

export const capitalize = (text: string): string => (text === '' ? text : text.charAt(0).toUpperCase() + text.slice(1));

/** Each, plus the item's own package unit. An item with no package unit may be given one. */
export function unitChoices(item: Pick<MasterItem, 'package_unit' | 'count_unit'>): CountUnit[] {
  const own = item.package_unit && ALL_UNITS.includes(item.package_unit as CountUnit) ? item.package_unit as CountUnit : null;
  if (own === null) return [...ALL_UNITS];
  return item.count_unit === 'each' || item.count_unit === own ? ['each', own] : ['each', own, item.count_unit];
}

export function initialForm(item: MasterItem): ItemForm {
  const level = thresholdInCountUnits(item);
  return {
    name: item.name,
    shelf: item.location_code ?? '',
    newShelf: '',
    onHand: item.on_hand === null ? '' : formatQty(item.on_hand),
    countUnit: item.count_unit,
    perPackage: item.units_per_package === null ? '' : String(item.units_per_package),
    threshold: level === null ? '' : formatQty(level),
  };
}

export const EMPTY_NEW_ITEM: ItemForm = { name: '', shelf: '', newShelf: '', onHand: '', countUnit: 'each', perPackage: '', threshold: '' };

/** Digits only, six at most. */
export const wholeText = (text: string): string => text.replace(/\D/g, '').slice(0, 6);

/** Digits with at most one decimal place, for the order level. */
export function levelText(text: string): string {
  const cleaned = text.replace(/[^\d.]/g, '');
  const dot = cleaned.indexOf('.');
  const whole = dot < 0 ? cleaned : cleaned.slice(0, dot);
  const tail = dot < 0 ? '' : `.${cleaned.slice(dot + 1).replace(/\./g, '').slice(0, 1)}`;
  return `${whole.slice(0, 6)}${tail}`;
}

const parseLevel = (text: string): number | null => (/^\d{1,6}(\.\d)?$/.test(text.trim()) ? Number(text) : null);

function checkShared(form: ItemForm, errors: Record<string, string>): void {
  const name = form.name.trim();
  if (name === '') errors.name = 'Enter a name.';
  else if (name.length > MAX_NAME) errors.name = `Keep the name under ${MAX_NAME} characters.`;
  if (form.shelf === NEW_SHELF) {
    const label = form.newShelf.trim();
    if (label === '') errors.shelf = 'Type a name for the new shelf.';
    else if (label.length > MAX_SHELF_LABEL) errors.shelf = `Keep the shelf name under ${MAX_SHELF_LABEL} characters.`;
  }
  if (form.countUnit !== 'each' && (!/^\d+$/.test(form.perPackage) || Number(form.perPackage) < 1)) {
    errors.perPackage = `Enter how many are in one ${form.countUnit}.`;
  }
  if (form.threshold.trim() !== '' && parseLevel(form.threshold) === null) errors.threshold = 'Enter a number, or leave it blank for no order level.';
}

export function planEdit(item: MasterItem, form: ItemForm): EditPlan {
  const errors: Record<string, string> = {};
  checkShared(form, errors);
  const before = initialForm(item);
  const changes: Record<string, unknown> = {};

  if (form.name.trim() !== item.name) changes.name = form.name.trim();
  if (form.shelf === NEW_SHELF) changes.new_location_label = form.newShelf.trim();
  else if (form.shelf !== before.shelf) changes.location_code = form.shelf === '' ? null : form.shelf;

  const unitChanged = form.countUnit !== before.countUnit;
  if (unitChanged) changes.count_unit = form.countUnit;
  if (form.countUnit !== 'each' && (unitChanged || form.perPackage !== before.perPackage)) changes.units_per_package = Number(form.perPackage);

  if (form.threshold.trim() !== before.threshold) {
    changes.threshold = form.threshold.trim() === '' ? null : parseLevel(form.threshold);
  }

  let count: number | null = null;
  if (form.onHand.trim() !== before.onHand) {
    if (/^\d{1,6}$/.test(form.onHand.trim())) count = Number(form.onHand);
    else if (form.onHand.trim() !== '') errors.onHand = 'Type a whole number.';
  }

  return { changes: changes as ItemChanges, count, errors, nothingChanged: Object.keys(changes).length === 0 && count === null };
}

export function planNewItem(form: ItemForm): { input: NewItemInput | null; errors: Record<string, string> } {
  const errors: Record<string, string> = {};
  checkShared(form, errors);
  if (form.onHand.trim() !== '' && !/^\d{1,6}$/.test(form.onHand.trim())) errors.onHand = 'Type a whole number.';
  if (Object.keys(errors).length > 0) return { input: null, errors };
  const input: NewItemInput = { name: form.name.trim(), count_unit: form.countUnit };
  if (form.shelf === NEW_SHELF) input.new_location_label = form.newShelf.trim();
  else if (form.shelf !== '') input.location_code = form.shelf;
  if (form.countUnit !== 'each') input.units_per_package = Number(form.perPackage);
  if (form.threshold.trim() !== '') input.threshold = parseLevel(form.threshold) ?? undefined;
  if (form.onHand.trim() !== '') input.on_hand = Number(form.onHand);
  return { input, errors };
}
