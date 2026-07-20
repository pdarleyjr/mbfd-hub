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

const manifest = resolve(root, "public/build/manifest.json");
if (!existsSync(manifest)) {
  console.log("SKIP: public/build/manifest.json not present (no build performed in this job).");
  process.exit(0);
}

console.log("== Vite manifest guard ==");
if (readFileSync(manifest, "utf8").trim().length === 0) {
  console.error("ERROR: Vite manifest exists but is empty.");
  process.exit(1);
}

const data = JSON.parse(readFileSync(manifest, "utf8"));
const refs = new Set();
for (const entry of Object.values(data)) {
  if (entry.file) refs.add(entry.file);
  if (Array.isArray(entry.css)) entry.css.forEach((f) => refs.add(f));
  if (Array.isArray(entry.js)) entry.js.forEach((f) => refs.add(f));
  if (Array.isArray(entry.imports)) entry.imports.forEach((f) => refs.add(f));
}

let missing = 0;
for (const f of refs) {
  const full = resolve(root, "public/build", f);
  if (!existsSync(full)) {
    console.error("ERROR: manifest references missing file: " + f);
    missing += 1;
  }
}
if (missing > 0) process.exit(1);
console.log("OK: Vite manifest present and all referenced primary files exist.");
