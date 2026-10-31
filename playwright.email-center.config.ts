import { defineConfig, devices } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { closeSync, openSync } from 'node:fs';
import { disposableTestAppKey, isolatedSqliteDatabasePath, sanitizedTestEnvironment } from './tests/e2e/support/test-environment';

// The Email Center (/admin/email) in a real browser, on a disposable SQLite database and its own port. Nothing here
// reaches Cloudflare or any other service: sending is exercised up to the point the Hub itself refuses or pauses it,
// and the accepted path is covered by the PHP suite with the provider faked.
const database = isolatedSqliteDatabasePath('EMAIL_CENTER_E2E_DATABASE', 'email_center_e2e.sqlite');
const baseURL = 'http://127.0.0.1:8029';
process.env.EMAIL_CENTER_E2E_PASSWORD ??= randomBytes(24).toString('base64url');
closeSync(openSync(database, 'a'));
export const emailCenterEnvironment = sanitizedTestEnvironment({
  APP_ENV: 'testing', APP_KEY: disposableTestAppKey, APP_URL: baseURL,
  DB_CONNECTION: 'sqlite', DB_DATABASE: database, CACHE_STORE: 'array',
  SESSION_DRIVER: 'file', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array',
  BCRYPT_ROUNDS: '4',
  FILESYSTEM_DISK: 'local', PRIVATE_FILESYSTEM_DISK: 'local', BROADCAST_DRIVER: 'log',
  EMAIL_CENTER_E2E_PASSWORD: process.env.EMAIL_CENTER_E2E_PASSWORD,
});

const desktop = { ...devices['Desktop Chrome'] };

export default defineConfig({
  testDir: './tests/e2e', testMatch: /email-center\.spec\.ts/,
  globalSetup: './tests/e2e/email-center.setup.ts',
  workers: 1, retries: 0, timeout: 90_000, reporter: 'list',
  expect: { timeout: 10_000 },
  use: { baseURL, trace: 'retain-on-failure', screenshot: 'only-on-failure', serviceWorkers: 'allow' },
  webServer: {
    command: `php ${process.platform === 'win32' ? '-d extension=sodium ' : ''}artisan serve --host=127.0.0.1 --port=8029`,
    url: baseURL, timeout: 60_000, reuseExistingServer: false, env: emailCenterEnvironment,
  },
  projects: [
    { name: 'w320', use: { ...desktop, viewport: { width: 320, height: 640 }, hasTouch: true } },
    { name: 'w360', use: { ...desktop, viewport: { width: 360, height: 740 }, hasTouch: true } },
    { name: 'w390', use: { ...devices['iPhone 13'], browserName: 'chromium' } },
    { name: 'w768', use: { ...desktop, viewport: { width: 768, height: 1024 } } },
    { name: 'w1024', use: { ...desktop, viewport: { width: 1024, height: 768 } } },
    { name: 'w1440', use: { ...desktop, viewport: { width: 1440, height: 1000 } } },
    { name: 'landscape-phone', use: { ...desktop, viewport: { width: 740, height: 360 }, hasTouch: true } },
  ],
});
