import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const css = readFileSync(resolve(root, "resources/js/video-conferencing/video-conferencing.css"), "utf8");

test("conference role radios stay circular without shrinking their touch rows", () => {
  assert.match(css, /\.vc-shell input:not\(\[type="radio"\]\):not\(\[type="checkbox"\]\)\s*\{\s*min-height:\s*48px;/s);
  assert.match(css, /\.vc-role-list label\s*\{[^}]*min-height:\s*52px;/s);
  assert.match(css, /\.vc-role-list input\[type="radio"\]\s*\{[^}]*border-radius:\s*50%;[^}]*height:\s*20px;[^}]*min-height:\s*20px;[^}]*width:\s*20px;/s);
  assert.match(css, /\.vc-role-list label:has\(input:focus-visible\)/);
});
