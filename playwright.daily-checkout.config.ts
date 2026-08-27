import { defineConfig } from '@playwright/test';
import { loopbackBaseUrl, sanitizedTestEnvironment } from './tests/e2e/support/test-environment';

const baseURL = loopbackBaseUrl('DAILY_CHECKOUT_E2E_BASE_URL', 'http://127.0.0.1:4176');
const serverUrl = new URL(baseURL);

if (serverUrl.pathname !== '/' || serverUrl.port === '') {
  throw new Error('DAILY_CHECKOUT_E2E_BASE_URL must use a loopback host root with an explicit port.');
}

const webServerEnvironment = sanitizedTestEnvironment({
  // Build the browser fixture into a disposable local directory rather than
  // modifying the tracked Daily deployment assets.
  DAILY_CHECKOUT_OUT_DIR: '../../../test-results/daily-checkout-e2e-build',
  VITE_SENTRY_DSN: '',
  VITE_SENTRY_RELEASE: '',
});

/**
 * Public Daily Checkout browser acceptance does not need the privileged
 * root-suite authentication setup. Keep it isolated so it can run locally
 * against Vite with API responses explicitly controlled by the spec.
 */
export default defineConfig({
  testDir: './tests/e2e',
  testMatch: /daily-checkout-inspection\.spec\.ts/,
  timeout: 45_000,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL,
    // This suite isolates browser-local queue replay; it does not certify the
    // separately deployed service-worker cache/background-sync contract.
    serviceWorkers: 'block',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  webServer: {
    // Exercise the production bundle and the actual signature canvas.
    command: `npm run build && npx vite preview --host=${serverUrl.hostname} --port=${serverUrl.port} --strictPort`,
    cwd: './resources/js/daily-checkout',
    url: `${baseURL}/daily/`,
    reuseExistingServer: false,
    timeout: 120_000,
    env: webServerEnvironment,
  },
  projects: [
    {
      name: 'daily-checkout-chromium',
      use: {
        browserName: 'chromium',
        viewport: { width: 1280, height: 800 },
      },
    },
  ],
});
