import { defineConfig } from '@playwright/test';
import { APEX_ORIGIN, INSTALLED_HOST_PORT, installedHostEnvironment } from './tests/e2e/installed-host.setup';

/**
 * An app installed on the apex host stays on that host. Serves the real Laravel app from a disposable SQLite
 * database on two same-site names: *.localhost resolves to this machine and counts as a secure context.
 */
export default defineConfig({
  testDir: './tests/e2e',
  testMatch: /(?:installed-host|one-hub-navigation)\.spec\.ts/,
  globalSetup: './tests/e2e/installed-host.setup.ts',
  timeout: 90_000,
  retries: 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: APEX_ORIGIN,
    browserName: 'chromium',
    viewport: { width: 1280, height: 800 },
    serviceWorkers: 'allow',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: `php artisan serve --host=127.0.0.1 --port=${INSTALLED_HOST_PORT}`,
    url: `http://127.0.0.1:${INSTALLED_HOST_PORT}/up`,
    timeout: 60_000,
    reuseExistingServer: false,
    env: installedHostEnvironment(),
  },
  projects: [{ name: 'desktop-chromium' }],
});
