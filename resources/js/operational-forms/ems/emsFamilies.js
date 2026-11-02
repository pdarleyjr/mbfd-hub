// The four medical inventory forms (form families), seen from the member's device.
// docs/ems/four-inventory-forms.md owns the table; the lists a family shows always come from GET /form?family=.
// Plain ES module with JSDoc types so it can be unit-tested with `node --test` (tests/Node/operational-forms).
//
// Rules this file protects:
//  - a family is one form: its draft, its queued sends and its deep link never share an entry with another family;
//  - the ALS Transport draft keeps the legacy IndexedDB key (the employee id), so a draft typed before this release still opens;
//  - nothing here renames or reuses a stored key.

/** @typedef {import('./emsTypes').EmsFormDefinition} EmsFormDefinition */
/** @typedef {import('./emsTypes').EmsFamily} EmsFamily */
/** @typedef {import('./emsTypes').EmsFamilySummary} EmsFamilySummary */
/** @typedef {import('./emsTypes').EmsForm} EmsForm */

export const DEFAULT_FAMILY = 'als-transport';
/** The deep-link form key every page before the four forms used. It means ALS Transport. */
export const LEGACY_FORM_KEY = 'medical-inventory-request';
/** The title used when a server does not send a family (a page loaded before this release). */
export const GENERIC_FORM_TITLE = 'Medical Inventory Request';

/** family key -> deep-link `form=` value (the server's own table is `families[].form_key`; this one lets a URL be read before it loads). */
const FAMILY_FORM_KEYS = /** @type {Record<string, string>} */ ({
    'als-transport': 'als-transport-inventory',
    'als-non-transport': 'als-non-transport-inventory',
    'fire-boat-6': 'fire-boat-6-medical-inventory',
    'bls-detail': 'bls-detail-medical-inventory',
});

const FAMILY_KEY_PATTERN = /^[a-z0-9][a-z0-9-]{0,39}$/;

/** @param {unknown} value */
export function isFamilyKey(value) {
    return typeof value === 'string' && FAMILY_KEY_PATTERN.test(value);
}

/**
 * The deep-link `form=` value for a family. A family this build does not know opens ALS Transport's legacy key.
 * @param {string | undefined | null} family
 */
export function formKeyForFamily(family) {
    if (!family) return LEGACY_FORM_KEY;
    return FAMILY_FORM_KEYS[family] ?? LEGACY_FORM_KEY;
}

/**
 * The family a `form=` value opens: the legacy key means ALS Transport; a family's own form key (from the server's list when
 * it is loaded, else the built-in table) opens that family; anything else is null.
 * @param {string | null | undefined} formKey @param {EmsFamilySummary[]} [families]
 * @returns {string | null}
 */
export function familyFromFormKey(formKey, families = []) {
    if (!formKey) return null;
    if (formKey === LEGACY_FORM_KEY) return DEFAULT_FAMILY;
    const listed = families.find((family) => family.form_key === formKey);
    if (listed) return listed.key;
    return Object.entries(FAMILY_FORM_KEYS).find(([, key]) => key === formKey)?.[0] ?? null;
}

/**
 * A family key from whatever a server link carries: a family key, a family's form key, or nothing (ALS Transport).
 * @param {unknown} value @param {EmsFamilySummary[]} [families] @returns {string}
 */
export function resolveFamily(value, families = []) {
    if (typeof value !== 'string' || value === '') return DEFAULT_FAMILY;
    return familyFromFormKey(value, families) ?? (isFamilyKey(value) ? value : DEFAULT_FAMILY);
}

/** @param {EmsFormDefinition | undefined | null} definition @returns {string} */
export function familyOfDefinition(definition) {
    return definition?.family?.key ?? DEFAULT_FAMILY;
}

/** @param {{family?: string} | undefined | null} payload an outbox payload; one without a family is ALS Transport @returns {string} */
export function familyOfPayload(payload) {
    return isFamilyKey(payload?.family) ? /** @type {string} */ (payload?.family) : DEFAULT_FAMILY;
}

/** The title to show for a definition's family; a server without families says "Medical Inventory Request". @param {EmsFormDefinition | undefined | null} definition */
export function familyTitle(definition) {
    return definition?.family?.title || GENERIC_FORM_TITLE;
}

/** @param {EmsFormDefinition | undefined | null} definition */
export function familyShortTitle(definition) {
    return definition?.family?.short_title || definition?.family?.title || GENERIC_FORM_TITLE;
}

/**
 * Where a family's unfinished request lives on the device (the `drafts` table's primary key).
 * ALS Transport keeps the legacy key, the employee id; every other family is `<employee id>|<family key>`.
 * @param {string} owner @param {string | undefined | null} family
 */
export function draftStoreKey(owner, family) {
    return !family || family === DEFAULT_FAMILY ? owner : `${owner}|${family}`;
}

// ---------------------------------------------------------------- list names

const FORM_NAMES = /** @type {Record<string, string>} */ ({
    'airway-bag': 'Airway Bag',
    'als-med-bag': 'ALS Med Bag/Box',
    'als-transport-vehicle': 'ALS Transport Vehicle',
    handtevy: 'Handtevy',
    'als-non-transport': 'ALS Non Transport Bag',
    'fire-boat-6-als': 'Fire Boat 6 ALS',
    'bls-detail-bag': 'BLS Detail Bag',
});

/** A short name for a list by its key, or the fallback (a list this build does not know). @param {string | null | undefined} key @param {string} [fallback] */
export function bagName(key, fallback = '') {
    return (key ? FORM_NAMES[key] : undefined) ?? (fallback || key || '');
}

/** A short name for a list. @param {EmsForm} form */
export function formName(form) {
    return bagName(form.key, form.title);
}
