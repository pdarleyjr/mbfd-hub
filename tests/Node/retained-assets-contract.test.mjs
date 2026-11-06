// Release-standard finding F4: a page still open across a deploy lazy-loads hashed chunks the new
// immutable image no longer has. deploy.yml keeps the replaced release's build/daily assets on the
// host (bounded), compose mounts them read-only, and nginx serves one only when the current image
// lacks that file. Runbook: docs/ops/retained-assets.md
import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const read = (path) => readFileSync(resolve(root, path), "utf8").replace(/\r\n/g, "\n");

const RETAIN_STEP = "Retain the running release's hashed assets";
const SWITCH_STEP = "Migrate and activate prebuilt Hub image";
const HOST_DIR = "/var/lib/mbfd-hub/retained-assets";
// Escapes every RegExp metacharacter, backslash included, so a path is matched literally.
const escapeRegExp = (value) => value.replace(/[\\^$.*+?()[\]{}|/-]/g, '\\$&');

function step(workflow, name) {
  const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const body = workflow.match(new RegExp(`^      - name: ${escaped}\\n(?<body>[\\s\\S]*?)(?=^      - name:|^      # |(?![\\s\\S]))`, "m"))
    ?.groups?.body;
  assert.ok(body, `deploy.yml must define the "${name}" step`);
  return body;
}

test("deploy retains the running release's hashed assets before the image switch", () => {
  const deploy = read(".github/workflows/deploy.yml");
  const retain = step(deploy, RETAIN_STEP);
  const retainIndex = deploy.indexOf(`- name: ${RETAIN_STEP}`);
  const switchIndex = deploy.indexOf(`- name: ${SWITCH_STEP}`);
  const recreateIndex = deploy.indexOf("--no-build --no-deps --force-recreate laravel.test");

  assert.ok(retainIndex > deploy.indexOf("- name: Enter maintenance mode and verify queue safety"));
  assert.ok(retainIndex < switchIndex && switchIndex < recreateIndex, "assets must be retained before laravel.test is recreated");

  // Copied from the container that is still serving, both asset trees.
  assert.match(retain, /HUB_APP_CONTAINER=mbfd-hub-laravel/);
  assert.match(retain, /docker cp "\$HUB_APP_CONTAINER:\/var\/www\/html\/public\/\$AREA\/assets\/\." /);
  assert.match(retain, /for AREA in build daily; do/);
  assert.match(retain, new RegExp(`RETAINED_ASSETS_DIR=${escapeRegExp(HOST_DIR)}\\n`));

  // Same hashed name means same bytes: an existing retained file is never replaced.
  assert.match(retain, /if \[ -e "\$DST\/\$NAME" \]; then\n\s+cmp -s/);
  assert.doesNotMatch(retain, /cp -f|--force|rsync[^\n]*--delete|mv -f/);
  // Written under a dotted temporary name (nginx never serves dotfiles), then renamed.
  assert.match(retain, /cp "\$FILE" "\$DST\/\.\$NAME\.partial"[\s\S]*mv "\$DST\/\.\$NAME\.partial" "\$DST\/\$NAME"/);
  assert.match(retain, /touch -c -- "\$DST\/\$NAME"/);

  // Root work only inside a throwaway, network-less, read-only, capability-free container.
  assert.match(retain, /docker run --rm --network none --pull never --read-only/);
  assert.match(retain, /--user 0:0 --cap-drop=ALL --security-opt=no-new-privileges/);
  assert.match(retain, /--mount type=bind,src="\$STAGING",dst=\/staging,readonly/);
  assert.doesNotMatch(retain, /--privileged|--cap-add|docker compose|sudo/);
});

test("asset retention can never block, fail or roll back a release", () => {
  const deploy = read(".github/workflows/deploy.yml");
  const retain = step(deploy, RETAIN_STEP);

  assert.match(retain, /^        continue-on-error: true$/m);
  const timeout = Number(retain.match(/^        timeout-minutes: (\d+)$/m)?.[1]);
  assert.ok(timeout > 0 && timeout <= 10, "the retention step must have a short timeout");
  // No errexit anywhere in the step: every failure becomes a warning and the step still exits 0.
  assert.doesNotMatch(retain, /set -[a-z]*e[a-z]*\b|set -o errexit/);
  assert.match(retain, /if ! ssh -F "\$HUB_SSH_CONFIG" deploy-target 'bash -s' <<'REMOTE'/);
  assert.match(retain, /\n          exit 0\n          REMOTE\n          then\n            echo '::warning title=Hashed asset retention::/);
  assert.match(retain, /\|\| warn 'Retaining, pruning or capping did not fully succeed; the release continues\.'/);
  assert.match(retain, /timeout 120 docker cp/);
  assert.match(retain, /timeout 180 docker run/);
  // The recovery step keys on failure(); a continue-on-error step never reaches it, and the switch
  // step itself carries no retention code that its set -e could trip over.
  assert.doesNotMatch(step(deploy, SWITCH_STEP), /retain/i);
});

test("retained assets are bounded by age and total size", () => {
  const retain = step(read(".github/workflows/deploy.yml"), RETAIN_STEP);

  assert.match(retain, /RETAINED_ASSETS_MAX_AGE_MINUTES=4320\n/);
  assert.match(retain, /RETAINED_ASSETS_MAX_BYTES=524288000\n/);
  assert.match(retain, /' sh "\$RETAINED_ASSETS_MAX_AGE_MINUTES" "\$RETAINED_ASSETS_MAX_BYTES"/);
  assert.match(retain, /find \/retained -type f -mmin \+"\$MAX_AGE_MINUTES" -delete/);
  // Over the cap, the least recently retained files go first.
  assert.match(retain, /if \[ "\$TOTAL" -gt "\$MAX_BYTES" \]; then\n\s+find \/retained -type f -printf "%T@ %s %p\\n" \| sort -n \|/);
  assert.match(retain, /\[ "\$TOTAL" -le "\$MAX_BYTES" \] && break/);
  // Nothing outside the retained directory is ever deleted.
  for (const deletion of retain.match(/[^\n]*(?:-delete|rm -f)[^\n]*/g) ?? []) {
    assert.match(deletion, /\/retained|\$DST|\$FILE"/, `unexpected deletion: ${deletion.trim()}`);
  }
});

test("compose mounts the retained assets read-only into the app container", () => {
  const compose = read("compose.prod.image.yaml");
  const service = compose.split(/^  laravel\.test:\n/m)[1]?.split(/^networks:/m)[0] ?? "";
  const volumes = service.split(/^    volumes:\n/m)[1]?.split(/^    [a-z_]+:/m)[0] ?? "";

  assert.match(volumes, new RegExp(`^      - "${escapeRegExp(HOST_DIR)}:\\/var\\/www\\/retained:ro"$`, "m"));
  assert.doesNotMatch(compose, /\/var\/www\/retained(?!:ro")/);
});

test("nginx falls back to the retained copy only for a hashed asset the image lacks", () => {
  const nginx = read("docker/production/nginx.conf");
  const hashed = nginx.match(/location ~ "\^\/\(\?:build\|daily\)\/assets\/[^"]+" \{\n(?<body>[\s\S]*?)\n        \}/)?.groups?.body;
  const retained = nginx.match(/location @retained_asset \{\n(?<body>[\s\S]*?)\n        \}/)?.groups?.body;

  assert.ok(hashed, "the hashed-asset location must exist");
  assert.ok(retained, "the @retained_asset named location must exist");
  // Current files keep their headers; a miss goes to the retained copy, never to Laravel.
  assert.match(hashed, /add_header Cache-Control "public, max-age=31536000, immutable" always;/);
  assert.match(hashed, /add_header X-Content-Type-Options "nosniff" always;/);
  assert.match(hashed, /try_files \$uri @retained_asset;/);
  assert.doesNotMatch(hashed, /index\.php/);
  // The retained copy: immutable when found, a plain no-store 404 otherwise (also when the mount is absent).
  assert.match(retained, /root \/var\/www\/retained;/);
  assert.match(retained, /try_files \$uri =404;/);
  assert.match(retained, /add_header Cache-Control \$hub_retained_asset_cache_control always;/);
  assert.match(retained, /add_header X-Content-Type-Options "nosniff" always;/);
  const cacheMap = nginx.match(/map \$status \$hub_retained_asset_cache_control \{\n(?<body>[\s\S]*?)\n    \}/)?.groups?.body ?? "";
  assert.match(cacheMap, /200 "public, max-age=31536000, immutable";/);
  assert.match(cacheMap, /304 "public, max-age=31536000, immutable";/);
  assert.match(cacheMap, /default "no-store";/);
});

test("the runbook documents the bounds, verification and how to turn it off", () => {
  const path = "docs/ops/retained-assets.md";
  assert.ok(existsSync(resolve(root, path)), `${path} must exist`);
  const runbook = read(path);
  assert.match(runbook, new RegExp(escapeRegExp(HOST_DIR)));
  assert.match(runbook, /72 hours/);
  assert.match(runbook, /500 MB/);
  assert.match(runbook, /curl -sI https:\/\/www\.mbfdhub\.com\/build\/assets\//);
  assert.match(runbook, /X-Hub-Asset-Source/);
});
