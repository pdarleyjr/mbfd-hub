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
  const prepare = readFileSync(resolve(root, ".github/workflows/prepare-production-image.yml"), "utf8");
  const imageRepository = readFileSync(resolve(root, "docker/production/image-repository"), "utf8").trim();
  const imageCompose = readFileSync(resolve(root, "compose.prod.image.yaml"), "utf8");
  const productionDockerfile = readFileSync(resolve(root, "docker/production/Dockerfile"), "utf8");
  const productionEntrypoint = readFileSync(resolve(root, "docker/production/start-production-container"), "utf8");
  const gates = readFileSync(resolve(root, ".github/workflows/hub-release-gates.yml"), "utf8");
  const requiredGateJobs = [
    "ci-configuration",
    "php-quality",
    "static-analysis",
    "actionlint",
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
  assert.match(deploy, /final_release_sha:/);
  assert.match(deploy, /final_image_digest:/);
  assert.match(deploy, /FINAL_RELEASE_SHA/);
  assert.match(deploy, /FINAL_IMAGE_DIGEST/);
  assert.match(
    deploy,
    /^permissions:\r?\n  contents: read\r?\n  pull-requests: read$/m,
    "the deploy caller must grant every permission requested by the reusable release gate",
  );

  const assertMain = workflowJob(deploy, "assert-main");
  assert.match(assertMain, /if:\s*\$\{\{\s*github\.ref\s*==\s*'refs\/heads\/main'\s*\}\}/);
  assert.match(assertMain, /test "\$GITHUB_REF" = "refs\/heads\/main"/);
  assert.match(assertMain, /inputs\.confirm_production_activation/);
  assert.match(assertMain, /inputs\.final_release_sha/);
  assert.match(assertMain, /inputs\.final_image_digest/);
  assert.match(assertMain, /sha256:/);

  const releaseGateCaller = workflowJob(deploy, "release-gates");
  assert.match(releaseGateCaller, /needs:\s*assert-main/);
  assert.match(releaseGateCaller, /uses:\s*\.\/\.github\/workflows\/hub-release-gates\.yml/);
  assert.match(releaseGateCaller, /pint_base_sha:\s*\$\{\{\s*format\('\{0\}\^',\s*github\.sha\)\s*\}\}/);

  const deployment = workflowJob(deploy, "deploy");
  assert.match(deployment, /needs:\s*release-gates/);
  assert.match(deployment, /if:\s*\$\{\{\s*github\.ref\s*==\s*'refs\/heads\/main'\s*\}\}/);
  assert.match(deployment, /environment:\s*\r?\n\s+name:\s*production/);
  assert.doesNotMatch(deployment, /if:\s*\$\{\{\s*always\(\)\s*\}\}/);

  const sshTarget = workflowStep(deployment, "Configure ephemeral Hub deployment SSH target");
  assert.match(sshTarget, /RUNNER_TEMP/);
  assert.match(sshTarget, /DEPLOY_KNOWN_HOSTS/);
  assert.match(sshTarget, /StrictHostKeyChecking yes/);
  assert.doesNotMatch(sshTarget, /ssh-keyscan/);
  assert.doesNotMatch(sshTarget, /\$HOME\/\.ssh/);

  const preconditions = workflowStep(deployment, "Inspect Hub production checkout and runtime");
  assert.match(preconditions, /git status --short --branch/);
  assert.match(preconditions, /git diff --stat/);
  assert.match(preconditions, /git status --porcelain/);
  assert.match(preconditions, /Refusing to overwrite a dirty production checkout/);
  assert.match(preconditions, /trt_inventory_sessions/);
  assert.match(preconditions, /php artisan migrate:status/);
  assert.doesNotMatch(preconditions, /git reset|git clean/);

  const exactCandidate = workflowStep(deployment, "Checkout exact approved candidate");
  assert.match(exactCandidate, /RELEASE_SHA:\s*\$\{\{ github\.sha \}\}/);
  assert.match(exactCandidate, /test -n "\$RELEASE_SHA"/);
  assert.match(exactCandidate, /git fetch origin main --no-tags/);
  assert.match(exactCandidate, /git rev-parse origin\/main/);
  assert.doesNotMatch(exactCandidate, /git merge-base --is-ancestor/);
  assert.match(exactCandidate, /git checkout --detach "\$RELEASE_SHA"/);
  assert.match(exactCandidate, /git rev-parse HEAD/);
  assert.doesNotMatch(exactCandidate, /deploy-marker/);

  const databaseBackup = workflowStep(deployment, "Verify targeted Hub database backup");
  assert.match(databaseBackup, /HUB_PG_CONTAINER=mbfd-hub-pgsql/);
  assert.match(databaseBackup, /HUB_PG_DATABASE=mbfd_hub/);
  assert.match(databaseBackup, /HUB_PG_USER=mbfd_user/);
  assert.match(databaseBackup, /HUB_BACKUP_DIR/);
  assert.match(databaseBackup, /PG_DATA_SOURCE/);
  assert.match(databaseBackup, /BACKUP_PATH_HELPER_IMAGE=sail-8\.5\/app/);
  assert.match(databaseBackup, /docker run --rm --network none --pull=never/);
  assert.match(databaseBackup, /--read-only --cap-drop=ALL --cap-add=DAC_READ_SEARCH --security-opt=no-new-privileges/);
  assert.match(databaseBackup, /--user 0:0/);
  assert.doesNotMatch(databaseBackup, /--privileged\b/);
  assert.doesNotMatch(databaseBackup, /--cap-add(?:=|\s+)(?!DAC_READ_SEARCH\b)/);
  assert.doesNotMatch(databaseBackup, /--cap-add=DAC_OVERRIDE/);
  assert.match(databaseBackup, /--mount type=bind,src=\/mnt,dst=\/host-mnt,readonly/);
  assert.match(databaseBackup, /PG_DATA_SOURCE_IN_HELPER/);
  assert.match(databaseBackup, /HUB_BACKUP_DIR_IN_HELPER/);
  assert.match(databaseBackup, /realpath -e -- "\$1"/);
  assert.match(databaseBackup, /realpath -e -- "\$2"/);
  assert.match(databaseBackup, /case "\$HUB_BACKUP_DIR_REAL" in/);
  assert.match(databaseBackup, /"\$PG_DATA_SOURCE_REAL"\|"\$PG_DATA_SOURCE_REAL"\/\*\)/);
  assert.doesNotMatch(databaseBackup, /PG_DATA_SOURCE_REAL="\$\(realpath -e -- "\$PG_DATA_SOURCE"\)"/);
  assert.doesNotMatch(databaseBackup, /HUB_BACKUP_DIR_REAL="\$\(realpath -e -- "\$HUB_BACKUP_DIR"\)"/);
  assert.match(databaseBackup, /docker inspect/);
  assert.match(databaseBackup, /pg_dump/);
  assert.match(databaseBackup, /test -s/);
  assert.match(databaseBackup, /pg_restore --list/);
  assert.doesNotMatch(databaseBackup, /pg_restore --list\s+-\s*</);
  assert.match(databaseBackup, /sha256sum/);
  assert.match(databaseBackup, /BACKUP_FILE="\$HUB_BACKUP_DIR\//);
  assert.doesNotMatch(databaseBackup, /BACKUP_DIR=\/var\/lib\/postgresql\/data/);

  const immutableImage = workflowStep(deployment, "Pull and verify immutable Hub image");
  assert.match(immutableImage, /docker pull/);
  assert.match(immutableImage, /ghcr\.io\//);
  assert.match(immutableImage, /org\.opencontainers\.image\.revision/);
  assert.match(immutableImage, /FINAL_RELEASE_SHA/);
  assert.match(immutableImage, /FINAL_IMAGE_DIGEST/);
  assert.match(immutableImage, /IMAGE_REPOSITORY/);
  assert.match(immutableImage, /IMAGE_REF="\$IMAGE_REPOSITORY@\$FINAL_IMAGE_DIGEST"/);

  const maintenance = workflowStep(deployment, "Enter maintenance mode and verify queue safety");
  assert.match(maintenance, /php artisan down --render=errors::503/);
  assert.match(maintenance, /APP_MAINTENANCE_DRIVER/);
  assert.match(maintenance, /storage\/framework\/down/);
  assert.doesNotMatch(maintenance, /http:\/\/localhost\//);
  assert.match(maintenance, /--force/);

  const activation = workflowStep(deployment, "Migrate and activate prebuilt Hub image");
  assert.match(activation, /RELEASE_SHA:\s*\$\{\{ github\.sha \}\}/);
  assert.match(activation, /test -n "\$RELEASE_SHA"/);
  assert.match(activation, /RELEASE_SHA='\$RELEASE_SHA'.*bash -s/);

  const applicationHealth = workflowStep(deployment, "Verify Hub application health");
  assert.match(applicationHealth, /\.State\.Health\.Status/);
  assert.match(applicationHealth, /http:\/\/localhost:8081\/up/);
  assert.doesNotMatch(applicationHealth, /localhost:80\/up/);
  assert.match(activation, /test "\$\(git rev-parse HEAD\)" = "\$RELEASE_SHA"/);
  assert.match(activation, /php artisan migrate:status/);
  assert.match(activation, /compose\.prod\.image\.yaml/);
  assert.match(activation, /docker compose.*run --interactive=false --rm --no-deps --pull never/s);
  assert.match(activation, /--entrypoint bash/);
  assert.match(activation, /--name "\$HUB_MIGRATION_CONTAINER"/);
  assert.doesNotMatch(activation, /--service-ports/);
  assert.match(activation, /--no-build --no-deps --force-recreate laravel\.test/);
  assert.match(activation, /OLD_CONTAINER_ID="\$\(docker inspect --format '\{\{\.Id\}\}' "\$HUB_APP_CONTAINER"\)"/);
  assert.match(activation, /NEW_CONTAINER_ID="\$\(docker inspect --format '\{\{\.Id\}\}' "\$HUB_APP_CONTAINER"\)"/);
  assert.match(activation, /test "\$NEW_CONTAINER_ID" != "\$OLD_CONTAINER_ID"/);
  assert.match(activation, /\.State\.Running/);
  assert.match(activation, /RUNNING_IMAGE_REF="\$\(docker inspect --format '\{\{\.Config\.Image\}\}' "\$HUB_APP_CONTAINER"\)"/);
  assert.match(activation, /RUNNING_IMAGE_DIGEST="\$\{RUNNING_IMAGE_REF##\*@\}"/);
  assert.match(activation, /test "\$RUNNING_IMAGE_DIGEST" = "\$FINAL_IMAGE_DIGEST"/);
  assert.match(activation, /APPROVED_IMAGE_ID="\$\(docker image inspect --format '\{\{\.Id\}\}' "\$IMAGE_REF"\)"/);
  assert.match(activation, /test "\$RUNNING_IMAGE_ID" = "\$APPROVED_IMAGE_ID"/);
  assert.match(activation, /\.RepoDigests/);
  assert.match(activation, /org\.opencontainers\.image\.revision/);
  assert.match(activation, /\/var\/www\/html\/\.git-sha/);
  assert.doesNotMatch(activation, /composer install|npm ci|vite build|filament:assets/);
  assert.match(
    activation,
    /daily-checkout:apply-approved-policy --confirm=APPLY_APPROVED_FRONTLINE_DAILY_POLICY --no-interaction/,
  );
  assert.match(activation, /daily-checkout:activate-ledger --release-sha="\$RELEASE_SHA" --no-interaction/);
  assert.match(activation, /daily-checkout:apply-approved-policy --verify --no-interaction/);
  assert.doesNotMatch(activation, /identity:reconcile-apply|mbfd:provision-users/);
  assert.doesNotMatch(activation, /docker compose .* down|docker (?:system )?prune/);
  const oneOffMigrationIndex = activation.indexOf("run --interactive=false --rm --no-deps --pull never");
  const recreateIndex = activation.indexOf("--no-build --no-deps --force-recreate laravel.test");
  const migrationIndex = activation.indexOf("php artisan migrate --force");
  const policyIndex = activation.indexOf("daily-checkout:apply-approved-policy");
  const cutoverIndex = activation.indexOf("daily-checkout:activate-ledger");
  const policyVerifyIndex = activation.indexOf("daily-checkout:apply-approved-policy --verify");
  assert.ok(
    oneOffMigrationIndex >= 0 &&
      migrationIndex > oneOffMigrationIndex &&
      policyIndex > migrationIndex &&
      cutoverIndex > policyIndex &&
      policyVerifyIndex > cutoverIndex,
  );
  assert.ok(
    recreateIndex > policyVerifyIndex,
    "laravel.test must be recreated only after migration, approved policy, ledger activation, and policy verification succeed",
  );
  const policyCommand = activation.match(/[^\r\n]*daily-checkout:apply-approved-policy[^\r\n]*/)?.[0] ?? "";
  assert.doesNotMatch(policyCommand, /--dry-run|\|\|\s*true/);
  const cutoverCommand = activation.match(/[^\r\n]*daily-checkout:activate-ledger[^\r\n]*/)?.[0] ?? "";
  assert.doesNotMatch(cutoverCommand, /\|\|\s*true|--force/);

  const backgroundServices = workflowStep(deployment, "Restart Hub queues and verify background services");
  assert.match(backgroundServices, /php artisan queue:restart/);
  assert.match(backgroundServices, /supervisorctl -c \/etc\/supervisor\/conf\.d\/supervisord\.conf restart 'queue-worker:\*'/);
  assert.match(backgroundServices, /supervisorctl -c \/etc\/supervisor\/conf\.d\/supervisord\.conf status/);
  assert.match(backgroundServices, /\^php \+RUNNING /);
  assert.match(backgroundServices, /\^queue-worker:queue-worker_00 \+RUNNING /);
  assert.match(backgroundServices, /\^reverb \+RUNNING /);
  assert.doesNotMatch(backgroundServices, /pgrep/);

  const leaveMaintenance = workflowStep(deployment, "Leave maintenance mode and verify internal health");
  assert.match(leaveMaintenance, /php artisan up/);
  assert.match(leaveMaintenance, /http:\/\/localhost:8081\/up/);
  assert.doesNotMatch(leaveMaintenance, /http:\/\/localhost\/up/);

  const successfulActivation = workflowStep(deployment, "Record successful Hub candidate activation");
  assert.match(successfulActivation, /RELEASE_SHA:\s*\$\{\{ github\.sha \}\}/);
  assert.match(successfulActivation, /FINAL_IMAGE_DIGEST:\s*\$\{\{ inputs\.final_image_digest \}\}/);
  assert.doesNotMatch(successfulActivation, /git rev-parse HEAD/);
  assert.match(successfulActivation, /RUNNING_IMAGE_REF="\$\(docker inspect --format '\{\{\.Config\.Image\}\}' "\$HUB_APP_CONTAINER"\)"/);
  assert.match(successfulActivation, /test "\$RUNNING_IMAGE_DIGEST" = "\$FINAL_IMAGE_DIGEST"/);
  assert.match(successfulActivation, /org\.opencontainers\.image\.revision/);
  assert.match(successfulActivation, /\/var\/www\/html\/\.git-sha/);
  assert.match(successfulActivation, /"image_digest":"%s"/);
  assert.match(successfulActivation, /"container_id":"%s"/);
  assert.match(successfulActivation, /deploy-marker\.json/);
  assert.match(successfulActivation, /docker exec -u sail "\$HUB_APP_CONTAINER" sh -c/);
  assert.match(successfulActivation, /\/var\/www\/html\/public\/deploy-marker\.json/);
  assert.ok(
    successfulActivation.indexOf("DEPLOY_MARKER=") > successfulActivation.indexOf('test "$RUNNING_GIT_SHA" = "$RELEASE_SHA"'),
    "deployment marker must be created only after runtime provenance is verified",
  );

  const publicSmoke = workflowStep(deployment, "Verify public Hub smoke routes");
  assert.match(publicSmoke, /RELEASE_SHA:\s*\$\{\{ github\.sha \}\}/);
  assert.match(publicSmoke, /deploy-marker\.json/);
  assert.match(publicSmoke, /jq -er '\.sha'/);
  assert.match(publicSmoke, /https:\/\/www\.mbfdhub\.com\/daily\/stations\/6[^\r\n]*\)" = '302'/);
  assert.match(publicSmoke, /https:\/\/www\.mbfdhub\.com\/api\/public\/stations[^\r\n]*\)" = '302'/);

  assert.doesNotMatch(deploy, /docker compose[^\r\n]*--build/);
  assert.doesNotMatch(deploy, /docker compose[^\r\n]*(?:down|prune)/);

  assert.equal(imageRepository, "ghcr.io/pdarleyjr/mbfd-hub");
  assert.match(prepare, /docker\/production\/image-repository/);
  assert.match(deploy, /docker\/production\/image-repository/);
  assert.doesNotMatch(prepare, /ghcr\.io\/[\w/-]*mbfd_hub/);
  assert.doesNotMatch(deploy, /ghcr\.io\/[\w/-]*mbfd_hub/);
  assert.match(imageCompose, /HUB_IMAGE_REF must be an immutable image digest/);

  const checkoutIndex = deployment.indexOf("Checkout exact approved candidate");
  const pullIndex = deployment.indexOf("Pull and verify immutable Hub image");
  const backupIndex = deployment.indexOf("Verify targeted Hub database backup");
  const maintenanceIndex = deployment.indexOf("Enter maintenance mode and verify queue safety");
  const healthIndex = deployment.indexOf("Verify Hub application health");
  assert.ok(checkoutIndex >= 0 && pullIndex > checkoutIndex && backupIndex > pullIndex && maintenanceIndex > backupIndex);
  assert.ok(healthIndex > maintenanceIndex);

  assert.match(prepare, /^on:\r?\n  workflow_dispatch:/m);
  assert.doesNotMatch(prepare, /^  push:/m);
  assert.match(prepare, /final_release_sha:/);
  assert.match(prepare, /uses:\s*\.\/\.github\/workflows\/hub-release-gates\.yml/);
  assert.match(prepare, /docker buildx build/);
  assert.match(prepare, /SOURCE_REVISION/);
  assert.match(prepare, /WWWUSER=1000/);
  assert.match(prepare, /WWWGROUP=1000/);
  assert.match(prepare, /docker push/);
  assert.match(prepare, /cyclonedx/);
  assert.match(prepare, /trivy/);
  assert.match(prepare, /org\.opencontainers\.image\.revision/);
  assert.doesNotMatch(prepare, /self-hosted|ssh |DEPLOY_SSH_KEY|environment:\s*\r?\n\s+name:\s*production/i);

  assert.match(imageCompose, /image:\s*"\$\{HUB_IMAGE_REF/);
  assert.match(imageCompose, /HUB_APP_ENV_FILE.*\.env:ro/);
  assert.match(imageCompose, /HUB_STORAGE_PATH.*\/var\/www\/html\/storage/);
  assert.match(imageCompose, /external:\s*true/);
  assert.doesNotMatch(imageCompose, /\bbuild:/);
  assert.doesNotMatch(imageCompose, /\.\s*:\/var\/www\/html/);
  assert.doesNotMatch(imageCompose, /^\s{2}(?:pgsql|redis):/m);

  assert.match(productionDockerfile, /ARG SOURCE_REVISION/);
  assert.match(productionDockerfile, /org\.opencontainers\.image\.revision/);
  assert.match(productionDockerfile, /USER sail/);
  assert.doesNotMatch(productionEntrypoint, /\b(?:user|group)mod\b|\bchown\b/);
  assert.match(productionEntrypoint, /baked non-root sail identity/);

  const aggregate = workflowJob(gates, "release-gates");
  assert.doesNotMatch(aggregate, /if:\s*\$\{\{\s*always\(\)\s*\}\}/);
  assert.doesNotMatch(aggregate, /continue-on-error:\s*(?:true|["']true["'])/);

  for (const gateJob of requiredGateJobs) {
    assert.match(aggregate, new RegExp(`- ${gateJob}\\b`));
    const gate = workflowJob(gates, gateJob);
    assert.doesNotMatch(gate, /continue-on-error:\s*(?:true|["']true["'])/);
  }
});

test("the PHP server routes Daily SPA navigation through Laravel authentication", () => {
  const server = readFileSync(resolve(root, "server.php"), "utf8");

  assert.doesNotMatch(server, /readfile\s*\(\s*\$spaIndex\s*\)/);
  assert.doesNotMatch(server, /preg_match\s*\(\s*['"]#\^\/daily/);
  assert.match(server, /is_file\s*\(\s*\$publicPath\s*\.\s*\$uri\s*\)/);
  assert.match(server, /\$_SERVER\[['"]SCRIPT_NAME['"]\]\s*=\s*['"]\/index\.php['"]/);
  assert.match(server, /\$_SERVER\[['"]PHP_SELF['"]\]\s*=\s*['"]\/index\.php['"]/);
  assert.match(server, /require_once\s+\$publicPath\s*\.\s*['"]\/index\.php['"]/);
});

test("the immutable image starts Supervisor without privilege-switch directives", () => {
  const dockerfile = readFileSync(resolve(root, "docker/production/Dockerfile"), "utf8");
  const supervisor = readFileSync(resolve(root, "docker/production/supervisord.conf"), "utf8");
  const prepare = readFileSync(resolve(root, ".github/workflows/prepare-production-image.yml"), "utf8");

  assert.match(dockerfile, /COPY docker\/production\/supervisord\.conf \/etc\/supervisor\/conf\.d\/supervisord\.conf/);
  assert.doesNotMatch(supervisor, /^user\s*=/mi);
  assert.match(supervisor, /\[program:php\]/);
  assert.match(supervisor, /\[program:queue-worker\]/);
  assert.match(supervisor, /\[program:reverb\]/);
  assert.match(supervisor, /\[program:scheduler\]/);

  const runtime = workflowStep(prepare, "Test immutable Hub Supervisor runtime readiness");
  assert.match(runtime, /docker run --detach/);
  assert.match(runtime, /--network none/);
  assert.match(runtime, /supervisord --version/);
  assert.match(runtime, /supervisorctl -c \/etc\/supervisor\/conf\.d\/supervisord\.conf status/);
  assert.match(runtime, /queue-worker:queue-worker_00 \+RUNNING/);
  assert.match(runtime, /test "\$\(docker exec "\$RUNTIME_CONTAINER" id -u\)" = 1000/);
  assert.match(runtime, /http:\/\/localhost:8081\/daily\/stations\/6[^\r\n]*\)" = '302'/);
  assert.match(runtime, /http:\/\/localhost:8081\/api\/public\/stations[^\r\n]*\)" = '302'/);
  assert.match(runtime, /ps -eo uid=/);
  assert.match(runtime, /trap cleanup EXIT/);
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
  const postgresNodeDependencies = workflowStep(postgres, "Install Node dependencies");
  assert.match(postgresNodeDependencies, /npm ci --ignore-scripts --legacy-peer-deps/);
  assert.match(postgresNodeDependencies, /npm --prefix resources\/js\/daily-checkout ci --ignore-scripts/);
  const postgresTestAssets = workflowStep(postgres, "Build root test assets");
  assert.match(postgresTestAssets, /npm run build/);
  assert.match(postgresTestAssets, /SENTRY_AUTH_TOKEN:\s*""/);
  const postgresDailyAssets = workflowStep(postgres, "Build Daily Checkout test assets");
  assert.match(postgresDailyAssets, /working-directory:\s*resources\/js\/daily-checkout/);
  assert.match(postgresDailyAssets, /DAILY_CHECKOUT_OUT_DIR:\s*\.\.\/\.\.\/\.\.\/public\/daily/);
  assert.match(postgresDailyAssets, /npm run build/);
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
  for (const testFile of [
    "ActivateDailyCheckoutLedgerTest.php",
    "AuditDailyCheckoutPreactivationTest.php",
    "DailyCheckoutComplianceServiceTest.php",
    "PublicStationDailyCheckoutContractTest.php",
  ]) {
    assert.match(workflowStep(daily, "Run Daily Checkout contract and integrity tests"), new RegExp(testFile.replace(/\./g, "\\.")));
  }

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
  assert.match(securitySecrets, /fetch-depth:\s*0/);
  const eventCandidateScan = workflowStep(securitySecrets, "Run Gitleaks for push and pull request candidates");
  assert.match(eventCandidateScan, /if:\s*\$\{\{\s*github\.event_name\s*!=\s*'workflow_dispatch'\s*\}\}/);
  assert.match(eventCandidateScan, /gitleaks\/gitleaks-action@/);
  const secretRange = workflowStep(securitySecrets, "Verify dispatched candidate secret-scan range");
  assert.match(secretRange, /if:\s*\$\{\{\s*github\.event_name\s*==\s*'workflow_dispatch'\s*\}\}/);
  assert.match(secretRange, /SECRET_SCAN_BASE_REV:\s*\$\{\{\s*inputs\.pint_base_sha\s*\}\}/);
  assert.match(secretRange, /git rev-parse --verify/);
  assert.match(secretRange, /git merge-base --is-ancestor/);
  assert.match(secretRange, /expected_base_sha=.*git rev-parse --verify/);
  assert.match(secretRange, /test "\$base_sha" = "\$expected_base_sha"/);
  assert.match(secretRange, /GITHUB_OUTPUT/);
  const candidateSecretScan = workflowStep(securitySecrets, "Scan dispatched candidate range for secrets");
  assert.match(candidateSecretScan, /if:\s*\$\{\{\s*github\.event_name\s*==\s*'workflow_dispatch'\s*\}\}/);
  assert.match(candidateSecretScan, /ghcr\.io\/gitleaks\/gitleaks:v8\.24\.3@sha256:e1b35e12a8c6fa8901f060459cfb6b2fc4c484d3afbe3b029733a3bbfab07055/);
  assert.match(candidateSecretScan, /--network=none/);
  assert.match(candidateSecretScan, /--read-only/);
  assert.match(candidateSecretScan, /--cap-drop=ALL/);
  assert.match(candidateSecretScan, /--security-opt=no-new-privileges/);
  assert.match(candidateSecretScan, /:\/repo:ro/);
  assert.match(candidateSecretScan, /--log-opts="\$\{SECRET_SCAN_BASE_SHA\}\.\.\$\{SECRET_SCAN_HEAD_SHA\}"/);
  assert.doesNotMatch(candidateSecretScan, /--(?:all|full-history)/);
  const securityFilesystem = workflowJob(gates, "security-filesystem");
  assert.match(securityFilesystem, /severity:\s*CRITICAL,HIGH/);
  assert.match(securityFilesystem, /exit-code:\s*1/);

  const php85 = workflowJob(gates, "php-85-compatibility");
  assert.match(php85, /php-version:\s*["']8\.5["']/);
  assert.match(php85, /actions\/setup-node@/);
  const php85NodeDependencies = workflowStep(php85, "Install Node dependencies");
  assert.match(php85NodeDependencies, /npm ci --ignore-scripts --legacy-peer-deps/);
  assert.match(php85NodeDependencies, /npm --prefix resources\/js\/daily-checkout ci --ignore-scripts/);
  const php85TestAssets = workflowStep(php85, "Build root test assets");
  assert.match(php85TestAssets, /npm run build/);
  assert.match(php85TestAssets, /SENTRY_AUTH_TOKEN:\s*""/);
  const php85DailyAssets = workflowStep(php85, "Build Daily Checkout test assets");
  assert.match(php85DailyAssets, /working-directory:\s*resources\/js\/daily-checkout/);
  assert.match(php85DailyAssets, /DAILY_CHECKOUT_OUT_DIR:\s*\.\.\/\.\.\/\.\.\/public\/daily/);
  assert.match(php85DailyAssets, /npm run build/);
  assert.match(workflowStep(php85, "Run PHP 8.5 PHPUnit compatibility suite"), /php artisan test --exclude-group=postgres/);
  assert.match(workflowStep(php85, "Run PHP 8.5 PostgreSQL compatibility tests"), /php artisan test --group=postgres/);

  const aiGateway = workflowJob(gates, "ai-gateway-source");
  assert.match(aiGateway, /fetch-depth:\s*0/);
  const aiGatewayValidation = workflowStep(aiGateway, "Validate canonical AI Gateway source");
  assert.match(aiGatewayValidation, /python3 -m unittest[\s\\]*\r?\n?\s*tests\.security\.test_mbfd_ai_gateway/);
  assert.match(aiGatewayValidation, /tests\.security\.test_mbfd_ai_gateway_release/);
  assert.match(aiGatewayValidation, /python3 -m compileall/);
  assert.match(aiGatewayValidation, /--validate-config/);
  assert.match(aiGatewayValidation, /bash -n/);
  assert.doesNotMatch(aiGateway, /\b(?:ssh|sudo)\b/);
  assert.doesNotMatch(aiGateway, /\$\{\{\s*secrets\./i);

  const aggregate = workflowJob(gates, "release-gates");
  assert.match(aggregate, /^\s+- ai-gateway-source\s*$/m);
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
  assert.match(config, /serviceWorkers:\s*'allow'/);
  assert.match(config, /daily-pwa-chromium/);
  assert.match(config, /daily-webkit-iphone/);
  assert.match(config, /daily-webkit-ipad/);
  assert.match(config, /reuseExistingServer:\s*false/);
  assert.match(config, /daily-checkout-service-worker\\\.spec\\\.ts/);
  assert.match(dailySpec, /page\.route\('\*\*\/api\/\*\*'/);
  assert.match(workflowStep(daily, "Install root browser test dependencies"), /npm ci --ignore-scripts --legacy-peer-deps/);
  assert.match(workflowStep(daily, "Install Chromium and WebKit for Daily Checkout Playwright"), /npx playwright install --with-deps chromium webkit/);
  assert.match(workflowStep(daily, "Run mocked Daily Checkout Playwright"), /npm run test:daily-checkout-e2e/);
});

test("Operational Forms browser acceptance includes real WebKit phone and tablet projects", () => {
  const config = readFileSync(resolve(root, "playwright.operational-forms.config.ts"), "utf8");

  assert.match(config, /webkit-iphone/);
  assert.match(config, /webkit-ipad/);
  assert.match(config, /browserName:\s*'webkit'/);
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
