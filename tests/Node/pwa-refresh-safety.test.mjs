import assert from 'node:assert/strict';
import test from 'node:test';

/**
 * Refresh safety (resources/js/pwa/busy.ts):
 *  - isUnsafeToRefresh(): every refresh a member can see or ask for (banner, "Refresh now") —
 *    busy reason / page guard / edited editor or file / text the member TYPED and has not saved.
 *  - isUnsafeForAutomaticReload(): reloads nobody asked for — all of the above, plus any control
 *    that differs from the server-rendered page (Filament fills fields from script, so that
 *    comparison alone would leave "Refresh now" disabled on almost every admin page).
 * The messaging feature registers `employee-message:<uuid>` reasons in window.__mbfdBusy and these
 * must hold the refresh back exactly like any other busy reason.
 */

class HTMLSelectElement {}
class HTMLInputElement {}
globalThis.HTMLSelectElement = HTMLSelectElement;
globalThis.HTMLInputElement = HTMLInputElement;

let controls = [];
globalThis.window = new EventTarget();
const documentTarget = new EventTarget();
documentTarget.querySelector = () => null;
documentTarget.querySelectorAll = () => controls;
documentTarget.contains = () => false;
globalThis.document = documentTarget;

const {
  busyReasons, clearBusy, isUnsafeToRefresh, isUnsafeForAutomaticReload, markBusy, clearFieldEdits,
  rememberTypingBaseline, recordTypedEdit,
} = await import('../../resources/js/pwa/busy.ts');

/** A plain textarea nobody registered anywhere. */
const textarea = (value, defaultValue = '') => ({
  tagName: 'TEXTAREA',
  value,
  defaultValue,
  disabled: false,
  isConnected: true,
  closest: () => null,
});

/** The member focuses the field, then types `text` (trusted events, as a browser sends them). */
const typeInto = (field, text) => {
  rememberTypingBaseline({ target: field });
  field.value = text;
  recordTypedEdit({ isTrusted: true, target: field });
};

test('a clean page with no busy reason is safe to refresh', () => {
  controls = [textarea('')];
  assert.equal(isUnsafeToRefresh(), false);
  assert.equal(isUnsafeForAutomaticReload(), false);
});

test('text the member typed makes every refresh unsafe; clearing it makes it safe again', () => {
  const field = textarea('');
  controls = [field];
  typeInto(field, 'Engine 5 pump test notes');
  assert.equal(isUnsafeToRefresh(), true);
  assert.equal(isUnsafeForAutomaticReload(), true);

  field.value = '';
  assert.equal(isUnsafeToRefresh(), false);
  assert.equal(isUnsafeForAutomaticReload(), false);
  clearFieldEdits();
});

test('a field filled by script after load does not disable "Refresh now", but still holds back an automatic reload', () => {
  const field = textarea('Filled by Livewire', '');
  controls = [field];
  assert.equal(isUnsafeToRefresh(), false, 'nothing the member typed would be lost');
  assert.equal(isUnsafeForAutomaticReload(), true, 'an unasked-for reload stays conservative');
});

test('typing into a script-filled field counts, and typing it back to where it started does not', () => {
  const field = textarea('Filled by Livewire', '');
  controls = [field];
  typeInto(field, 'Filled by Livewire, corrected');
  assert.equal(isUnsafeToRefresh(), true);

  field.value = 'Filled by Livewire';
  assert.equal(isUnsafeToRefresh(), false);
  clearFieldEdits();
});

test('untrusted (script-dispatched) input events never count as typing', () => {
  const field = textarea('');
  controls = [field];
  rememberTypingBaseline({ target: field });
  field.value = 'set by a script';
  recordTypedEdit({ isTrusted: false, target: field });
  assert.equal(isUnsafeToRefresh(), false);
  clearFieldEdits();
});

test('a field the member did not change (value equals its server default) is not unsafe', () => {
  controls = [textarea('Prefilled by the server', 'Prefilled by the server')];
  assert.equal(isUnsafeToRefresh(), false);
  assert.equal(isUnsafeForAutomaticReload(), false);
});

test('disabled fields and fields inside an ignore-edits / search area do not count', () => {
  const disabled = { ...textarea(''), disabled: true };
  const ignored = { ...textarea(''), closest: () => ({}) };
  controls = [disabled, ignored];
  typeInto(disabled, 'typed');
  typeInto(ignored, 'typed');
  assert.equal(isUnsafeToRefresh(), false);
  clearFieldEdits();
});

test('a successful save (clearFieldEdits) forgets typed text', () => {
  const field = textarea('');
  controls = [field];
  typeInto(field, 'saved now');
  assert.equal(isUnsafeToRefresh(), true);
  clearFieldEdits();
  field.defaultValue = 'saved now';
  assert.equal(isUnsafeToRefresh(), false);
});

test('an employee-message busy reason is unsafe until it is cleared, even on an otherwise clean page', () => {
  controls = [];
  const reason = 'employee-message:3f1c0c1e-7a52-4d3e-9b52-0d6f4f0a1c11';

  markBusy(reason);
  assert.deepEqual(busyReasons(), [reason]);
  assert.equal(isUnsafeToRefresh(), true);
  assert.equal(isUnsafeForAutomaticReload(), true);

  clearBusy(reason);
  assert.equal(isUnsafeToRefresh(), false);
});

test('busy reasons and typed fields are independent: either one alone is enough, both must clear', () => {
  const field = textarea('');
  controls = [field];
  typeInto(field, 'draft');
  markBusy('employee-message:abc');
  assert.equal(isUnsafeToRefresh(), true);

  clearBusy('employee-message:abc');
  assert.equal(isUnsafeToRefresh(), true, 'the typed field still holds the refresh back');

  field.value = '';
  assert.equal(isUnsafeToRefresh(), false);
  clearFieldEdits();
});
