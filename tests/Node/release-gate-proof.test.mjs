import assert from "node:assert/strict";
import test from "node:test";
import {
  AGGREGATE_CHECK_NAME,
  GITHUB_ACTIONS_APP_ID,
  evaluateProof,
} from "../../scripts/ci/release-gate-proof.mjs";

const repo = "pdarleyjr/mbfd-hub";
const releaseSha = "a".repeat(40);
const baseSha = "b".repeat(40);
const headSha = "c".repeat(40);
const tree = "d".repeat(40);
const nowMs = Date.parse("2026-10-15T20:00:00Z");
const dispatch = { repo, sha: releaseSha, eventName: "workflow_dispatch", ref: "refs/heads/main", nowMs };

const gateJobNames = [
  "CI configuration guard",
  "PHP and root frontend quality",
  "PHPStan static analysis",
  "GitHub Actions syntax and policy",
  "PHPUnit and PostgreSQL concurrency/integrity",
  "Daily Checkout contract and integrity",
  "Daily Checkout authenticated shell and Bid browsers",
  "Daily Checkout member request and Operational Forms lifecycles",
  "Hub dependency security thresholds",
  "Hub secret scan",
  "Hub filesystem and configuration security thresholds",
  "Release gate proof reuse",
].map((name) => `release-gates / ${name}`);

function fixture(overrides = {}) {
  const jobs = [
    ...gateJobNames.map((name, index) => ({ id: 100 + index, name, conclusion: "success", runner_group_name: "GitHub Actions" })),
    { id: 99, name: "release-gates / Repository-wide Pint debt (advisory)", conclusion: "failure", runner_group_name: "GitHub Actions" },
    { id: 500, name: AGGREGATE_CHECK_NAME, conclusion: "success", runner_group_name: "GitHub Actions" },
  ];
  const routes = {
    [`/repos/${repo}/git/commits/${releaseSha}`]: { tree: { sha: tree }, parents: [{ sha: baseSha }, { sha: headSha }] },
    [`/repos/${repo}/git/commits/${headSha}`]: { tree: { sha: tree }, parents: [{ sha: baseSha }] },
    [`/repos/${repo}/commits/${releaseSha}/pulls`]: [
      {
        merged_at: "2026-10-15T19:00:00Z",
        merge_commit_sha: releaseSha,
        base: { ref: "main", repo: { full_name: repo } },
        head: { sha: headSha, repo: { full_name: repo } },
      },
    ],
    [`/repos/${repo}/commits/${releaseSha}/check-runs`]: { check_runs: [] },
    [`/repos/${repo}/commits/${headSha}/check-runs`]: {
      check_runs: [
        {
          id: 500,
          name: AGGREGATE_CHECK_NAME,
          head_sha: headSha,
          status: "completed",
          conclusion: "success",
          completed_at: "2026-10-15T18:00:00Z",
          app: { id: GITHUB_ACTIONS_APP_ID },
        },
      ],
    },
    [`/repos/${repo}/actions/jobs/500`]: { run_id: 42, head_sha: headSha },
    [`/repos/${repo}/actions/runs/42`]: {
      path: ".github/workflows/ci.yml",
      event: "pull_request",
      head_sha: headSha,
      status: "completed",
      conclusion: "success",
      repository: { full_name: repo },
      head_repository: { full_name: repo },
    },
    [`/repos/${repo}/actions/runs/42/jobs`]: { total_count: jobs.length, jobs },
    [`/repos/${repo}/compare/${baseSha}...${headSha}`]: { status: "ahead" },
  };
  for (const [path, value] of Object.entries(overrides)) {
    routes[path] = typeof value === "function" ? value(structuredClone(routes[path])) : value;
  }

  const calls = [];
  const api = async (path) => {
    calls.push(path);
    const key = path.split("?")[0];
    if (!(key in routes)) throw new Error(`unexpected GitHub API call ${path}`);
    return structuredClone(routes[key]);
  };

  return { api, calls };
}

const checkRuns = `/repos/${repo}/commits/${headSha}/check-runs`;
const run = `/repos/${repo}/actions/runs/42`;
const runJobs = `/repos/${repo}/actions/runs/42/jobs`;
const mutateCheck = (change) => (value) => ({ check_runs: value.check_runs.map((entry) => ({ ...entry, ...change })) });

test("a successful hosted CI run for the identical PR head tree proves the release", async () => {
  const { api, calls } = fixture();
  const result = await evaluateProof(dispatch, api);

  assert.equal(result.verified, true, result.reason);
  assert.equal(result.proof.candidate, headSha);
  assert.ok(calls.some((path) => path.includes(`check_name=${encodeURIComponent(AGGREGATE_CHECK_NAME)}`)));
});

test("proof reuse never applies outside a main workflow_dispatch", async () => {
  for (const context of [
    { ...dispatch, eventName: "pull_request" },
    { ...dispatch, eventName: "push" },
    { ...dispatch, ref: "refs/heads/feature" },
  ]) {
    const { api, calls } = fixture();
    const result = await evaluateProof(context, api);
    assert.equal(result.verified, false);
    assert.deepEqual(calls, [], "no API lookup is made for ineligible runs");
  }
});

const rejections = {
  "a different tree": { [`/repos/${repo}/git/commits/${headSha}`]: { tree: { sha: "e".repeat(40) } } },
  "a failed aggregate": { [checkRuns]: mutateCheck({ conclusion: "failure" }) },
  "a check from another app": { [checkRuns]: mutateCheck({ app: { id: 1 } }) },
  "a stale proof": { [checkRuns]: mutateCheck({ completed_at: "2026-10-10T18:00:00Z" }) },
  "a future-dated proof": { [checkRuns]: mutateCheck({ completed_at: "2026-10-16T18:00:00Z" }) },
  "another workflow": { [run]: (value) => ({ ...value, path: ".github/workflows/evil.yml" }) },
  "a fork head repository": { [run]: (value) => ({ ...value, head_repository: { full_name: "attacker/mbfd-hub" } }) },
  "a non-PR non-push event": { [run]: (value) => ({ ...value, event: "workflow_dispatch" }) },
  "an unsuccessful run": { [run]: (value) => ({ ...value, conclusion: "failure" }) },
  "a self-hosted job": {
    [runJobs]: (value) => ({ ...value, jobs: value.jobs.map((job, index) => (index === 0 ? { ...job, runner_group_name: "Default" } : job)) }),
  },
  "a skipped gate (chained proof)": {
    [runJobs]: (value) => ({ ...value, jobs: value.jobs.map((job, index) => (index === 1 ? { ...job, conclusion: "skipped" } : job)) }),
  },
  "a truncated job list": { [runJobs]: (value) => ({ ...value, total_count: value.total_count + 1 }) },
  "a PR head that did not contain the release base": { [`/repos/${repo}/compare/${baseSha}...${headSha}`]: { status: "diverged" } },
  "an unmerged PR and no merge parent": {
    [`/repos/${repo}/commits/${releaseSha}/pulls`]: [],
    [`/repos/${repo}/git/commits/${releaseSha}`]: { tree: { sha: tree }, parents: [{ sha: baseSha }] },
  },
};

for (const [label, overrides] of Object.entries(rejections)) {
  test(`proof is rejected for ${label}`, async () => {
    const { api } = fixture(overrides);
    const result = await evaluateProof(dispatch, api);
    assert.equal(result.verified, false, `expected rejection for ${label}`);
    assert.ok(result.reason.length > 0);
  });
}

test("a push proof must be for the release commit itself", async () => {
  const { api } = fixture({ [run]: (value) => ({ ...value, event: "push" }) });
  assert.equal((await evaluateProof(dispatch, api)).verified, false);
});

test("a successful push run for the release commit itself is accepted", async () => {
  const { api } = fixture({
    [`/repos/${repo}/commits/${releaseSha}/pulls`]: [],
    [`/repos/${repo}/git/commits/${releaseSha}`]: { tree: { sha: tree }, parents: [{ sha: baseSha }] },
    [`/repos/${repo}/commits/${releaseSha}/check-runs`]: {
      check_runs: [
        {
          id: 500,
          name: AGGREGATE_CHECK_NAME,
          head_sha: releaseSha,
          status: "completed",
          conclusion: "success",
          completed_at: "2026-10-15T18:00:00Z",
          app: { id: GITHUB_ACTIONS_APP_ID },
        },
      ],
    },
    [`/repos/${repo}/actions/jobs/500`]: { run_id: 42, head_sha: releaseSha },
    [run]: (value) => ({ ...value, event: "push", head_sha: releaseSha }),
  });
  const result = await evaluateProof(dispatch, api);
  assert.equal(result.verified, true, result.reason);
  assert.equal(result.proof.candidate, releaseSha);
});

test("API failures propagate so the CLI falls back to running every gate", async () => {
  await assert.rejects(
    evaluateProof(dispatch, async () => {
      throw new Error("rate limited");
    }),
    /rate limited/,
  );
});
