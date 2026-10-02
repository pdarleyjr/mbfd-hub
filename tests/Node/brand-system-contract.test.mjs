import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const read = (path) => readFileSync(resolve(root, path), "utf8");

const dailySurfaces = [
  "resources/js/daily-checkout/src/components/StationListPage.tsx",
  "resources/js/daily-checkout/src/components/StationCard.tsx",
  "resources/js/daily-checkout/src/components/StationDetailPage.tsx",
  "resources/js/daily-checkout/src/components/RoomAssetTracker.tsx",
  "resources/js/daily-checkout/src/components/FormsHub.tsx",
  "resources/js/daily-checkout/src/components/VehicleInspectionSelect.tsx",
];

function objectProperty(source, property) {
  const start = new RegExp(`\\b${property}\\s*:\\s*\\{`).exec(source);
  assert.ok(start, `expected ${property} object property`);

  const openingBrace = start.index + start[0].lastIndexOf("{");
  let depth = 0;
  for (let index = openingBrace; index < source.length; index += 1) {
    if (source[index] === "{") depth += 1;
    if (source[index] !== "}") continue;
    depth -= 1;
    if (depth === 0) return source.slice(start.index, index + 1);
  }

  assert.fail(`unterminated ${property} object property`);
}

test("Daily uses shared cool-neutral tokens and Plus Jakarta primary typography", () => {
  const stylesheet = read("resources/js/daily-checkout/src/index.css");
  const tailwind = read("resources/js/daily-checkout/tailwind.config.js");

  assert.match(
    stylesheet,
    /@import\s+["']\.\.\/\.\.\/\.\.\/css\/mbfd-theme\.css["'];/,
    "Daily must import the shared Hub token source",
  );
  const neutral = objectProperty(tailwind, "neutral");
  for (const [shade, token] of Object.entries({
    50: "control", 100: "surface-muted", 200: "border-soft", 300: "border",
    400: "border-strong", 500: "muted", 600: "ink-secondary", 700: "ink-secondary",
    800: "ink", 900: "ink",
  })) {
    assert.match(
      neutral,
      new RegExp(`${shade}:\\s*["']rgb\\(var\\(--hub-${token}\\)\\s*\\/\\s*<alpha-value>\\)["']`),
      `Daily neutral-${shade} must use the shared cool-neutral ${token} token`,
    );
  }
  assert.match(
    tailwind,
    /sans:\s*\[\s*["']var\(--hub-font-sans\)["']\s*\]/,
    "Daily's primary sans stack must use the shared Plus Jakarta token",
  );
  assert.match(read("resources/css/mbfd-theme.css"), /--hub-font-sans:\s*'Plus Jakarta Sans Variable',\s*'Plus Jakarta Sans'/);
  assert.match(
    tailwind,
    /hub:\s*\[\s*["']var\(--hub-font-sans\)["']\s*\]/,
    "Daily must expose a scoped Plus Jakarta font-hub utility",
  );

  for (const token of ["blue", "red", "canvas", "surface", "border", "ink", "muted", "focus", "success", "warning", "danger"]) {
    assert.match(
      tailwind,
      new RegExp(`${token}:\\s*["']rgb\\(var\\(--hub-${token}\\)\\s*\\/\\s*<alpha-value>\\)["']`),
      `Daily Tailwind must expose the --hub-${token} semantic token`,
    );
  }
});

test("six non-inspection Daily surfaces scope Plus Jakarta and avoid decorative category hues", () => {
  const rawPaletteUtility = /\b(?:(?:sm|md|lg|xl|2xl|hover|focus|focus-visible|active|disabled):)*(?:bg|text|border|ring|outline|from|via|to)-(?:neutral|slate|gray|blue|red|green|emerald|amber|orange|teal|cyan|purple|indigo)-[^\s"'`]+/g;
  const violations = [];

  for (const path of dailySurfaces) {
    const source = read(path);
    assert.match(source, /\bfont-hub\b/, `${path} must scope its surface to Plus Jakarta through font-hub`);
    assert.match(
      source,
      /\b(?:bg|text|border|ring|outline|from|via|to)-hub-[^\s"'`]+/,
      `${path} must use semantic hub-* color utilities`,
    );

    for (const utility of source.match(rawPaletteUtility) ?? []) {
      violations.push(`${path}: ${utility}`);
    }
  }

  assert.deepEqual(
    violations,
    [],
    "Daily structure, actions, categories, and documented warning/success/danger states must use semantic hub-* utilities instead of raw palette families",
  );
});

test("Station Detail quick actions share ordinary hub-blue treatment", () => {
  const source = read("resources/js/daily-checkout/src/components/StationDetailPage.tsx");
  const labels = [
    "Personnel Equipment Request",
    "Apparatus Service",
    "Station Inspection",
    "Vehicle Inspection",
  ];

  for (const label of labels) {
    const labelIndex = source.indexOf(label);
    assert.notEqual(labelIndex, -1, `Station Detail must retain the ${label} quick action`);
    const openingIndex = Math.max(
      source.lastIndexOf("<a", labelIndex),
      source.lastIndexOf("<Link", labelIndex),
    );
    const openingTag = source.slice(openingIndex, source.indexOf(">", openingIndex) + 1);
    const classes = openingTag.match(/className="(?<classes>[^"]+)"/)?.groups?.classes;
    assert.ok(classes, `${label} must have a static className contract`);

    for (const utility of ["bg-hub-blue/10", "ring-hub-blue/30", "hover:bg-hub-blue/20", "text-hub-blue"]) {
      assert.match(classes, new RegExp(`(?:^|\\s)${utility.replace("/", "\\/")}(?:\\s|$)`), `${label} must use ${utility}`);
    }
    assert.doesNotMatch(
      classes,
      /(?:^|\s)(?:[^\s:]+:)*(?:bg|text|border|ring|outline)-(?:hub-(?:warning|success|danger|red)|red(?:-|\/))[^\s]*/,
      `${label} is ordinary navigation and must not use warning, success, danger, or red decoration`,
    );
  }
});

test("all four Filament panels use the centralized MBFD semantic palette", () => {
  const providers = [
    "app/Providers/Filament/AdminPanelProvider.php",
    "app/Providers/Filament/EmployeePanelProvider.php",
    "app/Providers/Filament/TrainingPanelProvider.php",
    "app/Providers/Filament/WorkgroupPanelProvider.php",
  ];

  const palette = read("app/Filament/Support/HubPalette.php");
  for (const [role, hex] of Object.entries({ primary: "#1E4E8C", danger: "#B91C1C", info: "#0369A1", success: "#047857", warning: "#B45309" })) {
    assert.match(palette, new RegExp(`["']${role}["']\\s*=>\\s*self::semantic\\(["']${hex}["']\\)`), `${role} must use its prescribed MBFD semantic color`);
  }
  for (const path of providers) {
    assert.match(read(path), /->colors\(HubPalette::colors\(\)\)/, `${path} must use the shared HubPalette configuration`);
  }
});

test("live Filament command-center and workgroup structural colors resolve through semantic tokens", () => {
  const stylesheet = read("resources/css/filament/admin/theme.css");
  const deadSelectors = [
    ".mbfd-station-overview-",
    ".wg-session-pill--overall",
    ".wg-overall-banner",
  ];
  const legacyWarmStone = /#(?:fafaf8|f8f6f2|f5f3f0|e8e5e0|d4d0ca|a8a29e|78716c|57534e|44403c|292524|1c1917)\b/ig;
  const violations = [];

  for (const match of stylesheet.matchAll(/(?<selectors>[^{}]+)\{(?<body>[^{}]*)\}/g)) {
    const selectors = match.groups.selectors.trim();
    const body = match.groups.body;
    const isLiveTarget = /\.(?:command-center-|mbfd-command|wg-)/.test(selectors)
      && !deadSelectors.some((selector) => selectors.includes(selector));

    if (!isLiveTarget) continue;

    for (const literal of body.match(legacyWarmStone) ?? []) {
      violations.push(`${selectors}: ${literal}`);
    }
  }

  assert.deepEqual(
    violations,
    [],
    "live command-center/workgroup structural colors must use var(--hub-*) semantic tokens instead of legacy warm-stone literals",
  );

  for (const match of stylesheet.matchAll(/(?<name>--command-[a-z-]+):\s*(?<value>[^;]+);/gi)) {
    assert.match(
      match.groups.value,
      /var\(--hub-[a-z-]+\)/i,
      `${match.groups.name} must resolve through a shared Hub semantic token`,
    );
  }
});

test("Home controls and presentation load from the Hub build without third-party CDNs", () => {
  const home = read("resources/views/welcome.blade.php");
  const appCss = read("resources/css/app.css");

  for (const host of ["fonts.googleapis.com", "fonts.gstatic.com", "cdn.jsdelivr.net"]) {
    assert.equal(home.includes(host), false, `Home must not load ${host}`);
  }
  assert.doesNotMatch(appCss, /https?:\/\//i, "Home CSS must not reference external URLs");
  assert.match(appCss, /@import ['"]@fontsource-variable\/plus-jakarta-sans\/wght\.css['"]/);
  assert.match(home, /@vite\('resources\/css\/app\.css'\)/);
  assert.match(read("resources/views/components/hub/shell.blade.php"), /@vite\('resources\/js\/hub-shell\.js'\)/);
  assert.match(home, /@include\('components\.hub-support-widget'\)/);
});

test("Home retains Department Updates and Quick Access without an incident integration", () => {
  const home = read("resources/views/welcome.blade.php");

  assert.match(home, /data-home-section="department-updates"/);
  assert.match(home, /data-home-section="quick-access"/);
  assert.doesNotMatch(home, /PulsePoint|MBFD Incidents|\/api\/incidents|data-home-column="incidents"|home-feed__/i);
});

test("Admin browser chrome and installed PWA use Hub header and canvas colors", () => {
  const head = read("resources/views/filament/admin/partials/head-pwa.blade.php");
  const manifest = JSON.parse(read("public/admin-pwa/manifest.webmanifest"));

  assert.match(head, /<meta name="theme-color" content="#102A43">/);
  assert.doesNotMatch(head, /#FAFAF8/i);
  assert.equal(manifest.theme_color, "#102A43");
  assert.equal(manifest.background_color, "#FFFFFF");
});

test("Workgroup views keep links and buttons structurally balanced without nested controls", () => {
  const pages = [
    "admin-dashboard.blade.php",
    "links.blade.php",
    "session-results.blade.php",
    "saver-report.blade.php",
    "saver-report-pdf.blade.php",
    "partials/granular-tool-table.blade.php",
  ];

  for (const page of pages) {
    const source = read(`resources/views/filament/workgroup/pages/${page}`);
    const stack = [];
    for (const [tag] of source.matchAll(/<\/?(?:a|button)\b[^>]*>/gi)) {
      const kind = /^<\//.test(tag) ? "close" : "open";
      const name = /^<\/?(a|button)\b/i.exec(tag)[1].toLowerCase();
      if (kind === "open") {
        assert.equal(stack.length, 0, `${page}: nested interactive control ${tag}`);
        stack.push(name);
      } else {
        assert.equal(stack.pop(), name, `${page}: mismatched closing tag ${tag}`);
      }
    }
    assert.deepEqual(stack, [], `${page}: unclosed interactive control`);
  }
});

test("Workgroup export links use the shared tokens and keyboard focus treatment", () => {
  const source = read("resources/views/filament/workgroup/pages/session-results.blade.php");
  const theme = read("resources/css/filament/admin/theme.css");
  const exports = [...source.matchAll(/<a href="(\/workgroup-export\/[^"]+)" class="wg-export-link" title="Export CSV">/g)];

  assert.equal(exports.length, 9);
  assert.match(theme, /\.wg-export-link\s*\{[^}]*min-height:\s*44px;[^}]*color:\s*rgb\(var\(--hub-action-primary\)\)/);
  assert.match(theme, /\.wg-session-results \.wg-export-link:focus-visible,/);
  assert.ok(/\.wg-session-results \.wg-session-pill:focus-visible\s*\{[^}]*outline:\s*2px solid rgb\(var\(--hub-focus\)\)/.test(theme), "Workgroup controls need a visible token-based keyboard outline");
  assert.doesNotMatch(source, /<div class="wg-section-header-icon" style="background:\s*linear-gradient/);
  assert.doesNotMatch(source, /<a href="\/workgroup-export\/[^>]*text-neutral-/);
});
