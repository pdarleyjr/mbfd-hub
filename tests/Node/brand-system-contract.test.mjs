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

test("Daily exposes shared semantic tokens while preserving its frozen neutral and global sans definitions", () => {
  const stylesheet = read("resources/js/daily-checkout/src/index.css");
  const tailwind = read("resources/js/daily-checkout/tailwind.config.js");

  assert.match(
    stylesheet,
    /@import\s+["']\.\.\/\.\.\/\.\.\/css\/mbfd-theme\.css["'];/,
    "Daily must import the shared Hub token source",
  );
  assert.equal(
    objectProperty(tailwind, "neutral"),
    `neutral: {
                    50:  '#FAFAF8',
                    100: '#F5F3F0',
                    200: '#E8E5E0',
                    300: '#D4D0CA',
                    400: '#A8A29E',
                    500: '#78716C',
                    600: '#57534E',
                    700: '#44403C',
                    800: '#292524',
                    900: '#1C1917',
                }`,
    "Daily's frozen neutral block must remain byte-identical",
  );
  assert.match(
    tailwind,
    /sans:\s*\[\s*['"]"Source Sans 3"['"],\s*['"]"DM Sans"['"],\s*['"]system-ui['"],\s*['"]sans-serif['"]\s*\]/,
    "Daily's existing global Source Sans stack must remain unchanged",
  );
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

test("all four Filament panels use blue primary and a distinct red or rose danger palette", () => {
  const providers = [
    "app/Providers/Filament/AdminPanelProvider.php",
    "app/Providers/Filament/EmployeePanelProvider.php",
    "app/Providers/Filament/TrainingPanelProvider.php",
    "app/Providers/Filament/WorkgroupPanelProvider.php",
  ];

  for (const path of providers) {
    const source = read(path);
    const primary = source.match(/["']primary["']\s*=>\s*Color::([A-Za-z]+)/)?.[1];
    const danger = source.match(/["']danger["']\s*=>\s*Color::([A-Za-z]+)/)?.[1];

    assert.equal(primary, "Blue", `${path} must use Color::Blue for primary actions`);
    assert.ok(["Red", "Rose"].includes(danger), `${path} must use a red or rose danger palette`);
    assert.notEqual(primary, danger, `${path} primary and danger palettes must remain distinct`);
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
