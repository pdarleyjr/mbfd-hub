#!/usr/bin/env node
// PROPOSAL (requires owner approval): lets the production-image and
// deployment callers of hub-release-gates.yml reuse a successful gate run for
// the exact same git tree instead of re-running the expensive test jobs.
//
// A proof is accepted only when ALL of the following hold, otherwise the
// gates run normally (the script never fails the job; it reports "false"):
// - the current run is a workflow_dispatch on refs/heads/main;
// - a candidate commit (the release SHA itself, or the head of the merged PR
//   whose merge commit is the release SHA) has the identical git tree;
// - that commit carries a completed, successful "All required Hub release
//   gates" check run created by the GitHub Actions app;
// - the check run belongs to a run of .github/workflows/ci.yml in this
//   repository (not a fork), triggered by pull_request or push, for exactly
//   that commit, whose latest attempt concluded success;
// - every job of that run (except the advisory Pint debt report) concluded
//   success on a GitHub-hosted runner (so the proof run itself reused nothing);
// - for pull_request proofs, the PR head contains the release commit's first
//   parent, so the tested merge result equals the PR head tree;
// - the proof finished within MAX_PROOF_AGE_HOURS.
import { appendFileSync } from "node:fs";
import { resolve } from "node:path";
import { fileURLToPath } from "node:url";

export const PRODUCTION_REF = "refs/heads/main";
export const PRODUCTION_EVENT = "workflow_dispatch";
export const AGGREGATE_CHECK_NAME = "release-gates / All required Hub release gates";
export const GITHUB_ACTIONS_APP_ID = 15368;
export const PROOF_WORKFLOW_PATH = ".github/workflows/ci.yml";
export const PROOF_EVENTS = Object.freeze(["pull_request", "push"]);
export const HOSTED_RUNNER_GROUP = "GitHub Actions";
export const ADVISORY_JOB_NAMES = Object.freeze(["release-gates / Repository-wide Pint debt (advisory)"]);
export const MIN_PROOF_JOBS = 10;
export const MAX_PROOF_AGE_HOURS = 72;
const SHA_PATTERN = /^[0-9a-f]{40}$/;

const rejected = (reason) => ({ verified: false, reason });

async function candidateCommits(api, repo, sha, commit) {
  const candidates = [sha];
  const pulls = await api(`/repos/${repo}/commits/${sha}/pulls`);
  for (const pull of Array.isArray(pulls) ? pulls : []) {
    if (
      pull?.merged_at &&
      pull.merge_commit_sha === sha &&
      pull.base?.ref === "main" &&
      pull.base?.repo?.full_name === repo &&
      pull.head?.repo?.full_name === repo &&
      SHA_PATTERN.test(pull.head?.sha ?? "")
    ) {
      candidates.push(pull.head.sha);
    }
  }
  const secondParent = commit.parents?.[1]?.sha;
  if (SHA_PATTERN.test(secondParent ?? "")) candidates.push(secondParent);

  return [...new Set(candidates)];
}

async function verifyRun(api, context, candidate, checkRun) {
  const { repo, sha, firstParent, nowMs, maxAgeMs } = context;
  if (checkRun?.app?.id !== GITHUB_ACTIONS_APP_ID) return "check run was not created by GitHub Actions";
  if (checkRun.name !== AGGREGATE_CHECK_NAME) return "check run name does not match the aggregate gate";
  if (checkRun.status !== "completed" || checkRun.conclusion !== "success") return "aggregate gate did not succeed";
  if (checkRun.head_sha !== candidate) return "check run is not for the candidate commit";
  const completedMs = Date.parse(checkRun.completed_at ?? "");
  if (!Number.isFinite(completedMs) || nowMs - completedMs > maxAgeMs || completedMs > nowMs) {
    return "aggregate gate result is too old";
  }

  // For GitHub Actions the check run id is the workflow job id.
  const job = await api(`/repos/${repo}/actions/jobs/${checkRun.id}`);
  if (!job?.run_id || job.head_sha !== candidate) return "check run does not map to a workflow job";
  const run = await api(`/repos/${repo}/actions/runs/${job.run_id}`);
  if (run?.path !== PROOF_WORKFLOW_PATH) return "proof did not come from the CI workflow";
  if (run.repository?.full_name !== repo || run.head_repository?.full_name !== repo) {
    return "proof run did not execute in this repository";
  }
  if (!PROOF_EVENTS.includes(run.event)) return "proof run event is not pull_request or push";
  if (run.head_sha !== candidate) return "proof run is not for the candidate commit";
  if (run.status !== "completed" || run.conclusion !== "success") return "proof run did not succeed";
  if (run.event === "push" && candidate !== sha) return "push proof must be for the release commit itself";

  const jobs = await api(`/repos/${repo}/actions/runs/${job.run_id}/jobs?filter=latest&per_page=100`);
  const runJobs = Array.isArray(jobs?.jobs) ? jobs.jobs : [];
  if (runJobs.length < MIN_PROOF_JOBS || runJobs.length !== jobs.total_count) return "proof run job list is incomplete";
  if (!runJobs.some((runJob) => runJob.name === AGGREGATE_CHECK_NAME && runJob.conclusion === "success")) {
    return "proof run aggregate gate job is missing";
  }
  for (const runJob of runJobs) {
    if (runJob.runner_group_name !== HOSTED_RUNNER_GROUP) return `job ${runJob.name} did not run on a GitHub-hosted runner`;
    if (runJob.conclusion !== "success" && !ADVISORY_JOB_NAMES.includes(runJob.name)) {
      return `job ${runJob.name} concluded ${runJob.conclusion}`;
    }
  }

  if (run.event === "pull_request") {
    if (!SHA_PATTERN.test(firstParent ?? "")) return "release commit has no first parent";
    const comparison = await api(`/repos/${repo}/compare/${firstParent}...${candidate}`);
    if (!["ahead", "identical"].includes(comparison?.status)) {
      return "PR head did not contain the release base, so its tested merge tree may differ";
    }
  }

  return null;
}

/**
 * Decide whether a prior successful gate run proves the release candidate.
 * `api(path)` must resolve to parsed GitHub REST JSON for a GET request.
 */
export async function evaluateProof({ repo, sha, eventName, ref, nowMs, maxAgeHours = MAX_PROOF_AGE_HOURS }, api) {
  if (eventName !== PRODUCTION_EVENT || ref !== PRODUCTION_REF) {
    return rejected("proof reuse is limited to production candidate dispatches from main");
  }
  if (!/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(repo ?? "") || !SHA_PATTERN.test(sha ?? "")) {
    return rejected("invalid repository or release SHA");
  }

  const commit = await api(`/repos/${repo}/git/commits/${sha}`);
  const tree = commit?.tree?.sha;
  if (!SHA_PATTERN.test(tree ?? "")) return rejected("could not resolve the release tree");

  const context = {
    repo,
    sha,
    firstParent: commit.parents?.[0]?.sha,
    nowMs,
    maxAgeMs: maxAgeHours * 60 * 60 * 1000,
  };
  const reasons = [];
  for (const candidate of await candidateCommits(api, repo, sha, commit)) {
    const candidateCommit = candidate === sha ? commit : await api(`/repos/${repo}/git/commits/${candidate}`);
    if (candidateCommit?.tree?.sha !== tree) {
      reasons.push(`${candidate}: tree differs`);
      continue;
    }
    const checks = await api(
      `/repos/${repo}/commits/${candidate}/check-runs?check_name=${encodeURIComponent(AGGREGATE_CHECK_NAME)}&filter=all&per_page=100`,
    );
    for (const checkRun of Array.isArray(checks?.check_runs) ? checks.check_runs : []) {
      const problem = await verifyRun(api, context, candidate, checkRun);
      if (problem === null) {
        return {
          verified: true,
          reason: `tree ${tree} passed all gates in run for ${candidate}`,
          proof: { candidate, tree, checkRunId: checkRun.id },
        };
      }
      reasons.push(`${candidate}/${checkRun?.id}: ${problem}`);
    }
  }

  return rejected(reasons.length > 0 ? reasons.join("; ") : "no successful gate run exists for this tree");
}

function githubApi(baseUrl, token) {
  return async (path) => {
    const response = await fetch(`${baseUrl}${path}`, {
      headers: {
        Accept: "application/vnd.github+json",
        Authorization: `Bearer ${token}`,
        "X-GitHub-Api-Version": "2022-11-28",
      },
    });
    if (!response.ok) throw new Error(`GitHub API ${path} returned ${response.status}`);
    return response.json();
  };
}

async function main() {
  const env = process.env;
  let result;
  try {
    if (!env.GITHUB_TOKEN) throw new Error("GITHUB_TOKEN is required");
    result = await evaluateProof(
      {
        repo: env.GITHUB_REPOSITORY,
        sha: env.GITHUB_SHA,
        eventName: env.GITHUB_EVENT_NAME,
        ref: env.GITHUB_REF,
        nowMs: Date.now(),
      },
      githubApi(env.GITHUB_API_URL ?? "https://api.github.com", env.GITHUB_TOKEN),
    );
  } catch (error) {
    // Any lookup failure falls back to running every gate.
    result = rejected(`proof lookup failed: ${error instanceof Error ? error.message : String(error)}`);
  }

  const verified = result.verified === true ? "true" : "false";
  const reason = summaryText(result.reason);
  console.log(`release-gate proof verified=${verified}: ${reason}`);
  if (env.GITHUB_OUTPUT) appendFileSync(env.GITHUB_OUTPUT, `verified=${verified}\n`);
  if (env.GITHUB_STEP_SUMMARY) {
    appendFileSync(env.GITHUB_STEP_SUMMARY, `Release gate proof reuse: **${verified}**\n\n${reason}\n`);
  }
}

/** The reason can quote API data; keep only plain single-line text before it reaches logs or files. */
export function summaryText(value) {
  return String(value ?? "")
    .replace(/[^A-Za-z0-9 .,:;()#=/_-]/g, " ")
    .replace(/\s+/g, " ")
    .trim()
    .slice(0, 400);
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  await main();
}
