import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const resource = readFileSync(
  resolve(root, "app/Http/Resources/ApparatusResource.php"),
  "utf8",
);

test("ApparatusResource does not shadow Laravel 12 JsonResource::whenAppended", () => {
  assert.doesNotMatch(resource, /function\s+whenAppended\s*\(/);
  assert.match(resource, /function\s+whenPmHealthAppended\s*\(/);
});
