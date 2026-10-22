import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e', testMatch: 'personnel-dialog-mobile.spec.ts',
    workers: 1, timeout: 180_000, retries: 0, expect: { timeout: 30_000 },
    outputDir: 'test-results/personnel-dialog-mobile', reporter: 'list',
    use: { baseURL: 'http://127.0.0.1:8047', actionTimeout: 30_000, navigationTimeout: 90_000,
        isMobile: true, hasTouch: true, serviceWorkers: 'block', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
    webServer: { command: 'node tests/e2e/support/personnel-directory-server.mjs', url: 'http://127.0.0.1:8047/login', reuseExistingServer: false, timeout: 180_000 },
    projects: ['chromium', 'webkit'].flatMap(browserName => [
        { name: `${browserName}-375`, use: { browserName: browserName as 'chromium' | 'webkit', viewport: { width: 375, height: 667 } } },
        { name: `${browserName}-360`, use: { browserName: browserName as 'chromium' | 'webkit', viewport: { width: 360, height: 560 } } },
    ]),
});
