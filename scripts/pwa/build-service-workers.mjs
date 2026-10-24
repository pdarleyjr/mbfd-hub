#!/usr/bin/env node
/**
 * Inline the shared service-worker core into every worker script that ships it.
 *
 *   node scripts/pwa/build-service-workers.mjs          rewrite the inlined blocks
 *   node scripts/pwa/build-service-workers.mjs --check  exit 1 if any block differs
 *
 * The output is committed (workers must be plain static files at frozen URLs),
 * deterministic (no timestamps, no hashes of the environment) and idempotent.
 * Nothing but the text between the two marker comments is ever touched.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

export const START_MARKER = '/* MBFD_WORKER_CORE:START */';
export const END_MARKER = '/* MBFD_WORKER_CORE:END */';
export const CORE_PATH = 'resources/js/pwa/worker-core.js';
export const TARGETS = ['public/sw.js', 'public/admin-pwa/service-worker.js', 'resources/js/daily-checkout/public/service-worker.js'];
export const REPOSITORY_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

const GENERATED_NOTICE = `// GENERATED from ${CORE_PATH} by scripts/pwa/build-service-workers.mjs.\n// Do not edit between the markers; edit the core and run \`npm run build:service-workers\`.`;

const toLf = (text) => text.replace(/\r\n/g, '\n');

function occurrences(text, needle) {
  return text.split(needle).length - 1;
}

/** Validate the core source: it is pasted into classic scripts, so it must stay classic-safe. */
export function assertCoreIsInlinable(core) {
  const problems = [];

  if (core.includes(START_MARKER) || core.includes(END_MARKER)) {
    problems.push('the core must not contain the inline markers');
  }
  if (/^\s*(import|export)\s/m.test(core)) {
    problems.push('the core must not use import/export (classic script)');
  }
  if (/\bimportScripts\s*\(/.test(core.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, ''))) {
    problems.push('the core must not call importScripts');
  }
  if (!/const MBFD_WORKER_CORE_VERSION = '[^']+';/.test(core)) {
    problems.push('the core must declare const MBFD_WORKER_CORE_VERSION');
  }
  if (problems.length > 0) {
    throw new Error(`worker-core.js cannot be inlined: ${problems.join('; ')}`);
  }
}

/** Return the worker source with the marker block replaced by the (normalized) core. */
export function renderWorker(workerSource, coreSource, label = 'worker') {
  const worker = toLf(workerSource);
  const core = toLf(coreSource).trimEnd();
  assertCoreIsInlinable(core);

  if (occurrences(worker, START_MARKER) !== 1 || occurrences(worker, END_MARKER) !== 1) {
    throw new Error(`${label} must contain exactly one ${START_MARKER} and one ${END_MARKER}`);
  }
  const start = worker.indexOf(START_MARKER);
  const end = worker.indexOf(END_MARKER);
  if (start > end) {
    throw new Error(`${label} has its markers in the wrong order`);
  }

  return `${worker.slice(0, start)}${START_MARKER}\n${GENERATED_NOTICE}\n${core}\n${worker.slice(end)}`;
}

export function loadCore(root = REPOSITORY_ROOT) {
  return readFileSync(resolve(root, CORE_PATH), 'utf8');
}

/** Compare every target with what the build would write. Returns the drifted paths. */
export function findDrift(root = REPOSITORY_ROOT) {
  const core = loadCore(root);

  return TARGETS.filter((target) => {
    const current = readFileSync(resolve(root, target), 'utf8');

    return toLf(current) !== renderWorker(current, core, target);
  });
}

export function writeTargets(root = REPOSITORY_ROOT) {
  const core = loadCore(root);
  const changed = [];

  for (const target of TARGETS) {
    const path = resolve(root, target);
    const current = readFileSync(path, 'utf8');
    const next = renderWorker(current, core, target);

    if (toLf(current) !== next) {
      writeFileSync(path, next, 'utf8');
      changed.push(target);
    }
  }

  return changed;
}

function main(args) {
  if (args.includes('--check')) {
    const drift = findDrift();
    if (drift.length > 0) {
      console.error(`Inlined service-worker core is out of date in: ${drift.join(', ')}`);
      console.error('Run `npm run build:service-workers` and commit the result.');
      process.exitCode = 1;

      return;
    }
    console.log('Service-worker core is in sync with all workers.');

    return;
  }

  const changed = writeTargets();
  console.log(changed.length > 0 ? `Updated: ${changed.join(', ')}` : 'Service workers already up to date.');
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main(process.argv.slice(2));
}
