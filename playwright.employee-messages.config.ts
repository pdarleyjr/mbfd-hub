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
 * Employee <-> Logistics messaging acceptance (docs/employee-logistics-messaging.md), in real browsers.
 *
 * Disposable SQLite database seeded with synthetic personas only (EmployeeMessagesE2ESeeder). The
 * password is random per run unless EMPLOYEE_MESSAGES_E2E_PASSWORD is set; nothing is committed.
 *   npm run test:employee-messages-e2e
 * On Windows PHP needs the sodium extension; this config passes `-d extension=sodium`.
 */
const database = isolatedSqliteDatabasePath('EMPLOYEE_MESSAGES_E2E_DATABASE', 'employee_messages_e2e.sqlite');
const baseURL = loopbackBaseUrl('PLAYWRIGHT_BASE_URL', 'http://127.0.0.1:8044');
const port = new URL(baseURL).port;
process.env.EMPLOYEE_MESSAGES_E2E_PASSWORD ??= randomBytes(24).toString('base64url');
closeSync(openSync(database, 'a'));

export const employeeMessagesEnvironment = sanitizedTestEnvironment({
  APP_ENV: 'testing',
  APP_KEY: disposableTestAppKey,
  APP_URL: baseURL,
  APP_CONFIG_CACHE: `${process.cwd()}/bootstrap/cache/employee-messages-config.php`,
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
  EMPLOYEE_MESSAGES_E2E_PASSWORD: process.env.EMPLOYEE_MESSAGES_E2E_PASSWORD,
});

export const phpExtensionArguments: string[] = process.platform === 'win32' ? ['-d', 'extension=sodium'] : [];

export default defineConfig({
  testDir: './tests/e2e',
  testMatch: /employee-messages\.spec\.ts/,
  globalSetup: './tests/e2e/employee-messages.setup.ts',
  timeout: 90_000,
  retries: 0,
  workers: 1,
  outputDir: 'test-results/employee-messages',
  reporter: 'list',
  use: { baseURL, browserName: 'chromium', serviceWorkers: 'allow', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
  webServer: {
    command: `php ${phpExtensionArguments.join(' ')} artisan serve --host=127.0.0.1 --port=${port}`,
    url: `${baseURL}/login`,
    timeout: 90_000,
    reuseExistingServer: false,
    env: employeeMessagesEnvironment,
  },
  projects: [
    { name: 'desktop', use: { viewport: { width: 1440, height: 900 } } },
    { name: 'mobile', use: { ...devices['iPhone 13'], viewport: { width: 375, height: 812 }, browserName: 'chromium' } },
  ],
});
