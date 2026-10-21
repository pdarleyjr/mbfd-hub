import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e', testMatch: 'personnel-directory.spec.ts',
    workers: 1, timeout: 180_000, retries: 0,
    expect: { timeout: 30_000 },
    outputDir: 'test-results/personnel-directory', reporter: 'list',
    use: { baseURL: 'http://127.0.0.1:8047', actionTimeout: 30_000, navigationTimeout: 90_000, serviceWorkers: 'block', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
    webServer: { command: 'node tests/e2e/support/personnel-directory-server.mjs', url: 'http://127.0.0.1:8047/login', reuseExistingServer: false, timeout: 180_000 },
    projects: [
        { name: 'desktop', use: { browserName: 'chromium', viewport: { width: 1440, height: 1000 } } },
        { name: 'tablet', use: { browserName: 'chromium', viewport: { width: 768, height: 1024 }, hasTouch: true } },
        { name: 'phone', use: { browserName: 'chromium', viewport: { width: 375, height: 812 }, hasTouch: true } },
    ],
});
