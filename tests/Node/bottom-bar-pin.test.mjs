import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

/**
 * iOS 26 Safari strands `position: fixed; bottom: 0` bars above the visible edge when
 * visualViewport.offsetTop goes stale (after the keyboard closes, while the toolbar resizes).
 * resources/js/pwa/bottom-bar-pin.ts measures the drift and hub-shell.css moves the bars down by it.
 */

const root = resolve(import.meta.dirname, '..', '..');
const { bottomBarShift } = await import('../../resources/js/pwa/bottom-bar-pin.ts');
const shellCss = readFileSync(resolve(root, 'resources/css/hub-shell.css'), 'utf8');

test('no drift: the visible bottom edge is the layout bottom edge, so nothing moves', () => {
  assert.equal(bottomBarShift({ offsetTop: 0, height: 852 }, 852, false), 0);
});

test('stale offsetTop after the keyboard closes: the bar moves down by exactly the drift', () => {
  // The screenshot case: about a keyboard height (336px) left in offsetTop with a full-height viewport.
  assert.equal(bottomBarShift({ offsetTop: 336, height: 852 }, 852, false), 336);
});

test('toolbar collapse: a visual viewport taller than the layout viewport pins the bar to the new edge', () => {
  assert.equal(bottomBarShift({ offsetTop: 0, height: 932 }, 852, false), 80);
});

test('keyboard open (a field has focus): the bar is left where every browser puts it', () => {
  assert.equal(bottomBarShift({ offsetTop: 336, height: 516 }, 852, true), 0);
});

test('never moves a bar up, and ignores sub-pixel noise', () => {
  assert.equal(bottomBarShift({ offsetTop: 0, height: 516 }, 852, false), 0);
  assert.equal(bottomBarShift({ offsetTop: 0, height: 853.4 }, 852, false), 0);
});

test('the bars and the More sheet read the shift through bottom, never a transform', () => {
  assert.match(shellCss, /bottom: calc\(0px - var\(--hub-bottom-bar-shift, 0px\)\);/);
  assert.match(shellCss, /inset: auto 0 calc\(0px - var\(--hub-bottom-bar-shift, 0px\)\) 0;/);
  assert.doesNotMatch(shellCss, /\.hub-member-nav[^{]*\{[^}]*transform:/, 'a transform on the bar would trap its own fixed More sheet');
});

test('every page with a bottom bar starts the pin (Hub/panels and Daily)', () => {
  assert.match(readFileSync(resolve(root, 'resources/js/hub-shell.js'), 'utf8'), /startBottomBarPin\(\);/);
  assert.match(readFileSync(resolve(root, 'resources/js/daily-checkout/src/main.tsx'), 'utf8'), /startBottomBarPin\(\)/);
});
