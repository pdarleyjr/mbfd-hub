/**
 * Pure rules for the Add item sheet: starting values and what must be fixed before saving.
 * Editing existing items happens in Edit mode (batchLogic.ts).
 */
import type { CountUnit, NewItemInput } from './types.ts';

export const NEW_SHELF = '__new__';
export const MAX_NAME = 120;
export const MAX_SHELF_LABEL = 60;

export interface ItemForm {
  name: string;
  shelf: string;
  newShelf: string;
  onHand: string;
  countUnit: CountUnit;
  perPackage: string;
  /** The expected on hand, in the count unit. Sent as `threshold`. */
  threshold: string;
}

export const capitalize = (text: string): string => (text === '' ? text : text.charAt(0).toUpperCase() + text.slice(1));

export const EMPTY_NEW_ITEM: ItemForm = { name: '', shelf: '', newShelf: '', onHand: '', countUnit: 'each', perPackage: '', threshold: '' };

/** Digits only, six at most. */
export const wholeText = (text: string): string => text.replace(/\D/g, '').slice(0, 6);

/** Digits with at most one decimal place, for the expected amount. */
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
    errors.perPackage = `Enter how many single units are in one ${form.countUnit}.`;
  }
  if (form.threshold.trim() !== '' && parseLevel(form.threshold) === null) errors.threshold = 'Enter a number, or leave it blank for no expected amount.';
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
