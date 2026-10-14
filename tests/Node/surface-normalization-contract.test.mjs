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

/*
 * Daily Checkout inspection and station surfaces consume mbfd-theme.css tokens and the shared
 * hub-components classes: no hex colours, no inline React style objects, no raw Tailwind palette families.
 */
const dailyRoot = 'resources/js/daily-checkout/src';
const normalisedDailySources = [
  'index.css',
  'components/inspection-workspace.css',
  'components/CompartmentStep.tsx',
  'components/ApparatusBlueprint.tsx',
  'components/ChecklistFieldsStep.tsx',
  'components/OfficerStep.tsx',
  'components/MeterStep.tsx',
  'components/SubmitStep.tsx',
  'components/VehicleInspectionSelect.tsx',
  'components/StationListPage.tsx',
  'components/StationDetailPage.tsx',
  'components/StationCard.tsx',
  'components/RoomAssetTracker.tsx',
  'components/ui/Tag.tsx',
  'components/ui/StateViews.tsx',
  'components/ui/Tabs.tsx',
  'components/ui/QuickLink.tsx',
].map((path) => `${dailyRoot}/${path}`);
const rawPaletteUtility = /\b(?:[a-z0-9-]+:)*(?:bg|text|border|ring|outline|from|via|to|fill|stroke|divide|placeholder)-(?:neutral|slate|gray|zinc|stone|blue|red|green|emerald|amber|orange|yellow|teal|cyan|sky|purple|indigo)-\d{2,3}\b/;

for (const path of normalisedDailySources) {
  test(`${path} uses tokens, not hex literals, inline styles or raw palette utilities`, () => {
    const source = readFileSync(path, 'utf8');
    assert.doesNotMatch(source, /#[0-9a-fA-F]{3,8}\b(?![-\w])/, 'no hex colour literals');
    assert.doesNotMatch(source, /\sstyle=\{/, 'no inline style objects');
    assert.doesNotMatch(source, rawPaletteUtility, 'no raw Tailwind palette families');
  });
}

test('Daily loads the shared Hub components inside a data-hub-ui scope and the shared content maximum', () => {
  const stylesheet = readFileSync(`${dailyRoot}/index.css`, 'utf8');
  assert.match(stylesheet, /@import\s+["']\.\.\/\.\.\/\.\.\/css\/hub-components\.css["'];/);
  assert.match(stylesheet, /\.daily-workspace\s*\{[^}]*max-width:\s*var\(--hub-content-max\)/);
  assert.match(stylesheet, /@media \(pointer: coarse\)[\s\S]*?font-size:\s*16px/);
  for (const page of ['StationListPage', 'StationDetailPage', 'VehicleInspectionSelect', 'CompartmentStep', 'SubmitStep']) {
    assert.match(readFileSync(`${dailyRoot}/components/${page}.tsx`, 'utf8'), /data-hub-ui="2"/, `${page} renders inside the shared component scope`);
  }
  const workspace = readFileSync(`${dailyRoot}/components/inspection-workspace.css`, 'utf8');
  for (const [, size] of workspace.matchAll(/font-size:\s*(\d+(?:\.\d+)?)px/g)) {
    assert.ok(Number(size) >= 12, `inspection labels stay at 12px or more (found ${size}px)`);
  }
  assert.doesNotMatch(workspace, /border-radius:\s*(?:\d+px|999px)/, 'radii come from the radius tokens');
});

test('Station pages link by station DB id; only conference rooms take the station number', () => {
  const source = readFileSync(`${dailyRoot}/components/StationDetailPage.tsx`, 'utf8');
  assert.match(source, /href: `\/video-conferencing\/stations\/\$\{stationNumber\}`/);
  assert.doesNotMatch(source, /\/stations\/\$\{stationNumber\}(?!`,)/, 'no station route built from the station number');
  assert.doesNotMatch(source, /onTouch(?:Start|Move|End)|navigator\.vibrate/);
  assert.doesNotMatch(readFileSync(`${dailyRoot}/components/StationListPage.tsx`, 'utf8'), /onTouch(?:Start|Move|End)|navigator\.vibrate|pullDistance/, 'no pull-to-refresh gesture');
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
