import { appendFileSync } from "node:fs";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { resolve } from "node:path";

/**
 * Decides whether the responsive tables and Home duty browser suites apply to a candidate.
 *
 * They exercise the rendered Hub (Blade, Filament, Livewire, CSS and the JavaScript that adapts tables), so a
 * candidate that only touches documentation, the separate vacation app, Cloudflare workers or operations notes
 * cannot change what they measure. The decision fails open: any doubt (no base revision, git failure, an empty
 * diff) means the suites run. Only a diff made entirely of paths in IGNORED skips them.
 */
export const IGNORED = [
  /^docs\//,
  /^vacation-app\//,
  /^cloudflare-(?:worker|email-worker)\//,
  /^scripts\/operations\//,
  /^\.ai_plans\//,
  /^plans\//,
  /^[^/]+\.md$/,
  /^\.github\/(?!workflows\/)/,
];

/** @param {string[]} files */
export function suitesApply(files) {
  const changed = files.filter((file) => file !== "");
  if (changed.length === 0) return true;
  return changed.some((file) => !IGNORED.some((pattern) => pattern.test(file)));
}

/** @param {Buffer | string} output NUL separated `git diff --name-only -z` output */
export function parseNames(output) {
  return (Buffer.isBuffer(output) ? output.toString("utf8") : output).split("\0").filter(Boolean);
}

export function decide({ base, head, runner = spawnSync, gitBinary = "git" } = {}) {
  if (!base || !head) return { relevant: true, reason: "no base revision: running the suites" };
  const result = runner(gitBinary, ["diff", "--name-only", "-z", base, head], { encoding: "buffer" });
  if (result.error || result.status !== 0) return { relevant: true, reason: "git diff failed: running the suites" };
  const files = parseNames(result.stdout ?? Buffer.alloc(0));
  const relevant = suitesApply(files);
  return { relevant, reason: relevant ? `${files.length} changed path(s) can affect the rendered Hub` : `${files.length} changed path(s), none of them in the rendered Hub` };
}

export function main(env = process.env, log = console.log) {
  const { relevant, reason } = decide({ base: env.GATE_BASE_SHA ?? "", head: env.GATE_HEAD_SHA ?? "" });
  log(`UI browser suites: ${relevant ? "run" : "skipped"} (${reason})`);
  if (env.GITHUB_OUTPUT) appendFileSync(env.GITHUB_OUTPUT, `relevant=${relevant}\n`);
  return relevant;
}

const invoked = process.argv[1] === undefined ? null : resolve(process.argv[1]);
if (invoked === fileURLToPath(import.meta.url)) main();
