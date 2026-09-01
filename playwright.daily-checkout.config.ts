import { defineConfig } from '@playwright/test';
import { loopbackBaseUrl, sanitizedTestEnvironment } from './tests/e2e/support/test-environment';

const baseURL = loopbackBaseUrl('DAILY_CHECKOUT_E2E_BASE_URL', 'http://127.0.0.1:4176');
const serverUrl = new URL(baseURL);
const browserChannel = process.env.PLAYWRIGHT_BROWSER_CHANNEL;

if (browserChannel && !['chrome', 'msedge'].includes(browserChannel)) {
  throw new Error('PLAYWRIGHT_BROWSER_CHANNEL must be chrome or msedge when set.');
}

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

const responsiveViewports = [
  { name: 'phone-320', width: 320, height: 568 },
  { name: 'phone-360', width: 360, height: 800 },
  { name: 'phone-390', width: 390, height: 844 },
  { name: 'phone-430', width: 430, height: 932 },
  { name: 'tablet-768', width: 768, height: 1024 },
  { name: 'tablet-landscape-1024', width: 1024, height: 768 },
  { name: 'desktop-1280', width: 1280, height: 720 },
  { name: 'wide-1440', width: 1440, height: 900 },
  { name: 'wide-1920', width: 1920, height: 1080 },
  { name: 'display-2560', width: 2560, height: 1440 },
  { name: 'display-3840', width: 3840, height: 2160 },
] as const;

/**
 * Public Daily Checkout browser acceptance does not need the privileged
 * root-suite authentication setup. Keep it isolated so it can run locally
 * against Vite with API responses explicitly controlled by the spec.
 */
export default defineConfig({
  testDir: './tests/e2e',
  testMatch: /(canonical-login-responsive|daily-checkout-(inspection|responsive))\.spec\.ts/,
  timeout: 45_000,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: 'list',
  preserveOutput: 'always',
  use: {
    baseURL,
    channel: browserChannel,
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
      testMatch: /daily-checkout-inspection\.spec\.ts/,
      use: {
        browserName: 'chromium',
        viewport: { width: 1280, height: 800 },
      },
    },
    ...responsiveViewports.map(({ name, width, height }) => ({
      name: `daily-responsive-${name}`,
      testMatch: /(canonical-login-responsive|daily-checkout-responsive)\.spec\.ts/,
      use: {
        browserName: 'chromium' as const,
        viewport: { width, height },
        hasTouch: width < 1024,
        isMobile: width < 768,
      },
    })),
  ],
});
