import assert from 'node:assert/strict';
import test from 'node:test';
import {
  FALLBACK_SEAL_MAX, MESSAGE_SEAL_FORMAT, MESSAGE_SEAL_REQUIRED,
  sealError, sealFromCheck, sealPayload, sealSettings, sealText, serverSealError,
} from '../../../resources/js/operational-forms/narcotics/narcoticsSeal.js';
import { formPhase } from '../../../resources/js/operational-forms/narcotics/narcoticsImage.js';

const settings = sealSettings({ seal_number_max_length: 40 });

test('the length comes from the form answer; a missing or odd answer falls back to 40', () => {
  assert.equal(settings.max, 40);
  assert.equal(sealSettings({ seal_number_max_length: 25 }).max, 25);
  for (const definition of [{}, null, undefined, { seal_number_max_length: 'lots' }, { seal_number_max_length: 0 }, { seal_number_max_length: -3 }, { seal_number_max_length: 1.5 }]) {
    assert.equal(sealSettings(definition).max, FALLBACK_SEAL_MAX);
  }
});

test('a seal number is required, trimmed, one line and at most the server length', () => {
  assert.equal(sealError('', settings), MESSAGE_SEAL_REQUIRED);
  assert.equal(sealError('   ', settings), MESSAGE_SEAL_REQUIRED);
  assert.equal(sealError('  ', settings), MESSAGE_SEAL_REQUIRED, 'no-break spaces are spaces');
  assert.equal(sealError('SEAL-0001', settings), null);
  assert.equal(sealError('  A 12 B-7  ', settings), null, 'digits, letters, dashes and spaces are all fine');
  assert.equal(sealError('a'.repeat(40), settings), null);
  assert.equal(sealError(`  ${'a'.repeat(40)}  `, settings), null, 'spaces around the text do not count');
  assert.equal(sealError('😀'.repeat(40), settings), null, 'characters are counted the way the server counts them');
  assert.equal(sealError('a'.repeat(41), settings), 'The seal number can be at most 40 characters.');
  assert.equal(sealError('12\n34', settings), MESSAGE_SEAL_FORMAT);
  assert.equal(sealError('12\t34', settings), MESSAGE_SEAL_FORMAT);
  assert.equal(sealError('12​34', settings), MESSAGE_SEAL_FORMAT);
  assert.equal(sealError('1234‮', settings), MESSAGE_SEAL_FORMAT);
  assert.equal(sealError('abcdef', { max: 5 }), 'The seal number can be at most 5 characters.');
});

test('what is sent is the trimmed text exactly as typed (case is kept)', () => {
  assert.equal(sealPayload('  ab-12C  '), 'ab-12C');
  assert.equal(sealPayload('SEAL 0001'), 'SEAL 0001');
});

test('answering a rejected check starts with its seal number; an old check starts blank', () => {
  assert.equal(sealFromCheck({ seal_number: 'ZX-4471' }), 'ZX-4471');
  assert.equal(sealFromCheck({ seal_number: null }), '');
  assert.equal(sealFromCheck(null), '');
});

test('the server\'s own words are carried to the field', () => {
  assert.equal(serverSealError({ seal_number: ['Enter the seal number.'] }), 'Enter the seal number.');
  assert.equal(serverSealError({ seal_number: 'Enter the seal number.' }), 'Enter the seal number.');
  assert.equal(serverSealError({ rescue_unit: ['x'] }), undefined);
  assert.equal(serverSealError(undefined), undefined);
});

test('display text: the number as typed, or "Not recorded" for old checks', () => {
  assert.equal(sealText({ seal_number: 'ZX-4471' }), 'ZX-4471');
  assert.equal(sealText({ seal_number: ' ZX-4471 ' }), 'ZX-4471');
  assert.equal(sealText({ seal_number: null }), 'Not recorded');
  assert.equal(sealText({ seal_number: '  ' }), 'Not recorded');
  assert.equal(sealText({}), 'Not recorded');
  assert.equal(sealText(null), 'Not recorded');
});

test('a seal number the member typed is unsaved work; callers that do not know about it behave as before', () => {
  const base = { hasPhoto: false, note: '', hasUnit: false, hasSeal: false, sending: false, failed: false, saved: false };
  assert.equal(formPhase(base), 'empty');
  assert.equal(formPhase({ ...base, hasSeal: true }), 'dirty');
  assert.equal(formPhase({ ...base, hasSeal: true, failed: true }), 'failed');
  assert.equal(formPhase({ ...base, hasSeal: true, sending: true }), 'sending');
  assert.equal(formPhase({ ...base, hasSeal: true, saved: true }), 'saved');
  assert.equal(formPhase({ hasPhoto: false, note: '', sending: false, failed: false, saved: false }), 'empty');
});
