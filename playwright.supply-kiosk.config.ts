import { defineConfig } from '@playwright/test';

// The built kiosk served as static files (tests/e2e/support/supply-kiosk-preview.mjs); each test mocks the API.
// Build first: npx vite build (or npm run build).
const port = Number(process.env.SUPPLY_KIOSK_E2E_PORT ?? 4196);
const baseURL = `http://127.0.0.1:${port}`;

export default defineConfig({
    testDir: './tests/e2e',
    testMatch: 'supply-kiosk.spec.ts',
    workers: process.env.CI ? 2 : 1,
    timeout: 45_000,
    retries: 0,
    outputDir: 'test-results/supply-kiosk',
    reporter: 'list',
    use: { baseURL, serviceWorkers: 'block', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
    webServer: {
        command: 'node tests/e2e/support/supply-kiosk-preview.mjs',
        url: `${baseURL}/supply-kiosk`,
        reuseExistingServer: false,
        env: { SUPPLY_KIOSK_E2E_PORT: String(port) },
    },
    projects: [
        { name: 'phone', use: { browserName: 'chromium', viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true } },
        { name: 'tablet', use: { browserName: 'chromium', viewport: { width: 1024, height: 768 }, hasTouch: true } },
    ],
});
