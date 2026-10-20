// A new SQLite database per run; the PHP bootstrap clears all external credentials.
import { spawnSync, spawn } from 'node:child_process';
import { mkdirSync, mkdtempSync } from 'node:fs';
import { resolve } from 'node:path';
mkdirSync('test-results', { recursive: true });
const directory = mkdtempSync(resolve('test-results/training-matrix-server-'));
const env = { ...process.env, TRAINING_MATRIX_TEST_DIRECTORY: directory };
const php = process.env.PHP_BINARY || 'php';
const fixture = 'tests/e2e/support/training-matrix-fixture.php';
const seeded = spawnSync(php, [fixture, '--seed'], { env, stdio: 'inherit' });
if (seeded.status !== 0) process.exit(seeded.status ?? 1);
const server = spawn(php, ['-S', '127.0.0.1:8038', '-t', 'public', fixture], { env, stdio: ['ignore', 'inherit', 'ignore'] });
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => { server.kill(); process.exit(); });
server.on('exit', code => process.exit(code ?? 0));
