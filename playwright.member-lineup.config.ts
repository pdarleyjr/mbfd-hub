import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e/member-lineup',
    fullyParallel: false,
    workers: 1,
    outputDir: 'test-results/member-lineup',
    timeout: 30_000,
    use: { baseURL: 'http://127.0.0.1:5186', timezoneId: 'Asia/Tokyo', trace: 'retain-on-failure' },
    webServer: {
        command: 'node node_modules/vite/bin/vite.js --config tests/e2e/member-lineup/vite.config.ts --host 127.0.0.1 --port 5186 --strictPort',
        url: 'http://127.0.0.1:5186/@vite/client',
        reuseExistingServer: false,
        env: { SENTRY_AUTH_TOKEN: '' },
    },
    projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
