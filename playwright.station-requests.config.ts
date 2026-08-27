import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  testMatch: /(?:station-requests|daily-checkout-inspection)\.spec\.ts/,
  timeout: 45_000,
  retries: 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: 'http://127.0.0.1:4178',
    browserName: 'chromium',
    serviceWorkers: 'block',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: 'node resources/js/daily-checkout/node_modules/vite/bin/vite.js resources/js/daily-checkout --host 127.0.0.1 --port 4178 --strictPort',
    url: 'http://127.0.0.1:4178/daily/',
    timeout: 60_000,
    reuseExistingServer: false,
  },
  projects: [
    { name: 'phone', use: { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true } },
    { name: 'tablet', use: { viewport: { width: 820, height: 1180 }, hasTouch: true } },
    { name: 'desktop', use: { viewport: { width: 1440, height: 1000 } } },
  ],
});
