import { defineConfig, devices } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { closeSync, openSync } from 'node:fs';
import {
  disposableTestAppKey,
  isolatedSqliteDatabasePath,
  loopbackBaseUrl,
  sanitizedTestEnvironment,
} from './tests/e2e/support/test-environment';

/*
 * Vehicle changeouts and unit alerts (docs/apparatus-changeouts.md) against the real Laravel API and database.
 *
 * Disposable SQLite database seeded with synthetic personas and trucks only (FleetChangeoutE2ESeeder). The Daily
 * app is the build in public/daily: run `npm run build` in resources/js/daily-checkout first when its source changed.
 * The password is random per run unless FLEET_CHANGEOUT_E2E_PASSWORD is set; nothing is committed.
 *   npm run test:fleet-changeout-e2e
 * Screenshots go to test-results/fleet-changeout-screens/. On Windows PHP needs the sodium extension.
 */
const database = isolatedSqliteDatabasePath('FLEET_CHANGEOUT_E2E_DATABASE', 'fleet_changeout_e2e.sqlite');
const baseURL = loopbackBaseUrl('PLAYWRIGHT_BASE_URL', 'http://127.0.0.1:8046');
const port = new URL(baseURL).port;
process.env.FLEET_CHANGEOUT_E2E_PASSWORD ??= randomBytes(24).toString('base64url');
closeSync(openSync(database, 'a'));

export const fleetChangeoutEnvironment = sanitizedTestEnvironment({
  APP_ENV: 'testing',
  APP_KEY: disposableTestAppKey,
  APP_URL: baseURL,
  APP_CONFIG_CACHE: `${process.cwd()}/bootstrap/cache/fleet-changeout-config.php`,
  BCRYPT_ROUNDS: '4',
  BROADCAST_DRIVER: 'log',
  BROADCAST_CONNECTION: 'log',
  CACHE_STORE: 'array',
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: database,
  FILESYSTEM_DISK: 'local',
  MAIL_MAILER: 'array',
  PRIVATE_FILESYSTEM_DISK: 'local',
  QUEUE_CONNECTION: 'sync',
  SESSION_DRIVER: 'file',
  SESSION_SECURE_COOKIE: 'false',
  FLEET_CHANGEOUT_E2E_PASSWORD: process.env.FLEET_CHANGEOUT_E2E_PASSWORD,
});

export const phpArguments: string[] = ['-d', 'memory_limit=768M', ...(process.platform === 'win32' ? ['-d', 'extension=sodium'] : [])];

export default defineConfig({
  testDir: './tests/e2e',
  testMatch: /(fleet-changeout|fb6-inspection)-real\.spec\.ts/,
  globalSetup: './tests/e2e/fleet-changeout.setup.ts',
  timeout: 180_000,
  retries: 0,
  workers: 1,
  outputDir: 'test-results/fleet-changeout',
  reporter: 'list',
  use: { baseURL, browserName: 'chromium', serviceWorkers: 'block', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
  webServer: {
    command: `php ${phpArguments.join(' ')} artisan serve --host=127.0.0.1 --port=${port}`,
    url: `${baseURL}/login`,
    timeout: 120_000,
    reuseExistingServer: false,
    env: fleetChangeoutEnvironment,
  },
  projects: [
    { name: 'phone', use: { ...devices['iPhone 13'], viewport: { width: 390, height: 844 }, browserName: 'chromium' } },
  ],
});
