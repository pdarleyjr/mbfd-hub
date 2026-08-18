import { defineConfig, devices } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { closeSync, openSync } from 'node:fs';
import { resolve } from 'node:path';

const database = process.env.PERSONNEL_REQUESTS_E2E_DATABASE ?? resolve(process.cwd(), 'database/personnel_requests_e2e.sqlite');
const appKey = process.env.APP_KEY ?? 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
for (const name of [
  'PERSONNEL_REQUESTS_E2E_ADMIN_PASSWORD',
  'PERSONNEL_REQUESTS_E2E_OFFICER_PASSWORD',
  'PERSONNEL_REQUESTS_E2E_MEMBER_PASSWORD',
]) {
  process.env[name] ??= randomBytes(24).toString('base64url');
}
closeSync(openSync(database, 'a'));

export default defineConfig({
  testDir: './tests/e2e',
  testMatch: /personnel-requests\.spec\.ts/,
  globalSetup: './tests/e2e/personnel-requests.setup.ts',
  timeout: 60_000,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8018',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: process.env.PERSONNEL_REQUESTS_E2E_SERVER_COMMAND ?? 'php artisan serve --host=127.0.0.1 --port=8018',
    url: process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8018',
    timeout: 60_000,
    reuseExistingServer: false,
    env: {
      ...process.env,
      APP_ENV: 'testing',
      APP_KEY: appKey,
      DB_CONNECTION: 'sqlite',
      DB_DATABASE: database,
      QUEUE_CONNECTION: 'sync',
      SESSION_DRIVER: 'file',
    },
  },
  projects: [
    { name: 'phone-touch', use: { ...devices['iPhone 13'], browserName: 'chromium' } },
    { name: 'tablet-touch', use: { browserName: 'chromium', viewport: { width: 820, height: 1180 }, hasTouch: true } },
    { name: 'desktop', use: { browserName: 'chromium', viewport: { width: 1440, height: 1000 } } },
  ],
});
