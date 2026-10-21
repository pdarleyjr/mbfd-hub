import { defineConfig } from '@playwright/test';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const directory = dirname(fileURLToPath(import.meta.url));

export default defineConfig({
    testDir: directory, testMatch: 'training-crew-planner.spec.ts',
    workers: 1, timeout: 150_000, retries: 0, expect: { timeout: 15_000 },
    outputDir: resolve(directory, '../../test-results/training-crew-planner'), reporter: 'list',
    use: { baseURL: 'http://127.0.0.1:8049', navigationTimeout: 120_000, serviceWorkers: 'block', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
    webServer: { command: `node "${resolve(directory, 'support/training-crew-planner-server.mjs')}"`, cwd: resolve(directory, '../..'), url: 'http://127.0.0.1:8049/login', reuseExistingServer: false, timeout: 300_000 },
    projects: [
        { name: 'desktop', use: { browserName: 'chromium', viewport: { width: 1440, height: 1000 } } },
        { name: 'tablet', use: { browserName: 'chromium', viewport: { width: 1024, height: 768 }, hasTouch: true } },
        { name: 'phone', use: { browserName: 'chromium', viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true } },
    ],
});
