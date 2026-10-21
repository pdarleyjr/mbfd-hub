import { spawnSync, spawn } from 'node:child_process';
import { mkdirSync, mkdtempSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const fixture = resolve(dirname(fileURLToPath(import.meta.url)), 'training-crew-planner-fixture.php');
mkdirSync('test-results', { recursive: true });
const directory = mkdtempSync(resolve('test-results/training-crew-planner-server-'));
const env = { ...process.env, CREW_PLANNER_BROWSER_DIRECTORY: directory };
const php = process.env.PHP_BINARY || 'php';
const seeded = spawnSync(php, [fixture, '--seed'], { env, stdio: 'inherit' });
if (seeded.status !== 0) process.exit(seeded.status ?? 1);
const server = spawn(php, ['-d', 'max_execution_time=120', '-S', '127.0.0.1:8049', fixture], { env, stdio: ['ignore', 'inherit', 'ignore'] });
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => { server.kill(); process.exit(); });
server.on('exit', code => process.exit(code ?? 0));
