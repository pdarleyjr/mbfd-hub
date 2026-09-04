import { defineConfig, devices } from '@playwright/test';
import { closeSync, openSync } from 'node:fs';
import {
  disposableTestAppKey,
  isolatedSqliteDatabasePath,
  loopbackBaseUrl,
  sanitizedTestEnvironment,
} from './tests/e2e/support/test-environment';

const e2eDatabase = isolatedSqliteDatabasePath('OPERATIONAL_FORMS_E2E_DATABASE', 'operational_forms_e2e.sqlite');
const baseURL = loopbackBaseUrl('PLAYWRIGHT_BASE_URL', 'http://127.0.0.1:8017');
closeSync(openSync(e2eDatabase, 'a'));

const webServerEnvironment = sanitizedTestEnvironment({
  APP_ENV: 'testing',
  APP_KEY: disposableTestAppKey,
  APP_URL: baseURL,
  BROADCAST_DRIVER: 'log',
  CACHE_STORE: 'array',
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: e2eDatabase,
  FILESYSTEM_DISK: 'local',
  FROC_IMPORT_FORCE_FALLBACK: 'true',
  MAIL_MAILER: 'array',
  PRIVATE_FILESYSTEM_DISK: 'local',
  QUEUE_CONNECTION: 'sync',
  SESSION_DRIVER: 'file',
});

export default defineConfig({
  testDir: './tests/e2e',
  testMatch: /operational-forms\.spec\.ts/,
  globalSetup: './tests/e2e/operational-forms.setup.ts',
  timeout: 90_000,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL,
    viewport: { width: 1440, height: 1000 },
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: 'php artisan serve --host=127.0.0.1 --port=8017',
    url: baseURL,
    timeout: 60_000,
    reuseExistingServer: false,
    env: webServerEnvironment,
  },
  projects: [
    { name: 'phone-small', use: { browserName: 'chromium', viewport: { width: 320, height: 568 }, hasTouch: true, isMobile: true } },
    { name: 'phone', use: { ...devices['iPhone 13'], browserName: 'chromium' } },
    { name: 'phone-large', use: { browserName: 'chromium', viewport: { width: 430, height: 932 }, hasTouch: true, isMobile: true } },
    { name: 'tablet-portrait', use: { viewport: { width: 820, height: 1180 }, hasTouch: true } },
    { name: 'tablet-landscape', use: { viewport: { width: 1180, height: 820 }, hasTouch: true } },
    { name: 'laptop', use: { viewport: { width: 1366, height: 768 } } },
    { name: 'desktop', use: { viewport: { width: 1440, height: 1000 } } },
    { name: 'webkit-iphone', use: { ...devices['iPhone 13'], browserName: 'webkit' } },
    { name: 'webkit-ipad', use: { ...devices['iPad (gen 7)'], browserName: 'webkit' } },
  ],
});
