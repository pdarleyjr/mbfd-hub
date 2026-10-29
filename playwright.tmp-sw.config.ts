import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: './tests/e2e',
  testMatch: /pwa-sw-upgrade\.spec\.ts/,
  timeout: 90_000,
  retries: 0,
  workers: 1,
  reporter: 'list',
  use: { browserName: 'chromium', channel: 'chromium', viewport: { width: 1440, height: 900 }, serviceWorkers: 'allow', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
  projects: [{ name: 'desktop-chromium' }],
});
