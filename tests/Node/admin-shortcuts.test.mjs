import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const view = readFileSync(new URL('../../resources/views/filament/admin/partials/keyboard-shortcuts.blade.php', import.meta.url), 'utf8');
const script = view.match(/<script>([\s\S]*?)<\/script>/i)?.[1];

function createHarness({ saved = null, unavailable = false } = {}) {
  const components = new Map();
  const listeners = new Map();
  const effects = { focus: 0, events: [], prevented: 0, path: '' };
  const input = { focus: () => effects.focus++ };
  const window = {
    localStorage: {
      getItem: () => { if (unavailable) throw new Error('Storage unavailable'); return saved; },
      setItem: (_key, value) => { if (unavailable) throw new Error('Storage unavailable'); saved = value; },
    },
    matchMedia: () => ({ matches: true }),
    location: { set href(value) { effects.path = value; } },
    dispatchEvent: event => effects.events.push(event.type),
  };
  const document = {
    activeElement: null,
    addEventListener: (name, listener) => { if (name === 'alpine:init') listener(); else listeners.set(name, listener); },
    removeEventListener: name => listeners.delete(name),
    querySelector: selector => selector === '.fi-global-search-field input' ? input : null,
    querySelectorAll: () => [],
  };
  vm.runInNewContext(script, {
    Alpine: { data: (name, factory) => components.set(name, factory) },
    document, window, setTimeout, clearTimeout,
    CustomEvent: class { constructor(type, detail) { this.type = type; this.detail = detail; } },
  });
  const shortcuts = components.get('adminKeyboardShortcuts')();
  const help = components.get('adminShortcutsHelp')();
  shortcuts.init();
  const key = (value, options = {}) => listeners.get('keydown')({
    key: value, target: { tagName: 'BODY', closest: () => null },
    preventDefault: () => effects.prevented++, ...options,
  });
  return { shortcuts, help, key, effects, stored: () => saved, listeners };
}

test('turning character shortcuts off blocks navigation and focus, and persists after reload', () => {
  const harness = createHarness();
  harness.help.toggle();
  harness.key('/');
  harness.key('g');
  harness.key('a');
  assert.equal(harness.effects.focus, 0);
  assert.equal(harness.effects.path, '');
  assert.equal(harness.stored(), 'off');

  const reloaded = createHarness({ saved: harness.stored() });
  assert.equal(reloaded.help.enabled, false);
  reloaded.key('/');
  assert.equal(reloaded.effects.focus, 0);
  reloaded.key('/', { ctrlKey: true });
  assert.deepEqual(reloaded.effects.events, ['open-admin-shortcuts-help']);
});

test('the off setting still works for the current visit when browser storage is unavailable', () => {
  const harness = createHarness({ unavailable: true });
  harness.help.toggle();
  assert.equal(harness.help.enabled, false);
  harness.key('/');
  harness.key('g');
  harness.key('a');
  assert.equal(harness.effects.focus, 0);
  assert.equal(harness.effects.path, '');
});

test('Ctrl/Cmd+K focuses native Filament search with character shortcuts disabled', () => {
  const harness = createHarness({ saved: 'off' });
  harness.key('k', { ctrlKey: true });
  harness.key('k', { metaKey: true });
  assert.equal(harness.effects.focus, 2);
  assert.deepEqual(harness.effects.events, []);
});

test('modifier search stays inside an active dialog', () => {
  const harness = createHarness();
  const target = { tagName: 'BUTTON', closest: selector => selector.includes('dialog') ? {} : null };
  harness.key('k', { ctrlKey: true, target });
  harness.key('k', { metaKey: true, target });
  assert.equal(harness.effects.focus, 0);
  assert.deepEqual(harness.effects.events, []);
});

test('the health sequence uses the registered Application Health route', () => {
  const harness = createHarness();
  harness.key('g');
  harness.key('h');
  assert.equal(harness.effects.path, '/admin/health-check-results');
});

test('typing, composing, repeating, and an open dialog do not trigger character actions', () => {
  const harness = createHarness();
  harness.key('/', { target: { tagName: 'INPUT' } });
  harness.key('/', { isComposing: true });
  harness.key('/', { repeat: true });
  harness.key('/', { target: { tagName: 'BUTTON', closest: selector => selector.includes('dialog') ? {} : null } });
  assert.equal(harness.effects.focus, 0);
  assert.equal(harness.effects.prevented, 0);
});

test('disposing the shortcut component removes its key listener', () => {
  const harness = createHarness();
  assert.equal(harness.listeners.has('keydown'), true);
  harness.shortcuts.destroy();
  assert.equal(harness.listeners.has('keydown'), false);
});
