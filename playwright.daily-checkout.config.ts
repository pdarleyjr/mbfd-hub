import { defineConfig } from '@playwright/test';

/**
 * Public Daily Checkout browser acceptance does not need the privileged
 * root-suite authentication setup. Keep it isolated so it can run locally
 * against Vite with API responses explicitly controlled by the spec.
 */
export default defineConfig({
  testDir: './tests/e2e',
  testMatch: /daily-checkout-inspection\.spec\.ts/,
  timeout: 45_000,
  reporter: 'list',
  use: {
    baseURL: 'http://127.0.0.1:4176',
    // This suite isolates browser-local queue replay; it does not certify the
    // separately deployed service-worker cache/background-sync contract.
    serviceWorkers: 'block',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  webServer: {
    // Exercise the production bundle and the actual signature canvas.
    command: 'npm run build && npx vite preview --host=127.0.0.1 --port=4176 --strictPort',
    cwd: './resources/js/daily-checkout',
    url: 'http://127.0.0.1:4176/daily/',
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
  },
  projects: [
    {
      name: 'daily-checkout-chromium',
      use: {
        viewport: { width: 1280, height: 800 },
      },
    },
  ],
});
