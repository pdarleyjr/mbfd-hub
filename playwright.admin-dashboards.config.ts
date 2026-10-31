import { defineConfig } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { existsSync } from 'node:fs';
import { disposableTestAppKey, isolatedSqliteDatabasePath, localPhpBinary, sanitizedTestEnvironment } from './tests/e2e/support/test-environment';

/*
 * Admin dashboards (Admin Overview 1/3, Vehicle Checkout 2/3, Station Operations 3/3) in real browsers.
 * Disposable SQLite database with synthetic personas (AdminDashboardsE2ESeeder); issue rows come from the
 * test-only fixture sources in tests/Support/Dashboards, added to this run's own config cache by the setup.
 *   npx playwright test -c playwright.admin-dashboards.config.ts
 * Needs a built admin theme (npx vite build). Screenshots go to ADMIN_DASHBOARDS_SCREENS_DIR when set.
 */
if (existsSync('.env') || existsSync('.env.testing')) {
  throw new Error('Admin dashboards acceptance requires an isolated checkout without dotenv credentials.');
}
process.env.ADMIN_DASHBOARDS_E2E_PASSWORD ??= randomBytes(32).toString('base64url');
const database = isolatedSqliteDatabasePath('ADMIN_DASHBOARDS_E2E_DATABASE', 'admin_dashboards_e2e.sqlite');
const port = '8133';
export const adminDashboardsEnvironment = sanitizedTestEnvironment({
  APP_ENV: 'testing', APP_KEY: disposableTestAppKey, APP_URL: `http://127.0.0.1:${port}`,
  // Relative: Laravel reads a Windows drive path as relative and doubles it.
  APP_CONFIG_CACHE: 'bootstrap/cache/admin-dashboards-config.php',
  DB_CONNECTION: 'sqlite', DB_DATABASE: database, CACHE_STORE: 'file',
  BROADCAST_DRIVER: 'log', BROADCAST_CONNECTION: 'log', MAIL_MAILER: 'array',
  FILESYSTEM_DISK: 'local', PRIVATE_FILESYSTEM_DISK: 'local', QUEUE_CONNECTION: 'sync',
  SESSION_DRIVER: 'file', SESSION_SECURE_COOKIE: 'false', PULSE_ENABLED: 'false', TELESCOPE_ENABLED: 'false',
  ADMIN_DASHBOARDS_E2E_PASSWORD: process.env.ADMIN_DASHBOARDS_E2E_PASSWORD,
});

/** Optional local Chromium binary (for sandboxes whose preinstalled browser differs from the Playwright pin). */
export const chromiumExecutable = process.env.ADMIN_DASHBOARDS_CHROMIUM || undefined;

const sizes: Array<[number, number]> = [[320, 640], [360, 780], [390, 844], [430, 932], [768, 1024], [1024, 768], [1366, 768], [1440, 900], [1920, 1080]];

export default defineConfig({
  testDir: './tests/e2e', testMatch: /admin-dashboards\.spec\.ts/,
  globalSetup: './tests/e2e/admin-dashboards.setup.ts', workers: 1, retries: 0, timeout: 90_000,
  outputDir: 'test-results/admin-dashboards', reporter: 'list',
  use: {
    baseURL: `http://127.0.0.1:${port}`, browserName: 'chromium',
    storageState: 'test-results/admin-dashboards-auth/admin.json', serviceWorkers: 'block',
    screenshot: 'only-on-failure', trace: 'retain-on-failure',
    launchOptions: chromiumExecutable ? { executablePath: chromiumExecutable } : {},
  },
  webServer: {
    command: `"${localPhpBinary('ADMIN_DASHBOARDS_E2E_PHP')}" artisan serve --host=127.0.0.1 --port=${port}`, url: `http://127.0.0.1:${port}/login`,
    reuseExistingServer: false, timeout: 90_000, env: adminDashboardsEnvironment,
  },
  projects: sizes.map(([width, height]) => ({
    name: `${width}x${height}`, use: { viewport: { width, height }, hasTouch: width < 1024 },
  })),
});
