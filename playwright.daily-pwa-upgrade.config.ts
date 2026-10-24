import { defineConfig } from '@playwright/test';

/**
 * Daily Checkout service-worker upgrade safety: worker upgrade and rollback from the exact
 * production bytes, deploys while work is unsaved, a network drop during an update, a device
 * that was offline for days, and shared-tablet cache purging.
 *
 * The spec starts its own tiny origin (tests/e2e/support/daily-pwa-origin.ts), so no preview
 * server is needed. The global setup builds the Daily app into its own directory, separate
 * from the one the other Daily configs use, so runs never fight over files.
 *
 *   npm run test:daily-pwa-upgrade-e2e
 */
export default defineConfig({
  testDir: './tests/e2e',
  // Own output directory (the default test-results/ is emptied by every Playwright run in this repository).
  outputDir: './node_modules/.cache/daily-pwa-upgrade-results',
  testMatch: /daily-checkout-sw-upgrade\.spec\.ts/,
  globalSetup: './tests/e2e/daily-pwa-upgrade.setup.ts',
  timeout: 120_000,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: 'list',
  use: {
    browserName: 'chromium',
    viewport: { width: 390, height: 844 },
    serviceWorkers: 'allow',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'daily-pwa-upgrade-chromium' }],
});
