import { defineConfig, devices } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { closeSync, openSync } from 'node:fs';
import { disposableTestAppKey, isolatedSqliteDatabasePath, sanitizedTestEnvironment } from './tests/e2e/support/test-environment';

const database = isolatedSqliteDatabasePath('COMMUNICATIONS_E2E_DATABASE', 'communications_e2e.sqlite');
const baseURL = 'http://127.0.0.1:8026';
process.env.COMMUNICATIONS_E2E_PASSWORD ??= randomBytes(24).toString('base64url');
closeSync(openSync(database, 'a'));
export const communicationsEnvironment = sanitizedTestEnvironment({
  APP_ENV: 'testing', APP_KEY: disposableTestAppKey, APP_URL: baseURL,
  DB_CONNECTION: 'sqlite', DB_DATABASE: database, CACHE_STORE: 'array',
  SESSION_DRIVER: 'file', QUEUE_CONNECTION: 'database', MAIL_MAILER: 'array',
  BCRYPT_ROUNDS: '4',
  FILESYSTEM_DISK: 'local', PRIVATE_FILESYSTEM_DISK: 'local', BROADCAST_DRIVER: 'log',
  COMMUNICATIONS_E2E_PASSWORD: process.env.COMMUNICATIONS_E2E_PASSWORD,
});

export default defineConfig({
  testDir: './tests/e2e', testMatch: /communications\.spec\.ts/,
  globalSetup: './tests/e2e/communications.setup.ts',
  workers: 1, retries: 0, timeout: 60_000, reporter: 'list',
  use: { baseURL, trace: 'retain-on-failure', screenshot: 'only-on-failure', serviceWorkers: 'allow' },
  webServer: {
    command: `php ${process.platform === 'win32' ? '-d extension=sodium ' : ''}artisan serve --host=127.0.0.1 --port=8026`,
    url: baseURL, timeout: 60_000, reuseExistingServer: false, env: communicationsEnvironment,
  },
  projects: [
    { name: 'desktop', use: { viewport: { width: 1440, height: 1000 } } },
    { name: 'mobile', use: { ...devices['iPhone 13'], browserName: 'chromium' } },
  ],
});
