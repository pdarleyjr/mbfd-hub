import assert from "node:assert/strict";
import test from "node:test";
import { decide, parseNames, suitesApply } from "../../scripts/ci/ui-browser-scope.mjs";

test("a diff made only of documentation and separate apps skips the UI browser suites", () => {
  assert.equal(suitesApply(["docs/ui/responsive-tables.md", "README.md", "vacation-app/apps/web/page.tsx"]), false);
});

test("any rendered Hub path, test, workflow or dependency file runs them", () => {
  for (const file of ["resources/css/hub-tables.css", "app/Filament/Admin/Pages/X.php", "routes/web.php", "package.json", "composer.lock", "tests/e2e/responsive-tables.spec.ts", ".github/workflows/hub-release-gates.yml", "database/migrations/2026_01_01_000000_x.php"]) {
    assert.equal(suitesApply(["docs/a.md", file]), true, file);
  }
});

test("an empty diff fails open", () => {
  assert.equal(suitesApply([]), true);
});

test("git trouble or a missing base fails open", () => {
  assert.equal(decide({ base: "", head: "abc" }).relevant, true);
  assert.equal(decide({ base: "a", head: "b", runner: () => ({ status: 128, stdout: Buffer.alloc(0) }) }).relevant, true);
  assert.equal(decide({ base: "a", head: "b", runner: () => ({ error: new Error("spawn"), status: null }) }).relevant, true);
});

test("a docs-only diff reported by git skips, a mixed one runs", () => {
  const docs = () => ({ status: 0, stdout: Buffer.from("docs/a.md\0README.md\0") });
  const mixed = () => ({ status: 0, stdout: Buffer.from("docs/a.md\0resources/js/hub-tables.js\0") });
  assert.equal(decide({ base: "a", head: "b", runner: docs }).relevant, false);
  assert.equal(decide({ base: "a", head: "b", runner: mixed }).relevant, true);
  assert.deepEqual(parseNames("a\0b\0"), ["a", "b"]);
});
