// Pure, dependency-free logic for the rescue unit on the Narcotics check form (docs/narcotics-check.md section 1).
// A plain ES module with JSDoc types so it is unit-tested with `node --test`
// (tests/Node/operational-forms/narcotics-unit.test.mjs) without a browser or a TypeScript step.
//
// The server owns the list (config/narcotics_checks.php) and validates every submission against it; this file
// only follows what GET /api/narcotics-checks/form says. The built-in list below is used ONLY when that answer
// has no `rescue_units` (an old or partial answer), so the form still works. It can never make a bad value
// valid: the server refuses anything it does not list.

export const FALLBACK_OTHER_OPTION = 'Other';
export const FALLBACK_OTHER_MAX = 40;
export const FALLBACK_UNITS = ['R1', 'R11', 'R2', 'R22', 'R3', 'R4', 'R44', 'FB6', FALLBACK_OTHER_OPTION];

export const MESSAGE_CHOOSE = 'Choose your rescue unit.';
export const MESSAGE_OTHER_REQUIRED = 'Enter which rescue unit you are on.';
export const MESSAGE_OTHER_FORMAT = 'The rescue unit must be on one line, without special characters.';
export const NOT_RECORDED = 'Not recorded';

// Control characters (tabs and line breaks too), invisible formatting characters and line/paragraph separators.
const UNSAFE_TEXT = /[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u;

/**
 * @typedef {{ options: string[], other: string, otherMax: number }} UnitSettings
 * @typedef {{ choice: string, other: string }} UnitValue
 */

/**
 * What the form offers, from the form definition. "Other" is the last option the server lists.
 * @param {{ rescue_units?: unknown, rescue_unit_other_option?: unknown, rescue_unit_other_max_length?: unknown } | null | undefined} definition
 * @returns {UnitSettings}
 */
export function unitSettings(definition) {
    const listed = Array.isArray(definition?.rescue_units)
        ? definition.rescue_units.filter((option) => typeof option === 'string' && option.trim() !== '')
        : [];
    const options = listed.length > 0 ? listed : FALLBACK_UNITS;
    const named = typeof definition?.rescue_unit_other_option === 'string' && definition.rescue_unit_other_option !== ''
        ? definition.rescue_unit_other_option
        : FALLBACK_OTHER_OPTION;
    const max = Number(definition?.rescue_unit_other_max_length);

    return { options, other: named, otherMax: Number.isInteger(max) && max > 0 ? max : FALLBACK_OTHER_MAX };
}

/** @returns {UnitValue} nothing chosen yet */
export function blankUnit() {
    return { choice: '', other: '' };
}

/**
 * A new value after the member picks an option. The typed text only lives with "Other": any other choice clears it.
 * @param {UnitValue} unit
 * @param {string} choice
 * @param {UnitSettings} settings
 * @returns {UnitValue}
 */
export function pickUnit(unit, choice, settings) {
    return { choice, other: choice === settings.other ? unit.other : '' };
}

/**
 * The value that answers a rejected check, from the check the member is answering. Anything the list no
 * longer offers starts blank, so the member chooses again.
 * @param {{ rescue_unit_choice?: string | null, rescue_unit_other?: string | null } | null | undefined} check
 * @param {UnitSettings} settings
 * @returns {UnitValue}
 */
export function unitFromCheck(check, settings) {
    const choice = typeof check?.rescue_unit_choice === 'string' ? check.rescue_unit_choice : '';
    if (!settings.options.includes(choice)) return blankUnit();
    const other = choice === settings.other && typeof check?.rescue_unit_other === 'string' ? check.rescue_unit_other : '';

    return { choice, other };
}

/**
 * Whether the member has started on the unit (picked an option or typed something).
 * @param {UnitValue} unit
 */
export function unitStarted(unit) {
    return unit.choice !== '' || unit.other.trim() !== '';
}

/** Characters as the server counts them (code points, not UTF-16 units). @param {string} text */
export const length = (text) => Array.from(text).length;

/** Whether the text holds a control, invisible formatting or line-separator character (the server refuses these). @param {string} text */
export const hasUnsafeText = (text) => UNSAFE_TEXT.test(text);

/** Typed text without leading and trailing spaces (ordinary and no-break). @param {string} text */
export function trimUnitText(text) {
    return text.replace(/^[\s\p{Z}]+|[\s\p{Z}]+$/gu, '');
}

/**
 * What is wrong with the unit, in the same words the server uses. Empty when it can be sent.
 * @param {UnitValue} unit
 * @param {UnitSettings} settings
 * @returns {{ choice?: string, other?: string }}
 */
export function unitErrors(unit, settings) {
    if (unit.choice === '' || !settings.options.includes(unit.choice)) return { choice: MESSAGE_CHOOSE };
    if (unit.choice !== settings.other) return {};
    const text = trimUnitText(unit.other);
    if (text === '') return { other: MESSAGE_OTHER_REQUIRED };
    if (length(text) > settings.otherMax) return { other: `The rescue unit can be at most ${settings.otherMax} characters.` };
    if (UNSAFE_TEXT.test(text)) return { other: MESSAGE_OTHER_FORMAT };

    return {};
}

/**
 * The two fields to send. The text goes only with "Other", trimmed.
 * @param {UnitValue} unit
 * @param {UnitSettings} settings
 * @returns {{ rescue_unit: string, rescue_unit_other: string | null }}
 */
export function unitPayload(unit, settings) {
    return { rescue_unit: unit.choice, rescue_unit_other: unit.choice === settings.other ? trimUnitText(unit.other) : null };
}

/**
 * The server's own words for the unit fields, from a 422's `fields`.
 * @param {Record<string, string[] | string> | null | undefined} fields
 * @returns {{ choice?: string, other?: string }}
 */
export function serverUnitErrors(fields) {
    const first = (value) => (Array.isArray(value) ? value[0] : value);
    const result = {};
    const choice = first(fields?.rescue_unit);
    const other = first(fields?.rescue_unit_other);
    if (typeof choice === 'string' && choice !== '') result.choice = choice;
    if (typeof other === 'string' && other !== '') result.other = other;

    return result;
}

/**
 * "R22", the typed text for Other, or "Not recorded" for a check sent before units were asked for.
 * @param {{ rescue_unit?: string | null } | null | undefined} check
 */
export function unitText(check) {
    const text = typeof check?.rescue_unit === 'string' ? check.rescue_unit.trim() : '';
    return text === '' ? NOT_RECORDED : text;
}
