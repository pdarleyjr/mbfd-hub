import { defineConfig } from '@playwright/test';

/**
 * This acceptance check is intentionally local-only. It must never inherit
 * the default authenticated setup, which targets a production URL by default.
 */
export default defineConfig({
  testDir: './tests/e2e',
  testMatch: /forced-password-change\.local\.spec\.ts/,
  timeout: 45_000,
  reporter: 'list',
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8098',
    serviceWorkers: 'block',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
});
