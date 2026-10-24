import { defineConfig } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { closeSync, openSync } from 'node:fs';
import {
  disposableTestAppKey,
  environmentValue,
  isolatedSqliteDatabasePath,
  loopbackBaseUrl,
  sanitizedTestEnvironment,
} from './tests/e2e/support/test-environment';

/*
 * Employee Portal page-frame acceptance: the first content block must sit close under the page header
 * on phones, tablets and desktops, in a browser tab and (emulated) in an installed standalone window.
 *
 * Runs on a disposable SQLite database seeded by the existing personnel-request and bid-assignment
 * fixtures (member 99002 and officer 99001). Nothing here touches production data.
 *   npx playwright test -c playwright.employee-layout.config.ts
 * On Windows PHP needs the sodium extension: set PHP_INI_SCAN_DIR to a folder holding an ini file
 * with "extension=sodium", or enable it in php.ini.
 */
const database = isolatedSqliteDatabasePath('EMPLOYEE_LAYOUT_E2E_DATABASE', 'employee_layout_e2e.sqlite');
const baseURL = loopbackBaseUrl('PLAYWRIGHT_BASE_URL', 'http://127.0.0.1:8142');
const port = new URL(baseURL).port;

const passwords: Record<string, string> = {};
for (const name of [
  'PERSONNEL_REQUESTS_E2E_ADMIN_PASSWORD',
  'PERSONNEL_REQUESTS_E2E_OFFICER_PASSWORD',
  'PERSONNEL_REQUESTS_E2E_MEMBER_PASSWORD',
]) {
  const password = environmentValue(name) ?? randomBytes(24).toString('base64url');
  process.env[name] = password;
  passwords[name] = password;
}
closeSync(openSync(database, 'a'));

export const employeeLayoutEnvironment = sanitizedTestEnvironment({
  APP_ENV: 'testing',
  APP_KEY: disposableTestAppKey,
  APP_URL: baseURL,
  APP_CONFIG_CACHE: `${process.cwd()}/bootstrap/cache/employee-layout-config.php`,
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
  ...passwords,
});

// Phones (small and large iPhone, Android), tablets in both orientations, a narrow desktop, a laptop and an ultrawide.
const viewports = [
  { name: 'iphone-se-375x667', width: 375, height: 667, phone: true },
  { name: 'iphone-390x844', width: 390, height: 844, phone: true },
  { name: 'iphone-max-430x932', width: 430, height: 932, phone: true },
  { name: 'android-412x915', width: 412, height: 915, phone: true },
  { name: 'ipad-portrait-820x1180', width: 820, height: 1180, phone: false },
  { name: 'ipad-landscape-1180x820', width: 1180, height: 820, phone: false },
  { name: 'narrow-desktop-1024x768', width: 1024, height: 768, phone: false },
  { name: 'laptop-1440x900', width: 1440, height: 900, phone: false },
  { name: 'ultrawide-2560x1080', width: 2560, height: 1080, phone: false },
];

export default defineConfig({
  testDir: './tests/e2e',
  testMatch: /employee-layout\.spec\.ts/,
  globalSetup: './tests/e2e/employee-layout.setup.ts',
  timeout: 90_000,
  retries: 0,
  workers: 1,
  outputDir: 'test-results/employee-layout',
  reporter: [['list'], ['json', { outputFile: 'test-results/employee-layout-report/results.json' }]],
  // bypassCSP lets the spec inject the safe-area token overrides that stand in for a notch.
  use: { baseURL, browserName: 'chromium', serviceWorkers: 'block', bypassCSP: true, trace: 'retain-on-failure' },
  webServer: {
    command: `php artisan serve --host=127.0.0.1 --port=${port}`,
    url: `${baseURL}/login`,
    timeout: 90_000,
    reuseExistingServer: false,
    env: employeeLayoutEnvironment,
  },
  projects: viewports.map(({ name, width, height, phone }) => ({
    name,
    use: { viewport: { width, height }, hasTouch: width < 1024, isMobile: phone },
  })),
});
