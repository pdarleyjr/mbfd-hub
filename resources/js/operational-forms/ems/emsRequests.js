// What the member sees about a request already sent (status, history, times, the form it was made on) and what to do with a failed send.
// Pure and dependency-free so `node --test` can run it. Re-exported by emsLogic.js.

import { DEFAULT_FAMILY, GENERIC_FORM_TITLE } from './emsFamilies.js';

export const TIME_ZONE = 'America/New_York';

// ---------------------------------------------------------------- requests (member views)

const STATUS_LABELS = {
    submitted: 'Submitted',
    reviewed: 'Reviewed',
    prepared: 'Being prepared',
    partially_issued: 'Partly issued',
    issued: 'Issued',
    closed: 'Closed',
    cancelled: 'Cancelled',
    returned: 'Returned',
};

/** Plain language for the member. A status this build does not list (reconciled, superseded) uses the server's own label. @param {string} status @param {unknown} [serverLabel] */
export function statusLabel(status, serverLabel = undefined) {
    const known = /** @type {Record<string, string>} */ (STATUS_LABELS)[status];
    if (known) return known;
    return typeof serverLabel === 'string' && serverLabel.trim() !== '' ? serverLabel.trim() : status;
}

/** @param {string} status @returns {'info' | 'progress' | 'done' | 'muted' | 'attention'} */
export function statusTone(status) {
    if (status === 'returned') return 'attention';
    if (status === 'issued' || status === 'reconciled') return 'done';
    if (status === 'superseded') return 'muted';
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
    rejected: 'Returned for correction',
    reconciled: 'Reconciled',
    revised: 'Corrected and resent',
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

/**
 * The short name of the form a request was made on ("ALS Transport"). A request from before the four forms has none: it is ALS Transport.
 * @param {{form_family?: string | null, form_family_short?: string | null, form_family_title?: string | null}} request
 */
export function requestFamilyShort(request) {
    return request.form_family_short?.trim() || request.form_family_title?.trim() || (request.form_family && request.form_family !== DEFAULT_FAMILY ? request.form_family : 'ALS Transport');
}

/** The full title of the form a request was made on; an old request keeps the historical "Medical Inventory Request". @param {{form_family_title?: string | null}} request */
export function requestFamilyTitle(request) {
    return request.form_family_title?.trim() || GENERIC_FORM_TITLE;
}

/** "ALS Transport Inventory Form" -> "ALS Transport": a form's title as a button wants it. @param {string} title */
export function shortFormTitle(title) {
    return title.replace(/\s+(?:Medical\s+)?Inventory\s+Form$/i, '').trim() || title;
}

/** @param {{requested_qty: number | null, requested_flag: boolean | null, response_type: string}} line */
export function requestedText(line) {
    if (line.response_type === 'yes_no') return line.requested_flag === true ? 'Yes' : line.requested_flag === false ? 'No' : '';
    return line.requested_qty === null ? '' : String(line.requested_qty);
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
