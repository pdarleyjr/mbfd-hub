import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

/**
 * Home Quick Access carries one Training Tools disclosure (Command Board Sandbox, Vehicle Placement Simulator, Pump
 * Panel, in that order) built on the same native <details>/<summary> pattern as Additional Links. PHPUnit renders
 * and counts the links (tests/Feature/TrainingTools/TrainingToolsHomeCardTest.php); this pins the source-level rules
 * a render cannot see: the keys and their order, and the touch-target size the shared disclosure rules give every row.
 */

const root = resolve(import.meta.dirname, '..', '..');
const home = readFileSync(resolve(root, 'resources/views/welcome.blade.php'), 'utf8');
const css = readFileSync(resolve(root, 'resources/css/hub-components.css'), 'utf8');

test('Training Tools lists the three destinations in the agreed order', () => {
  const keys = [...home.matchAll(/'key' => '([a-z-]+)',\s*\n\s*'title' => '([^']+)'/g)].map((match) => [match[1], match[2]]);

  assert.deepEqual(keys, [
    ['command-board-sandbox', 'Command Board Sandbox'],
    ['vehicle-placement-simulator', 'Vehicle Placement Simulator'],
    ['pump-panel', 'Pump Panel'],
  ]);
});

test('the simulators are listed once, in Training Tools, and no longer in Additional Links', () => {
  const additional = home.slice(home.indexOf('$additionalLinks = collect(['), home.indexOf(')->filter(fn (array $item): bool => $item[\'visible\'])'));

  assert.ok(additional.includes("'title' => 'Videos'"), 'the rest of Additional Links is still there');
  assert.equal(additional.split('https://firesim.mbfdhub.com').length - 1, 0);
  assert.equal(additional.split('https://pump-panel.pages.dev').length - 1, 0);
  assert.equal(home.split('https://firesim.mbfdhub.com').length - 1, 1);
  assert.equal(home.split('https://pump-panel.pages.dev/').length - 1, 1);
});

test('the sandbox link is a Hub route that opens in this window; only the external simulators open apart', () => {
  assert.match(home, /'href' => route\('training\.command-board'\),[\s\S]*?'external' => false,/);
  assert.match(home, /'href' => 'https:\/\/firesim\.mbfdhub\.com',[\s\S]*?'external' => true,/);
  assert.match(home, /'href' => 'https:\/\/pump-panel\.pages\.dev\/',[\s\S]*?'external' => true,/);
});

test('the disclosure is native details/summary and keeps the Home layout hooks and the separate @vite line', () => {
  assert.match(home, /<details data-training-tools class="[^"]*hub-additional[^"]*">\s*<summary class="hub-additional__summary">/);
  assert.match(home, /<ul class="hub-additional__list" aria-label="Training Tools">/);
  assert.match(home, /@vite\('resources\/css\/app\.css'\)\r?\n/, 'the brand contract needs the stylesheet on its own @vite line');
  assert.ok(home.indexOf('data-training-tools') < home.indexOf('data-additional-links'), 'Training Tools comes before Additional Links');
});

test('every row of the shared disclosure is at least 44px tall and has a visible focus ring', () => {
  for (const selector of ['.hub-additional__summary', '.hub-additional__link']) {
    const rule = new RegExp(String.raw`\[data-hub-ui="2"\] ${selector.replace('.', '\\.')} \{([^}]*)\}`).exec(css);
    assert.ok(rule, `${selector} has its own rule`);
    const minHeight = /min-height:\s*(\d+)px/.exec(rule[1]);
    assert.ok(minHeight && Number(minHeight[1]) >= 44, `${selector} keeps a 44px touch target`);
  }
  assert.match(css, /\.hub-additional__summary:focus-visible,\s*\n\[data-hub-ui="2"\] \.hub-additional__link:focus-visible \{ outline: 2px solid/);
});
