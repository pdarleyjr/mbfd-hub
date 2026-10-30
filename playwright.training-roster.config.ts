import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e', testMatch: 'training-roster.spec.ts',
    // The kiosk preview server is stateless static files and every test owns its API fixtures, so
    // CI can run the desktop, tablet and iPhone projects two at a time.
    workers: process.env.CI ? 2 : 1, timeout: 45_000, retries: 0,
    outputDir: 'test-results/training-roster', reporter: 'list',
    use: { baseURL: 'http://127.0.0.1:8028', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
    webServer: { command: 'node tests/e2e/support/training-roster-preview.mjs', url: 'http://127.0.0.1:8028', reuseExistingServer: false },
    projects: [
        { name: 'desktop', use: { browserName: 'chromium', viewport: { width: 1440, height: 1000 } } },
        { name: 'tablet', use: { browserName: 'chromium', viewport: { width: 1024, height: 768 }, hasTouch: true } },
        { name: 'iphone-webkit', use: { ...devices['iPhone 13'], browserName: 'webkit' } },
    ],
});
