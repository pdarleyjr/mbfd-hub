import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e', testMatch: 'training-matrix.spec.ts',
    workers: 1, timeout: 45_000, retries: 0,
    outputDir: 'test-results/training-matrix', reporter: 'list',
    use: { baseURL: 'http://127.0.0.1:8038', serviceWorkers: 'block', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
    webServer: { command: 'node tests/e2e/support/training-matrix-server.mjs', url: 'http://127.0.0.1:8038/login', reuseExistingServer: false, timeout: 120_000 },
    projects: [
        { name: 'desktop', use: { browserName: 'chromium', viewport: { width: 1440, height: 1000 } } },
        { name: 'tablet', use: { browserName: 'chromium', viewport: { width: 1024, height: 768 }, hasTouch: true } },
    ],
});
