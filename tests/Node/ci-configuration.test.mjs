import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..");

test("every Dependabot package directory contains its ecosystem manifest", () => {
  const config = readFileSync(resolve(root, ".github/dependabot.yml"), "utf8");
  const updateBlocks = config.split(/\n(?=\s{2}- package-ecosystem:)/);

  const missing = [];
  for (const block of updateBlocks) {
    const ecosystem = block.match(/package-ecosystem:\s*["']?([^"'\s]+)["']?/)?.[1];
    const directory = block.match(/directory:\s*["']?([^"'\s]+)["']?/)?.[1];

    if (!ecosystem || !directory || ecosystem === "github-actions") continue;

    const manifest = ecosystem === "composer" ? "composer.json" : "package.json";
    const manifestPath = resolve(root, directory.replace(/^\/+/, ""), manifest);
    if (!existsSync(manifestPath)) {
      missing.push(`${ecosystem}:${directory}/${manifest}`);
    }
  }

  assert.deepEqual(missing, []);
});

test("PHPStan is a hard gate backed by a reviewed baseline", () => {
  const workflow = readFileSync(resolve(root, ".github/workflows/06-static-analysis.yml"), "utf8");
  const config = readFileSync(resolve(root, "phpstan.neon"), "utf8");
  const phpstanCommand = workflow
    .split(/\r?\n/)
    .find((line) => line.includes("vendor/bin/phpstan"));

  assert.ok(phpstanCommand, "static-analysis workflow must invoke PHPStan");
  assert.doesNotMatch(phpstanCommand, /\|\|\s*true\b/);
  assert.doesNotMatch(workflow, /continue-on-error:\s*(?:true|["']true["'])\b/);
  assert.doesNotMatch(phpstanCommand, /--generate-baseline(?:=|\s)/);
  assert.match(config, /^\s+-\s+phpstan-baseline\.neon\s*$/m);
  assert.ok(existsSync(resolve(root, "phpstan-baseline.neon")));
});

test("missing PHPStan exclude paths are explicitly optional", () => {
  const config = readFileSync(resolve(root, "phpstan.neon"), "utf8");
  const excludeBlock = config.match(/excludePaths:\s*\n(?<body>(?:\s+-[^\n]+\n)+)/)?.groups?.body ?? "";
  const invalid = [];

  for (const line of excludeBlock.split(/\r?\n/)) {
    const configuredPath = line.match(/^\s+-\s+(.+?)\s*$/)?.[1];
    if (!configuredPath) continue;

    const optional = configuredPath.endsWith("(?)");
    const path = configuredPath.replace(/\s+\(\?\)$/, "");
    if (!optional && !existsSync(resolve(root, path))) {
      invalid.push(path);
    }
  }

  assert.deepEqual(invalid, []);
});

test("Actionlint failures fail the static-analysis job", () => {
  const workflow = readFileSync(resolve(root, ".github/workflows/06-static-analysis.yml"), "utf8");

  assert.doesNotMatch(workflow, /fail-on-error:\s*(?:false|["']false["'])\b/);
});
