import { defineConfig } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { disposableTestAppKey, isolatedSqliteDatabasePath, localPhpBinary, sanitizedTestEnvironment } from './tests/e2e/support/test-environment';

if (existsSync('.env') || existsSync('.env.testing')) {
  throw new Error('Protected UI acceptance requires an isolated checkout without dotenv credentials.');
}
process.env.PROTECTED_UI_E2E_PASSWORD ??= randomBytes(32).toString('base64url');
const database = isolatedSqliteDatabasePath('PROTECTED_UI_E2E_DATABASE', 'protected_ui_e2e.sqlite');
export const protectedUiEnvironment = sanitizedTestEnvironment({
  APP_ENV: 'testing', APP_KEY: disposableTestAppKey, APP_URL: 'http://127.0.0.1:8127',
  APP_CONFIG_CACHE: resolve('bootstrap/cache/protected-ui-config.php'),
  DB_CONNECTION: 'sqlite', DB_DATABASE: database, CACHE_STORE: 'file',
  BROADCAST_DRIVER: 'log', BROADCAST_CONNECTION: 'log', MAIL_MAILER: 'array',
  FILESYSTEM_DISK: 'local', PRIVATE_FILESYSTEM_DISK: 'local', QUEUE_CONNECTION: 'sync',
  SESSION_DRIVER: 'file', SESSION_SECURE_COOKIE: 'false',
  PULSEPOINT_WORKER_URL: 'http://127.0.0.1:9/disabled-integration',
  PROTECTED_UI_E2E_PASSWORD: process.env.PROTECTED_UI_E2E_PASSWORD,
});

export default defineConfig({
  testDir: './tests/e2e', testMatch: /(?:protected-ui|daily-contextual-back)\.spec\.ts/,
  globalSetup: './tests/e2e/protected-ui.setup.ts', workers: 1, retries: 0, timeout: 60_000,
  outputDir: 'test-results/protected-ui', reporter: [['list'], ['json', { outputFile: 'test-results/protected-ui-report/results.json' }]],
  use: {
    baseURL: 'http://127.0.0.1:8127', browserName: 'chromium',
    storageState: 'test-results/protected-ui-auth/state.json', serviceWorkers: 'block',
    screenshot: 'only-on-failure', trace: 'retain-on-failure',
  },
  webServer: {
    command: `"${localPhpBinary('PROTECTED_UI_E2E_PHP')}" artisan serve --host=127.0.0.1 --port=8127`, url: 'http://127.0.0.1:8127/login',
    reuseExistingServer: false, timeout: 90_000, env: protectedUiEnvironment,
  },
  projects: [390, 430, 768, 1024, 1280, 1366, 1440, 1920].map(width => ({
    name: `width-${width}`, use: { viewport: { width, height: width < 768 ? 844 : 1000 }, hasTouch: width < 1024 },
  })),
});
