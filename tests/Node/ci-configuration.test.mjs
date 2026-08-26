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

test("the production deployment PHP syntax check is a hard gate", () => {
  const workflow = readFileSync(resolve(root, ".github/workflows/deploy.yml"), "utf8");
  const syntaxStep = workflow.match(
    /- name: Syntax check\r?\n\s+run:\s*(?<command>[^\r\n]+)/,
  )?.groups?.command;

  assert.ok(syntaxStep, "deployment workflow must include a PHP syntax step");
  assert.match(syntaxStep, /php -l/);
  assert.doesNotMatch(syntaxStep, /\|\|\s*true\b/);
});

test("deployment treats the post-migration Daily Checkout audit as a hard failure", () => {
  const workflow = readFileSync(resolve(root, ".github/workflows/deploy.yml"), "utf8");

  const migration = workflow.indexOf("php artisan migrate --force");
  const gate = workflow.indexOf("php artisan daily-checkout:audit");
  const optimize = workflow.indexOf("php artisan optimize:clear");

  assert.ok(migration >= 0, "deployment must run migrations before the audit");
  assert.ok(gate >= 0, "deployment must run the Daily Checkout gate");
  assert.ok(optimize >= 0, "deployment must optimize only after the audit");
  assert.ok(migration < gate, "the gate requires the migrated schema");
  assert.ok(gate < optimize, "a failed audit must stop the remaining deployment command");
  assert.doesNotMatch(workflow, /daily-checkout:audit[^\r\n]*\|\|\s*true\b/);
});

test("CI runs PHPUnit against its provisioned PostgreSQL service", () => {
  const workflow = readFileSync(resolve(root, ".github/workflows/ci.yml"), "utf8");
  const phpunit = readFileSync(resolve(root, "phpunit.xml"), "utf8");
  const testStep = workflow.match(
    /- name: Run Tests\r?\n(?<body>[\s\S]*?)(?=\r?\n\s+- name:|\r?\n\s{2}[A-Za-z][\w-]*:|$)/,
  )?.groups?.body;

  assert.ok(testStep, "CI must define its PHPUnit step");
  assert.match(testStep, /DB_CONNECTION:\s*pgsql/);
  assert.match(testStep, /EXPECTED_TEST_DB_CONNECTION:\s*pgsql/);
  assert.match(testStep, /DB_HOST:\s*127\.0\.0\.1/);
  assert.match(testStep, /DB_DATABASE:\s*testing/);
  assert.match(testStep, /php artisan test/);
  assert.match(phpunit, /<directory>tests\/Integration<\/directory>/);
});

test("the Support AI deployment cannot be triggered by a PulsePoint-only change", () => {
  const workflow = readFileSync(resolve(root, ".github/workflows/deploy-support-ai-worker.yml"), "utf8");
  const pulsePointVerification = resolve(root, ".github/workflows/verify-pulsepoint-proxy.yml");

  assert.doesNotMatch(workflow, /-\s+"cloudflare-worker\/\*\*"/);
  assert.match(workflow, /-\s+"cloudflare-worker\/src\/\*\*"/);
  assert.match(workflow, /-\s+"cloudflare-worker\/package-lock\.json"/);
  assert.doesNotMatch(workflow, /wrangler deploy --dry-run/);
  assert.match(workflow, /npm exec -- wrangler deploy/);
  assert.ok(existsSync(pulsePointVerification), "PulsePoint proxy needs a dedicated verification workflow");

  const verification = readFileSync(pulsePointVerification, "utf8");
  assert.match(verification, /working-directory:\s*cloudflare-worker\/pulsepoint-proxy/);
  assert.match(verification, /npm ci --ignore-scripts/);
  assert.match(verification, /npm run typecheck/);
  assert.match(verification, /npm test/);
  assert.doesNotMatch(verification, /wrangler deploy/);
});

test("the PulsePoint verification workflow cannot acquire deployment capability", () => {
  const verification = readFileSync(resolve(root, ".github/workflows/verify-pulsepoint-proxy.yml"), "utf8");
  const forbiddenDeploymentPatterns = [
    /\bwrangler\s+(?:deploy|publish)\b/i,
    /\bnpm\s+(?:run|exec)\s+(?:--\s+)?(?:wrangler\s+)?(?:deploy|publish)\b/i,
    /\b(?:pnpm|yarn)\s+(?:run\s+)?(?:deploy|publish)\b/i,
    /cloudflare\/wrangler-action/i,
    /\b(?:CLOUDFLARE|CF)_API_TOKEN\b/i,
  ];

  for (const pattern of forbiddenDeploymentPatterns) {
    assert.doesNotMatch(verification, pattern, `verify-only workflow must not match ${pattern}`);
  }
});

test("CI executes the deploy-free configuration regression suite", () => {
  const packageJson = JSON.parse(readFileSync(resolve(root, "package.json"), "utf8"));
  const workflow = readFileSync(resolve(root, ".github/workflows/ci.yml"), "utf8");

  assert.equal(
    packageJson.scripts["test:ci-configuration"],
    "node --test tests/Node/ci-configuration.test.mjs",
  );
  assert.match(workflow, /- name: Verify CI configuration\r?\n\s+run: npm run test:ci-configuration/);
});
