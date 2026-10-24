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

/* Workgroup report decks render on the light Hub surfaces; chart colours come from mbfd-theme.css. */
function reportDeckViews() {
  return [
    'resources/views/workgroup/analysis-report.blade.php',
    'resources/views/workgroup/final-presentation.blade.php',
  ];
}

/* Views moved onto shared stylesheets: no page-private <style> blocks either. */
function uniformViews() {
  return [
    'resources/views/filament/employee/pages/request-equipment.blade.php',
    'resources/views/filament/employee/pages/my-requests.blade.php',
    'resources/views/filament/clusters/personnel-uniforms-equipment/pages/overview.blade.php',
    'resources/views/filament/clusters/personnel-uniforms-equipment/resources/employee-record.blade.php',
    ...reportDeckViews(),
    ...workgroupReportViews(),
  ];
}

const arbitraryColourUtility = /\b(?:[a-z0-9-]+:)*(?:bg|text|border(?:-[lrtbxy])?|from|via|to|ring)-\[(?:#|rgb)/;
const rawGreyUtility = /\b(?:[a-z0-9-]+:)*(?:bg|text|border)-(?:gray|slate|neutral|zinc)-\d{2,3}\b/;

for (const view of reportDeckViews()) {
  test(`${view} uses the light Hub design system with token-driven charts`, () => {
    const source = readFileSync(view, 'utf8');
    assert.doesNotMatch(source, arbitraryColourUtility, 'no arbitrary hex/rgb Tailwind colour utilities');
    assert.doesNotMatch(source, rawGreyUtility, 'secondary text uses hub-* tokens, not raw grey families');
    assert.doesNotMatch(source, /rgba?\(\s*\d/, 'no literal rgb() colours in chart code');
    assert.doesNotMatch(source, /(?:linear|radial)-gradient|bg-gradient-to-|backdrop-blur/, 'no gradients or blur on working surfaces');
    assert.doesNotMatch(source, /black\.css/, 'the dark Reveal theme is not loaded');
    assert.doesNotMatch(source, /text-hub-muted\b/, 'muted slate fails AA on glass; use hub-ink-secondary');
    assert.match(source, /\bhub-panel\b/, 'sections use the shared glass panel');
    assert.match(source, /--hub-chart-1/, 'chart series read their colours from the theme tokens');
  });
}

test('chart series tokens live in mbfd-theme.css and alias existing Hub colours', () => {
  const theme = readFileSync('resources/css/mbfd-theme.css', 'utf8');
  for (const token of ['--hub-chart-1', '--hub-chart-2', '--hub-chart-3', '--hub-chart-4', '--hub-chart-grid', '--hub-chart-label']) {
    assert.match(theme, new RegExp(`${token}:\\s*var\\(--hub-[a-z0-9-]+\\);`), `${token} aliases a Hub token`);
  }
});

test('the dark report-deck overrides are gone from the shared stylesheets', () => {
  const app = readFileSync('resources/css/app.css', 'utf8');
  const portals = readFileSync('resources/css/filament/hub-portals-v2.css', 'utf8');
  for (const [name, css] of [['app.css', app], ['hub-portals-v2.css', portals]]) {
    assert.doesNotMatch(css, /hub-report--(?:analysis-report|final-presentation)[^{]*\{[^}]*background(?:-color)?:\s*rgb\(var\(--hub-header/, `${name}: report decks no longer paint the navy canvas`);
  }
  assert.match(app, /@import '\.\/report-deck\.css';/, 'app.css loads the shared report-deck stylesheet');
});

/* One brand across the four Filament panels: identical name, mark, favicon, palette, font and theme. */
test('all four Filament panel providers share one brand configuration', () => {
  const providers = ['Admin', 'Employee', 'Training', 'Workgroup'].map((panel) => `app/Providers/Filament/${panel}PanelProvider.php`);
  const brandCalls = ['brandName', 'brandLogo', 'brandLogoHeight', 'favicon', 'darkMode', 'colors', 'font', 'viteTheme'];
  const configOf = (source) => Object.fromEntries(brandCalls.map((call) => {
    const match = new RegExp(`->${call}\\((.*)\\)\\s*$`, 'm').exec(source);
    return [call, match?.[1]?.trim() ?? null];
  }));

  const [reference, ...others] = providers.map((path) => ({ path, config: configOf(readFileSync(path, 'utf8')) }));
  for (const call of brandCalls) {
    assert.ok(reference.config[call], `${reference.path} declares ->${call}()`);
  }
  assert.equal(reference.config.brandName, "'MBFD Hub'");
  assert.equal(reference.config.darkMode, 'false');
  assert.match(reference.config.brandLogo, /components\.hub\.brand/);
  for (const { path, config } of others) {
    assert.deepEqual(config, reference.config, `${path} matches the Admin panel brand configuration`);
  }
});

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
  'resources/css/filament/hub-portals-v2.css',
  'resources/css/report-deck.css',
  'resources/css/reports/evaluation-report.css',
  'resources/css/reports/final-recommendations.css',
  'resources/css/reports/workgroup-summary.css',
  'resources/css/reports/saver-report.css',
  'resources/css/filament/workgroup-pages.css',
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
  // --hub-bar-total is the shell bar plus the top safe-area inset (installed apps on notched phones).
  assert.match(css, /--uo-toolbar-top: var\(--hub-bar-total\)/);
  assert.doesNotMatch(css, /--uo-toolbar-top: \d+px/);
  const theme = readFileSync('resources/css/mbfd-theme.css', 'utf8');
  assert.match(theme, /--hub-bar-total: calc\(var\(--hub-shell-bar-height\) \+ var\(--hub-safe-top\)\)/);
});

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

/* Standalone Workgroup reports carry the shared Hub head (theme colour, manifest, app title) and the one product name. */
test('standalone Workgroup reports include the shared Hub head and the product is named MBFD Hub', () => {
  const reports = ['analysis-report', 'data-dashboard', 'evaluation-report', 'final-presentation', 'final-recommendations', 'l1-inventory', 'workgroup-summary']
    .map((name) => `resources/views/workgroup/${name}.blade.php`)
    .concat('resources/views/filament/workgroup/pages/saver-report.blade.php');
  for (const view of reports) {
    assert.match(readFileSync(view, 'utf8'), /<x-hub\.head \/>/, `${view} includes <x-hub.head />`);
  }
  assert.match(readFileSync('config/app.php', 'utf8'), /'name' => env\('APP_NAME', 'MBFD Hub'\)/);
  assert.match(readFileSync('.env.example', 'utf8'), /^APP_NAME="MBFD Hub"$/m);
});

/* Every Workgroup report surface: shared stylesheets with tokens, no page CSS, no hex, no CDN scripts. */
function workgroupReportViews() {
  return [
    'resources/views/workgroup/data-dashboard.blade.php',
    'resources/views/workgroup/evaluation-report.blade.php',
    'resources/views/workgroup/final-recommendations.blade.php',
    'resources/views/workgroup/l1-inventory.blade.php',
    'resources/views/workgroup/workgroup-summary.blade.php',
    'resources/views/filament/workgroup/pages/saver-report.blade.php',
    'resources/views/filament/workgroup/pages/partials/granular-tool-table.blade.php',
  ];
}

for (const view of [...reportDeckViews(), ...workgroupReportViews()]) {
  test(`${view} carries no hex, inline styles, gradients or third-party CDN scripts`, () => {
    const source = readFileSync(view, 'utf8');
    assert.doesNotMatch(source, /#[0-9a-fA-F]{3,8}\b/, 'no hex colour literals');
    assert.doesNotMatch(source, /\sstyle="/, 'no inline style attributes');
    assert.doesNotMatch(source, /<style[\s>]/, 'no page-private style block');
    assert.doesNotMatch(source, /rgba?\(\s*\d/, 'no literal rgb() colours');
    assert.doesNotMatch(source, /(?:linear|radial)-gradient/, 'no gradients');
    assert.doesNotMatch(source, /<script[^>]+src="https?:\/\//, 'scripts are bundled or served from pinned local vendor copies');
  });
}

test('report charts use the pinned local Chart.js copy and token colours, not a CDN', () => {
  const vendored = readFileSync('public/vendor/chartjs-4.4.0/chart.umd.js', 'utf8');
  assert.match(vendored.slice(0, 200), /Chart\.js v4\.4\.0/, 'the vendored file is exactly Chart.js 4.4.0');
  assert.match(readFileSync('resources/js/report-charts.js', 'utf8'), /window\.hubColor = /);
  assert.match(readFileSync('vite.config.js', 'utf8'), /'resources\/js\/report-charts\.js'/);
  for (const name of ['analysis-report', 'final-presentation', 'evaluation-report', 'final-recommendations']) {
    const source = readFileSync(`resources/views/workgroup/${name}.blade.php`, 'utf8');
    assert.ok(source.includes(`<script src="{{ asset('vendor/chartjs-4.4.0/chart.umd.js') }}"></script>`), `${name} loads the pinned local Chart.js`);
    assert.match(source, /@vite\(\[[^\]]*'resources\/js\/report-charts\.js'/, `${name} loads the token colour helper`);
    assert.doesNotMatch(source, /<script\s*>/i, `${name} chart code runs as a module after the helper`);
  }
  for (const name of ['evaluation-report', 'final-recommendations', 'workgroup-summary']) {
    const source = readFileSync(`resources/views/workgroup/${name}.blade.php`, 'utf8');
    assert.ok(source.includes(`@vite(['resources/css/reports/${name}.css', 'resources/css/app.css'`), `${name} loads its token stylesheet before app.css`);
  }
});
