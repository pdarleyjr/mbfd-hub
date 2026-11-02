import assert from 'node:assert/strict';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import test from 'node:test';

import {
  ASSET_DIRECTORY,
  FORBIDDEN_MARKERS,
  HTML_DIRECTORY,
  MANIFEST_NAME,
  sha256,
  verifyVendored,
} from '../../scripts/training/lib/sandbox-bundle.mjs';
import { vendorSandbox, VendorError } from '../../scripts/training/vendor-command-board-sandbox.mjs';

/**
 * The Command Board training sandbox is vendored from pdarleyjr/mbfd-command by
 * scripts/training/vendor-command-board-sandbox.mjs. These tests run the real command against a fixture build
 * (tests/Fixtures/training-sandbox/dist) in a throwaway repository root, and check the committed bundle, if any.
 */

const root = resolve(import.meta.dirname, '..', '..');
const fixture = join(root, 'tests', 'Fixtures', 'training-sandbox', 'dist');
const COMMIT = 'a'.repeat(40);
const BUILT_AT = '2026-11-02T12:00:00.000Z';

function sandboxDirectories() {
  const base = mkdtempSync(join(tmpdir(), 'sandbox-vendor-'));
  return { base, dist: join(base, 'dist'), repo: join(base, 'repo') };
}

function freshBuild() {
  const dirs = sandboxDirectories();
  cpSync(fixture, dirs.dist, { recursive: true });
  mkdirSync(dirs.repo, { recursive: true });
  return dirs;
}

function indexPath(dist) {
  return join(dist, 'index.html');
}

function treeOf(directory) {
  if (!existsSync(directory)) return [];
  return readdirSync(directory, { recursive: true }).sort();
}

function vendor(dirs, overrides = {}) {
  return vendorSandbox({ distDirectory: dirs.dist, commit: COMMIT, repoRoot: dirs.repo, builtAt: BUILT_AT, ...overrides });
}

function problemsOf(callback) {
  try {
    callback();
  } catch (error) {
    assert.ok(error instanceof VendorError, `expected a VendorError, got ${error}`);
    return error.problems.join('\n');
  }
  return assert.fail('expected the vendoring to be refused');
}

test('the committed sandbox bundle is either absent or complete and unmodified', () => {
  // Not a skip: with nothing vendored the Hub route answers 503 and no stray file may exist; once the bundle is
  // vendored, every file must match SANDBOX_SOURCE.json and carry none of the forbidden markers.
  const result = verifyVendored(root);

  assert.deepEqual(result.problems, []);
  if (result.state === 'present') {
    const manifest = JSON.parse(readFileSync(join(root, HTML_DIRECTORY, MANIFEST_NAME), 'utf8'));
    assert.equal(manifest.repository, 'pdarleyjr/mbfd-command');
    assert.match(manifest.commit, /^[0-9a-f]{40}$/);
  }
});

test('vendoring the fixture build writes the page, the assets and a manifest with every checksum', () => {
  const dirs = freshBuild();
  try {
    const { manifest } = vendor(dirs);

    assert.deepEqual(manifest.files.map((file) => file.path), [
      'assets/index-B7mT2wQa.css',
      'assets/index-DqK3p9Lx.js',
      'index.html',
    ]);
    assert.equal(manifest.repository, 'pdarleyjr/mbfd-command');
    assert.equal(manifest.commit, COMMIT);
    assert.equal(manifest.built_at, BUILT_AT);
    assert.equal(manifest.base_path, '/training-tools/command-board/');

    const written = JSON.parse(readFileSync(join(dirs.repo, HTML_DIRECTORY, MANIFEST_NAME), 'utf8'));
    assert.deepEqual(written, manifest);
    for (const file of manifest.files) {
      const location = file.path === 'index.html' ? join(dirs.repo, HTML_DIRECTORY, 'index.html') : join(dirs.repo, ASSET_DIRECTORY, file.path.slice(7));
      const bytes = readFileSync(location);
      assert.equal(sha256(bytes), file.sha256, file.path);
      assert.equal(bytes.length, file.bytes, file.path);
    }
    assert.equal(existsSync(join(dirs.repo, ASSET_DIRECTORY, 'index.html')), false, 'the page is never placed under public/');
    assert.deepEqual(verifyVendored(dirs.repo), { state: 'present', problems: [] });
  } finally {
    rmSync(dirs.base, { recursive: true, force: true });
  }
});

test('re-vendoring replaces earlier assets, only inside the sandbox assets folder', () => {
  const dirs = freshBuild();
  try {
    vendor(dirs);
    const neighbour = join(dirs.repo, 'public', 'training-tools', 'command-board', 'keep.txt');
    const outside = join(dirs.repo, 'public', 'other.txt');
    writeFileSync(neighbour, 'not an asset');
    writeFileSync(outside, 'unrelated');
    writeFileSync(join(dirs.repo, ASSET_DIRECTORY, 'old-ZZZZZZZZ.js'), 'console.log(1);\n');

    writeFileSync(join(dirs.dist, 'assets', 'index-DqK3p9Lx.js'), "document.title='second build';\n");
    const { removed } = vendor(dirs);

    assert.deepEqual(removed, ['old-ZZZZZZZZ.js']);
    assert.equal(readFileSync(neighbour, 'utf8'), 'not an asset');
    assert.equal(readFileSync(outside, 'utf8'), 'unrelated');
    assert.deepEqual(verifyVendored(dirs.repo), { state: 'present', problems: [] });
  } finally {
    rmSync(dirs.base, { recursive: true, force: true });
  }
});

test('a build with anything unexpected is refused and nothing is written', () => {
  const cases = {
    'a source map': (dist) => writeFileSync(join(dist, 'assets', 'index-DqK3p9Lx.js.map'), '{}'),
    'a service worker': (dist) => writeFileSync(join(dist, 'assets', 'sw.js'), 'self.addEventListener("fetch",()=>{});\n'),
    'a workbox file': (dist) => writeFileSync(join(dist, 'assets', 'workbox-1a2b3c4d.js'), 'x\n'),
    'a data file': (dist) => writeFileSync(join(dist, 'assets', 'incidents.json'), '[]'),
    'a nested folder': (dist) => mkdirSync(join(dist, 'assets', 'nested')),
    'a stray root file': (dist) => writeFileSync(join(dist, 'favicon.ico'), 'x'),
    'a root manifest': (dist) => writeFileSync(join(dist, 'manifest.webmanifest'), '{}'),
    'a hidden file': (dist) => writeFileSync(join(dist, 'assets', '.env'), 'x'),
    'a missing page': (dist) => rmSync(indexPath(dist)),
    'no assets': (dist) => rmSync(join(dist, 'assets'), { recursive: true }),
  };

  for (const [label, mutate] of Object.entries(cases)) {
    const dirs = freshBuild();
    try {
      mutate(dirs.dist);
      problemsOf(() => vendor(dirs));
      assert.deepEqual(treeOf(dirs.repo), [], `${label}: nothing is written`);
    } finally {
      rmSync(dirs.base, { recursive: true, force: true });
    }
  }
});

test('every forbidden marker in any file refuses the build', () => {
  assert.deepEqual([...FORBIDDEN_MARKERS].sort(), [
    '/api/', 'cmd.mbfdhub.com', 'maps.googleapis.com', 'navigator.serviceWorker', 'new WebSocket(', 'sendBeacon',
  ].sort());

  for (const marker of FORBIDDEN_MARKERS) {
    for (const target of ['assets/index-DqK3p9Lx.js', 'assets/index-B7mT2wQa.css', 'index.html']) {
      const dirs = freshBuild();
      try {
        const file = join(dirs.dist, target);
        const original = readFileSync(file, 'utf8');
        writeFileSync(file, target === 'index.html' ? original.replace('</body>', `<!-- ${marker} --></body>`) : `${original}/* ${marker} */\n`);

        const message = problemsOf(() => vendor(dirs));
        assert.match(message, /forbidden marker/, `${marker} in ${target}`);
        assert.deepEqual(treeOf(dirs.repo), [], `${marker} in ${target}: nothing is written`);
      } finally {
        rmSync(dirs.base, { recursive: true, force: true });
      }
    }
  }
});

test('the page may only load its own hashed assets and may not run inline script', () => {
  const page = readFileSync(join(fixture, 'index.html'), 'utf8');
  const cases = {
    'an inline script': page.replace('</head>', '<script>window.x=1</script></head>'),
    'an outside script': page.replace('/training-tools/command-board/assets/index-DqK3p9Lx.js', 'https://example.test/app.js'),
    'a path outside the base': page.replace('/training-tools/command-board/assets/index-B7mT2wQa.css', '/assets/index-B7mT2wQa.css'),
    'a traversal': page.replace('/assets/index-B7mT2wQa.css', '/assets/../x.css'),
    'a query string': page.replace('index-B7mT2wQa.css', 'index-B7mT2wQa.css?v=1'),
    'a base element': page.replace('</head>', '<base href="/"></head>'),
    'a form': page.replace('</body>', '<form action="/x"></form></body>'),
    'an inline handler': page.replace('<div id="root">', '<div id="root" onclick="x()">'),
    'a missing asset': page.replace('index-B7mT2wQa.css', 'index-MISSING00.css'),
    'no script': page.replace(/<script[^>]*><\/script>/, ''),
  };

  for (const [label, html] of Object.entries(cases)) {
    const dirs = freshBuild();
    try {
      writeFileSync(indexPath(dirs.dist), html);
      problemsOf(() => vendor(dirs));
      assert.deepEqual(treeOf(dirs.repo), [], `${label}: nothing is written`);
    } finally {
      rmSync(dirs.base, { recursive: true, force: true });
    }
  }
});

test('the source commit must be a full lowercase SHA', () => {
  const dirs = freshBuild();
  try {
    for (const commit of ['', 'main', 'abc1234', 'A'.repeat(40), `${'a'.repeat(39)}g`]) {
      assert.match(problemsOf(() => vendor(dirs, { commit })), /commit/);
    }
    assert.deepEqual(treeOf(dirs.repo), []);
  } finally {
    rmSync(dirs.base, { recursive: true, force: true });
  }
});

test('verification catches a changed file, an unlisted file, a missing file and a missing manifest', () => {
  const dirs = freshBuild();
  try {
    vendor(dirs);
    const js = join(dirs.repo, ASSET_DIRECTORY, 'index-DqK3p9Lx.js');
    const original = readFileSync(js);

    writeFileSync(js, `${original.toString('utf8')}// edited\n`);
    assert.match(verifyVendored(dirs.repo).problems.join('\n'), /sha256 does not match/);
    writeFileSync(js, original);
    assert.deepEqual(verifyVendored(dirs.repo).problems, []);

    const extra = join(dirs.repo, ASSET_DIRECTORY, 'extra-AAAAAAAA.js');
    writeFileSync(extra, 'x\n');
    assert.match(verifyVendored(dirs.repo).problems.join('\n'), /not listed in the manifest/);
    rmSync(extra);

    writeFileSync(join(dirs.repo, ASSET_DIRECTORY, 'index-DqK3p9Lx.js.map'), '{}');
    assert.match(verifyVendored(dirs.repo).problems.join('\n'), /source maps are not allowed/);
    rmSync(join(dirs.repo, ASSET_DIRECTORY, 'index-DqK3p9Lx.js.map'));

    writeFileSync(js, `${original.toString('utf8')}// sendBeacon\n`);
    const manifestPath = join(dirs.repo, HTML_DIRECTORY, MANIFEST_NAME);
    const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
    const entry = manifest.files.find((file) => file.path.endsWith('.js'));
    entry.sha256 = sha256(readFileSync(js));
    entry.bytes = readFileSync(js).length;
    writeFileSync(manifestPath, JSON.stringify(manifest));
    assert.match(verifyVendored(dirs.repo).problems.join('\n'), /forbidden marker/, 'a consistent manifest cannot hide a marker');
    writeFileSync(js, original);

    rmSync(js);
    assert.match(verifyVendored(dirs.repo).problems.join('\n'), /listed in the manifest but missing/);

    rmSync(manifestPath);
    assert.match(verifyVendored(dirs.repo).problems.join('\n'), /SANDBOX_SOURCE\.json: missing/);
  } finally {
    rmSync(dirs.base, { recursive: true, force: true });
  }
});
