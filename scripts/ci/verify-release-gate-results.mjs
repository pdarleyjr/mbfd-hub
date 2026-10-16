#!/usr/bin/env node
// Decides the aggregate "All required Hub release gates" result from the
// reusable workflow's `needs` context. Without a verified proof every gate
// must succeed. With a verified proof (scripts/ci/release-gate-proof.mjs) a
// gate may be skipped only if it is explicitly listed as reusable.
import { resolve } from "node:path";
import { fileURLToPath } from "node:url";

export const PROOF_JOB = "reuse-proof";

/** Returns a list of violations; an empty list means the release gate passes. */
export function gateViolations(needs, proofVerified, reusableGates) {
  const violations = [];
  const entries = Object.entries(needs ?? {});
  const verified = proofVerified === "true";
  const reusable = new Set(reusableGates);

  if (!entries.some(([name]) => name !== PROOF_JOB)) {
    violations.push("no release gates were reported");
  }
  for (const [name, need] of entries) {
    const result = need?.result;
    if (name === PROOF_JOB) {
      if (verified && result !== "success") violations.push(`${name}: proof reported verified but concluded ${result}`);
      continue;
    }
    if (result === "success") continue;
    if (verified && result === "skipped" && reusable.has(name)) continue;
    violations.push(`${name}: ${result}`);
  }
  if (verified && !(PROOF_JOB in (needs ?? {}))) {
    violations.push("verified proof reported without the reuse-proof job");
  }

  return violations;
}

function main() {
  const needs = JSON.parse(process.env.GATE_RESULTS ?? "{}");
  const reusable = JSON.parse(process.env.REUSABLE_GATES ?? "[]");
  if (!Array.isArray(reusable) || reusable.some((name) => typeof name !== "string")) {
    throw new Error("REUSABLE_GATES must be a JSON array of job ids");
  }
  const proofVerified = process.env.PROOF_VERIFIED === "true" ? "true" : "false";

  console.log(`proof reuse verified: ${proofVerified}`);
  for (const [name, need] of Object.entries(needs)) console.log(`${name}: ${need?.result}`);

  const violations = gateViolations(needs, proofVerified, reusable);
  if (violations.length > 0) {
    console.error("One or more required Hub release gates did not succeed:");
    for (const violation of violations) console.error(`  ${violation}`);
    process.exit(1);
  }
  console.log("All required Hub release gates passed.");
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main();
}
