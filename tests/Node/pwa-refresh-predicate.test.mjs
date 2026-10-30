import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

/**
 * Release-standard F1: every way a member (or the app) can reload or navigate the window asks the
 * same two safety predicates from resources/js/pwa/busy.ts, and the work the Hub promises to protect
 * (an active call, a call being joined, a held photo/page guard, a chosen file, typed text) holds
 * BOTH of them. These are characterization tests of the current contract.
 */

const root = resolve(import.meta.dirname, '..', '..');
const registrar = readFileSync(resolve(root, 'resources/js/pwa/update-registrar.ts'), 'utf8');

// ---------------------------------------------------------------------------
// 1. Wiring: no refresh path may use a weaker check than the shared predicates.
// ---------------------------------------------------------------------------

test('the registrar never asks the bare busy registry: every decision goes through the shared predicates', () => {
  assert.doesNotMatch(registrar, /\bisBusy\(/, 'isBusy() alone would skip typed text, edited editors and chosen files');
  assert.doesNotMatch(registrar, /\bhasUnsavedFieldEdits\(/, 'the DOM comparison is applied inside isUnsafeForAutomaticReload only');
  assert.match(registrar, /import \{[^}]*isUnsafeForAutomaticReload[^}]*isUnsafeToRefresh[^}]*\} from '\.\/busy'/);
});

test('exactly one place reloads the window, and it re-checks the predicate right before doing so', () => {
  assert.equal((registrar.match(/window\.location\.reload\(\)/g) ?? []).length, 1, 'a single reload site');
  const applyAndReload = registrar.slice(registrar.indexOf('async function applyAndReload'), registrar.indexOf('function refreshNow'));
  assert.match(applyAndReload, /manual \? isUnsafeToRefresh\(\) : isUnsafeForAutomaticReload\(\)/, 'final recheck chooses by who asked');
  assert.ok(
    applyAndReload.indexOf('isUnsafeToRefresh()') < applyAndReload.indexOf('window.location.reload()'),
    'the recheck comes before the reload',
  );
});

test('banner state and the click-time recheck use the manual predicate; hidden-tab, chunk recovery and notification navigation use the stricter one', () => {
  const refreshNow = registrar.slice(registrar.indexOf('function refreshNow'), registrar.indexOf('function automaticReloadAllowed'));
  assert.match(refreshNow, /isUnsafeToRefresh\(\)/);
  assert.match(refreshNow, /applyAndReload\(pending, true\)/);

  const banner = registrar.slice(registrar.indexOf('function renderUpdateBanner'), registrar.indexOf('function renderNavigationBanner'));
  assert.match(banner, /const busy = isUnsafeToRefresh\(\)/);
  assert.match(banner, /disabled: busy/);

  assert.match(registrar, /return !isUnsafeForAutomaticReload\(\) && !hasReloadedFor\(release\) && canGuardReloads\(\);/);
  assert.match(registrar, /mayReload: !isUnsafeForAutomaticReload\(\)/);
  assert.match(registrar, /const deferred = href !== null && !isCurrentLocation\(href\) && isUnsafeForAutomaticReload\(\);/);
  assert.equal((registrar.match(/window\.location\.assign\(/g) ?? []).length, 1, 'a single navigation site (MBFD_NAVIGATE)');
});

test('a controllerchange never reloads, and the hidden-tab reload needs the automatic rules', () => {
  assert.doesNotMatch(registrar, /addEventListener\('controllerchange'[^)]*reload/, 'controllerchange must not reload');
  const automatic = registrar.slice(registrar.indexOf('function evaluate'), registrar.indexOf('// -- release detection'));
  assert.match(automatic, /document\.hidden && automaticReloadAllowed\(pending\)/);
});

// ---------------------------------------------------------------------------
// 2. Behaviour: what holds the predicates, using the real module.
// ---------------------------------------------------------------------------

class HTMLSelectElement {}
class HTMLInputElement {}
globalThis.HTMLSelectElement = HTMLSelectElement;
globalThis.HTMLInputElement = HTMLInputElement;

let controls = [];
let guarded = false;
const hooks = [];
globalThis.window = new EventTarget();
globalThis.window.Livewire = { hook: (name, callback) => hooks.push({ name, callback }) };
const documentTarget = new EventTarget();
documentTarget.querySelector = (selector) => (selector === '[data-mbfd-unsaved-work-guard]' && guarded ? {} : null);
documentTarget.querySelectorAll = () => controls;
globalThis.document = documentTarget;

const busy = await import('../../resources/js/pwa/busy.ts');
const { clearBusy, clearFieldEdits, isUnsafeForAutomaticReload, isUnsafeToRefresh, markBusy, recordFieldEdit, recordTypedEdit, rememberTypingBaseline, startFieldEditTracking } = busy;

const both = () => [isUnsafeToRefresh(), isUnsafeForAutomaticReload()];

const textarea = (value = '', within = null) => ({
  tagName: 'TEXTAREA', value, defaultValue: '', disabled: false, isConnected: true, closest: () => null, within,
});
const typeInto = (field, text) => {
  rememberTypingBaseline({ target: field });
  field.value = text;
  recordTypedEdit({ isTrusted: true, target: field });
};

test('an active call, a call being joined and the page guard hold BOTH predicates; releasing them frees both', () => {
  controls = [];
  for (const reason of ['active-call', 'joining', 'unsaved-draft', 'queued-submission']) {
    markBusy(reason);
    assert.deepEqual(both(), [true, true], `${reason} must hold every kind of refresh`);
    clearBusy(reason);
    assert.deepEqual(both(), [false, false], `${reason} released`);
  }

  guarded = true;
  assert.deepEqual(both(), [true, true], 'data-mbfd-unsaved-work-guard holds a manual refresh as well as an automatic one');
  guarded = false;
  assert.deepEqual(both(), [false, false]);
});

test('a chosen file or an edited rich-text surface holds both predicates until it is gone', () => {
  controls = [];
  const file = { tagName: 'INPUT', type: 'file', files: [{ name: 'tool.jpg' }], isConnected: true, closest: () => null };
  recordFieldEdit({ isTrusted: true, target: file });
  assert.deepEqual(both(), [true, true]);

  file.files = [];
  assert.deepEqual(both(), [false, false], 'an emptied file input stops counting');
  clearFieldEdits();
});

// ---------------------------------------------------------------------------
// 3. A Livewire action only counts as "saved" for the component that ran it.
// ---------------------------------------------------------------------------

startFieldEditTracking();
const commitHook = hooks.find((hook) => hook.name === 'commit')?.callback;

/** Runs one successful Livewire commit for a component that owns `owned` fields. */
const commit = (methods, owned) => {
  const el = { contains: (node) => owned.includes(node) };
  commitHook({ component: { el }, commit: { calls: methods.map((method) => ({ method })) }, succeed: (callback) => callback() });
};

test('the Livewire commit hook is registered', () => {
  assert.equal(typeof commitHook, 'function');
});

test('a successful save in the component that owns the typed field forgets it', () => {
  const field = textarea();
  controls = [field];
  typeInto(field, 'Reply being written');
  assert.equal(isUnsafeToRefresh(), true);

  commit(['save'], [field]);
  field.defaultValue = field.value;
  assert.equal(isUnsafeToRefresh(), false);
  clearFieldEdits();
});

test('an action in ANOTHER component does not forget text typed elsewhere on the page', () => {
  const typed = textarea();
  const other = textarea();
  controls = [typed, other];
  typeInto(typed, 'Half-written note in the page form');

  commit(['markRead'], [other]);
  assert.equal(isUnsafeToRefresh(), true, 'a widget action must not make "Refresh now" discard the page form');
  clearFieldEdits();
});

test('Livewire internals (__lazyLoad, __dispatch) and $refresh polls are never saves', () => {
  const typed = textarea();
  controls = [typed];
  typeInto(typed, 'Typed while a widget lazy-loads');

  for (const method of ['__lazyLoad', '__dispatch', '$refresh', '$commit']) {
    commit([method], [typed]);
    assert.equal(isUnsafeToRefresh(), true, `${method} is not a save`);
  }
  clearFieldEdits();
});
