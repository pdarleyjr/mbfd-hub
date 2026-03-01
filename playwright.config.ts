import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 45000,
  retries: 1,
  reporter: 'list',
  use: {
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
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
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        hasTouch: true,
        isMobile: true,
        storageState: 'tests/e2e/.auth/admin.json',
      },
      dependencies: ['setup'],
      testMatch: /mbfd-full-verification\.spec\.ts/,
      grep: /Mobile/,
    },
  ],
});
