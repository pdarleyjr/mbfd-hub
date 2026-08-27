import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..");

function workflowJob(workflow, name) {
  const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const body = workflow.match(
    new RegExp(
      `^  ${escapedName}:\\r?\\n(?<body>[\\s\\S]*?)(?=^  [A-Za-z0-9_-]+:\\r?$|(?![\\s\\S]))`,
      "m",
    ),
  )?.groups?.body;

  assert.ok(body, `workflow must define the ${name} job`);

  return body;
}

function workflowStep(job, name) {
  const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const body = job.match(
    new RegExp(
      `^      - name: ${escapedName}\\r?\\n(?<body>[\\s\\S]*?)(?=^      - name:|(?![\\s\\S]))`,
      "m",
    ),
  )?.groups?.body;

  assert.ok(body, `workflow job must define the ${name} step`);

  return body;
}

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
  assert.doesNotMatch(workflow, /composer require[^\r\n]*larastan/i);
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

test("Composer CI installs use an ephemeral GitHub token instead of a persisted Composer secret", () => {
  const composerInstallSteps = [
    [".github/workflows/06-static-analysis.yml", "phpstan", "Install dependencies"],
    [".github/workflows/observability.yml", "sentry_release", "Install Composer deps"],
    [".github/workflows/security.yml", "dependency-audit", "Install Composer dependencies"],
  ];

  for (const [path, jobName, stepName] of composerInstallSteps) {
    const workflow = readFileSync(resolve(root, path), "utf8");
    const install = workflowStep(workflowJob(workflow, jobName), stepName);

    assert.match(install, /COMPOSER_AUTH:/, `${path} must set Composer authentication`);
    assert.match(install, /github-oauth/, `${path} must scope Composer authentication to GitHub`);
    assert.match(install, /github\.token/, `${path} must use the ephemeral GitHub token`);
    assert.doesNotMatch(install, /secrets\./i, `${path} must not use a persisted Composer secret`);
  }
});

test("production activation is manual, main-only, and blocked by every Hub release gate", () => {
  const deploy = readFileSync(resolve(root, ".github/workflows/deploy.yml"), "utf8");
  const gates = readFileSync(resolve(root, ".github/workflows/hub-release-gates.yml"), "utf8");
  const requiredGateJobs = [
    "ci-configuration",
    "php-quality",
    "static-analysis",
    "phpunit-postgres",
    "daily-contract-integrity",
    "generated-assets",
    "security-dependencies",
    "security-secrets",
    "security-filesystem",
    "php-85-compatibility",
  ];

  assert.match(deploy, /^on:\r?\n  workflow_dispatch:/m);
  assert.doesNotMatch(deploy, /^  push:/m);
  assert.match(deploy, /confirm_production_activation:/);

  const assertMain = workflowJob(deploy, "assert-main");
  assert.match(assertMain, /if:\s*\$\{\{\s*github\.ref\s*==\s*'refs\/heads\/main'\s*\}\}/);
  assert.match(assertMain, /test "\$GITHUB_REF" = "refs\/heads\/main"/);
  assert.match(assertMain, /inputs\.confirm_production_activation/);

  const releaseGateCaller = workflowJob(deploy, "release-gates");
  assert.match(releaseGateCaller, /needs:\s*assert-main/);
  assert.match(releaseGateCaller, /uses:\s*\.\/\.github\/workflows\/hub-release-gates\.yml/);
  assert.match(releaseGateCaller, /pint_base_sha:\s*\$\{\{\s*format\('\{0\}\^',\s*github\.sha\)\s*\}\}/);

  const deployment = workflowJob(deploy, "deploy");
  assert.match(deployment, /needs:\s*release-gates/);
  assert.match(deployment, /if:\s*\$\{\{\s*github\.ref\s*==\s*'refs\/heads\/main'\s*\}\}/);
  assert.match(deployment, /environment:\s*\r?\n\s+name:\s*production/);
  assert.doesNotMatch(deployment, /if:\s*\$\{\{\s*always\(\)\s*\}\}/);

  const exactCandidate = workflowStep(deployment, "Checkout exact approved candidate");
  assert.match(exactCandidate, /RELEASE_SHA="\$GITHUB_SHA"/);
  assert.match(exactCandidate, /git merge-base --is-ancestor "\$RELEASE_SHA" origin\/main/);
  assert.match(exactCandidate, /git checkout --detach "\$RELEASE_SHA"/);
  assert.match(exactCandidate, /git rev-parse HEAD/);
  assert.doesNotMatch(exactCandidate, /deploy-marker/);

  const successfulActivation = workflowStep(deployment, "Record successful Hub candidate activation");
  assert.match(successfulActivation, /RELEASE_SHA="\$GITHUB_SHA"/);
  assert.match(successfulActivation, /git rev-parse HEAD/);
  assert.match(successfulActivation, /deploy-marker\.json/);

  const databaseBackup = workflowStep(deployment, "Verify targeted Hub database backup");
  assert.match(databaseBackup, /HUB_PG_CONTAINER=mbfd-hub-pgsql/);
  assert.match(databaseBackup, /HUB_PG_DATABASE=mbfd_hub/);
  assert.match(databaseBackup, /HUB_PG_USER=mbfd_user/);
  assert.match(databaseBackup, /docker inspect/);
  assert.match(databaseBackup, /pg_dump/);
  assert.match(databaseBackup, /test -s/);
  assert.match(databaseBackup, /pg_restore --list/);
  assert.doesNotMatch(databaseBackup, /docker ps|restic/i);
  assert.doesNotMatch(deploy, /media-control|mediamtx|\bobs\b|restic/i);

  const aggregate = workflowJob(gates, "release-gates");
  assert.doesNotMatch(aggregate, /if:\s*\$\{\{\s*always\(\)\s*\}\}/);
  assert.doesNotMatch(aggregate, /continue-on-error:\s*(?:true|["']true["'])/);

  for (const gateJob of requiredGateJobs) {
    assert.match(aggregate, new RegExp(`- ${gateJob}\\b`));
    const gate = workflowJob(gates, gateJob);
    assert.doesNotMatch(gate, /continue-on-error:\s*(?:true|["']true["'])/);
  }
});

test("independent Hub operations activation cannot run from an audit ref or without confirmation", () => {
  const activation = readFileSync(resolve(root, ".github/workflows/production-activate.yml"), "utf8");

  assert.match(activation, /^on:\r?\n  workflow_dispatch:/m);
  assert.doesNotMatch(activation, /^  push:/m);
  assert.match(activation, /confirm_production_activation:/);

  const job = workflowJob(activation, "activate");
  assert.match(job, /if:\s*\$\{\{\s*github\.ref\s*==\s*'refs\/heads\/main'\s*\}\}/);
  const confirmation = workflowStep(job, "Require main and explicit activation confirmation");
  assert.match(confirmation, /test "\$GITHUB_REF" = "refs\/heads\/main"/);
  assert.match(confirmation, /inputs\.confirm_production_activation/);
  assert.match(job, /environment:\s*\r?\n\s+name:\s*production/);
});

test("the shared release gate has hard-failing quality, Daily, PostgreSQL, asset, security, and runtime-compatibility checks", () => {
  const gates = readFileSync(resolve(root, ".github/workflows/hub-release-gates.yml"), "utf8");

  assert.match(gates, /workflow_call:\s*\r?\n\s+inputs:\s*\r?\n\s+pint_base_sha:\s*\r?\n(?:\s+.*\r?\n)*?\s+required:\s*true\s*\r?\n\s+type:\s*string/);

  const ciConfiguration = workflowJob(gates, "ci-configuration");
  assert.match(workflowStep(ciConfiguration, "Verify CI configuration"), /npm run test:ci-configuration/);

  const phpQuality = workflowJob(gates, "php-quality");
  const lint = workflowStep(phpQuality, "PHP lint");
  assert.match(lint, /set -euo pipefail/);
  assert.match(lint, /php -l/);
  assert.doesNotMatch(lint, /\|\|\s*true\b/);
  assert.match(phpQuality, /fetch-depth:\s*0/);
  const changedPhpPint = workflowStep(phpQuality, "Run changed-PHP Pint");
  assert.match(changedPhpPint, /PINT_BASE_SHA:\s*\$\{\{\s*inputs\.pint_base_sha\s*\}\}/);
  assert.match(changedPhpPint, /PINT_HEAD_SHA:\s*\$\{\{\s*github\.sha\s*\}\}/);
  assert.match(changedPhpPint, /node scripts\/ci\/changed-php-files\.mjs/);
  assert.match(workflowStep(phpQuality, "Validate Composer lockfile"), /composer validate --strict --no-check-publish/);
  assert.match(workflowStep(phpQuality, "Run Composer security audit"), /composer audit --locked/);
  assert.match(workflowStep(phpQuality, "Root TypeScript typecheck"), /npm run typecheck/);
  assert.match(workflowStep(phpQuality, "Root production build"), /npm run build/);

  const repositoryPintDebt = workflowJob(gates, "repository-pint-debt");
  assert.match(repositoryPintDebt, /continue-on-error:\s*true/);
  assert.match(workflowStep(repositoryPintDebt, "Report repository-wide Pint debt"), /vendor\/bin\/pint --test/);

  const staticAnalysis = workflowJob(gates, "static-analysis");
  assert.match(staticAnalysis, /composer install/);
  assert.match(workflowStep(staticAnalysis, "Run PHPStan"), /vendor\/bin\/phpstan analyse/);
  assert.doesNotMatch(staticAnalysis, /composer require[^\r\n]*larastan/i);

  for (const jobName of [
    "php-quality",
    "static-analysis",
    "phpunit-postgres",
    "daily-contract-integrity",
    "generated-assets",
    "security-dependencies",
    "php-85-compatibility",
  ]) {
    const install = workflowStep(workflowJob(gates, jobName), "Install PHP dependencies");
    assert.match(install, /COMPOSER_AUTH:/, `${jobName} must authenticate Composer with its ephemeral job token`);
    assert.match(install, /github-oauth/, `${jobName} must scope Composer authentication to GitHub`);
    assert.match(install, /github\.token/, `${jobName} must use the ephemeral GitHub token`);
    assert.doesNotMatch(install, /secrets\./i, `${jobName} must not require a persisted Composer secret`);
  }

  const postgres = workflowJob(gates, "phpunit-postgres");
  assert.match(postgres, /POSTGRES_DB:\s*mbfd_hub_test_ci/);
  assert.match(postgres, /MBFD_ALLOW_DISPOSABLE_POSTGRES:\s*["']1["']/);
  assert.match(postgres, /actions\/setup-node@/);
  assert.match(workflowStep(postgres, "Install Node dependencies"), /npm ci --ignore-scripts --legacy-peer-deps/);
  const postgresTestAssets = workflowStep(postgres, "Build root test assets");
  assert.match(postgresTestAssets, /npm run build/);
  assert.match(postgresTestAssets, /SENTRY_AUTH_TOKEN:\s*""/);
  assert.match(workflowStep(postgres, "Run PHPUnit suite"), /php artisan test --exclude-group=postgres/);
  assert.match(
    workflowStep(postgres, "Run PostgreSQL concurrency and integrity tests"),
    /php artisan test --group=postgres/,
  );

  const daily = workflowJob(gates, "daily-contract-integrity");
  assert.match(workflowStep(daily, "Daily TypeScript typecheck"), /npm run typecheck/);
  assert.match(workflowStep(daily, "Daily production build"), /npm run build/);
  assert.match(
    workflowStep(daily, "Run Daily Checkout contract and integrity tests"),
    /DailyCheckoutIntegrityTest\.php/,
  );

  const assets = workflowJob(gates, "generated-assets");
  assert.match(workflowStep(assets, "Verify generated assets after build"), /REQUIRE_GENERATED_ASSETS:\s*["']1["']/);
  assert.match(workflowStep(assets, "Verify generated assets after build"), /guard-generated-assets\.mjs/);
  assert.match(
    readFileSync(resolve(root, "scripts/ci/guard-generated-assets.mjs"), "utf8"),
    /hub-release-gates\.yml/,
  );

  const securityDependencies = workflowJob(gates, "security-dependencies");
  assert.match(securityDependencies, /npm audit --audit-level=high/);
  assert.match(securityDependencies, /composer audit --locked/);
  const securitySecrets = workflowJob(gates, "security-secrets");
  assert.match(securitySecrets, /gitleaks\/gitleaks-action@/);
  const securityFilesystem = workflowJob(gates, "security-filesystem");
  assert.match(securityFilesystem, /severity:\s*CRITICAL,HIGH/);
  assert.match(securityFilesystem, /exit-code:\s*1/);

  const php85 = workflowJob(gates, "php-85-compatibility");
  assert.match(php85, /php-version:\s*["']8\.5["']/);
  assert.match(php85, /actions\/setup-node@/);
  assert.match(workflowStep(php85, "Install Node dependencies"), /npm ci --ignore-scripts --legacy-peer-deps/);
  const php85TestAssets = workflowStep(php85, "Build root test assets");
  assert.match(php85TestAssets, /npm run build/);
  assert.match(php85TestAssets, /SENTRY_AUTH_TOKEN:\s*""/);
  assert.match(workflowStep(php85, "Run PHP 8.5 PHPUnit compatibility suite"), /php artisan test --exclude-group=postgres/);
  assert.match(workflowStep(php85, "Run PHP 8.5 PostgreSQL compatibility tests"), /php artisan test --group=postgres/);
});

test("CI invokes the deploy-free shared release gate for main and pull requests", () => {
  const ci = readFileSync(resolve(root, ".github/workflows/ci.yml"), "utf8");

  assert.match(ci, /^  push:\r?\n    branches: \[main\]/m);
  assert.match(ci, /^  pull_request:\r?\n    branches: \[main\]/m);
  assert.match(ci, /uses:\s*\.\/\.github\/workflows\/hub-release-gates\.yml/);
  assert.match(ci, /pint_base_sha:\s*\$\{\{\s*github\.event\.pull_request\.base\.sha\s*\|\|\s*github\.event\.before\s*\}\}/);
  assert.doesNotMatch(ci, /^\s*runs-on:\s*self-hosted\s*$/m);
  assert.doesNotMatch(ci, /^\s*environment:\s*$/m);
  assert.doesNotMatch(ci, /^\s*DEPLOY_SSH_KEY:/m);
  assert.doesNotMatch(ci, /^\s*run:.*\bssh\s+deploy-target\b/im);
});

test("CI runs a required PostgreSQL group against its dedicated disposable service", () => {
  const workflow = readFileSync(resolve(root, ".github/workflows/hub-release-gates.yml"), "utf8");
  const phpunit = readFileSync(resolve(root, "phpunit.xml"), "utf8");
  const postgresJob = workflowJob(workflow, "phpunit-postgres");
  const defaultTestStep = workflowStep(postgresJob, "Run PHPUnit suite");
  const postgresTestStep = workflowStep(postgresJob, "Run PostgreSQL concurrency and integrity tests");

  assert.match(workflow, /POSTGRES_DB:\s*mbfd_hub_test_ci/);
  assert.match(workflow, /POSTGRES_USER:\s*mbfd_test_ci/);
  assert.match(workflow, /POSTGRES_HOST_AUTH_METHOD:\s*trust/);
  assert.match(defaultTestStep, /php artisan test --exclude-group=postgres/);
  assert.match(postgresTestStep, /MBFD_ALLOW_DISPOSABLE_POSTGRES:\s*["']1["']/);
  assert.match(postgresTestStep, /REQUIRE_POSTGRES_INTEGRATION:\s*["']true["']/);
  assert.match(postgresTestStep, /DISPOSABLE_POSTGRES_HOST:\s*127\.0\.0\.1/);
  assert.match(postgresTestStep, /DISPOSABLE_POSTGRES_DATABASE:\s*mbfd_hub_test_ci/);
  assert.match(postgresTestStep, /DISPOSABLE_POSTGRES_USERNAME:\s*mbfd_test_ci/);
  assert.match(postgresTestStep, /DISPOSABLE_POSTGRES_PASSWORD:\s*["']{2}/);
  assert.match(postgresTestStep, /php artisan test --group=postgres/);
  assert.match(phpunit, /<directory>tests\/Integration<\/directory>/);
});

test("the Support AI deployment cannot be triggered by a PulsePoint-only change", () => {
  const workflow = readFileSync(resolve(root, ".github/workflows/deploy-support-ai-worker.yml"), "utf8");
  const pulsePointVerification = resolve(root, ".github/workflows/verify-pulsepoint-proxy.yml");

  assert.doesNotMatch(workflow, /^  push:/m);
  assert.doesNotMatch(workflow, /cloudflare-worker\/pulsepoint-proxy/i);
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

test("Support AI worker activation is manual, main-only, and environment-approved", () => {
  const workflow = readFileSync(resolve(root, ".github/workflows/deploy-support-ai-worker.yml"), "utf8");

  assert.match(workflow, /^on:\r?\n  workflow_dispatch:/m);
  assert.doesNotMatch(workflow, /^  push:/m);
  assert.match(workflow, /confirm_production_activation:/);

  const assertMain = workflowJob(workflow, "assert-main");
  assert.match(assertMain, /if:\s*\$\{\{\s*github\.ref\s*==\s*'refs\/heads\/main'\s*\}\}/);
  assert.match(workflowStep(assertMain, "Require main and explicit activation confirmation"), /inputs\.confirm_production_activation/);

  const validate = workflowJob(workflow, "validate");
  assert.match(validate, /needs:\s*assert-main/);
  assert.match(validate, /npm audit --audit-level=high/);

  const deploy = workflowJob(workflow, "deploy");
  assert.match(deploy, /needs:\s*validate/);
  assert.match(deploy, /if:\s*\$\{\{\s*github\.ref\s*==\s*'refs\/heads\/main'\s*\}\}/);
  assert.match(deploy, /environment:\s*\r?\n\s+name:\s*production/);
  assert.match(deploy, /npm exec -- wrangler deploy/);
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
  const gates = readFileSync(resolve(root, ".github/workflows/hub-release-gates.yml"), "utf8");

  assert.equal(
    packageJson.scripts["test:ci-configuration"],
    "node --test tests/Node/ci-configuration.test.mjs",
  );
  assert.equal(
    packageJson.scripts["test:changed-php-files"],
    "node --test tests/Node/changed-php-files.test.mjs",
  );
  const configuration = workflowStep(workflowJob(gates, "ci-configuration"), "Verify CI configuration");
  assert.match(configuration, /npm run test:ci-configuration/);
  assert.match(configuration, /npm run test:changed-php-files/);
});

test("browser and local-server test harnesses reject production endpoints and inherited integrations", () => {
  const testingExample = readFileSync(resolve(root, ".env.testing.example"), "utf8");
  const loopbackOnlyFiles = [
    "playwright.config.ts",
    "playwright.daily-checkout.config.ts",
    "playwright.operational-forms.config.ts",
    "playwright.personnel-requests.config.ts",
    "playwright.video-conferencing.config.ts",
    "tests/e2e/auth.setup.ts",
    "tests/e2e/debug-admin.spec.ts",
    "tests/e2e/mbfd-full-verification.spec.ts",
    "tests/e2e/workgroup-evaluations.spec.ts",
  ];
  const childProcessFiles = [
    "playwright.daily-checkout.config.ts",
    "playwright.operational-forms.config.ts",
    "playwright.personnel-requests.config.ts",
    "tests/e2e/operational-forms.setup.ts",
    "tests/e2e/personnel-requests.setup.ts",
  ];

  assert.match(testingExample, /^E2E_BASE_URL=http:\/\/(?:127\.0\.0\.1|localhost)(?::\d+)?$/m);

  for (const file of loopbackOnlyFiles) {
    const source = readFileSync(resolve(root, file), "utf8");
    assert.match(source, /loopbackBaseUrl\(/, `${file} must validate its base URL`);
    assert.doesNotMatch(source, /https:\/\/(?:www\.)?mbfdhub\.com|https:\/\/support\.darleyplex\.com/i);
  }

  for (const file of childProcessFiles) {
    const source = readFileSync(resolve(root, file), "utf8");
    assert.match(source, /sanitizedTestEnvironment\(/, `${file} must scrub inherited integration configuration`);
    assert.doesNotMatch(source, /\.\.\.process\.env/);
  }
});

test("Daily Checkout browser acceptance uses a mocked isolated loopback build", () => {
  const config = readFileSync(resolve(root, "playwright.daily-checkout.config.ts"), "utf8");
  const dailySpec = readFileSync(resolve(root, "tests/e2e/daily-checkout-inspection.spec.ts"), "utf8");
  const packageJson = JSON.parse(readFileSync(resolve(root, "package.json"), "utf8"));
  const gates = readFileSync(resolve(root, ".github/workflows/hub-release-gates.yml"), "utf8");
  const daily = workflowJob(gates, "daily-contract-integrity");

  assert.equal(
    packageJson.scripts["test:daily-checkout-e2e"],
    "playwright test --config=playwright.daily-checkout.config.ts",
  );
  assert.match(config, /loopbackBaseUrl\('DAILY_CHECKOUT_E2E_BASE_URL'/);
  assert.match(config, /sanitizedTestEnvironment\(/);
  assert.match(config, /DAILY_CHECKOUT_OUT_DIR/);
  assert.match(config, /serviceWorkers:\s*'block'/);
  assert.match(config, /reuseExistingServer:\s*false/);
  assert.match(config, /testMatch:\s*\/daily-checkout-inspection\\\.spec\\\.ts\//);
  assert.match(dailySpec, /page\.route\('\*\*\/api\/\*\*'/);
  assert.match(workflowStep(daily, "Install root browser test dependencies"), /npm ci --ignore-scripts --legacy-peer-deps/);
  assert.match(workflowStep(daily, "Install Chromium for mocked Daily Checkout Playwright"), /npx playwright install --with-deps chromium/);
  assert.match(workflowStep(daily, "Run mocked Daily Checkout Playwright"), /npm run test:daily-checkout-e2e/);
});

test("Lighthouse follows the approved Hub candidate activation workflow", () => {
  const lighthouse = readFileSync(resolve(root, ".github/workflows/lighthouse.yml"), "utf8");

  assert.match(lighthouse, /workflows:\s*\["Deploy approved Hub candidate"\]/);
  assert.doesNotMatch(lighthouse, /Deploy to Production/);
});

test("ordinary CI builds cannot inherit Sentry upload capability or production integration secrets", () => {
  const workflow = readFileSync(resolve(root, ".github/workflows/hub-release-gates.yml"), "utf8");
  const buildStep = workflowStep(workflowJob(workflow, "php-quality"), "Root production build");

  for (const variable of [
    "SENTRY_AUTH_TOKEN",
    "SENTRY_ORG",
    "SENTRY_PROJECT_FRONTEND",
    "VITE_SENTRY_DSN",
    "VITE_SENTRY_RELEASE",
  ]) {
    assert.match(buildStep, new RegExp(`${variable}:\\s*[\"']{2}`));
  }
  assert.doesNotMatch(workflow, /\$\{\{\s*secrets\./i);
});
