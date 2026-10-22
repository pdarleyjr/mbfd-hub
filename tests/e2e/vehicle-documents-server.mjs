import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const sourceRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const script = fileURLToPath(import.meta.url);

export default {
    testDir: resolve(sourceRoot, 'tests/e2e'),
    testMatch: /vehicle-documents\.spec\.ts/,
    outputDir: resolve(sourceRoot, 'test-results/vehicle-documents-browser'),
    timeout: 300_000,
    expect: { timeout: 60_000 },
    retries: 0,
    workers: 1,
    reporter: 'list',
    use: { baseURL: 'http://127.0.0.1:8053', serviceWorkers: 'block', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
    projects: [
        { name: 'desktop', use: { viewport: { width: 1440, height: 900 } } },
        { name: 'tablet', use: { viewport: { width: 820, height: 1180 }, hasTouch: true } },
        { name: 'phone', use: { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } },
    ],
    webServer: {
        command: `node "${script}" --serve`,
        cwd: sourceRoot,
        url: 'http://127.0.0.1:8053/login',
        reuseExistingServer: false,
        timeout: 300_000,
    },
};

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href && process.argv.includes('--serve')) {
    mkdirSync(resolve(sourceRoot, 'test-results'), { recursive: true });
    const directory = mkdtempSync(resolve(sourceRoot, 'test-results/vehicle-documents-server-'));
    const env = { ...process.env, VEHICLE_DOCUMENTS_BROWSER_DIRECTORY: directory };
    const fixture = resolve(sourceRoot, 'tests/e2e/vehicle-documents-fixture.php');
    const php = process.env.PHP_BINARY || 'php';
    const opcache = resolve(dirname(php), 'ext/php_opcache.dll');
    const options = [...(process.platform === 'win32' ? ['-d', 'extension=php_sodium.dll', ...(existsSync(opcache) ? ['-d', `zend_extension=${opcache}`] : [])] : []), '-d', 'max_execution_time=180', '-d', 'opcache.enable_cli=1'];
    const seeded = spawnSync(php, [...options, fixture, '--seed'], { cwd: sourceRoot, env, stdio: 'inherit' });
    if (seeded.status !== 0) process.exit(seeded.status ?? 1);
    if (!existsSync(resolve(directory, 'fixture.json'))) throw new Error('The disposable fixture did not finish seeding.');
    writeFileSync(resolve(sourceRoot, 'test-results/vehicle-documents-fixture.json'), JSON.stringify({ directory }, null, 2));
    const server = spawn(php, [...options, '-S', '127.0.0.1:8053', fixture], { cwd: sourceRoot, env, stdio: ['ignore', 'inherit', 'inherit'] });
    for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => { server.kill(); process.exit(); });
    server.on('exit', code => process.exit(code ?? 0));
}
