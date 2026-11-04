import { defineConfig } from '@playwright/test';

/**
 * Mark fixed on an issue card and the stretcher report (tests/e2e/stretcher-and-correction.spec.ts).
 * Vite only, every API call is mocked with page.route, so no Laravel server or database is needed.
 */
export default defineConfig({
  testDir: './tests/e2e',
  testMatch: /stretcher-and-correction\.spec\.ts/,
  timeout: 45_000,
  retries: 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: 'http://127.0.0.1:4183',
    browserName: 'chromium',
    serviceWorkers: 'block',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: 'node resources/js/daily-checkout/node_modules/vite/bin/vite.js resources/js/daily-checkout --host 127.0.0.1 --port 4183 --strictPort',
    url: 'http://127.0.0.1:4183/daily/',
    timeout: 60_000,
    reuseExistingServer: false,
  },
  projects: [
    { name: 'phone', use: { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true } },
    { name: 'desktop', use: { viewport: { width: 1440, height: 1000 } } },
  ],
});
