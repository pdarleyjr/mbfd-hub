import { defineConfig, devices } from '@playwright/test';
import { loopbackBaseUrl } from './tests/e2e/support/test-environment';

const baseURL = loopbackBaseUrl('PLAYWRIGHT_BASE_URL', 'http://127.0.0.1:8098', 'E2E_BASE_URL');

/**
 * Three-viewport regression matrix.
 *
 * The desktop modernization work introduces install-prompt JS, a service
 * worker, a Tailwind plugin, and a 1000+ line theme CSS append. Each of
 * those is gated behind `@media (min-width: 1280px) and (pointer: fine)`
 * or `@media (display-mode: standalone)` — but the *only* way to prove
 * mobile/tablet stayed byte-identical is to render the relevant routes at
 * each viewport on every PR. That is what `regression-mobile`,
 * `regression-tablet`, and `regression-desktop` do.
 *
 * The existing `desktop`, `mobile`, and `workgroup` projects are preserved
 * exactly so the current auth.setup.ts + mbfd-full-verification.spec.ts +
 * workgroup-evaluations.spec.ts pipelines keep running unchanged.
 */
export default defineConfig({
  testDir: './tests/e2e',
  timeout: 45000,
  retries: 1,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    baseURL,
    serviceWorkers: 'block',
  },
  projects: [
    // -------------------------------------------------------------------- //
    // Existing projects — unchanged (do not break current CI).             //
    // -------------------------------------------------------------------- //
    {
      name: 'setup',
      testMatch: /auth\.setup\.ts/,
    },
    {
      name: 'desktop',
      use: {
        viewport: { width: 1280, height: 800 },
        storageState: 'tests/e2e/.auth/admin.json',
      },
      dependencies: ['setup'],
      testMatch: /mbfd-full-verification\.spec\.ts/,
      grep: /Desktop/,
    },
    {
      name: 'mobile',
      use: {
        viewport: { width: 390, height: 844 },
        userAgent:
          'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        hasTouch: true,
        isMobile: true,
        storageState: 'tests/e2e/.auth/admin.json',
      },
      dependencies: ['setup'],
      testMatch: /mbfd-full-verification\.spec\.ts/,
      grep: /Mobile/,
    },
    {
      name: 'workgroup',
      use: {
        viewport: { width: 1280, height: 800 },
      },
      testMatch: /workgroup-evaluations\.spec\.ts/,
    },

    // -------------------------------------------------------------------- //
    // NEW: 3-viewport regression matrix.                                    //
    // Runs the same spec under three distinct device profiles. The spec    //
    // visits public routes only (no auth required for the regression       //
    // smoke), takes screenshots, and asserts critical content is present.  //
    // -------------------------------------------------------------------- //
    {
      name: 'regression-mobile',
      use: {
        ...devices['iPhone 13'],
      },
      testMatch: /regression-non-admin\.spec\.ts/,
    },
    {
      name: 'regression-tablet',
      use: {
        ...devices['iPad (gen 7)'],
      },
      testMatch: /regression-non-admin\.spec\.ts/,
    },
    {
      name: 'regression-desktop',
      use: {
        viewport: { width: 1920, height: 1080 },
        deviceScaleFactor: 1,
      },
      testMatch: /regression-non-admin\.spec\.ts/,
    },

    // Desktop-only PWA install / shortcut smoke. Skipped on mobile/tablet.
    {
      name: 'admin-pwa-desktop',
      use: {
        viewport: { width: 1920, height: 1080 },
        storageState: 'tests/e2e/.auth/admin.json',
      },
      dependencies: ['setup'],
      testMatch: /admin-pwa\.spec\.ts/,
    },
    {
      name: 'apparatus-service-mobile',
      use: { ...devices['iPhone 13'], serviceWorkers: 'block' },
      testMatch: /apparatus-service-tickets\.spec\.ts/,
    },
    {
      name: 'apparatus-service-tablet',
      use: { ...devices['iPad (gen 7)'], serviceWorkers: 'block' },
      testMatch: /apparatus-service-tickets\.spec\.ts/,
    },
    {
      name: 'apparatus-service-desktop',
      use: { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1, serviceWorkers: 'block' },
      testMatch: /apparatus-service-tickets\.spec\.ts/,
    },
  ],
});
