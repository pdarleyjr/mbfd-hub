import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');

test('Cloudflare Worker keeps internal exception detail out of API responses', () => {
  const source = read('cloudflare-worker/src/index.ts');
  assert.doesNotMatch(source, /json\(\{\s*error:\s*'Ingest failed',\s*detail:/);
  assert.doesNotMatch(source, /json\(\{\s*error:\s*'Delete failed',\s*detail:/);
});

test('Ollama proxy validates one fixed loopback upstream and relative request targets', () => {
  const source = read('scripts/operations/ollama-ai-proxy.py');
  assert.match(source, /def validate_upstream\(/);
  assert.match(source, /ipaddress\.ip_address\(parsed\.hostname\)\.is_loopback/);
  assert.match(source, /def relative_request_target\(/);
  assert.doesNotMatch(source, /UPSTREAM \+ self\.path/);
});

test('push navigation and messages remain same-origin in source and generated workers', () => {
  for (const file of [
    'resources/js/daily-checkout/inject-push-sw.js',
    'resources/js/daily-checkout/public/service-worker.js',
  ]) {
    const source = read(file);
    assert.match(source, /sameOriginNavigation/);
    assert.match(source, /candidate\.origin !== self\.location\.origin/);
  }
  const worker = read('resources/js/daily-checkout/public/service-worker.js');
  assert.match(worker, /event\.source\?\.url/);
  assert.match(worker, /sourceUrl\.origin !== self\.location\.origin/);
});

test('static-analysis regex and wildcard findings are removed without widening input', () => {
  const app = read('resources/js/app.js');
  assert.match(app, /support\\\.darleyplex\\\.com\(\?:\\\/\|\$\)/);

  const codes = read('vacation-app/packages/shared/src/telestaff-codes.ts');
  assert.doesNotMatch(codes, /\/\^\(LV\|OT\|SP\)/);
  assert.match(codes, /const separator = normalized\.indexOf\('-'\)/);

  const members = read('vacation-app/apps/api/src/routes/members.ts');
  assert.match(members, /q\.replace\(\/\[\\\\%_\]\//);
});

test('service-worker injection uses one opened descriptor instead of exists/read/append races', () => {
  const source = read('resources/js/daily-checkout/inject-push-sw.js');
  assert.match(source, /fs\.openSync\(swPath,\s*'r\+'\)/);
  assert.doesNotMatch(source, /fs\.existsSync\(swPath\)/);
});
