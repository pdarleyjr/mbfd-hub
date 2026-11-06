/**
 * Form families on the Submitted Inventory Forms tab (docs/ems/four-inventory-forms.md sections 1 and 4). Pure helpers, no React;
 * imports use .ts so `node --test` loads the file directly. The server owns the table; these are the fallbacks for an answer
 * that predates the families (a missing family is ALS Transport, never "unknown").
 */
import type { FamilyOption, FormSummary } from './types.ts';

export const DEFAULT_FAMILY = 'als-transport';
export const ALL_FAMILIES = 'all';

export const KNOWN_FAMILIES: readonly FamilyOption[] = [
  { key: 'als-transport', title: 'ALS Transport Inventory Form', short_title: 'ALS Transport' },
  { key: 'als-non-transport', title: 'ALS Non Transport Inventory Form', short_title: 'ALS Non Transport' },
  { key: 'fire-boat-6', title: 'Fire Boat 6 Medical Inventory Form', short_title: 'Fire Boat 6' },
  { key: 'bls-detail', title: 'BLS Detail Medical Inventory Form', short_title: 'BLS Detail' },
];

type FamilyFields = Partial<Pick<FormSummary, 'form_family' | 'form_family_title' | 'form_family_short'>>;

const text = (value: unknown): string => (typeof value === 'string' ? value.trim() : '');

/** The stored family key; null, missing or blank means ALS Transport (a rolled-back image writes null). */
export const familyKey = (form: FamilyFields): string => text(form.form_family) || DEFAULT_FAMILY;

function optionFor(key: string, options: readonly FamilyOption[]): FamilyOption | undefined {
  return options.find((option) => option.key === key) ?? KNOWN_FAMILIES.find((option) => option.key === key);
}

/** The short name for a card or a reconcile row: "Fire Boat 6". The server's own words win, then the family list, then the key. */
export function familyShort(form: FamilyFields, options: readonly FamilyOption[] = KNOWN_FAMILIES): string {
  const key = familyKey(form);
  return text(form.form_family_short) || optionFor(key, options)?.short_title || key;
}

/** The full title for the detail sheet: "Fire Boat 6 Medical Inventory Form". */
export function familyTitle(form: FamilyFields, options: readonly FamilyOption[] = KNOWN_FAMILIES): string {
  const key = familyKey(form);
  return text(form.form_family_title) || optionFor(key, options)?.title || familyShort(form, options);
}

/** Short label for a preview row that carries only `form_family_short`; ALS Transport when the server did not say. */
export const previewFamilyShort = (row: { form_family_short?: string | null }): string => text(row.form_family_short) || KNOWN_FAMILIES[0].short_title;

/** The families GET /meta lists, or the four known ones when it lists none (or something malformed). */
export function familyOptions(fromMeta: unknown): FamilyOption[] {
  const rows = Array.isArray(fromMeta) ? fromMeta : [];
  const valid = rows.flatMap((row): FamilyOption[] => {
    const key = text((row as Partial<FamilyOption> | null)?.key);
    if (key === '') return [];
    const known = KNOWN_FAMILIES.find((option) => option.key === key);
    const short = text((row as Partial<FamilyOption>).short_title) || known?.short_title || key;
    return [{ key, title: text((row as Partial<FamilyOption>).title) || known?.title || short, short_title: short }];
  });
  return valid.length > 0 ? valid : KNOWN_FAMILIES.map((option) => ({ ...option }));
}

export const isFamilyFilter = (value: string, options: readonly FamilyOption[]): boolean => value === ALL_FAMILIES || options.some((option) => option.key === value);

/** The filter's test for one form: `all` keeps everything; a family keeps its own forms, and ALS Transport keeps the ones with no family stored. */
export const matchesFamily = (form: FamilyFields, filter: string): boolean => filter === ALL_FAMILIES || familyKey(form) === filter;
