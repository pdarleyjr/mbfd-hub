import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e/video-conferencing',
    timeout: 120_000,
    expect: { timeout: 20_000 },
    fullyParallel: false,
    retries: 0,
    outputDir: 'test-results/video-conferencing',
    reporter: [['list'], ['html', { outputFolder: 'playwright-report/video-conferencing', open: 'never' }]],
    use: {
        baseURL: process.env.VIDEO_CONFERENCING_E2E_BASE_URL ?? 'http://127.0.0.1:8000',
        viewport: { width: 1280, height: 900 },
        permissions: ['camera', 'microphone', 'local-network-access'],
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        launchOptions: {
            args: [
                '--use-fake-ui-for-media-stream',
                '--use-fake-device-for-media-stream',
                '--autoplay-policy=no-user-gesture-required',
                '--enable-usermedia-screen-capturing',
                '--auto-select-desktop-capture-source=Entire screen',
            ],
        },
    },
    projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
