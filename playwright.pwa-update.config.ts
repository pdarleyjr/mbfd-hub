import { defineConfig } from '@playwright/test';
import { closeSync, openSync } from 'node:fs';
import {
  disposableTestAppKey,
  isolatedSqliteDatabasePath,
  loopbackBaseUrl,
  sanitizedTestEnvironment,
} from './tests/e2e/support/test-environment';

/**
 * PWA update flow: release detection, busy-aware reloads, chunk recovery,
 * notification deep links and the in-place upgrade of an installed root worker.
 * Serves the real Laravel app from a disposable SQLite database on port 8031.
 */
const e2eDatabase = isolatedSqliteDatabasePath('PWA_UPDATE_E2E_DATABASE', 'pwa_update_e2e.sqlite');
const baseURL = loopbackBaseUrl('PLAYWRIGHT_BASE_URL', 'http://127.0.0.1:8031');
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
  testMatch: /pwa-(?:update|sw-upgrade)\.spec\.ts/,
  globalSetup: './tests/e2e/pwa-update.setup.ts',
  timeout: 90_000,
  retries: 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL,
    browserName: 'chromium',
    // New headless mode: the default headless shell denies Notification permission, which the push specs need.
    channel: 'chromium',
    viewport: { width: 1440, height: 900 },
    serviceWorkers: 'allow',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: 'php artisan serve --host=127.0.0.1 --port=8031',
    url: baseURL,
    timeout: 60_000,
    reuseExistingServer: false,
    env: webServerEnvironment,
  },
  projects: [{ name: 'desktop-chromium' }],
});
