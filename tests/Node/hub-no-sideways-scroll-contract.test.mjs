import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';
import test from 'node:test';

/*
 * Responsive tables contract (docs/ui/responsive-tables.md, .impeccable.md "Layout and interaction").
 *
 * Operational tables never scroll sideways, so a new overflow-x:auto / overflow-x-auto /
 * overflow-x-scroll wrapper is a regression unless it is a deliberate exception. Every existing use is
 * listed with its count and the reason it is allowed. The list is a ratchet: it may only shrink.
 *   - a file that gains a wrapper fails (fix the table: resources/js/hub-tables.js, data-hub-table),
 *   - a file that loses one fails until its entry is lowered or removed, so fixes stay fixed.
 * Kinds: "not-a-table" (tab strips, toolbars, carousels, preview stages), "exception" (a table with a
 * documented reason in docs/ui/responsive-tables.md) and "pending" (a table not yet migrated).
 */

const root = resolve(import.meta.dirname, '..', '..');
const allowlist = JSON.parse(readFileSync(resolve(import.meta.dirname, 'fixtures', 'sideways-scroll-allowlist.json'), 'utf8'));

const pattern = /overflow-x-auto|overflow-x-scroll|overflow-x\s*:\s*(?:auto|scroll)/g;
const extensions = /\.(css|php|tsx|jsx|js|ts|html)$/;
const skipped = new Set(['node_modules', 'build', 'fixtures']);

function* walk(directory) {
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) {
      if (!skipped.has(entry.name)) yield* walk(path);
    } else if (extensions.test(entry.name)) {
      yield path;
    }
  }
}

function scan() {
  const found = {};
  for (const base of ['resources', 'app']) {
    for (const file of walk(resolve(root, base))) {
      const matches = readFileSync(file, 'utf8').match(pattern);
      if (matches) found[relative(root, file).replaceAll('\\', '/')] = matches.length;
    }
  }
  return found;
}

test('no file gains a sideways-scrolling wrapper', () => {
  const found = scan();
  const added = Object.entries(found)
    .filter(([file, count]) => count > (allowlist[file]?.count ?? 0))
    .map(([file, count]) => `${file}: ${count} found, ${allowlist[file]?.count ?? 0} allowed`);
  assert.deepEqual(added, [], 'New overflow-x wrappers on tables are not allowed. Let resources/js/hub-tables.js adapt the table (add data-hub-table to Blade/React tables) or document an exception.');
});

test('the allowlist is a ratchet: fixed wrappers are removed from it', () => {
  const found = scan();
  const stale = Object.entries(allowlist)
    .filter(([file, entry]) => (found[file] ?? 0) < entry.count)
    .map(([file, entry]) => `${file}: allowlist says ${entry.count}, source has ${found[file] ?? 0}`);
  assert.deepEqual(stale, [], 'Lower or remove these allowlist entries in tests/Node/fixtures/sideways-scroll-allowlist.json.');
});

test('every allowlist entry says what it is and why', () => {
  for (const [file, entry] of Object.entries(allowlist)) {
    assert.ok(['not-a-table', 'exception', 'pending'].includes(entry.kind), `${file}: unknown kind`);
    assert.ok(Number.isInteger(entry.count) && entry.count > 0, `${file}: count`);
    assert.ok(typeof entry.reason === 'string' && entry.reason.length > 10, `${file}: reason`);
  }
});

test('Filament tables are never given a sideways scroller by the Hub', () => {
  const css = readFileSync(resolve(root, 'resources/css/hub-tables.css'), 'utf8');
  assert.doesNotMatch(css, /\.fi-ta-content[^{]*\{[^}]*overflow-x\s*:\s*(auto|scroll)/);
  assert.doesNotMatch(css, /position:\s*sticky/, 'sticky columns only exist to serve sideways scrolling');
});

test('the dense directory pages no longer use sticky sideways-scroll columns', () => {
  const styles = readFileSync(resolve(root, 'resources/views/filament/hooks/responsive-tables-styles.blade.php'), 'utf8');
  assert.doesNotMatch(styles, /position:\s*sticky/);
  assert.doesNotMatch(styles, /overflow-x/);
});

test('hub-tables.css uses design tokens only (no colour literals)', () => {
  const css = readFileSync(resolve(root, 'resources/css/hub-tables.css'), 'utf8');
  assert.doesNotMatch(css, /#[0-9a-fA-F]{3,8}\b/);
  assert.doesNotMatch(css, /\brgba?\(\s*\d/);
});

test('touch targets stay at least 44px (2.75rem) on the controls the module injects', () => {
  const css = readFileSync(resolve(root, 'resources/css/hub-tables.css'), 'utf8');
  const toggle = css.match(/\.hub-rt-toggle\s*\{[^}]*\}/)?.[0] ?? '';
  assert.match(toggle, /min-width:\s*2\.75rem/);
  assert.match(toggle, /min-height:\s*2\.75rem/);
  assert.match(css, /\.hub-rt-sort__select\s*\{[^}]*min-height:\s*2\.75rem/);
});
