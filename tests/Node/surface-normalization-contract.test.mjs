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
];

for (const view of normalisedViews) {
  test(`${view} uses tokens, not hex literals or inline styles`, () => {
    const source = readFileSync(view, 'utf8');
    assert.doesNotMatch(source, /#[0-9a-fA-F]{3,8}\b/, 'no hex colour literals');
    assert.doesNotMatch(source, /\sstyle="/, 'no inline style attributes');
  });
}

test('Home keeps its pinned layout hooks and puts Quick Access in a right rail', () => {
  const home = readFileSync('resources/views/welcome.blade.php', 'utf8');
  for (const hook of ['data-home-column="primary"', 'data-home-section="department-updates"', 'data-home-section="quick-access"', 'data-quick-access-card', 'data-important-target']) {
    assert.ok(home.includes(hook), `${hook} stays`);
  }
  assert.match(home, /<aside data-home-column="rail"[\s\S]*data-home-section="quick-access"/);
  const css = readFileSync('resources/css/hub-components.css', 'utf8');
  assert.match(css, /@media \(min-width: 1280px\)[\s\S]*?\.home-layout \{ grid-template-columns: minmax\(0, 62fr\) minmax\(0, 38fr\)/);
});
