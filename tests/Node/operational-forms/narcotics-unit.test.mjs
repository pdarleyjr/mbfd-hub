import assert from 'node:assert/strict';
import test from 'node:test';
import {
  FALLBACK_UNITS, MESSAGE_CHOOSE, MESSAGE_OTHER_FORMAT, MESSAGE_OTHER_REQUIRED, NOT_RECORDED,
  blankUnit, pickUnit, serverUnitErrors, trimUnitText, unitErrors, unitFromCheck, unitPayload, unitSettings, unitStarted, unitText,
} from '../../../resources/js/operational-forms/narcotics/narcoticsUnit.js';
import { formPhase } from '../../../resources/js/operational-forms/narcotics/narcoticsImage.js';

const SERVER = ['R1', 'R11', 'R2', 'R22', 'R3', 'R4', 'R44', 'FB6', 'Other'];
const settings = unitSettings({ rescue_units: SERVER, rescue_unit_other_option: 'Other', rescue_unit_other_max_length: 40 });

test('the options come from the form answer, in the order the server gave, with Other last', () => {
  assert.deepEqual(settings.options, SERVER);
  assert.equal(settings.options.at(-1), settings.other);
  assert.equal(settings.otherMax, 40);
  // The server's list wins, whatever it is.
  assert.deepEqual(unitSettings({ rescue_units: ['R7', 'Other'] }).options, ['R7', 'Other']);
  assert.equal(unitSettings({ rescue_units: ['R7', 'Other'], rescue_unit_other_max_length: 25 }).otherMax, 25);
});

test('an answer without the list still gives a working form (the server stays the judge)', () => {
  for (const definition of [{}, null, undefined, { rescue_units: [] }, { rescue_units: 'R1' }, { rescue_units: [3, null, ' '] }]) {
    const fallback = unitSettings(definition);
    assert.deepEqual(fallback.options, FALLBACK_UNITS);
    assert.equal(fallback.other, 'Other');
    assert.equal(fallback.otherMax, 40);
  }
  assert.equal(unitSettings({ rescue_unit_other_max_length: 'lots' }).otherMax, 40);
  assert.equal(unitSettings({ rescue_unit_other_max_length: 0 }).otherMax, 40);
});

test('a unit is required and must be one the list offers', () => {
  assert.deepEqual(unitErrors(blankUnit(), settings), { choice: MESSAGE_CHOOSE });
  assert.deepEqual(unitErrors({ choice: 'R5', other: '' }, settings), { choice: MESSAGE_CHOOSE });
  assert.deepEqual(unitErrors({ choice: 'r22', other: '' }, settings), { choice: MESSAGE_CHOOSE });
  for (const unit of SERVER.slice(0, -1)) assert.deepEqual(unitErrors({ choice: unit, other: '' }, settings), {}, unit);
});

test('Other needs one line of text, trimmed, 1 to 40 characters', () => {
  const other = (text) => unitErrors({ choice: 'Other', other: text }, settings);
  assert.deepEqual(other(''), { other: MESSAGE_OTHER_REQUIRED });
  assert.deepEqual(other('   '), { other: MESSAGE_OTHER_REQUIRED });
  assert.deepEqual(other('  '), { other: MESSAGE_OTHER_REQUIRED }, 'no-break spaces are spaces');
  assert.deepEqual(other('  Rescue 9  '), {});
  assert.deepEqual(other('a'.repeat(40)), {});
  assert.deepEqual(other(`  ${'a'.repeat(40)}  `), {}, 'spaces around the text do not count');
  assert.deepEqual(other('é'.repeat(40)), {});
  assert.deepEqual(other('😀'.repeat(40)), {}, 'characters are counted the way the server counts them');
  assert.deepEqual(other('a'.repeat(41)), { other: 'The rescue unit can be at most 40 characters.' });
  assert.deepEqual(other('R5\nR6'), { other: MESSAGE_OTHER_FORMAT });
  assert.deepEqual(other('R5\tR6'), { other: MESSAGE_OTHER_FORMAT });
  assert.deepEqual(other('R​5'), { other: MESSAGE_OTHER_FORMAT }, 'invisible characters are refused');
});

test('picking a listed unit clears and hides the typed text; picking Other keeps what was typed', () => {
  const typed = { choice: 'Other', other: 'Rescue 9' };
  assert.deepEqual(pickUnit(typed, 'R22', settings), { choice: 'R22', other: '' });
  assert.deepEqual(pickUnit(typed, '', settings), { choice: '', other: '' });
  assert.deepEqual(pickUnit({ choice: 'R22', other: '' }, 'Other', settings), { choice: 'Other', other: '' });
  assert.deepEqual(pickUnit(typed, 'Other', settings), typed);
});

test('the request carries the option, and the trimmed text only for Other', () => {
  assert.deepEqual(unitPayload({ choice: 'R22', other: 'left over' }, settings), { rescue_unit: 'R22', rescue_unit_other: null });
  assert.deepEqual(unitPayload({ choice: 'Other', other: '  Rescue 9 ' }, settings), { rescue_unit: 'Other', rescue_unit_other: 'Rescue 9' });
});

test('answering a rejected check starts with its unit; anything no longer offered starts blank', () => {
  assert.deepEqual(unitFromCheck({ rescue_unit_choice: 'R44', rescue_unit_other: null }, settings), { choice: 'R44', other: '' });
  assert.deepEqual(unitFromCheck({ rescue_unit_choice: 'Other', rescue_unit_other: 'Rescue 9' }, settings), { choice: 'Other', other: 'Rescue 9' });
  assert.deepEqual(unitFromCheck({ rescue_unit_choice: 'R44', rescue_unit_other: 'ignored' }, settings), { choice: 'R44', other: '' });
  assert.deepEqual(unitFromCheck({ rescue_unit_choice: null, rescue_unit_other: null }, settings), blankUnit(), 'a check sent before units existed');
  assert.deepEqual(unitFromCheck({ rescue_unit_choice: 'R5', rescue_unit_other: null }, settings), blankUnit());
  assert.deepEqual(unitFromCheck(null, settings), blankUnit());
});

test('the server\'s own words for the unit fields are carried to the form', () => {
  assert.deepEqual(serverUnitErrors({ rescue_unit: ['Choose your rescue unit.'] }), { choice: 'Choose your rescue unit.' });
  assert.deepEqual(serverUnitErrors({ rescue_unit_other: ['Enter which rescue unit you are on.'], note: ['x'] }), { other: 'Enter which rescue unit you are on.' });
  assert.deepEqual(serverUnitErrors({ note: ['x'] }), {});
  assert.deepEqual(serverUnitErrors(undefined), {});
});

test('display text: the unit, the typed text, or "Not recorded" for old checks', () => {
  assert.equal(unitText({ rescue_unit: 'R22' }), 'R22');
  assert.equal(unitText({ rescue_unit: ' Rescue 9 ' }), 'Rescue 9');
  assert.equal(unitText({ rescue_unit: null }), NOT_RECORDED);
  assert.equal(unitText({}), NOT_RECORDED);
  assert.equal(unitText(null), NOT_RECORDED);
  assert.equal(trimUnitText('  x  '), 'x');
});

test('a unit the member picked is unsaved work, like a note; a prefill alone is not', () => {
  assert.equal(unitStarted(blankUnit()), false);
  assert.equal(unitStarted({ choice: 'R1', other: '' }), true);
  assert.equal(unitStarted({ choice: '', other: ' x ' }), true);
  const base = { hasPhoto: false, note: '', hasUnit: false, sending: false, failed: false, saved: false };
  assert.equal(formPhase(base), 'empty');
  assert.equal(formPhase({ ...base, hasUnit: true }), 'dirty');
  assert.equal(formPhase({ ...base, hasUnit: true, failed: true }), 'failed');
  assert.equal(formPhase({ ...base, hasUnit: true, sending: true }), 'sending');
  assert.equal(formPhase({ ...base, hasUnit: true, saved: true }), 'saved');
  // Callers that do not know about units behave exactly as before.
  assert.equal(formPhase({ hasPhoto: false, note: '', sending: false, failed: false, saved: false }), 'empty');
});
