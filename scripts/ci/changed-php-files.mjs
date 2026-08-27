import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { resolve } from "node:path";

const phpExtension = ".php";

function commandError(command, args, result) {
  if (result.error) {
    throw result.error;
  }

  if (result.status === 0) {
    return;
  }

  const stderr = Buffer.isBuffer(result.stderr)
    ? result.stderr.toString("utf8").trim()
    : String(result.stderr ?? "").trim();
  const detail = stderr === "" ? "" : `: ${stderr}`;

  throw new Error(`${command} ${args.join(" ")} exited with status ${result.status ?? "unknown"}${detail}`);
}

/**
 * @param {Buffer | string} output
 * @returns {string[]}
 */
export function parseChangedPhpFiles(output) {
  const fields = (Buffer.isBuffer(output) ? output.toString("utf8") : output).split("\0");
  const phpFiles = new Set();

  for (let index = 0; index < fields.length;) {
    const status = fields[index++];
    if (status === "") {
      continue;
    }

    const kind = status[0];
    if (kind === "C" || kind === "R") {
      index++;
      const destination = fields[index++];
      if (destination?.endsWith(phpExtension)) {
        phpFiles.add(destination);
      }

      continue;
    }

    const path = fields[index++];
    if ((kind === "A" || kind === "M") && path?.endsWith(phpExtension)) {
      phpFiles.add(path);
    }
  }

  return [...phpFiles].sort();
}

/**
 * @param {{ base: string, head: string, gitBinary?: string, runner?: typeof spawnSync }} options
 * @returns {string[]}
 */
export function collectChangedPhpFiles({
  base,
  head,
  gitBinary = "git",
  runner = spawnSync,
}) {
  if (base.trim() === "" || head.trim() === "") {
    throw new Error("PINT_BASE_SHA and PINT_HEAD_SHA must both identify the candidate diff.");
  }

  const args = [
    "diff",
    "--name-status",
    "-z",
    "--find-renames",
    "--diff-filter=ACMR",
    base,
    head,
  ];
  const result = runner(gitBinary, args, { encoding: "buffer" });
  commandError(gitBinary, args, result);

  return parseChangedPhpFiles(result.stdout ?? Buffer.alloc(0));
}

/**
 * @param {{ files: string[], phpBinary?: string, pintScript?: string, runner?: typeof spawnSync }} options
 */
export function runPintForChangedPhpFiles({
  files,
  phpBinary = "php",
  pintScript = "vendor/bin/pint",
  runner = spawnSync,
}) {
  if (files.length === 0) {
    return 0;
  }

  const args = [pintScript, "--test", "--", ...files];
  const result = runner(phpBinary, args, { stdio: "inherit" });
  commandError(phpBinary, args, result);

  return 0;
}

export function main({ env = process.env, runner = spawnSync, log = console.log } = {}) {
  const files = collectChangedPhpFiles({
    base: env.PINT_BASE_SHA ?? "",
    head: env.PINT_HEAD_SHA ?? "",
    gitBinary: env.GIT_BINARY ?? "git",
    runner,
  });

  if (files.length === 0) {
    log("No changed PHP files to check with Pint.");

    return 0;
  }

  log(`Checking ${files.length} changed PHP file${files.length === 1 ? "" : "s"} with Pint.`);

  return runPintForChangedPhpFiles({
    files,
    phpBinary: env.PINT_PHP_BINARY ?? "php",
    pintScript: env.PINT_SCRIPT ?? "vendor/bin/pint",
    runner,
  });
}

const invokedPath = process.argv[1] === undefined ? null : resolve(process.argv[1]);
if (invokedPath === fileURLToPath(import.meta.url)) {
  try {
    process.exitCode = main();
  } catch (error) {
    console.error(error instanceof Error ? error.message : error);
    process.exitCode = 1;
  }
}
