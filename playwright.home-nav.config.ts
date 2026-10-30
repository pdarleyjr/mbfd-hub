import { defineConfig } from '@playwright/test';
import { employeeLayoutEnvironment } from './playwright.employee-layout.config';

/*
 * Employee menu button and Home quick actions (Rescue Forms card, Command Board button).
 *
 * Reuses the Employee layout acceptance setup: a disposable SQLite database seeded with the personnel-request
 * fixtures (member 99002 Firefighter, officer 99001 Captain) and signed-in storage states. Nothing here touches
 * production data.
 *   npx playwright test -c playwright.home-nav.config.ts
 * Set HOME_NAV_SHOTS_DIR / HOME_NAV_LABEL (before|after) to save screenshots.
 */
const baseURL = employeeLayoutEnvironment.APP_URL;
const port = new URL(baseURL).port;

const viewports = [
  { name: 'iphone-se-320x568', width: 320, height: 568 },
  { name: 'iphone-390x844', width: 390, height: 844 },
  { name: 'iphone-max-430x932', width: 430, height: 932 },
  { name: 'ipad-portrait-820x1180', width: 820, height: 1180 },
  { name: 'laptop-1440x900', width: 1440, height: 900 },
];

export default defineConfig({
  testDir: './tests/e2e',
  testMatch: /(employee-menu-button|home-quick-actions)\.spec\.ts/,
  globalSetup: './tests/e2e/employee-layout.setup.ts',
  timeout: 90_000,
  retries: 0,
  workers: 1,
  outputDir: 'test-results/home-nav',
  reporter: [['list']],
  use: { baseURL, browserName: 'chromium', serviceWorkers: 'block', bypassCSP: true, trace: 'retain-on-failure' },
  webServer: {
    command: `php artisan serve --host=127.0.0.1 --port=${port}`,
    url: `${baseURL}/login`,
    timeout: 90_000,
    // Set HOME_NAV_REUSE_SERVER=1 when a server for the same disposable database is already running.
    reuseExistingServer: process.env.HOME_NAV_REUSE_SERVER === '1',
    env: employeeLayoutEnvironment,
  },
  projects: viewports.map(({ name, width, height }) => ({
    name,
    use: { viewport: { width, height }, hasTouch: width < 1024, isMobile: width < 640 },
  })),
});
