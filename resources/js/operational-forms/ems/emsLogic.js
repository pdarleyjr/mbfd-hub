// Pure, dependency-free logic for the Medical Inventory Request in the Forms app.
// Plain ES module with JSDoc types so it can be unit-tested with `node --test`
// (tests/Node/operational-forms/ems-member-form.test.mjs) without a TypeScript step.
//
// Rules this file protects:
//  - blank stays blank: an untouched item is never sent, and a quantity of 0 is never stored or sent;
//  - the draft that is saved on the device holds quantities, answers, the unit choice and the submission id only;
//  - identity and time are never sent (the server sets them).

/** @typedef {import('./emsTypes').EmsDraft} EmsDraft */
/** @typedef {import('./emsTypes').EmsFormDefinition} EmsFormDefinition */
/** @typedef {import('./emsTypes').EmsForm} EmsForm */
/** @typedef {import('./emsTypes').EmsFormSection} EmsFormSection */
/** @typedef {import('./emsTypes').EmsFormItem} EmsFormItem */
/** @typedef {import('./emsTypes').EmsSubmitPayload} EmsSubmitPayload */
/** @typedef {import('./emsTypes').StoredDraft} StoredDraft */
/** @typedef {import('./emsTypes').EmsUnit} EmsUnit */

export const FORM_KEY = 'medical-inventory-request';
export const DEFAULT_MAX_QUANTITY = 999;
export const STORED_DRAFT_VERSION = 1;

/** Upper bound on retired entries read back from a stored draft (a corrupt row cannot grow without limit). */
const RETIRED_LIMIT = 600;
export const TIME_ZONE = 'America/New_York';

/** Record types that are a read-only PDF copy in the member's Forms records. */
export const DOCUMENT_RECORD_TYPES = ['uploaded_file', 'medical_inventory_request'];

const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/** @param {unknown} value */
export function isUuid(value) {
    return typeof value === 'string' && UUID_PATTERN.test(value);
}

/**
 * A new random uuid (v4). Uses crypto.randomUUID when the page allows it, else getRandomValues.
 * @param {{randomUUID?: () => string, getRandomValues?: (a: Uint8Array) => Uint8Array} | undefined} [cryptoLike]
 */
export function newSubmissionId(cryptoLike = globalThis.crypto) {
    if (cryptoLike && typeof cryptoLike.randomUUID === 'function') return cryptoLike.randomUUID();
    const bytes = new Uint8Array(16);
    if (cryptoLike && typeof cryptoLike.getRandomValues === 'function') cryptoLike.getRandomValues(bytes);
    else for (let i = 0; i < 16; i += 1) bytes[i] = Math.floor(Math.random() * 256);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

// ---------------------------------------------------------------- record labels (Forms library)

/** @param {string} type */
export function isDocumentRecordType(type) {
    return DOCUMENT_RECORD_TYPES.includes(type);
}

/** @param {string} type */
export function recordTypeLabel(type) {
    if (type === 'medical_inventory_request') return 'Medical Inventory Request';
    if (type === 'uploaded_file') return 'Submitted file';
    return '';
}

// ---------------------------------------------------------------- draft

/** @param {string} form @param {string} itemKey */
export function lineKey(form, itemKey) {
    return `${form}|${itemKey}`;
}

/** @param {string} [clientSubmissionId] @returns {EmsDraft} */
export function blankDraft(clientSubmissionId = newSubmissionId()) {
    return { clientSubmissionId, targetChoice: '', eventAssignment: '', note: '', quantities: {}, answers: {} };
}

/**
 * A typed or tapped quantity as a whole number from 1 to max, or null (blank). 0 and junk are blank.
 * @param {unknown} raw @param {number} [max]
 * @returns {number | null}
 */
export function normalizeQuantity(raw, max = DEFAULT_MAX_QUANTITY) {
    if (raw === null || raw === undefined) return null;
    const digits = String(raw).replace(/\D+/g, '');
    if (digits === '') return null;
    const value = Number.parseInt(digits, 10);
    if (!Number.isFinite(value) || value <= 0) return null;
    return Math.min(value, max);
}

/** @param {Record<string, any>} map @param {string} key @param {any} value */
function withEntry(map, key, value) {
    const next = { ...map };
    if (value === null || value === undefined) delete next[key];
    else next[key] = value;
    return next;
}

/** @param {EmsDraft} draft @param {string} key @param {unknown} value @param {number} [max] @returns {EmsDraft} */
export function setQuantity(draft, key, value, max = DEFAULT_MAX_QUANTITY) {
    return { ...draft, quantities: withEntry(draft.quantities, key, normalizeQuantity(value, max)) };
}

/** + and - steppers: from blank, + gives 1; going below 1 returns to blank. @param {EmsDraft} draft @param {string} key @param {number} delta @param {number} [max] @returns {EmsDraft} */
export function bumpQuantity(draft, key, delta, max = DEFAULT_MAX_QUANTITY) {
    const current = draft.quantities[key] ?? 0;
    return setQuantity(draft, key, Math.max(0, current + delta), max);
}

/** @param {EmsDraft} draft @param {string} key @param {boolean | null} value @returns {EmsDraft} */
export function setAnswer(draft, key, value) {
    return { ...draft, answers: withEntry(draft.answers, key, typeof value === 'boolean' ? value : null) };
}

/** Items the member is asking for: a quantity, or a Yes answer. A No answer is an answer but not a request. @param {EmsDraft} draft */
export function countRequested(draft) {
    return Object.keys(draft.quantities).length + Object.values(draft.answers).filter((answer) => answer === true).length;
}

/** @param {EmsDraft} draft */
export function hasContent(draft) {
    return countRequested(draft) > 0
        || Object.keys(draft.answers).length > 0
        || draft.note.trim() !== '';
}

/**
 * Entries kept for items the form does not list right now (retired, renamed, or a list not yet imported).
 * They are saved with the draft and never sent. @param {EmsDraft} draft
 */
export function retiredCount(draft) {
    return Object.keys(draft.retired?.quantities ?? {}).length + Object.keys(draft.retired?.answers ?? {}).length;
}

/** @param {EmsDraft} draft @returns {EmsDraft} */
export function discardRetired(draft) {
    const { retired, labels, ...rest } = draft;
    void retired;
    void labels;
    return rest;
}

/**
 * The retired entries by name, for the review screen. A name comes from the draft (saved with it) or, failing that, the last part of the key.
 * @param {EmsDraft} draft @returns {{key: string, label: string, detail: string}[]}
 */
export function retiredEntries(draft) {
    /** @param {string} key */
    const name = (key) => draft.labels?.[key] || key.split('|').slice(1).join('|') || key;
    return [
        ...Object.entries(draft.retired?.quantities ?? {}).map(([key, value]) => ({ key, label: name(key), detail: `${value} needed` })),
        ...Object.entries(draft.retired?.answers ?? {}).map(([key, value]) => ({ key, label: name(key), detail: value ? 'Yes' : 'No' })),
    ];
}

/** Anything the member would have to redo if the page went away: entries, unit, note, event text, retired entries. @param {EmsDraft} draft */
export function hasUnsavedWork(draft) {
    return hasContent(draft) || draft.targetChoice !== '' || draft.eventAssignment.trim() !== '' || retiredCount(draft) > 0;
}

/** Requested items in a list of sections. @param {EmsDraft} draft @param {string} formKey @param {EmsFormSection[]} sections */
export function requestedInSections(draft, formKey, sections) {
    let total = 0;
    for (const section of sections) {
        for (const item of section.items) {
            const key = lineKey(formKey, item.item_key);
            if (draft.quantities[key] !== undefined || draft.answers[key] === true) total += 1;
        }
    }
    return total;
}

/** @param {number} count */
export function itemsLabel(count) {
    return `${count} ${count === 1 ? 'item' : 'items'}`;
}

/**
 * What still stands between the member and a send. Nothing here is typed text: the unit is a pick,
 * and the server refuses a request with no quantity and no note.
 * @param {EmsDraft} draft
 * @returns {{needsTarget: boolean, needsContent: boolean, ready: boolean, message: string}}
 */
export function readiness(draft) {
    const needsTarget = draft.targetChoice === '';
    const needsContent = !hasContent(draft);
    let message = 'Ready to review';
    if (needsTarget && needsContent) message = 'Choose who this is for, then add an item or a note.';
    else if (needsTarget) message = 'Choose who this is for to send.';
    else if (needsContent) message = 'Add an item or a note to send.';
    return { needsTarget, needsContent, ready: !needsTarget && !needsContent, message };
}

// ---------------------------------------------------------------- units and payload

/** @param {string} choice @param {EmsUnit[]} units @returns {EmsUnit | null} */
export function unitForChoice(choice, units) {
    if (choice === 'detail') return units.find((unit) => unit.kind === 'detail_event') ?? null;
    const match = /^unit:(\d+)$/.exec(choice);
    if (!match) return null;
    const id = Number(match[1]);
    return units.find((unit) => unit.id === id) ?? null;
}

/** @param {EmsUnit} unit */
export function choiceForUnit(unit) {
    return unit.kind === 'detail_event' || unit.id === null ? 'detail' : `unit:${unit.id}`;
}

/**
 * The unit choices as the picker shows them: Rescue units as buttons (server order), every other unit in a list,
 * and Detail / Special Event.
 * @param {EmsUnit[]} units
 * @returns {{rescue: EmsUnit[], other: EmsUnit[], detail: EmsUnit | null}}
 */
export function unitChoices(units) {
    return {
        rescue: units.filter((unit) => unit.kind === 'rescue_unit'),
        other: units.filter((unit) => unit.kind === 'other_unit'),
        detail: units.find((unit) => unit.kind === 'detail_event') ?? null,
    };
}

/** The unit's name in words for a stored choice, or '' when the choice is empty or no longer a unit (never another unit's name). @param {string} choice @param {EmsUnit[]} units */
export function unitLine(choice, units) {
    return unitForChoice(choice, units)?.label ?? '';
}

/**
 * Arrow-key navigation of the radio buttons: where focus (and the choice) goes next, or null for other keys.
 * @param {string[]} order choices in display order @param {string} current @param {string} key
 * @returns {string | null}
 */
export function nextUnitChoice(order, current, key) {
    if (order.length === 0) return null;
    const index = order.indexOf(current);
    if (key === 'Home') return order[0];
    if (key === 'End') return order[order.length - 1];
    if (key === 'ArrowRight' || key === 'ArrowDown') return index < 0 ? order[0] : order[(index + 1) % order.length];
    if (key === 'ArrowLeft' || key === 'ArrowUp') return index < 0 ? order[order.length - 1] : order[(index - 1 + order.length) % order.length];
    return null;
}

/** @param {EmsFormDefinition} definition @returns {Set<string>} every line key on the active forms */
export function validKeys(definition) {
    const keys = new Set();
    for (const form of definition.forms) {
        for (const section of form.sections) {
            for (const item of section.items) keys.add(lineKey(form.key, item.item_key));
        }
    }
    return keys;
}

/**
 * The POST body. Only items with a quantity or an answer are listed, in form order; no zero is ever sent.
 * Identity and time are not included: the server sets them.
 * @param {EmsDraft} draft @param {EmsFormDefinition} definition
 * @returns {EmsSubmitPayload}
 */
export function buildPayload(draft, definition) {
    const unit = unitForChoice(draft.targetChoice, definition.units);
    if (!unit) throw new Error('Choose a unit, or Detail / Special Event.');

    /** @type {EmsSubmitPayload['lines']} */
    const lines = [];
    for (const form of definition.forms) {
        for (const section of form.sections) {
            for (const item of section.items) {
                const key = lineKey(form.key, item.item_key);
                if (item.response_type === 'yes_no') {
                    if (typeof draft.answers[key] === 'boolean') lines.push({ form: form.key, item_key: item.item_key, flag: draft.answers[key] });
                } else if (draft.quantities[key] !== undefined && draft.quantities[key] > 0) {
                    lines.push({ form: form.key, item_key: item.item_key, quantity: draft.quantities[key] });
                }
            }
        }
    }

    /** @type {EmsSubmitPayload['target']} */
    // label_seen is the name the member saw; the server refuses the request if it is not the name of that unit.
    const target = unit.kind === 'detail_event' ? { kind: 'detail_event' } : { kind: unit.kind, apparatus_id: /** @type {number} */ (unit.id), label_seen: unit.label };
    const event = draft.eventAssignment.trim();
    if (unit.kind === 'detail_event' && event !== '') target.event_assignment = event;

    /** @type {EmsSubmitPayload} */
    const payload = { client_submission_id: draft.clientSubmissionId, target, lines };
    const note = draft.note.trim();
    if (note !== '') payload.member_note = note;
    return payload;
}

/** @param {EmsSubmitPayload} payload items asked for (a No answer is not a request) */
export function payloadItemCount(payload) {
    return payload.lines.filter((line) => line.flag !== false).length;
}

/**
 * Rebuild an editable draft from a payload that could not be sent ("Edit again").
 * @param {EmsSubmitPayload} payload @param {EmsFormDefinition} definition @returns {EmsDraft}
 */
export function draftFromPayload(payload, definition) {
    const keys = validKeys(definition);
    let draft = blankDraft(payload.client_submission_id);
    const retired = { quantities: /** @type {Record<string, number>} */ ({}), answers: /** @type {Record<string, boolean>} */ ({}) };
    for (const line of payload.lines) {
        const key = lineKey(line.form, line.item_key);
        if (typeof line.flag === 'boolean') {
            if (keys.has(key)) draft = setAnswer(draft, key, line.flag);
            else retired.answers[key] = line.flag;
        } else if (line.quantity !== undefined) {
            if (keys.has(key)) draft = setQuantity(draft, key, line.quantity, definition.limits.max_quantity);
            else if (normalizeQuantity(line.quantity, definition.limits.max_quantity) !== null) retired.quantities[key] = /** @type {number} */ (normalizeQuantity(line.quantity, definition.limits.max_quantity));
        }
    }
    if (Object.keys(retired.quantities).length + Object.keys(retired.answers).length > 0) draft = { ...draft, retired };
    const unit = payload.target.kind === 'detail_event'
        ? definition.units.find((candidate) => candidate.kind === 'detail_event')
        : definition.units.find((candidate) => candidate.id === payload.target.apparatus_id);
    return {
        ...draft,
        targetChoice: unit ? choiceForUnit(unit) : '',
        eventAssignment: payload.target.event_assignment ?? '',
        note: payload.member_note ?? '',
    };
}

// ---------------------------------------------------------------- what is stored on the device

/**
 * Names for every key that has an entry: from the form when it lists the item, else the name saved with the draft.
 * @param {EmsDraft} draft @param {EmsFormDefinition | undefined} definition @returns {Record<string, string> | null}
 */
function entryLabels(draft, definition) {
    /** @type {Record<string, string>} */
    const labels = {};
    const live = new Map();
    for (const form of definition?.forms ?? []) {
        for (const section of form.sections) for (const item of section.items) live.set(lineKey(form.key, item.item_key), item.label);
    }
    for (const key of [...Object.keys(draft.quantities), ...Object.keys(draft.answers), ...Object.keys(draft.retired?.quantities ?? {}), ...Object.keys(draft.retired?.answers ?? {})]) {
        const label = live.get(key) ?? draft.labels?.[key];
        if (label) labels[key] = label;
    }
    return Object.keys(labels).length > 0 ? labels : null;
}

/**
 * The unfinished draft for IndexedDB: quantities, answers, the unit choice and the submission id. Nothing else.
 * No names, no personnel data, and not the free-text note or event text.
 * Returns null when there is nothing worth keeping.
 * Item names are saved next to the entries (`labels`) so an entry for an item that later leaves the form can still be named.
 * @param {EmsDraft} draft @param {string} [savedAt] @param {EmsFormDefinition} [definition] @returns {StoredDraft | null}
 */
export function serializeDraft(draft, savedAt = new Date().toISOString(), definition = undefined) {
    const hasEntries = Object.keys(draft.quantities).length > 0 || Object.keys(draft.answers).length > 0 || retiredCount(draft) > 0;
    if (!hasEntries && draft.targetChoice === '') return null;
    return {
        v: STORED_DRAFT_VERSION,
        clientSubmissionId: draft.clientSubmissionId,
        targetChoice: draft.targetChoice,
        quantities: { ...draft.quantities },
        answers: { ...draft.answers },
        // Additive and optional, so the stored version stays 1 and a build that does not know them ignores them.
        ...(retiredCount(draft) > 0 ? { retired: { quantities: { ...draft.retired?.quantities }, answers: { ...draft.retired?.answers } } } : {}),
        ...(entryLabels(draft, definition) ? { labels: entryLabels(draft, definition) } : {}),
        savedAt,
    };
}

/**
 * Read a stored draft back, keeping only what the current form still has.
 * @param {unknown} stored @param {EmsFormDefinition} definition @returns {EmsDraft | null}
 */
export function restoreDraft(stored, definition) {
    if (!stored || typeof stored !== 'object') return null;
    const data = /** @type {Record<string, any>} */ (stored);
    if (data.v !== STORED_DRAFT_VERSION || !isUuid(data.clientSubmissionId)) return null;
    const keys = validKeys(definition);
    const max = definition.limits.max_quantity;

    let draft = blankDraft(data.clientSubmissionId);
    // Merge by key: what the form lists goes back into it; what it does not list is retired, never dropped.
    const retired = { quantities: /** @type {Record<string, number>} */ ({}), answers: /** @type {Record<string, boolean>} */ ({}) };
    const place = (/** @type {'quantities' | 'answers'} */ kind, /** @type {unknown} */ entries) => {
        if (!entries || typeof entries !== 'object') return;
        for (const [key, value] of Object.entries(/** @type {Record<string, unknown>} */ (entries)).slice(0, RETIRED_LIMIT)) {
            if (key.length > 240) continue;
            if (kind === 'quantities') {
                const quantity = normalizeQuantity(value, max);
                if (quantity === null) continue;
                if (keys.has(key)) draft = setQuantity(draft, key, quantity, max);
                else retired.quantities[key] = quantity;
            } else if (typeof value === 'boolean') {
                if (keys.has(key)) draft = setAnswer(draft, key, value);
                else retired.answers[key] = value;
            }
        }
    };
    // Retired entries first, so a value the member set on the live form wins over an older retired one.
    place('quantities', data.retired?.quantities);
    place('answers', data.retired?.answers);
    place('quantities', data.quantities);
    place('answers', data.answers);
    for (const key of Object.keys(draft.quantities)) delete retired.quantities[key];
    for (const key of Object.keys(draft.answers)) delete retired.answers[key];
    const choice = typeof data.targetChoice === 'string' && unitForChoice(data.targetChoice, definition.units) ? data.targetChoice : '';
    const restored = { ...draft, targetChoice: choice };
    if (Object.keys(retired.quantities).length + Object.keys(retired.answers).length === 0) return restored;
    /** @type {Record<string, string>} */
    const labels = {};
    for (const key of [...Object.keys(retired.quantities), ...Object.keys(retired.answers)]) {
        const label = data.labels?.[key];
        if (typeof label === 'string' && label.trim() !== '') labels[key] = label.trim().slice(0, 160);
    }
    return { ...restored, retired, labels };
}

// ---------------------------------------------------------------- search and grouping

/** @param {string} query @returns {string[]} */
export function searchTokens(query) {
    return query.toLowerCase().split(/[\s,]+/).map((token) => token.trim()).filter(Boolean);
}

/** @param {EmsFormItem} item @param {EmsFormSection} section @param {EmsForm} form */
function haystack(item, section, form) {
    return [item.label, item.variant_label, item.min_text, ...(item.aliases ?? []), section.label, form.title]
        .filter(Boolean).join(' ').toLowerCase();
}

/**
 * Items that match every word of the query, across every form. A match on a section name keeps its items.
 * @param {EmsForm[]} forms @param {string} query
 * @returns {{forms: EmsForm[], matches: number}}
 */
export function filterForms(forms, query) {
    const tokens = searchTokens(query);
    if (tokens.length === 0) return { forms, matches: forms.reduce((n, form) => n + form.sections.reduce((m, s) => m + s.items.length, 0), 0) };
    let matches = 0;
    const result = [];
    for (const form of forms) {
        const sections = [];
        for (const section of form.sections) {
            const items = section.items.filter((item) => {
                const text = haystack(item, section, form);
                return tokens.every((token) => text.includes(token));
            });
            if (items.length > 0) {
                matches += items.length;
                sections.push({ ...section, items });
            }
        }
        if (sections.length > 0) result.push({ ...form, sections });
    }
    return { forms: result, matches };
}

/**
 * Top-level categories with their sub-sections (BVM Bag Set Up under Main, Rescue Only under Miscellaneous).
 * A sub-section whose parent is not on the form stands on its own.
 * @param {EmsFormSection[]} sections
 * @returns {{section: EmsFormSection, children: EmsFormSection[]}[]}
 */
export function groupSections(sections) {
    const keys = new Set(sections.map((section) => section.key));
    /** @type {Map<string, EmsFormSection[]>} */
    const children = new Map();
    for (const section of sections) {
        if (section.parent_key && keys.has(section.parent_key)) {
            children.set(section.parent_key, [...(children.get(section.parent_key) ?? []), section]);
        }
    }
    return sections
        .filter((section) => !(section.parent_key && keys.has(section.parent_key)))
        .map((section) => ({ section, children: children.get(section.key) ?? [] }));
}

const FORM_NAMES = { 'airway-bag': 'Airway Bag', 'als-med-bag': 'ALS Med Bag/Box', 'als-transport-vehicle': 'ALS Transport Vehicle' };

/** A short name for a bag form. @param {EmsForm} form */
export function formName(form) {
    return /** @type {Record<string, string>} */ (FORM_NAMES)[form.key] ?? form.title;
}

/**
 * What will be sent, grouped by form, for the review sheet. Only items with a quantity or an answer.
 * @param {EmsDraft} draft @param {EmsFormDefinition} definition
 * @returns {{key: string, name: string, rows: {itemKey: string, label: string, detail: string, answered: boolean}[]}[]}
 */
export function reviewGroups(draft, definition) {
    const groups = [];
    for (const form of definition.forms) {
        const rows = [];
        for (const section of form.sections) {
            for (const item of section.items) {
                const key = lineKey(form.key, item.item_key);
                if (item.response_type === 'yes_no') {
                    if (typeof draft.answers[key] === 'boolean') rows.push({ itemKey: key, label: item.label, detail: draft.answers[key] ? 'Yes' : 'No', answered: true });
                } else if (draft.quantities[key] !== undefined) {
                    rows.push({ itemKey: key, label: item.label, detail: String(draft.quantities[key]), answered: false });
                }
            }
        }
        if (rows.length > 0) groups.push({ key: form.key, name: formName(form), rows });
    }
    return groups;
}

/**
 * "Min 2 each", exactly as the paper form gives it; the transport vehicle sheet reads "Par 4, refill under 2".
 * Yes/No items have no minimum. @param {EmsFormItem} item
 */
export function minLabel(item) {
    const text = (item.min_text ?? '').trim();
    if (item.response_type === 'yes_no' || text === '') return '';
    const fillIf = String(item.flags?.fill_if_text ?? '').trim();
    return fillIf === '' ? `Min ${text}` : `Par ${text}, refill under ${fillIf}`;
}

const FLAG_LABELS = /** @type {const} */ ([
    ['detail_airway_bag_only', 'Detail airway bags only'],
    ['rescue_only', 'Rescue only'],
    ['refrigerated', 'Refrigerated'],
    ['controlled_substance', 'Controlled'],
]);

/** @param {import('./emsTypes').EmsFlags | undefined} flags @returns {string[]} */
export function flagTags(flags) {
    return FLAG_LABELS.filter(([key]) => Boolean(flags && flags[key])).map(([, label]) => label);
}

/** The first item of a run that shares a variant group gets the group caption. @param {EmsFormItem[]} items @param {number} index */
export function variantCaption(items, index) {
    const item = items[index];
    if (!item.variant_group) return '';
    return index > 0 && items[index - 1].variant_group === item.variant_group ? '' : item.variant_group;
}

// ---------------------------------------------------------------- requests (member views)

const STATUS_LABELS = {
    submitted: 'Submitted',
    reviewed: 'Reviewed',
    prepared: 'Being prepared',
    partially_issued: 'Partly issued',
    issued: 'Issued',
    closed: 'Closed',
    cancelled: 'Cancelled',
};

/** Plain language for the member. @param {string} status */
export function statusLabel(status) {
    return /** @type {Record<string, string>} */ (STATUS_LABELS)[status] ?? status;
}

/** @param {string} status @returns {'info' | 'progress' | 'done' | 'muted'} */
export function statusTone(status) {
    if (status === 'issued') return 'done';
    if (status === 'closed' || status === 'cancelled') return 'muted';
    if (status === 'prepared' || status === 'partially_issued') return 'progress';
    return 'info';
}

const EVENT_LABELS = {
    submitted: 'Request submitted',
    reviewed: 'Reviewed',
    prepared: 'Being prepared',
    issued: 'Issued',
    issue_corrected: 'Issued quantity corrected',
    not_issued: 'Marked not issued',
    closed: 'Closed',
    reopened: 'Reopened',
    cancelled: 'Cancelled',
    status_changed: 'Status changed',
};

/** @param {string} action */
export function eventLabel(action) {
    return /** @type {Record<string, string>} */ (EVENT_LABELS)[action] ?? action.replace(/_/g, ' ');
}

/** "Gauze: 4" style detail for a history row, from the member-safe payload. @param {{quantity?: number, reason?: string, label?: string}} payload */
export function eventDetail(payload) {
    const parts = [];
    if (payload.label) parts.push(payload.quantity !== undefined ? `${payload.label}: ${payload.quantity}` : payload.label);
    else if (payload.quantity !== undefined) parts.push(`Quantity ${payload.quantity}`);
    if (payload.reason) parts.push(`Reason: ${payload.reason}`);
    return parts.join('. ');
}

/** Submitted time as Eastern time. @param {string | null | undefined} iso */
export function formatEastern(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    return new Intl.DateTimeFormat('en-US', {
        timeZone: TIME_ZONE, month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short',
    }).format(date);
}

/** @param {{requested_qty: number | null, requested_flag: boolean | null, response_type: string}} line */
export function requestedText(line) {
    if (line.response_type === 'yes_no') return line.requested_flag === true ? 'Yes' : line.requested_flag === false ? 'No' : '';
    return line.requested_qty === null ? '' : String(line.requested_qty);
}

// ---------------------------------------------------------------- deep links

/** The view a Forms URL asks for, or null for the plain library. @param {string} search @returns {import('./emsTypes').EmsView | null} */
export function viewFromSearch(search) {
    const params = new URLSearchParams(search);
    const request = (params.get('ems_request') ?? '').toLowerCase();
    if (isUuid(request)) return { name: 'detail', id: request };
    if (params.get('form') === FORM_KEY) return params.get('view') === 'mine' ? { name: 'mine' } : { name: 'form' };
    return null;
}

/** The query string for a view, so it can be bookmarked or shared. @param {import('./emsTypes').EmsView | null} view */
export function searchForView(view) {
    if (!view) return '';
    if (view.name === 'detail') return `?ems_request=${view.id}`;
    return view.name === 'mine' ? `?form=${FORM_KEY}&view=mine` : `?form=${FORM_KEY}`;
}

/** @param {import('./emsTypes').EmsDeepLink | undefined} link @returns {import('./emsTypes').EmsView | null} */
export function viewFromDeepLink(link) {
    if (!link || !link.view) return null;
    if (link.view === 'detail') return link.request && isUuid(link.request) ? { name: 'detail', id: link.request } : null;
    return { name: link.view };
}

// ---------------------------------------------------------------- failures

/**
 * What to do with a failed send. `queue`: keep it on the device and retry with the same submission id.
 * `auth`: sign in again (the draft is kept). `message`: show the server's own friendly message.
 * @param {{status: number, code?: string | null, network?: boolean}} failure
 * @returns {'queue' | 'auth' | 'message'}
 */
export function classifyFailure(failure) {
    if (failure.network) return 'queue';
    if (failure.status === 401 || failure.status === 419) return 'auth';
    if (failure.status === 429) return 'queue';
    if (failure.status >= 500 && !failure.code) return 'queue';
    return 'message';
}

/**
 * Which control a server refusal belongs next to.
 * @param {string | null | undefined} code @param {Record<string, unknown> | null | undefined} fields
 * @returns {'target' | 'event' | 'note' | 'items' | 'general'}
 */
export function fieldForError(code, fields) {
    const names = Object.keys(fields ?? {});
    if (code === 'invalid_unit' || code === 'unit_mismatch' || names.some((name) => name === 'target' || name === 'target.kind' || name === 'target.apparatus_id')) return 'target';
    if (names.includes('target.event_assignment')) return 'event';
    if (names.includes('member_note')) return 'note';
    if (code === 'empty_request' || names.includes('lines') || ['unknown_item', 'invalid_quantity', 'invalid_answer', 'duplicate_item'].includes(code ?? '')) return 'items';
    return 'general';
}
