import assert from "node:assert/strict";
import test from "node:test";
import { gateViolations } from "../../scripts/ci/verify-release-gate-results.mjs";

const reusable = ["php-quality", "daily-checkout-browser"];
const needs = (overrides = {}) => ({
  "reuse-proof": { result: "success", outputs: { verified: "false" } },
  "ci-configuration": { result: "success" },
  "security-secrets": { result: "success" },
  "php-quality": { result: "success" },
  "daily-checkout-browser": { result: "success" },
  ...overrides,
});

test("every gate succeeding passes with or without proof", () => {
  assert.deepEqual(gateViolations(needs(), "false", reusable), []);
  assert.deepEqual(gateViolations(needs(), "true", reusable), []);
});

test("without verified proof any non-success gate fails the aggregate", () => {
  for (const result of ["failure", "skipped", "cancelled", undefined]) {
    assert.notDeepEqual(gateViolations(needs({ "php-quality": { result } }), "false", reusable), []);
  }
});

test("verified proof allows only listed reusable gates to be skipped", () => {
  const skipped = needs({ "php-quality": { result: "skipped" }, "daily-checkout-browser": { result: "skipped" } });
  assert.deepEqual(gateViolations(skipped, "true", reusable), []);
  assert.notDeepEqual(gateViolations(skipped, "false", reusable), []);
  assert.notDeepEqual(gateViolations(needs({ "security-secrets": { result: "skipped" } }), "true", reusable), []);
  assert.notDeepEqual(gateViolations(needs({ "php-quality": { result: "failure" } }), "true", reusable), []);
});

test("a failed proof lookup falls back to requiring every gate", () => {
  assert.deepEqual(gateViolations(needs({ "reuse-proof": { result: "failure" } }), "false", reusable), []);
  assert.notDeepEqual(
    gateViolations(needs({ "reuse-proof": { result: "failure" }, "php-quality": { result: "skipped" } }), "false", reusable),
    [],
  );
  assert.notDeepEqual(gateViolations(needs({ "reuse-proof": { result: "failure" } }), "true", reusable), []);
});

test("an empty gate set never passes", () => {
  assert.notDeepEqual(gateViolations({}, "false", reusable), []);
  assert.notDeepEqual(gateViolations({ "reuse-proof": { result: "success" } }, "true", reusable), []);
});
