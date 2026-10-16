import assert from "node:assert/strict";
import test from "node:test";
import { summaryText } from "../../scripts/ci/release-gate-proof.mjs";

test("proof reasons reach the step summary as plain single-line text", () => {
  assert.equal(summaryText("proof from run 123: tree abc/def matched"), "proof from run 123: tree abc/def matched");
  assert.equal(summaryText("bad\n## heading <img src=x onerror=alert(1)> `code` *x*"), "bad ## heading img src=x onerror=alert(1) code x");
  assert.equal(summaryText(undefined), "");
  assert.equal(summaryText("a".repeat(1000)).length, 400);
});
