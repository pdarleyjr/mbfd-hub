#!/usr/bin/env node
// Vendor a built Command Board training sandbox into the Hub.
//
//   node scripts/training/vendor-command-board-sandbox.mjs <dist-training-dir> <source-commit-sha> [--built-at <ISO date>]
//   node scripts/training/vendor-command-board-sandbox.mjs --verify
//
// The build comes from pdarleyjr/mbfd-command (Vite base /training-tools/command-board/). Nothing is written until
// the whole build has passed every check in lib/sandbox-bundle.mjs. Writes only:
//   resources/training-tools/command-board/index.html          (served by the authenticated Hub route)
//   resources/training-tools/command-board/SANDBOX_SOURCE.json (commit, build time, sha256 of every file)
//   public/training-tools/command-board/assets/*               (content-hashed JS/CSS/fonts/images)
// Assets from an earlier vendoring that are not in the new build are removed, and only inside that assets folder.
import { existsSync, mkdirSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

import {
  ASSET_DIRECTORY,
  buildManifest,
  HTML_DIRECTORY,
  MANIFEST_NAME,
  readBuildDirectory,
  validateBuild,
  verifyVendored,
} from './lib/sandbox-bundle.mjs';

const defaultRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

export class VendorError extends Error {
  constructor(problems) {
    super(problems.join('\n'));
    this.problems = problems;
  }
}

/**
 * @param {{distDirectory: string, commit: string, repoRoot?: string, builtAt?: string}} options
 */
export function vendorSandbox({ distDirectory, commit, repoRoot = defaultRoot, builtAt = new Date().toISOString() }) {
  const problems = [];
  if (!/^[0-9a-f]{40}$/.test(commit)) problems.push('commit: must be the full 40-character lowercase source commit SHA');
  if (Number.isNaN(Date.parse(builtAt))) problems.push('built-at: must be a date');

  const build = readBuildDirectory(resolve(distDirectory));
  problems.push(...build.problems);
  if (build.index !== null && build.assets.length > 0) problems.push(...validateBuild(build));
  if (problems.length > 0) throw new VendorError(problems);

  const htmlDirectory = join(repoRoot, HTML_DIRECTORY);
  const assetDirectory = join(repoRoot, ASSET_DIRECTORY);
  mkdirSync(htmlDirectory, { recursive: true });
  mkdirSync(assetDirectory, { recursive: true });

  const keep = new Set(build.assets.map((asset) => asset.name));
  const removed = existsSync(assetDirectory) ? readdirSync(assetDirectory).filter((name) => !keep.has(name)) : [];
  for (const name of removed) rmSync(join(assetDirectory, name), { recursive: true, force: true });

  for (const asset of build.assets) writeFileSync(join(assetDirectory, asset.name), asset.buffer);
  writeFileSync(join(htmlDirectory, 'index.html'), build.index);

  const manifest = buildManifest({ commit, builtAt, index: build.index, assets: build.assets });
  writeFileSync(join(htmlDirectory, MANIFEST_NAME), `${JSON.stringify(manifest, null, 2)}\n`);

  const check = verifyVendored(repoRoot);
  if (check.problems.length > 0) throw new VendorError(check.problems);

  return { manifest, removed };
}

function parseArguments(argv) {
  const positional = [];
  const flags = { verify: false, builtAt: undefined };
  for (let i = 0; i < argv.length; i += 1) {
    if (argv[i] === '--verify') flags.verify = true;
    else if (argv[i] === '--built-at') { flags.builtAt = argv[i + 1]; i += 1; }
    else positional.push(argv[i]);
  }
  return { positional, flags };
}

function main() {
  const { positional, flags } = parseArguments(process.argv.slice(2));

  if (flags.verify) {
    const result = verifyVendored(defaultRoot);
    if (result.problems.length > 0) {
      console.error(`Vendored sandbox is not valid:\n${result.problems.map((problem) => `  ${problem}`).join('\n')}`);
      process.exit(1);
    }
    console.log(result.state === 'absent' ? 'No sandbox bundle is vendored (the route answers 503).' : 'Vendored sandbox bundle verified.');
    return;
  }

  if (positional.length !== 2) {
    console.error('Usage: node scripts/training/vendor-command-board-sandbox.mjs <dist-training-dir> <source-commit-sha> [--built-at <ISO date>]');
    process.exit(2);
  }

  try {
    const { manifest, removed } = vendorSandbox({ distDirectory: positional[0], commit: positional[1], builtAt: flags.builtAt });
    console.log(`Vendored ${manifest.files.length} files from ${manifest.repository}@${manifest.commit.slice(0, 12)}.`);
    if (removed.length > 0) console.log(`Removed ${removed.length} earlier asset(s): ${removed.join(', ')}`);
  } catch (error) {
    if (!(error instanceof VendorError)) throw error;
    console.error(`Refusing to vendor, nothing was written:\n${error.problems.map((problem) => `  ${problem}`).join('\n')}`);
    process.exit(1);
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) main();
