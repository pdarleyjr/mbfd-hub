import { spawnSync, spawn } from 'node:child_process';
import { mkdirSync, mkdtempSync } from 'node:fs';
import { resolve } from 'node:path';

mkdirSync('test-results', { recursive: true });
const directory = mkdtempSync(resolve('test-results/responsive-tables-server-'));
const env = { ...process.env, RESPONSIVE_TABLES_TEST_DIRECTORY: directory };
const php = process.env.PHP_BINARY || 'php';
// Bound cold Filament compilation on the Windows test host; production settings are untouched.
const options = [...(process.platform === 'win32' ? ['-d', 'extension=php_sodium.dll'] : []), '-d', 'max_execution_time=120', '-d', 'opcache.enable_cli=1'];
const fixture = 'tests/e2e/support/responsive-tables-fixture.php';
const seeded = spawnSync(php, [...options, fixture, '--seed'], { env, stdio: 'inherit' });
if (seeded.status !== 0) process.exit(seeded.status ?? 1);
const server = spawn(php, [...options, '-S', '127.0.0.1:8161', '-t', 'public', fixture], { env, stdio: ['ignore', 'inherit', 'ignore'] });
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => { server.kill(); process.exit(); });
server.on('exit', code => process.exit(code ?? 0));
