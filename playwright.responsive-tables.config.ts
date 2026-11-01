import { defineConfig } from '@playwright/test';

/**
 * Responsive tables contract: no operational table scrolls sideways at any supported width.
 * Starts a disposable synthetic-data server (tests/e2e/support/responsive-tables-server.mjs).
 * Run: npx playwright test --config=playwright.responsive-tables.config.ts
 */
const widths = [320, 390, 768, 1024, 1280, 1440] as const;

export default defineConfig({
    testDir: './tests/e2e', testMatch: 'responsive-tables.spec.ts',
    workers: 1, timeout: 240_000, retries: 0,
    expect: { timeout: 30_000 },
    outputDir: 'test-results/responsive-tables', reporter: 'list',
    use: { baseURL: 'http://127.0.0.1:8161', actionTimeout: 30_000, navigationTimeout: 120_000, serviceWorkers: 'block', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
    webServer: { command: 'node tests/e2e/support/responsive-tables-server.mjs', url: 'http://127.0.0.1:8161/login', reuseExistingServer: !process.env.CI, timeout: 240_000 },
    projects: widths.map(width => ({
        name: `w${width}`,
        use: { browserName: 'chromium' as const, viewport: { width, height: width < 700 ? 800 : 900 }, hasTouch: width < 1024, isMobile: width < 700 },
    })),
});
