// Finding a supply on the Medical Inventory forms. Pure and dependency-free so `node --test` can run it.
//
// What matches: case, accents, spacing and punctuation do not matter ("et tube 7.5" finds "ET tube 7.5 (cuffed)",
// "i gel" finds "i-gel"), and sizes read the same however they are written ("18g", "18ga", "18 ga", "18 gauge").
// Every word of the query must be found in the item, its aliases or search terms, its section or its list. Nothing
// here changes a quantity: the draft is separate, so what was typed survives any filter.

/** @typedef {import('./emsTypes').EmsForm} EmsForm */
/** @typedef {import('./emsTypes').EmsFormSection} EmsFormSection */
/** @typedef {import('./emsTypes').EmsFormItem} EmsFormItem */
/** @typedef {import('./emsTypes').EmsDraft} EmsDraft */

import { formName } from './emsFamilies.js';

/** A number and its unit written apart or together read the same: "18 ga", "18g", "18 gauge" -> "18g". */
const UNIT_JOINS = /** @type {const} */ ([
    [/(\d(?:\.\d+)?) ?(?:gauge|ga|g)(?![a-z0-9])/g, '$1g'],
    [/(\d(?:\.\d+)?) ?(?:french|fr)(?![a-z0-9])/g, '$1fr'],
    [/(\d(?:\.\d+)?) ?(mg|mcg|ml|cc|mm|cm)(?![a-z0-9])/g, '$1$2'],
]);

/**
 * Lower case, no accents, punctuation as spaces (a decimal point between digits stays), one space between words.
 * @param {unknown} text @param {boolean} [joinUnits] glue a number to its unit
 */
export function normalizeSearch(text, joinUnits = true) {
    let result = String(text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
        .replace(/_/g, ' ').replace(/&/g, ' and ')
        .replace(/(\d)\.(\d)/g, '$1_$2').replace(/[^a-z0-9_]+/g, ' ').replace(/_/g, '.')
        .replace(/\s+/g, ' ').trim();
    if (joinUnits) for (const [pattern, to] of UNIT_JOINS) result = result.replace(pattern, to);
    return result;
}

/** @param {string} query @returns {string[]} */
export function searchTokens(query) {
    return normalizeSearch(query).split(' ').filter(Boolean);
}

/** The searchable text of some words: spaced as typed, and with the spaces removed so "ettube" still finds "et tube". @param {unknown[]} parts */
function indexOf(parts) {
    const raw = parts.filter((part) => typeof part === 'string' && part.trim() !== '').join(' ');
    const joined = normalizeSearch(raw);
    const plain = normalizeSearch(raw, false);
    const spaced = plain === joined ? joined : `${joined} ${plain}`;
    return { spaced, compact: spaced.replace(/ /g, '') };
}

/** @type {WeakMap<object, {spaced: string, compact: string}>} */
const cache = new WeakMap();

/** @template {object} T @param {T} owner @param {() => unknown[]} parts */
function cached(owner, parts) {
    let found = cache.get(owner);
    if (!found) { found = indexOf(parts()); cache.set(owner, found); }
    return found;
}

/** @param {EmsFormItem} item */
function itemIndex(item) {
    return cached(item, () => {
        const flags = item.flags ?? {};
        const flagTerms = /** @type {unknown} */ (flags.search_terms);
        return [
            // A variant's printed line names every size ("IV 18G/20G/22G/24G"); searching 20G must not bring the others.
            item.label, item.variant_group ? '' : item.source_label, item.variant_label, item.min_text, flags.fill_if_text,
            flags.location_text, flags.qualifier_text, flags.color_band,
            ...(item.aliases ?? []), ...(item.search_terms ?? []), ...(Array.isArray(flagTerms) ? flagTerms : []),
        ];
    });
}

/** @param {EmsFormSection} section */
function sectionIndex(section) {
    return cached(section, () => [section.label]);
}

/** @param {EmsForm} form */
function formIndex(form) {
    return cached(form, () => [form.title, formName(form)]);
}

/** A lone letter ("i" in "i gel") must be a whole word, or it would match nearly everything. @param {string} token @param {{spaced: string, compact: string}} part */
function tokenFound(token, part) {
    if (token.length === 1 && /[a-z]/.test(token)) return ` ${part.spaced} `.includes(` ${token} `);
    return part.spaced.includes(token) || part.compact.includes(token);
}

/** Every word is found somewhere: in the item, its section or its list (not split across the seam between two of them). @param {string[]} tokens @param {{spaced: string, compact: string}[]} parts */
function hasEveryToken(tokens, parts) {
    return tokens.every((token) => parts.some((part) => tokenFound(token, part)));
}

/**
 * Whether an item matches. 0: the item's own words match; 1: it matches only through its section or list name; null: no match.
 * @param {string[]} tokens @param {EmsFormItem} item @param {EmsFormSection} section @param {EmsForm} form
 * @returns {0 | 1 | null}
 */
function matchRank(tokens, item, section, form) {
    const own = itemIndex(item);
    if (hasEveryToken(tokens, [own])) return 0;
    return hasEveryToken(tokens, [own, sectionIndex(section), formIndex(form)]) ? 1 : null;
}

/** @param {EmsForm[]} forms */
function countItems(forms) {
    return forms.reduce((total, form) => total + form.sections.reduce((sum, section) => sum + section.items.length, 0), 0);
}

/**
 * Items that match every word of the query, across the forms given. A match on a section name keeps its items.
 * @param {EmsForm[]} forms @param {string} query
 * @returns {{forms: EmsForm[], matches: number}}
 */
export function filterForms(forms, query) {
    const tokens = searchTokens(query);
    if (tokens.length === 0) return { forms, matches: countItems(forms) };
    const result = [];
    for (const form of forms) {
        const sections = [];
        for (const section of form.sections) {
            const items = section.items.filter((item) => matchRank(tokens, item, section, form) !== null);
            if (items.length > 0) sections.push({ ...section, items });
        }
        if (sections.length > 0) result.push({ ...form, sections });
    }
    return { forms: result, matches: countItems(result) };
}

/**
 * The matches as one flat list for the results view: items whose own words match come first, then the rest of a
 * matching section, each in list order. Every row says where it lives ("Airway Bag › Top Compartment").
 * @param {EmsForm[]} forms @param {string} query
 * @returns {{rows: {form: EmsForm, section: EmsFormSection, item: EmsFormItem, context: string}[], matches: number}}
 */
export function searchResults(forms, query) {
    const tokens = searchTokens(query);
    /** @type {{form: EmsForm, section: EmsFormSection, item: EmsFormItem, context: string, rank: number, order: number}[]} */
    const found = [];
    let order = 0;
    for (const form of forms) {
        for (const section of form.sections) {
            for (const item of section.items) {
                const rank = tokens.length === 0 ? 0 : matchRank(tokens, item, section, form);
                if (rank !== null) found.push({ form, section, item, context: `${formName(form)} › ${section.label}`, rank, order: order++ });
            }
        }
    }
    found.sort((a, b) => a.rank - b.rank || a.order - b.order);
    return { rows: found.map(({ form, section, item, context }) => ({ form, section, item, context })), matches: found.length };
}

/** Whether the member has entered anything for an item: a quantity, or a Yes or a No. @param {EmsDraft} draft @param {string} formKey @param {string} itemKey */
export function isEntered(draft, formKey, itemKey) {
    const key = `${formKey}|${itemKey}`;
    return draft.quantities[key] !== undefined || typeof draft.answers[key] === 'boolean';
}

/**
 * Only the items the member entered something for ("Entered only"). Sections and lists with none are dropped.
 * `keep` holds keys that stay on screen even if the entry was just cleared, so a row does not vanish while it is being retyped.
 * @param {EmsForm[]} forms @param {EmsDraft} draft @param {ReadonlySet<string>} [keep]
 * @returns {{forms: EmsForm[], matches: number}}
 */
export function filterEntered(forms, draft, keep = new Set()) {
    const result = [];
    for (const form of forms) {
        const sections = [];
        for (const section of form.sections) {
            const items = section.items.filter((item) => isEntered(draft, form.key, item.item_key) || keep.has(`${form.key}|${item.item_key}`));
            if (items.length > 0) sections.push({ ...section, items });
        }
        if (sections.length > 0) result.push({ ...form, sections });
    }
    return { forms: result, matches: countItems(result) };
}
