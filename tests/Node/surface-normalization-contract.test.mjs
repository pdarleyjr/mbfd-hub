import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

/*
 * WP6: surfaces that were normalised onto the design tokens carry no hex colour literals and no
 * inline style attributes. Add a view here when a work package moves it onto tokens.
 */
const normalisedViews = [
  'resources/views/welcome.blade.php',
  'resources/views/errors/hub.blade.php',
  'resources/views/auth/partials/city-email-style.blade.php',
  'resources/views/employee/personnel-request-detail.blade.php',
  'resources/views/filament/admin/pages/equipment-intake.blade.php',
  'resources/views/filament/admin/pages/trt-trailer-inventory.blade.php',
  'resources/views/filament/admin/modals/employee-record.blade.php',
  'resources/views/filament/workgroup/pages/session-results.blade.php',
  'resources/views/filament/workgroup/pages/links.blade.php',
  ...uniformViews(),
];

/* Views moved onto shared stylesheets: no page-private <style> blocks either. */
function uniformViews() {
  return [
    'resources/views/filament/employee/pages/request-equipment.blade.php',
    'resources/views/filament/employee/pages/my-requests.blade.php',
    'resources/views/filament/clusters/personnel-uniforms-equipment/pages/overview.blade.php',
    'resources/views/filament/clusters/personnel-uniforms-equipment/resources/employee-record.blade.php',
  ];
}

for (const view of uniformViews()) {
  test(`${view} has no page-private style block`, () => {
    assert.doesNotMatch(readFileSync(view, 'utf8'), /<style[\s>]/);
  });
}

for (const view of normalisedViews) {
  test(`${view} uses tokens, not hex literals or inline styles`, () => {
    const source = readFileSync(view, 'utf8');
    assert.doesNotMatch(source, /#[0-9a-fA-F]{3,8}\b/, 'no hex colour literals');
    assert.doesNotMatch(source, /\sstyle="/, 'no inline style attributes');
  });
}

/* Stylesheets moved onto tokens: colours, radii and layers come from mbfd-theme.css. */
const normalisedStylesheets = [
  'resources/css/filament/uniform-order-builder.css',
  'resources/css/filament/admin/pages/personnel-uniforms.css',
];

for (const sheet of normalisedStylesheets) {
  test(`${sheet} consumes tokens only`, () => {
    const source = readFileSync(sheet, 'utf8');
    assert.doesNotMatch(source, /#[0-9a-fA-F]{3,8}\b/, 'no hex colour literals');
    assert.doesNotMatch(source, /rgba?\(\s*\d/, 'no literal rgb() colours');
    assert.doesNotMatch(source, /border-radius:\s*\d+px/, 'radii use the radius tokens');
    assert.doesNotMatch(source, /z-index:\s*\d+/, 'layers use the z-index tokens');
  });
}

test('the uniform toolbar sits under the shared shell bar, not a fixed offset', () => {
  const css = readFileSync('resources/css/filament/uniform-order-builder.css', 'utf8');
  assert.match(css, /--uo-toolbar-top: calc\(var\(--hub-shell-bar-height\)/);
  assert.doesNotMatch(css, /--uo-toolbar-top: \d+px/);
});

test('Home keeps its pinned layout hooks and puts Quick Access in a right rail', () => {
  const home = readFileSync('resources/views/welcome.blade.php', 'utf8');
  for (const hook of ['data-home-column="primary"', 'data-home-section="department-updates"', 'data-home-section="quick-access"', 'data-quick-access-card', 'data-important-target']) {
    assert.ok(home.includes(hook), `${hook} stays`);
  }
  assert.match(home, /<aside data-home-column="rail"[\s\S]*data-home-section="quick-access"/);
  const css = readFileSync('resources/css/hub-components.css', 'utf8');
  assert.match(css, /@media \(min-width: 1280px\)[\s\S]*?\.home-layout \{ grid-template-columns: minmax\(0, 62fr\) minmax\(0, 38fr\)/);
});
