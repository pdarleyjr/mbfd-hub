// Pure, dependency-free logic for the seal number on the Narcotics check form (docs/narcotics-check.md section 1).
// A plain ES module with JSDoc types, unit-tested with `node --test` (tests/Node/operational-forms/narcotics-seal.test.mjs).
//
// A seal number is free text (digits, letters, dashes, spaces), required, trimmed, one line. The server owns the
// length (config/narcotics_checks.php, sent by GET /api/narcotics-checks/form) and judges every submission; the
// built-in length below is used only when that answer lacks one.

import { hasUnsafeText, length, trimUnitText } from './narcoticsUnit.js';

export const FALLBACK_SEAL_MAX = 40;
export const MESSAGE_SEAL_REQUIRED = 'Enter the seal number.';
export const MESSAGE_SEAL_FORMAT = 'The seal number must be on one line, without special characters.';

/**
 * @param {{ seal_number_max_length?: unknown } | null | undefined} definition
 * @returns {{ max: number }}
 */
export function sealSettings(definition) {
    const max = Number(definition?.seal_number_max_length);

    return { max: Number.isInteger(max) && max > 0 ? max : FALLBACK_SEAL_MAX };
}

/**
 * What is wrong with the seal number, in the server's words; null when it can be sent.
 * @param {string} value
 * @param {{ max: number }} settings
 * @returns {string | null}
 */
export function sealError(value, settings) {
    const text = trimUnitText(value);
    if (text === '') return MESSAGE_SEAL_REQUIRED;
    if (length(text) > settings.max) return `The seal number can be at most ${settings.max} characters.`;
    if (hasUnsafeText(text)) return MESSAGE_SEAL_FORMAT;

    return null;
}

/** The text to send: trimmed, otherwise exactly as typed. @param {string} value */
export function sealPayload(value) {
    return trimUnitText(value);
}

/**
 * The prefill for an answer to a rejected check.
 * @param {{ seal_number?: string | null } | null | undefined} check
 */
export function sealFromCheck(check) {
    return typeof check?.seal_number === 'string' ? check.seal_number : '';
}

/**
 * The server's own words for the field, from a 422's `fields`.
 * @param {Record<string, string[] | string> | null | undefined} fields
 * @returns {string | undefined}
 */
export function serverSealError(fields) {
    const value = fields?.seal_number;
    const first = Array.isArray(value) ? value[0] : value;

    return typeof first === 'string' && first !== '' ? first : undefined;
}

/** The number, or "Not recorded" for a check sent before seal numbers were asked for. @param {{ seal_number?: string | null } | null | undefined} check */
export function sealText(check) {
    const text = typeof check?.seal_number === 'string' ? check.seal_number.trim() : '';

    return text === '' ? 'Not recorded' : text;
}
