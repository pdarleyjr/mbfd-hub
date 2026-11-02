// Shared rules for the Command Board training sandbox bundle that the Hub hosts.
//
// The sandbox is built in pdarleyjr/mbfd-command as a static, network-free single page. The Hub vendors the build
// output unchanged: index.html under resources/ (served only by the authenticated route) and the content-hashed
// assets under public/. This module is the one place that says what a valid bundle is, so the vendoring command, the
// repository test, and the PHP route (App\Support\Training\CommandBoardSandboxBundle) agree.
import { createHash } from 'node:crypto';
import { existsSync, lstatSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

export const BASE_PATH = '/training-tools/command-board/';
export const ASSET_BASE_PATH = `${BASE_PATH}assets/`;
export const HTML_DIRECTORY = 'resources/training-tools/command-board';
export const ASSET_DIRECTORY = 'public/training-tools/command-board/assets';
export const MANIFEST_NAME = 'SANDBOX_SOURCE.json';
export const SOURCE_REPOSITORY = 'pdarleyjr/mbfd-command';

/** Extensions a static Vite build of the sandbox may emit under assets/. No data files, no source maps. */
export const ALLOWED_ASSET_EXTENSIONS = new Set([
  'js', 'css', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf',
]);

/** Files that are read as text and scanned for forbidden markers. */
const TEXT_EXTENSIONS = new Set(['js', 'css', 'svg', 'html']);

/** Anything that would let the sandbox reach a network, a map service, or the live Command Board. */
export const FORBIDDEN_MARKERS = [
  '/api/',
  'new WebSocket(',
  'sendBeacon',
  'maps.googleapis.com',
  'cmd.mbfdhub.com',
  'navigator.serviceWorker',
];

const SAFE_FILE_NAME = /^[A-Za-z0-9][A-Za-z0-9._-]*$/;
const SERVICE_WORKER_NAME = /(?:^|[-_.])(?:sw|service-?worker|workbox|registersw)(?:[-_.]|$)/i;
const SHA256 = /^[0-9a-f]{64}$/;

export function sha256(buffer) {
  return createHash('sha256').update(buffer).digest('hex');
}

function extensionOf(name) {
  const dot = name.lastIndexOf('.');
  return dot === -1 ? '' : name.slice(dot + 1).toLowerCase();
}

/** Problems with one file name under assets/. Empty when the name is acceptable. */
export function assetNameProblems(name) {
  const problems = [];
  if (!SAFE_FILE_NAME.test(name)) problems.push(`${name}: file name has unexpected characters`);
  if (name.toLowerCase().endsWith('.map')) problems.push(`${name}: source maps are not allowed`);
  if (SERVICE_WORKER_NAME.test(name)) problems.push(`${name}: service worker files are not allowed`);
  if (!ALLOWED_ASSET_EXTENSIONS.has(extensionOf(name))) problems.push(`${name}: file type is not allowed`);
  return problems;
}

/** Forbidden markers found in one file's text. */
export function markerProblems(label, buffer, extension) {
  if (!TEXT_EXTENSIONS.has(extension)) return [];
  const text = buffer.toString('utf8');
  return FORBIDDEN_MARKERS.filter((marker) => text.includes(marker)).map((marker) => `${label}: contains forbidden marker ${JSON.stringify(marker)}`);
}

/**
 * What the page may load. The route serves it with a script-src 'self' policy, so an inline script would simply not
 * run; reject it here so the failure is found when vendoring, not in a classroom.
 *
 * @returns {{problems: string[], referencedAssets: string[]}}
 */
export function inspectIndexHtml(html) {
  const problems = [];
  const referenced = new Set();
  const text = html.toString('utf8');

  const reject = (pattern, message) => { if (pattern.test(text)) problems.push(`index.html: ${message}`); };
  reject(/<base[\s>]/i, 'must not set a <base> element');
  reject(/<(?:iframe|object|embed|form|frame)[\s>]/i, 'must not contain frames, embeds, or forms');
  reject(/<meta[^>]+http-equiv/i, 'must not use <meta http-equiv>');
  reject(/\son[a-z]+\s*=/i, 'must not use inline event handler attributes');

  const scripts = [...text.matchAll(/<script\b([^>]*)>/gi)];
  if (scripts.length === 0) problems.push('index.html: has no script tag');
  for (const [, attributes] of scripts) {
    const src = /\ssrc\s*=\s*["']([^"']*)["']/i.exec(` ${attributes}`)?.[1];
    if (src === undefined) problems.push('index.html: inline <script> is not allowed');
    else referenced.add(src);
  }
  for (const [, attributes] of text.matchAll(/<link\b([^>]*)>/gi)) {
    const href = /\shref\s*=\s*["']([^"']*)["']/i.exec(` ${attributes}`)?.[1];
    if (href !== undefined && !href.startsWith('data:')) referenced.add(href);
  }

  const assets = [];
  for (const reference of referenced) {
    if (!reference.startsWith(ASSET_BASE_PATH) || reference.includes('..') || /[?#]/.test(reference)) {
      problems.push(`index.html: reference ${JSON.stringify(reference)} must be a plain path under ${ASSET_BASE_PATH}`);
    } else {
      assets.push(reference.slice(ASSET_BASE_PATH.length));
    }
  }

  return { problems, referencedAssets: assets.sort() };
}

/**
 * Read a Vite build directory (index.html + flat assets/) without writing anything.
 *
 * @returns {{problems: string[], index: Buffer|null, assets: {name: string, buffer: Buffer}[]}}
 */
export function readBuildDirectory(directory) {
  const problems = [];
  const assets = [];
  let index = null;

  if (!existsSync(directory) || !lstatSync(directory).isDirectory()) {
    return { problems: [`${directory}: build directory does not exist`], index, assets };
  }

  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    if (entry.name === 'index.html' && entry.isFile()) index = readFileSync(join(directory, entry.name));
    else if (entry.name === 'assets' && entry.isDirectory()) continue;
    else problems.push(`${entry.name}: unexpected entry at the top of the build (only index.html and assets/ are allowed)`);
  }
  if (index === null) problems.push('index.html: missing from the build');

  const assetsDirectory = join(directory, 'assets');
  if (!existsSync(assetsDirectory)) {
    problems.push('assets/: missing from the build');
    return { problems, index, assets };
  }
  for (const entry of readdirSync(assetsDirectory, { withFileTypes: true })) {
    if (!entry.isFile() || lstatSync(join(assetsDirectory, entry.name)).isSymbolicLink()) {
      problems.push(`assets/${entry.name}: only plain files are allowed under assets/`);
      continue;
    }
    problems.push(...assetNameProblems(entry.name).map((problem) => `assets/${problem}`));
    assets.push({ name: entry.name, buffer: readFileSync(join(assetsDirectory, entry.name)) });
  }
  if (assets.length === 0) problems.push('assets/: has no files');

  return { problems, index, assets };
}

/** Every rule that depends only on the build's own bytes. */
export function validateBuild({ index, assets }) {
  const problems = [];
  if (index !== null) {
    const inspected = inspectIndexHtml(index);
    problems.push(...inspected.problems, ...markerProblems('index.html', index, 'html'));
    const present = new Set(assets.map((asset) => asset.name));
    for (const name of inspected.referencedAssets) {
      if (!present.has(name)) problems.push(`index.html: refers to assets/${name}, which is not in the build`);
    }
  }
  for (const asset of assets) {
    problems.push(...markerProblems(`assets/${asset.name}`, asset.buffer, extensionOf(asset.name)));
  }
  return problems;
}

export function buildManifest({ commit, builtAt, index, assets }) {
  const files = [
    { path: 'index.html', sha256: sha256(index), bytes: index.length },
    ...assets.map((asset) => ({ path: `assets/${asset.name}`, sha256: sha256(asset.buffer), bytes: asset.buffer.length })),
  ].sort((a, b) => (a.path < b.path ? -1 : 1));

  return {
    repository: SOURCE_REPOSITORY,
    commit,
    built_at: builtAt,
    base_path: BASE_PATH,
    files,
  };
}

function listFiles(directory) {
  if (!existsSync(directory)) return [];
  return readdirSync(directory, { withFileTypes: true }).map((entry) => ({ name: entry.name, file: entry.isFile() }));
}

/**
 * Check what is vendored in a repository checkout.
 *
 * "absent" is a legitimate state (the route then answers 503). Anything in between, such as an index.html with no
 * manifest or an asset the manifest does not list, is a problem.
 *
 * @returns {{state: 'absent'|'present', problems: string[]}}
 */
export function verifyVendored(repoRoot) {
  const htmlDirectory = join(repoRoot, HTML_DIRECTORY);
  const assetDirectory = join(repoRoot, ASSET_DIRECTORY);
  const manifestPath = join(htmlDirectory, MANIFEST_NAME);

  const htmlEntries = listFiles(htmlDirectory);
  const assetEntries = listFiles(assetDirectory);
  if (htmlEntries.length === 0 && assetEntries.length === 0) return { state: 'absent', problems: [] };

  const problems = [];
  if (!existsSync(manifestPath)) return { state: 'present', problems: [`${MANIFEST_NAME}: missing while sandbox files are vendored`] };

  let manifest;
  try {
    manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
  } catch (error) {
    return { state: 'present', problems: [`${MANIFEST_NAME}: is not valid JSON (${error.message})`] };
  }

  if (manifest.repository !== SOURCE_REPOSITORY) problems.push(`${MANIFEST_NAME}: repository must be ${SOURCE_REPOSITORY}`);
  if (typeof manifest.commit !== 'string' || !/^[0-9a-f]{40}$/.test(manifest.commit)) problems.push(`${MANIFEST_NAME}: commit must be a full 40-character lowercase SHA`);
  if (typeof manifest.built_at !== 'string' || Number.isNaN(Date.parse(manifest.built_at))) problems.push(`${MANIFEST_NAME}: built_at must be a date`);
  if (!Array.isArray(manifest.files) || manifest.files.length === 0) {
    return { state: 'present', problems: [...problems, `${MANIFEST_NAME}: files must list the vendored files`] };
  }

  const listed = new Set();
  let index = null;
  const assets = [];
  for (const file of manifest.files) {
    const path = typeof file?.path === 'string' ? file.path : '';
    const inAssets = path.startsWith('assets/') && !path.slice(7).includes('/');
    if (path !== 'index.html' && !inAssets) { problems.push(`${MANIFEST_NAME}: ${JSON.stringify(path)} is not index.html or a file directly under assets/`); continue; }
    if (listed.has(path)) { problems.push(`${MANIFEST_NAME}: ${path} is listed twice`); continue; }
    listed.add(path);

    const location = path === 'index.html' ? join(htmlDirectory, path) : join(assetDirectory, path.slice(7));
    if (!existsSync(location)) { problems.push(`${path}: listed in the manifest but missing`); continue; }
    const buffer = readFileSync(location);
    if (!SHA256.test(String(file.sha256)) || sha256(buffer) !== file.sha256) problems.push(`${path}: sha256 does not match the manifest`);
    if (file.bytes !== buffer.length) problems.push(`${path}: size does not match the manifest`);
    if (path === 'index.html') index = buffer;
    else {
      problems.push(...assetNameProblems(path.slice(7)).map((problem) => `assets/${problem}`));
      assets.push({ name: path.slice(7), buffer });
    }
  }

  for (const entry of htmlEntries) {
    if (entry.name !== 'index.html' && entry.name !== MANIFEST_NAME) problems.push(`${HTML_DIRECTORY}/${entry.name}: not part of the vendored bundle`);
  }
  for (const entry of assetEntries) {
    if (!entry.file || !listed.has(`assets/${entry.name}`)) {
      problems.push(`assets/${entry.name}: present but not listed in the manifest`, ...assetNameProblems(entry.name).map((problem) => `assets/${problem}`));
    }
  }
  if (index === null) problems.push('index.html: not listed or missing');
  else problems.push(...validateBuild({ index, assets }));

  return { state: 'present', problems };
}
