import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const read = (path) => readFileSync(resolve(root, path), "utf8");

const css = read("resources/css/filament/hub-inspection-views.css");
const theme = read("resources/css/filament/admin/theme.css");
const views = {
  employeeDetail: read("resources/views/filament/employee/pages/my-vehicle-inspection-view.blade.php"),
  adminOverview: read("resources/views/filament/resources/apparatus-resource/partials/overview.blade.php"),
  adminInspection: read("resources/views/filament/resources/apparatus-resource/pages/view-inspection.blade.php"),
  rowCard: read("resources/views/filament/employee/tables/inspection-summary.blade.php"),
};

test("inspection views are laid out with semantic classes, not Tailwind utilities the prebuilt Filament theme does not ship", () => {
  // The Filament theme imports the prebuilt dist and Tailwind does not scan app views.
  assert.match(theme, /@import '\.\.\/\.\.\/\.\.\/\.\.\/vendor\/filament\/filament\/dist\/theme\.css';/, "ERROR-071: base theme import stays");
  assert.match(theme, /@import '\.\.\/hub-inspection-views\.css';/);

  const utilities = /\b(?:md:|sm:|lg:)?grid-cols-\d|\bbg-(?:emerald|amber|red)-\d{2,3}\b|\btext-(?:emerald|amber|red)-\d{3}\b|\bh-24\b|\bmt-4\b|\bmb-3\b/;
  for (const [name, source] of Object.entries(views)) {
    assert.doesNotMatch(source, utilities, `${name} must not depend on generated Tailwind utilities`);
  }

  for (const selector of [".hub-insp-facts", ".hub-insp-stats", ".hub-insp-stat--pass", ".hub-insp-stat--missing", ".hub-insp-stat--damaged", ".hub-insp-photo", ".hub-insp-statcards", ".hub-insp-metrics", ".hub-insp-row"]) {
    assert.ok(css.includes(selector), `${selector} is defined`);
  }
  assert.match(css, /@container \(min-width: 44rem\) \{\s*\.hub-insp-facts \{ grid-template-columns: repeat\(4/);
  assert.match(css, /\.hub-insp-photo \{[^}]*height: 6\.5rem/);
  assert.match(css, /\.hub-insp-photo img \{[^}]*object-fit: cover/);
  assert.doesNotMatch(css, /(?:tr|td|th|fi-ta-row|fi-ta-cell)[^{]*::(?:before|after)/, "ERROR-081: no pseudo-elements on table rows or cells");
});

test("the admin report letterhead is print-only and Back lives only in the shared page nav", () => {
  assert.match(views.adminInspection, /class="hub-insp-print-only hub-insp-letterhead"/);
  assert.doesNotMatch(views.adminInspection, /style="display:\s*block/);
  assert.match(css, /\.hub-insp-print-only \{ display: none; \}/);
  assert.match(css, /@media print \{[^}]*\.hub-insp-print-only \{ display: block; \}/s);

  assert.doesNotMatch(read("app/Filament/Resources/ApparatusResource/Pages/ViewInspection.php"), /Back to Apparatus/);
  assert.doesNotMatch(read("app/Filament/Employee/Pages/MyVehicleInspectionView.php"), /Back to my inspections'\)\s*->icon/);
});

test("station cards share one fixed aspect box and the Daily pages without Hub Home use the shared nav", () => {
  const media = read("resources/js/daily-checkout/src/components/StationCardMedia.tsx");
  assert.match(media, /aspect-\[16\/10\]/);
  assert.match(media, /object-cover/);
  assert.doesNotMatch(media, /xl:h-48|object-contain|h-auto/);

  const components = "resources/js/daily-checkout/src/components/";
  for (const file of ["VehicleInspectionSelect.tsx", "ApparatusList.tsx", "forms/StationRequestWizard.tsx"]) {
    assert.match(read(components + file), /<DailyPageNav/, `${file} renders DailyPageNav`);
  }
});

test("the operational forms app leaves Back and Hub Home to the shared Filament nav", () => {
  assert.doesNotMatch(read("resources/js/operational-forms/OperationalFormsApp.tsx"), /of-hub-home|Hub home/);
  assert.doesNotMatch(read("resources/js/operational-forms/operational-forms.css"), /of-hub-home/);
});

test("the inspection PDF keeps the checklist heading with its first table", () => {
  const pdf = read("resources/views/pdf/apparatus-inspection.blade.php");
  assert.match(pdf, /<div class="avoid-break">\s*(?:\{\{--[\s\S]*?--\}\}\s*)?<div class="avoid-break">|<div class="avoid-break">\s*@if\(\$loop->first\)<h2>Checklist results<\/h2>@endif\s*<table class="grid avoid-break"/);
  assert.match(pdf, /\.avoid-break \{ page-break-inside: avoid; \}/);
});
