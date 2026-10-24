import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

/**
 * Pages whose unsaved work lives in script state (take-home inspection photos and
 * signature, Operational Forms drafts) opt in with data-mbfd-unsaved-work-guard,
 * and the update registrar must then treat the window as busy.
 */

const root = resolve(import.meta.dirname, '..', '..');
let guarded = false;

globalThis.window = new EventTarget();
const documentTarget = new EventTarget();
documentTarget.querySelector = (selector) => (selector === '[data-mbfd-unsaved-work-guard]' && guarded ? {} : null);
globalThis.document = documentTarget;

const { busyReasons, clearBusy, isBusy, markBusy } = await import('../../resources/js/pwa/busy.ts');

test('a page carrying the unsaved-work guard is busy without any explicit reason', () => {
  guarded = false;
  assert.equal(isBusy(), false);

  guarded = true;
  assert.equal(isBusy(), true);
  assert.deepEqual(busyReasons(), ['unsaved-work-guard']);

  markBusy('active-call');
  clearBusy('active-call');
  assert.equal(isBusy(), true, 'clearing an explicit reason does not clear the page guard');

  guarded = false;
  assert.equal(isBusy(), false);
});

test('take-home inspections and Operational Forms opt in to the guard', () => {
  const takeHome = readFileSync(resolve(root, 'resources/views/take-home-inspections/create.blade.php'), 'utf8');
  const forms = readFileSync(resolve(root, 'resources/views/filament/employee/pages/operational-forms.blade.php'), 'utf8');

  assert.match(takeHome, /<form id="thvi-form"[^>]*data-mbfd-unsaved-work-guard/);
  assert.match(forms, /id="operational-forms-root"\s+data-mbfd-unsaved-work-guard/);
});
