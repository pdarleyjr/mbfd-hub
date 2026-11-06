// Pure, dependency-free logic for the Vehicle Accident Report, the Supervisor's Report of Accident and the
// Interoffice Memorandum. A plain ES module with JSDoc types so it is unit-tested with `node --test`
// (tests/Node/operational-forms/personnel-forms.test.mjs) without a browser or a TypeScript step.
//
// What this file protects:
//  - deep links: ?form=accident-report[&report=<ulid>|&view=mine], ?form=supervisor-accident-report, ?form=memorandum;
//  - the official field list and wording of each form, in the order of the paper form, split into steps;
//  - autosave bookkeeping: which fields changed since the last save, what is sent, and how the server answer is merged
//    back without losing typing that happened while the request was in flight;
//  - yes / no / N/A answers, required fields and the explanations the forms require only in some cases;
//  - the memorandum payload for each destination.

export const ACCIDENT_FORM_KEY = 'accident-report';
export const SUPERVISOR_REPORT_FORM_KEY = 'supervisor-accident-report';
export const MEMO_FORM_KEY = 'memorandum';
export const PERSONNEL_FORM_KEYS = [ACCIDENT_FORM_KEY, SUPERVISOR_REPORT_FORM_KEY, MEMO_FORM_KEY];

export const TYPE_VEHICLE = 'vehicle_accident';
export const TYPE_SUPERVISOR = 'supervisor_accident';

/** Meta fields are columns on the report, not entries in the section data. */
export const META_SUBJECT = 'subject_employee_id';
export const META_VEHICLE = 'unit_master_vehicle_id';
export const META_SUPERVISOR = 'supervisor_employee_id';
export const META_KEYS = [META_SUBJECT, META_VEHICLE, META_SUPERVISOR];

export const ATTESTATION_TEXT = 'I certify this report is accurate to the best of my knowledge.';
export const SUPERVISOR_ATTESTATION_TEXT = "I certify the supervisor's part of this report is accurate to the best of my knowledge.";

const ULID_PATTERN = /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i;
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/** @param {unknown} value */
export function isUlid(value) {
    return typeof value === 'string' && ULID_PATTERN.test(value);
}

/** @param {unknown} value */
export function isUuid(value) {
    return typeof value === 'string' && UUID_PATTERN.test(value);
}

/** A fresh client key. The server dedupes on it, so a retry or a double tap never starts two drafts or sends two memos. */
export function newClientKey() {
    const crypto = globalThis.crypto;
    if (crypto && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = new Uint8Array(16);
    if (crypto && typeof crypto.getRandomValues === 'function') crypto.getRandomValues(bytes);
    else for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

// ---------------------------------------------------------------------------------------------------------------
// Deep links
// ---------------------------------------------------------------------------------------------------------------

/**
 * @typedef {{ name: 'vehicle' } | { name: 'supervisor-report' } | { name: 'report', id: string } | { name: 'mine' } | { name: 'memo' }} PersonnelView
 */

/**
 * The view the server says the page was opened on.
 * @param {{ view?: string | null, report?: string | null } | null | undefined} link
 * @returns {PersonnelView | null}
 */
export function viewFromDeepLink(link) {
    if (!link || !link.view) return null;
    if (link.view === 'report') return isUlid(link.report) ? { name: 'report', id: String(link.report) } : { name: 'vehicle' };
    if (link.view === 'mine') return { name: 'mine' };
    if (link.view === 'vehicle') return { name: 'vehicle' };
    if (link.view === 'supervisor-report') return { name: 'supervisor-report' };
    if (link.view === 'memo') return { name: 'memo' };
    return null;
}

/**
 * The view named by a query string, or null when the address is about something else.
 * @param {string} search
 * @returns {PersonnelView | null}
 */
export function viewFromSearch(search) {
    const params = new URLSearchParams(search);
    const form = params.get('form');
    if (form === ACCIDENT_FORM_KEY) {
        const report = (params.get('report') || '').trim();
        if (isUlid(report)) return { name: 'report', id: report };
        return params.get('view') === 'mine' ? { name: 'mine' } : { name: 'vehicle' };
    }
    if (form === SUPERVISOR_REPORT_FORM_KEY) return { name: 'supervisor-report' };
    if (form === MEMO_FORM_KEY) return { name: 'memo' };
    return null;
}

/**
 * The query string for a view ('' for the Forms library).
 * @param {PersonnelView | null} view
 */
export function searchForView(view) {
    if (!view) return '';
    if (view.name === 'report') return `?form=${ACCIDENT_FORM_KEY}&report=${encodeURIComponent(view.id)}`;
    if (view.name === 'mine') return `?form=${ACCIDENT_FORM_KEY}&view=mine`;
    if (view.name === 'supervisor-report') return `?form=${SUPERVISOR_REPORT_FORM_KEY}`;
    if (view.name === 'memo') return `?form=${MEMO_FORM_KEY}`;
    return `?form=${ACCIDENT_FORM_KEY}`;
}

/**
 * @param {string} template an endpoint with the placeholder
 * @param {string} placeholder
 * @param {string} id
 */
export function urlFor(template, placeholder, id) {
    return template.split(placeholder).join(encodeURIComponent(id));
}

// ---------------------------------------------------------------------------------------------------------------
// Answers
// ---------------------------------------------------------------------------------------------------------------

export const YES_NO = [{ value: 'yes', label: 'Yes' }, { value: 'no', label: 'No' }];
export const YES_NO_NA = [...YES_NO, { value: 'na', label: 'N/A' }];

/** @param {unknown} value */
export function answerLabel(value) {
    if (value === 'yes') return 'Yes';
    if (value === 'no') return 'No';
    if (value === 'na') return 'N/A';
    return '';
}

/**
 * Tapping the chosen answer again clears it, so a question can go back to unanswered.
 * @param {string | null | undefined} current
 * @param {string} next
 * @returns {string | null}
 */
export function toggleAnswer(current, next) {
    return current === next ? null : next;
}

/**
 * Adds or removes one checkbox value, keeping the order of the official list.
 * @param {unknown} current
 * @param {string} value
 * @param {boolean} checked
 * @param {string[]} order
 * @returns {string[]}
 */
export function toggleListValue(current, value, checked, order) {
    const set = new Set(Array.isArray(current) ? current.filter((item) => typeof item === 'string') : []);
    if (checked) set.add(value); else set.delete(value);
    return order.filter((key) => set.has(key));
}

// ---------------------------------------------------------------------------------------------------------------
// The forms
// ---------------------------------------------------------------------------------------------------------------

/**
 * @typedef {'text' | 'textarea' | 'date' | 'time' | 'tel' | 'number' | 'yes_no' | 'yes_no_na' | 'causes' | 'actions'} FieldKind
 * @typedef {{ key: string, includes?: string, equals?: string }} FieldCondition
 * @typedef {{ key: string, label: string, kind: FieldKind, max?: number, required?: boolean, hint?: string, when?: FieldCondition, group?: string, wide?: boolean }} FieldDef
 * @typedef {'subject' | 'vehicle' | 'supervisor' | 'driver-summary' | 'review'} StepWidget
 * @typedef {{ id: string, title: string, intro?: string, fields: FieldDef[], widget?: StepWidget, requiredMeta?: string[] }} StepDef
 */

/** @type {StepDef[]} Vehicle Accident Report, page 2: "This side to be completed by driver". */
export const DRIVER_STEPS = [
    {
        id: 'who', title: 'Who and vehicle', widget: 'subject', requiredMeta: [META_SUBJECT, META_VEHICLE],
        intro: 'The employee involved and the City vehicle. Details come from the Hub records; correct anything that is wrong.',
        fields: [
            { key: 'employee_name', label: "Employee's Name", kind: 'text', max: 120, required: true },
            { key: 'dept_div', label: 'Dept./Div.', kind: 'text', max: 80 },
            { key: 'job_classification', label: 'Job Classification', kind: 'text', max: 80 },
            { key: 'city_vehicle_number', label: 'City Vehicle #', kind: 'text', max: 40, group: 'vehicle' },
            { key: 'vehicle_type', label: 'Type of Vehicle', kind: 'text', max: 80, group: 'vehicle' },
            { key: 'make_and_license', label: 'Make & License Tag of Vehicle', kind: 'text', max: 120, group: 'vehicle' },
        ],
    },
    {
        id: 'when', title: 'When and where',
        fields: [
            { key: 'accident_date', label: 'Date of Accident', kind: 'date', required: true },
            { key: 'accident_time', label: 'Time', kind: 'time', required: true },
            { key: 'location', label: 'Location of Accident', kind: 'text', max: 300, required: true, wide: true, hint: 'Street address or intersection, and anything that pins it down (for example, rear lot).' },
        ],
    },
    {
        id: 'others', title: 'Others involved',
        fields: [
            { key: 'passengers', label: 'List of Passengers', kind: 'textarea', max: 500, wide: true },
            { key: 'other_party_name', label: 'Name of other party involved', kind: 'text', max: 200, wide: true, group: 'party' },
            { key: 'other_party_address', label: 'Address of other party', kind: 'text', max: 300, group: 'party' },
            { key: 'other_party_phone', label: 'Phone', kind: 'tel', max: 40, group: 'party' },
            { key: 'other_insurance', label: 'Name of other Insurance Co./Agent', kind: 'text', max: 300, wide: true, group: 'party' },
            { key: 'other_vehicle_year', label: 'Other Vehicle: Year', kind: 'text', max: 10, group: 'other-vehicle' },
            { key: 'other_vehicle_make_tag', label: 'Make & Tag #', kind: 'text', max: 120, group: 'other-vehicle' },
            { key: 'injuries', label: 'Was anyone injured? Explain', kind: 'textarea', max: 2000, required: true, wide: true, hint: 'Write "No injuries" if nobody was hurt.' },
        ],
    },
    {
        id: 'police', title: 'Police and witnesses',
        fields: [
            { key: 'supervisor_notified', label: 'Was supervisor notified after accident?', kind: 'yes_no', required: true },
            { key: 'police_on_scene', label: 'Were Police on the scene?', kind: 'yes_no', required: true },
            { key: 'fl_report_made', label: 'Was FL. Traffic Accident Report Made?', kind: 'yes_no' },
            { key: 'photos_taken', label: 'Photos Taken?', kind: 'yes_no' },
            { key: 'person_charged', label: 'Person charged by Police', kind: 'text', max: 200 },
            { key: 'charge', label: 'Charge', kind: 'text', max: 200 },
            { key: 'witness1_name', label: 'Name of Witness', kind: 'text', max: 120, group: 'witness1' },
            { key: 'witness1_address', label: 'Address', kind: 'text', max: 300, group: 'witness1' },
            { key: 'witness1_phone', label: 'Phone', kind: 'tel', max: 40, group: 'witness1' },
            { key: 'witness2_name', label: 'Name of Witness', kind: 'text', max: 120, group: 'witness2' },
            { key: 'witness2_address', label: 'Address', kind: 'text', max: 300, group: 'witness2' },
            { key: 'witness2_phone', label: 'Phone', kind: 'tel', max: 40, group: 'witness2' },
        ],
    },
    {
        id: 'description', title: 'Description',
        fields: [
            { key: 'damage_other', label: 'Damage to Other vehicle/property', kind: 'textarea', max: 1000, wide: true },
            { key: 'driver_description', label: "Driver's description of accident", kind: 'textarea', max: 8000, required: true, wide: true, hint: 'What happened, in order: where you were going, what you saw, what you did, and where the vehicles ended up.' },
        ],
    },
    {
        id: 'supervisor', title: 'Supervisor', widget: 'supervisor', requiredMeta: [META_SUPERVISOR],
        intro: 'Choose the supervisor who will complete the supervisor\'s part. They are notified when you submit.',
        fields: [],
    },
    { id: 'review', title: 'Review and sign', widget: 'review', fields: [] },
];

/** @type {StepDef[]} Vehicle Accident Report, page 1: "To be completed by supervisor". */
export const VEHICLE_SUPERVISOR_STEPS = [
    { id: 'driver', title: "Driver's report", widget: 'driver-summary', fields: [], intro: "The driver's side as submitted. It cannot be changed here." },
    {
        id: 'assessment', title: 'Supervisor\'s assessment',
        fields: [
            { key: 'date_of_report', label: 'Date of Report', kind: 'date', required: true },
            { key: 'driver_years_with_city', label: 'Length of time driver with the City? (Years)', kind: 'number', max: 20, required: true },
            { key: 'vehicle_type_experience_years', label: 'Experience with this type of vehicle? (Years)', kind: 'number', max: 20, required: true },
            { key: 'injury_notice', label: 'If employee sustained an injury did you complete a Notice of Injury Report', kind: 'yes_no_na', required: true },
            { key: 'hours_on_duty', label: 'Total # of hours on duty at time of accident?', kind: 'number', max: 20, required: true },
            { key: 'vehicle_condition_form', label: 'Was Vehicle Condition Form completed?', kind: 'yes_no', required: true },
            { key: 'reasonable_observation_form', label: 'Did you complete Reasonable Observation Form', kind: 'yes_no', required: true },
            { key: 'cdl_drug_test', label: 'If employee has CDL was Drug Test conducted', kind: 'yes_no_na', required: true },
        ],
    },
    {
        id: 'factors', title: 'Contributing factors',
        fields: [
            { key: 'excessive_hours', label: 'Excessive Hours', kind: 'yes_no', required: true },
            { key: 'speeding', label: 'Speeding', kind: 'yes_no', required: true },
            { key: 'sickness', label: 'Sickness', kind: 'yes_no', required: true },
            { key: 'physical_defect', label: 'Physical Defect', kind: 'yes_no', required: true },
            { key: 'alcohol', label: 'Alcohol', kind: 'yes_no', required: true },
            { key: 'drugs', label: 'Drugs', kind: 'yes_no', required: true },
            { key: 'accidents_past_18_months', label: 'Number of accidents in the past 18 months?', kind: 'number', max: 20, required: true },
            { key: 'other_factors', label: 'Other (explain)', kind: 'textarea', max: 2000, wide: true },
        ],
    },
    {
        id: 'comments', title: 'Comments',
        fields: [{ key: 'supervisor_comments', label: 'Comments', kind: 'textarea', max: 6000, wide: true }],
    },
    { id: 'review', title: 'Review and sign', widget: 'review', fields: [] },
];

/** @type {StepDef[]} Supervisor's Report of Accident (one page). */
export const SUPERVISOR_REPORT_STEPS = [
    {
        id: 'employee', title: 'Employee information', widget: 'subject', requiredMeta: [META_SUBJECT],
        intro: 'Find the employee involved. Their details come from the Hub records; correct anything that is wrong.',
        fields: [
            { key: 'employee_name', label: "Employee's Name", kind: 'text', max: 120, required: true },
            { key: 'employee_id', label: 'ID/Clock No.', kind: 'text', max: 32, required: true },
            { key: 'division', label: 'Division', kind: 'text', max: 80 },
            { key: 'department', label: 'Department', kind: 'text', max: 80 },
            { key: 'age', label: 'Age', kind: 'number', max: 3 },
            { key: 'classification', label: 'Classification', kind: 'text', max: 80 },
        ],
    },
    {
        id: 'accident', title: 'Accident information',
        fields: [
            { key: 'accident_date', label: 'Date', kind: 'date', required: true },
            { key: 'accident_time', label: 'Time', kind: 'time', required: true },
            { key: 'place', label: 'Place', kind: 'text', max: 300, required: true, wide: true },
            { key: 'first_reported_to', label: 'Accident first reported to', kind: 'text', max: 200, required: true, wide: true },
            { key: 'witnesses', label: 'Witnesses', kind: 'textarea', max: 500, wide: true },
            { key: 'description', label: 'Describe how accident occurred', kind: 'textarea', max: 8000, required: true, wide: true },
        ],
    },
    {
        id: 'injury', title: 'Injury',
        fields: [
            { key: 'type_of_injury', label: 'Type of injury', kind: 'text', max: 200 },
            { key: 'part_of_body', label: 'Part of body', kind: 'text', max: 200 },
            { key: 'medical_treatment_by', label: 'Medical treatment by', kind: 'text', max: 300, wide: true },
            { key: 'losing_time', label: 'Losing time from work due to injury?', kind: 'yes_no', required: true },
        ],
    },
    {
        id: 'cause', title: 'Cause and action',
        fields: [
            { key: 'causes', label: 'Cause of accident (check any that apply)', kind: 'causes', wide: true },
            { key: 'cause_another_party_explain', label: 'Caused by another party (explain)', kind: 'textarea', max: 1000, required: true, wide: true, when: { key: 'causes', includes: 'another_party' } },
            { key: 'cause_other_explain', label: 'Other cause (explain)', kind: 'textarea', max: 1000, required: true, wide: true, when: { key: 'causes', includes: 'other' } },
            { key: 'actions', label: 'Action taken to prevent similar accident (check any that apply)', kind: 'actions', wide: true },
            { key: 'action_other_explain', label: 'Other action (explain)', kind: 'textarea', max: 1000, required: true, wide: true, when: { key: 'actions', includes: 'other' } },
            { key: 'similar_accidents', label: 'Has this employee had similar accidents?', kind: 'yes_no', required: true },
            { key: 'similar_accidents_explain', label: 'Explain', kind: 'textarea', max: 2000, required: true, wide: true, when: { key: 'similar_accidents', equals: 'yes' } },
        ],
    },
    {
        id: 'review', title: 'Review and sign', widget: 'review',
        fields: [{ key: 'supervisor_title', label: 'Title', kind: 'text', max: 80, required: true, hint: 'Your rank or title, as it should print beside your signature.' }],
    },
];

/**
 * The steps for what this viewer may edit.
 * @param {string} type
 * @param {'driver' | 'supervisor'} section
 * @returns {StepDef[]}
 */
export function stepsFor(type, section) {
    if (type === TYPE_SUPERVISOR) return SUPERVISOR_REPORT_STEPS;
    return section === 'supervisor' ? VEHICLE_SUPERVISOR_STEPS : DRIVER_STEPS;
}

/** @param {StepDef[]} steps */
export function allFields(steps) {
    return steps.flatMap((step) => step.fields);
}

/** @param {unknown} value */
export function isBlank(value) {
    if (value === null || value === undefined) return true;
    if (typeof value === 'string') return value.trim() === '';
    if (Array.isArray(value)) return value.length === 0;
    return false;
}

/**
 * A conditional explanation is shown, and required, only when its condition holds.
 * @param {FieldDef} field
 * @param {Record<string, unknown>} data
 */
export function isFieldVisible(field, data) {
    const when = field.when;
    if (!when) return true;
    const value = data[when.key];
    if (when.includes !== undefined) return Array.isArray(value) && value.includes(when.includes);
    if (when.equals !== undefined) return value === when.equals;
    return true;
}

const META_MESSAGES = {
    [META_SUBJECT]: 'Choose the employee involved',
    [META_VEHICLE]: 'Choose the City vehicle',
    [META_SUPERVISOR]: 'Choose the supervisor',
};

/**
 * What is still needed before the report can be submitted, in form order.
 * @param {StepDef[]} steps
 * @param {Record<string, unknown>} data
 * @param {Record<string, unknown>} meta current ids of the employee, vehicle and supervisor
 * @returns {{ stepId: string, key: string, label: string }[]}
 */
export function missingItems(steps, data, meta) {
    /** @type {{ stepId: string, key: string, label: string }[]} */
    const missing = [];
    for (const step of steps) {
        for (const key of step.requiredMeta ?? []) {
            if (isBlank(meta[key])) missing.push({ stepId: step.id, key, label: META_MESSAGES[key] ?? key });
        }
        for (const field of step.fields) {
            if (field.required && isFieldVisible(field, data) && isBlank(data[field.key])) {
                missing.push({ stepId: step.id, key: field.key, label: field.label });
            }
        }
    }
    return missing;
}

/**
 * The step that holds a server error key such as "driver_description", "causes.1" or "unit_master_vehicle_id".
 * Unknown keys (signature, attest) belong to the last step.
 * @param {StepDef[]} steps
 * @param {string} errorKey
 */
export function stepForKey(steps, errorKey) {
    const key = errorKey.split('.')[0];
    for (const step of steps) {
        if ((step.requiredMeta ?? []).includes(key) || step.fields.some((field) => field.key === key)) return step.id;
    }
    if (key === META_SUPERVISOR) {
        const picker = steps.find((step) => step.widget === 'supervisor');
        if (picker) return picker.id;
    }
    return steps[steps.length - 1]?.id ?? '';
}

/**
 * Flattens Laravel's { field: [messages] } into { field: firstMessage }, folding "causes.0" into "causes".
 * @param {Record<string, string[] | string> | null | undefined} errors
 * @returns {Record<string, string>}
 */
export function firstErrors(errors) {
    /** @type {Record<string, string>} */
    const result = {};
    for (const [key, value] of Object.entries(errors ?? {})) {
        const base = key.split('.')[0];
        const message = Array.isArray(value) ? value[0] : value;
        if (typeof message === 'string' && !(base in result)) result[base] = message;
    }
    return result;
}

// ---------------------------------------------------------------------------------------------------------------
// Autosave bookkeeping
// ---------------------------------------------------------------------------------------------------------------

/**
 * The value as the server will store it: strings trimmed, empty means "clear this field" (null).
 * @param {unknown} value
 */
export function normalizeValue(value) {
    if (typeof value === 'string') {
        const trimmed = value.trim();
        return trimmed === '' ? null : trimmed;
    }
    if (Array.isArray(value)) return value.length === 0 ? [] : [...value];
    return value === undefined ? null : value;
}

/** @param {unknown} a @param {unknown} b */
export function sameValue(a, b) {
    const left = normalizeValue(a);
    const right = normalizeValue(b);
    if (Array.isArray(left) || Array.isArray(right)) {
        const l = Array.isArray(left) ? left : [];
        const r = Array.isArray(right) ? right : [];
        return l.length === r.length && l.every((item, index) => item === r[index]);
    }
    return left === right;
}

/**
 * The PATCH body for the changed fields. Section data keys go in `data`; employee, vehicle and supervisor go as
 * their own keys. A cleared field is sent as null, which the server removes.
 * @param {number} revision
 * @param {Record<string, unknown>} values
 * @param {Iterable<string>} dirtyKeys
 * @param {Record<string, unknown>} meta
 */
export function buildPatch(revision, values, dirtyKeys, meta) {
    /** @type {Record<string, unknown>} */
    const data = {};
    /** @type {Record<string, unknown>} */
    const body = { revision };
    for (const key of dirtyKeys) {
        if (META_KEYS.includes(key)) body[key] = meta[key] ?? null;
        else data[key] = normalizeValue(values[key]);
    }
    if (Object.keys(data).length > 0) body.data = data;
    return body;
}

/**
 * Bumps a field's change number. The number tells, after a save, whether the field changed again meanwhile.
 * @param {Record<string, number>} versions
 * @param {string} key
 * @param {number} counter
 */
export function markDirty(versions, key, counter) {
    return { ...versions, [key]: counter };
}

/**
 * After a save: drops the fields that were sent and not changed again while the request was in flight.
 * @param {Record<string, number>} versions current change numbers
 * @param {Record<string, number>} sent the change numbers that went with the request
 */
export function settleDirty(versions, sent) {
    /** @type {Record<string, number>} */
    const next = {};
    for (const [key, version] of Object.entries(versions)) {
        if (sent[key] !== version) next[key] = version;
    }
    return next;
}

/**
 * The values to show after the server answered: the server's section data (which may have filled in vehicle or
 * employee details), with the fields the member is still changing kept as typed, and fields that only differ by
 * surrounding spaces kept as typed so the cursor never jumps.
 * @param {Record<string, unknown>} serverData
 * @param {Record<string, unknown>} local
 * @param {Iterable<string>} sentKeys
 * @param {Iterable<string>} stillDirty
 */
export function mergeAfterSave(serverData, local, sentKeys, stillDirty) {
    /** @type {Record<string, unknown>} */
    const result = { ...serverData };
    for (const key of sentKeys) {
        if (META_KEYS.includes(key)) continue;
        if (sameValue(local[key], serverData[key]) && local[key] !== undefined) result[key] = local[key];
    }
    for (const key of stillDirty) {
        if (META_KEYS.includes(key)) continue;
        if (local[key] === undefined || local[key] === null || local[key] === '') delete result[key];
        else result[key] = local[key];
    }
    return result;
}

/**
 * Which local changes differ from what the server now holds (for the "Changed elsewhere" choice).
 * @param {Record<string, unknown>} serverData
 * @param {Record<string, unknown>} local
 * @param {Iterable<string>} dirtyKeys
 * @param {FieldDef[]} fields
 */
export function conflictRows(serverData, local, dirtyKeys, fields) {
    const labels = new Map(fields.map((field) => [field.key, field]));
    /** @type {{ key: string, label: string, mine: string, theirs: string }[]} */
    const rows = [];
    for (const key of dirtyKeys) {
        if (META_KEYS.includes(key)) continue;
        if (sameValue(local[key], serverData[key])) continue;
        const field = labels.get(key);
        rows.push({ key, label: field?.label ?? key, mine: displayValue(field, local[key]), theirs: displayValue(field, serverData[key]) });
    }
    return rows;
}

/**
 * @typedef {'idle' | 'dirty' | 'saving' | 'saved' | 'offline' | 'conflict' | 'error'} SaveStatus
 */

/**
 * The words beside the form. "Offline" means the entries are on this device and will be sent when the connection returns.
 * @param {SaveStatus} status
 */
export function saveStatusLabel(status) {
    switch (status) {
        case 'saving': return 'Saving…';
        case 'saved': return 'Saved';
        case 'dirty': return 'Unsaved changes';
        case 'offline': return 'Offline. Saved on this device';
        case 'conflict': return 'Changed elsewhere';
        case 'error': return 'Not saved';
        default: return '';
    }
}

/**
 * How a failed call should be treated.
 * @param {{ status: number, network?: boolean }} failure
 * @returns {'offline' | 'conflict' | 'invalid' | 'signin' | 'error'}
 */
export function classifyFailure(failure) {
    if (failure.network) return 'offline';
    if (failure.status === 409) return 'conflict';
    if (failure.status === 422) return 'invalid';
    if (failure.status === 401 || failure.status === 419) return 'signin';
    return 'error';
}

// ---------------------------------------------------------------------------------------------------------------
// Display
// ---------------------------------------------------------------------------------------------------------------

/**
 * @param {FieldDef | undefined} field
 * @param {unknown} value
 * @param {Record<string, string>} [choices] the causes or actions labels
 */
export function displayValue(field, value, choices) {
    if (isBlank(value)) return '';
    if (field?.kind === 'yes_no' || field?.kind === 'yes_no_na') return answerLabel(value);
    if (field?.kind === 'date') return formatDate(String(value));
    if (field?.kind === 'time') return formatTime(String(value));
    if (Array.isArray(value)) return value.map((item) => (choices && choices[item]) || String(item)).join(', ');
    return String(value);
}

/** @param {string} ymd Y-m-d */
export function formatDate(ymd) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd);
    if (!match) return ymd;
    return `${match[2]}/${match[3]}/${match[1]}`;
}

/** @param {string} hm H:i (24 hour) */
export function formatTime(hm) {
    const match = /^(\d{2}):(\d{2})$/.exec(hm);
    if (!match) return hm;
    const hours = Number(match[1]);
    const suffix = hours >= 12 ? 'PM' : 'AM';
    const twelve = hours % 12 === 0 ? 12 : hours % 12;
    return `${twelve}:${match[2]} ${suffix}`;
}

/**
 * Today's date (Y-m-d) in the Hub's time zone.
 * @param {Date} now
 * @param {string} timeZone
 */
export function todayIn(now, timeZone) {
    const parts = new Intl.DateTimeFormat('en-US', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(now);
    /** @param {string} type */
    const part = (type) => parts.find((item) => item.type === type)?.value ?? '';
    return `${part('year')}-${part('month')}-${part('day')}`;
}

/**
 * "120 of 8,000 characters", and whether the member is close to the limit.
 * @param {unknown} value
 * @param {number} max
 */
export function characterCount(value, max) {
    const length = typeof value === 'string' ? value.length : 0;
    return { text: `${length.toLocaleString('en-US')} of ${max.toLocaleString('en-US')} characters`, near: length >= max * 0.9, over: length > max };
}

/**
 * Keeps typed numbers to digits and at most one decimal point (years, hours, age, counts).
 * @param {string} raw
 * @param {number} max
 */
export function cleanNumber(raw, max) {
    let seenDot = false;
    let out = '';
    for (const char of raw) {
        if (char >= '0' && char <= '9') out += char;
        else if (char === '.' && !seenDot) { seenDot = true; out += char; }
    }
    return out.slice(0, max);
}

/** @param {string | null | undefined} status */
export function statusTone(status) {
    if (status === 'complete') return 'done';
    if (status === 'awaiting_supervisor') return 'progress';
    return 'muted';
}

/**
 * The member's list: reports waiting for this viewer first, then drafts, then the rest by last change.
 * @template {{ awaiting_you?: boolean, status?: string, updated_at?: string | null }} T
 * @param {T[]} reports
 * @returns {T[]}
 */
export function sortReports(reports) {
    /** @param {T} report */
    const rank = (report) => (report.awaiting_you ? 0 : report.status === 'draft' ? 1 : 2);
    return [...reports].sort((a, b) => rank(a) - rank(b) || String(b.updated_at ?? '').localeCompare(String(a.updated_at ?? '')));
}

// ---------------------------------------------------------------------------------------------------------------
// Signature
// ---------------------------------------------------------------------------------------------------------------

/**
 * The exported signature must fit the server's limit (1600 x 800 px). Scales down, never up.
 * @param {number} width
 * @param {number} height
 * @param {number} [maxWidth]
 * @param {number} [maxHeight]
 */
export function signatureExportSize(width, height, maxWidth = 1600, maxHeight = 800) {
    const w = Math.max(2, Math.round(width));
    const h = Math.max(2, Math.round(height));
    const scale = Math.min(1, maxWidth / w, maxHeight / h);
    return { width: Math.max(2, Math.floor(w * scale)), height: Math.max(2, Math.floor(h * scale)) };
}

// ---------------------------------------------------------------------------------------------------------------
// Interoffice Memorandum
// ---------------------------------------------------------------------------------------------------------------

export const MEMO_LIMITS = { to: 200, from: 200, subject: 200, body: 20000 };

/**
 * @typedef {'support' | 'other'} MemoDestination
 * @typedef {{ to: string, from: string, date: string, subject: string, body: string }} MemoFields
 */

/**
 * What the member still has to fill in, keyed by field.
 * @param {MemoFields} memo
 * @param {MemoDestination} destination
 * @returns {Record<string, string>}
 */
export function memoErrors(memo, destination) {
    /** @type {Record<string, string>} */
    const errors = {};
    if (destination === 'other' && isBlank(memo.to)) errors.to = 'Enter who the memorandum is to.';
    if (isBlank(memo.from)) errors.from = 'Enter who the memorandum is from.';
    if (!/^\d{4}-\d{2}-\d{2}$/.test(memo.date || '')) errors.date = 'Choose the date.';
    if (isBlank(memo.subject)) errors.subject = 'Enter a subject.';
    if (isBlank(memo.body)) errors.body = 'Write the memorandum.';
    for (const [key, max] of Object.entries(MEMO_LIMITS)) {
        const value = /** @type {Record<string, string>} */ (memo)[key];
        if (typeof value === 'string' && value.length > max) errors[key] = `Keep this under ${max.toLocaleString('en-US')} characters.`;
    }
    return errors;
}

/**
 * The body sent to the memorandum endpoints. Support Services always gets its own name in To.
 * @param {MemoFields} memo
 * @param {MemoDestination} destination
 * @param {string} supportLabel
 */
export function memoPayload(memo, destination, supportLabel) {
    return {
        to: destination === 'support' ? supportLabel : memo.to.trim(),
        from: memo.from.trim(),
        date: memo.date,
        subject: memo.subject.trim(),
        body: memo.body.replace(/\r\n/g, '\n').replace(/\s+$/, ''),
    };
}

/**
 * The file name from a Content-Disposition header, or a plain default.
 * @param {string | null | undefined} header
 * @param {string} fallback
 */
export function fileNameFromDisposition(header, fallback) {
    if (!header) return fallback;
    const star = /filename\*=UTF-8''([^;]+)/i.exec(header);
    if (star) {
        try { return decodeURIComponent(star[1]); } catch { /* fall through */ }
    }
    const plain = /filename="?([^";]+)"?/i.exec(header);
    return plain ? plain[1].replace(/\\(.)/g, '$1') : fallback;
}
