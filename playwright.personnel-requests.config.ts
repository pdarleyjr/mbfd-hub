import { defineConfig, devices } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { closeSync, openSync } from 'node:fs';
import {
  disposableTestAppKey,
  environmentValue,
  isolatedSqliteDatabasePath,
  loopbackBaseUrl,
  sanitizedTestEnvironment,
} from './tests/e2e/support/test-environment';

const database = isolatedSqliteDatabasePath('PERSONNEL_REQUESTS_E2E_DATABASE', 'personnel_requests_e2e.sqlite');
const baseURL = loopbackBaseUrl('PLAYWRIGHT_BASE_URL', 'http://127.0.0.1:8018');
const personnelRequestPasswords: Record<string, string> = {};
for (const name of [
  'PERSONNEL_REQUESTS_E2E_ADMIN_PASSWORD',
  'PERSONNEL_REQUESTS_E2E_OFFICER_PASSWORD',
  'PERSONNEL_REQUESTS_E2E_MEMBER_PASSWORD',
]) {
  const password = environmentValue(name) ?? randomBytes(24).toString('base64url');
  process.env[name] = password;
  personnelRequestPasswords[name] = password;
}
closeSync(openSync(database, 'a'));

const webServerEnvironment = sanitizedTestEnvironment({
  APP_ENV: 'testing',
  APP_KEY: disposableTestAppKey,
  APP_URL: baseURL,
  BROADCAST_DRIVER: 'log',
  CACHE_STORE: 'array',
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: database,
  FILESYSTEM_DISK: 'local',
  MAIL_MAILER: 'array',
  PRIVATE_FILESYSTEM_DISK: 'local',
  QUEUE_CONNECTION: 'sync',
  SESSION_DRIVER: 'file',
  ...personnelRequestPasswords,
});

export default defineConfig({
  testDir: './tests/e2e',
  testMatch: /personnel-requests\.spec\.ts/,
  globalSetup: './tests/e2e/personnel-requests.setup.ts',
  timeout: 60_000,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: 'php artisan serve --host=127.0.0.1 --port=8018',
    url: baseURL,
    timeout: 60_000,
    reuseExistingServer: false,
    env: webServerEnvironment,
  },
  projects: [
    { name: 'phone-touch', use: { ...devices['iPhone 13'], browserName: 'chromium' } },
    { name: 'tablet-touch', use: { browserName: 'chromium', viewport: { width: 820, height: 1180 }, hasTouch: true } },
    { name: 'desktop', use: { browserName: 'chromium', viewport: { width: 1440, height: 1000 } } },
  ],
});
