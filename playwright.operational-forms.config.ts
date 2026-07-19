import { defineConfig, devices } from '@playwright/test';
import { resolve } from 'node:path';

const e2eDatabase = process.env.OPERATIONAL_FORMS_E2E_DATABASE ?? resolve(process.cwd(), 'database/operational_forms_e2e.sqlite');

export default defineConfig({
  testDir: './tests/e2e',
  testMatch: /operational-forms\.spec\.ts/,
  globalSetup: './tests/e2e/operational-forms.setup.ts',
  timeout: 90_000,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8017',
    viewport: { width: 1440, height: 1000 },
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: process.env.OPERATIONAL_FORMS_E2E_SERVER_COMMAND ?? 'php artisan serve --host=127.0.0.1 --port=8017',
    url: process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8017',
    timeout: 60_000,
    reuseExistingServer: false,
    env: {
      ...process.env,
      APP_ENV: 'testing',
      DB_CONNECTION: 'sqlite',
      DB_DATABASE: e2eDatabase,
      QUEUE_CONNECTION: 'sync',
      SESSION_DRIVER: 'file',
      FROC_IMPORT_FORCE_FALLBACK: 'true',
    },
  },
  projects: [
    { name: 'phone-small', use: { browserName: 'chromium', viewport: { width: 320, height: 568 }, hasTouch: true, isMobile: true } },
    { name: 'phone', use: { ...devices['iPhone 13'], browserName: 'chromium' } },
    { name: 'phone-large', use: { browserName: 'chromium', viewport: { width: 430, height: 932 }, hasTouch: true, isMobile: true } },
    { name: 'tablet-portrait', use: { viewport: { width: 820, height: 1180 }, hasTouch: true } },
    { name: 'tablet-landscape', use: { viewport: { width: 1180, height: 820 }, hasTouch: true } },
    { name: 'laptop', use: { viewport: { width: 1366, height: 768 } } },
    { name: 'desktop', use: { viewport: { width: 1440, height: 1000 } } },
  ],
});
