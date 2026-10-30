import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const cwd = dirname(fileURLToPath(import.meta.url));
const command = process.platform === 'win32' ? 'docker.exe' : 'docker';
const config = JSON.parse(execFileSync(command, ['compose', '-f', 'compose.private.yaml', '--profile', '*', 'config', '--format', 'json'], {
  cwd, encoding: 'utf8', env: { ...process.env, STAGING_SECRET_DIR: '/run/mbfd-v2-synthetic-secrets' },
}));
assert.equal(config.name, 'mbfd-v2-synthetic');
assert.equal(Object.keys(config.services).length, 9);
assert.equal(config.networks.isolated.internal, true);
for (const [name, service] of Object.entries(config.services)) {
  assert.match(service.image, /(?:@sha256:|^sha256:)[0-9a-f]{64}$/);
  assert.equal(service.restart, 'no');
  assert.ok(!service.privileged);
  assert.ok(!service.build);
  for (const port of service.ports ?? []) assert.equal(port.host_ip, '127.0.0.1');
  for (const mount of service.volumes ?? []) {
    assert.ok(!/docker\.sock|\/opt\/|\.env$/.test(mount.source));
    if (mount.type === 'bind') assert.equal(mount.read_only, true);
  }
  if (name !== 'tunnel') assert.deepEqual(Object.keys(service.networks), ['isolated']);
}
const tunnel = config.services.tunnel;
assert.equal(tunnel.network_mode, 'none');
assert.deepEqual(tunnel.command, ['--version']);
assert.ok(!tunnel.environment && !tunnel.secrets && !tunnel.volumes);
assert.equal(config.services.command.pull_policy, 'never');
assert.equal(config.services.command.environment.CMD_STT_MODEL_PROVISION_ENABLED, 'false');
assert.equal(config.services.command.environment.CMD_AUTH_MODE, 'hub');
assert.equal(config.services.command.environment.CMD_OIDC_CLIENT_ID, '');
assert.equal(config.services.command.environment.CMD_AI_GATEWAY_URL, 'http://127.0.0.1:1');
assert.equal(config.services.hub.environment.MAIL_MAILER, 'log');
const supervisor = readFileSync(`${cwd}/hub-supervisord.conf`, 'utf8');
assert.deepEqual([...supervisor.matchAll(/^\[program:([^\]]+)\]/gm)].map(m => m[1]), ['php', 'reverb']);
assert.ok(!/queue:work|schedule:run|cron/.test(supervisor.replace(/^#.*$/gm, '')));
for (const secret of Object.values(config.secrets)) assert.ok(secret.file.includes('mbfd-v2-synthetic-secrets'));
console.log('PRIVATE_PACKAGE_STATIC_PASS: 9 pinned declarations; 8 isolated rehearsal services; version-only connector; no production routes, tokens, workers or scheduler.');
