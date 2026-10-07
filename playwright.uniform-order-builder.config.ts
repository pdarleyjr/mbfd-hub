import { defineConfig } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { existsSync, mkdirSync } from 'node:fs';
import { basename, resolve } from 'node:path';
import {
  disposableTestAppKey,
  environmentValue,
  isolatedSqliteDatabasePath,
  localPhpBinary,
  loopbackBaseUrl,
  sanitizedTestEnvironment,
} from './tests/e2e/support/test-environment';

if (existsSync('.env') || existsSync('.env.testing')) {
  throw new Error('Uniform order acceptance requires an isolated checkout without dotenv credentials.');
}

const baseURL = loopbackBaseUrl('UNIFORM_ORDER_E2E_BASE_URL', 'http://127.0.0.1:8027');
const viewCache = resolve('test-results/uniform-order-view-cache');
mkdirSync(viewCache, { recursive: true });
const database = isolatedSqliteDatabasePath('UNIFORM_ORDER_E2E_DATABASE', 'uniform_order_builder_e2e.sqlite');
if (basename(database) !== 'uniform_order_builder_e2e.sqlite') {
  throw new Error('Uniform order acceptance requires its dedicated uniform_order_builder_e2e.sqlite database.');
}
const fixturePasswords: Record<string, string> = {};
for (const name of [
  'PERSONNEL_REQUESTS_E2E_ADMIN_PASSWORD',
  'PERSONNEL_REQUESTS_E2E_OFFICER_PASSWORD',
  'PERSONNEL_REQUESTS_E2E_MEMBER_PASSWORD',
]) {
  const password = environmentValue(name) ?? randomBytes(24).toString('base64url');
  process.env[name] = password;
  fixturePasswords[name] = password;
}

export const uniformOrderEnvironment = sanitizedTestEnvironment({
  APP_ENV: 'testing',
  APP_KEY: disposableTestAppKey,
  APP_URL: baseURL,
  APP_CONFIG_CACHE: resolve('bootstrap/cache/uniform-order-e2e-config.php'),
  VIEW_COMPILED_PATH: viewCache,
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
  ...fixturePasswords,
});

export default defineConfig({
  testDir: './tests/e2e',
  testMatch: /uniform-order-builder\.spec\.ts/,
  globalSetup: './tests/e2e/uniform-order-builder.setup.ts',
  workers: 1,
  retries: 0,
  timeout: 60_000,
  outputDir: 'test-results/uniform-order-builder',
  reporter: [['list'], ['json', { outputFile: 'test-results/uniform-order-builder-report/results.json' }]],
  use: {
    baseURL,
    browserName: 'chromium',
    serviceWorkers: 'block',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: `"${localPhpBinary('UNIFORM_ORDER_E2E_PHP')}" artisan serve --host=127.0.0.1 --port=${new URL(baseURL).port}`,
    url: `${baseURL}/login`,
    reuseExistingServer: false,
    timeout: 90_000,
    env: uniformOrderEnvironment,
  },
  projects: [
    ...[375, 390, 430, 768, 1024, 1440].map(width => ({
      name: `uniform-${width}`,
      use: { viewport: { width, height: width < 768 ? 844 : 1000 }, hasTouch: width < 1024 },
    })),
    { name: 'uniform-webkit-390', use: { browserName: 'webkit', viewport: { width: 390, height: 844 }, hasTouch: true } },
    { name: 'uniform-webkit-768', use: { browserName: 'webkit', viewport: { width: 768, height: 1000 }, hasTouch: true } },
    { name: 'uniform-firefox-1440', use: { browserName: 'firefox', viewport: { width: 1440, height: 1000 } } },
  ],
});
