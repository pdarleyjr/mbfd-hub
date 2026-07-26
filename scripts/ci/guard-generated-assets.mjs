// Guard against accidentally committing generated frontend assets.
//
// Exits non-zero when:
//   - any Vite/Filament/Daily generated output is tracked in git
//   - (after a build) the Vite manifest is missing
//   - (after a build) a manifest-referenced primary file is absent
//
// The tracking check runs anywhere (no build required). The manifest
// checks run only when a build has produced output (public/build exists).
import { execSync } from "node:child_process";
import { existsSync, readFileSync } from "node:fs";
import { resolve } from "node:path";

const root = execSync("git rev-parse --show-toplevel").toString().trim();

const trackedPaths = [
  "public/build",
  "public/css/filament",
  "public/js/filament",
  "public/daily",
];

console.log("== Generated-asset tracking guard ==");
const tracked = execSync(`git ls-files -- ${trackedPaths.map((p) => JSON.stringify(p)).join(" ")}`, {
  cwd: root,
})
  .toString()
  .trim()
  .split("\n")
  .filter(Boolean);

if (tracked.length > 0) {
  console.error("ERROR: generated asset(s) are still tracked in git (should be gitignored):");
  tracked.forEach((f) => console.error("  " + f));
  process.exit(1);
}
console.log("OK: no generated assets are tracked in git.");

const requireBuild = process.env.REQUIRE_GENERATED_ASSETS === "1";
const manifest = resolve(root, "public/build/manifest.json");
if (!existsSync(manifest) && !requireBuild) {
  console.log("SKIP: public/build/manifest.json not present (no build performed in this job).");
  process.exit(0);
}
if (!existsSync(manifest)) {
  console.error("ERROR: required Vite manifest is missing after the build.");
  process.exit(1);
}

console.log("== Vite manifest guard ==");
if (readFileSync(manifest, "utf8").trim().length === 0) {
  console.error("ERROR: Vite manifest exists but is empty.");
  process.exit(1);
}

const data = JSON.parse(readFileSync(manifest, "utf8"));
const refs = new Set();
const referencedEntries = new Set();
for (const entry of Object.values(data)) {
  if (entry.file) refs.add(entry.file);
  if (Array.isArray(entry.css)) entry.css.forEach((f) => refs.add(f));
  if (Array.isArray(entry.assets)) entry.assets.forEach((f) => refs.add(f));
  if (Array.isArray(entry.imports)) entry.imports.forEach((key) => referencedEntries.add(key));
  if (Array.isArray(entry.dynamicImports)) entry.dynamicImports.forEach((key) => referencedEntries.add(key));
}

let missing = 0;
for (const key of referencedEntries) {
  if (!Object.hasOwn(data, key)) {
    console.error("ERROR: manifest references missing entry: " + key);
    missing += 1;
  }
}
for (const f of refs) {
  const full = resolve(root, "public/build", f);
  if (!existsSync(full)) {
    console.error("ERROR: manifest references missing file: " + f);
    missing += 1;
  }
}
if (missing > 0) process.exit(1);
console.log("OK: Vite manifest present and all referenced primary files exist.");

if (requireBuild) {
  const requiredOutputs = [
    "public/css/filament/filament/app.css",
    "public/js/filament/filament/app.js",
    "public/daily/index.html",
    "public/daily/manifest.webmanifest",
    "public/daily/sw.js",
  ];
  const missingOutputs = requiredOutputs.filter((file) => !existsSync(resolve(root, file)));
  if (missingOutputs.length > 0) {
    console.error("ERROR: deployment-generated asset(s) are missing:");
    missingOutputs.forEach((file) => console.error("  " + file));
    process.exit(1);
  }
  console.log("OK: Vite, Filament, and Daily deployment assets are reproducible.");
}
