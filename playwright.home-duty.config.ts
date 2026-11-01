import { defineConfig } from '@playwright/test';
import protectedUi, { protectedUiEnvironment } from './playwright.protected-ui.config';

// Runs on the protected-UI harness (isolated SQLite database, seeded admin, loopback server) with the Daily
// Roster card display switched on and a synthetic roster seeded. The widths are the ones the Home greeting block
// must never overflow at.
const viewports = [
  { width: 320, height: 700 },
  { width: 390, height: 844 },
  { width: 768, height: 1024 },
  { width: 1280, height: 900 },
];

export default defineConfig({
  ...protectedUi,
  globalSetup: './tests/e2e/daily-roster.setup.ts',
  testMatch: /home-duty\.spec\.ts/,
  timeout: 120_000,
  expect: { timeout: 20_000 },
  outputDir: 'test-results/home-duty',
  reporter: [
    ['list'],
    ['json', { outputFile: 'test-results/home-duty-report/results.json' }],
  ],
  webServer: {
    ...protectedUi.webServer,
    env: { ...protectedUiEnvironment, DAILY_ROSTER_CARD_DISPLAY_ENABLED: 'true', DAILY_ROSTER_MODE: 'publish' },
  },
  projects: viewports.map(({ width, height }) => ({
    name: `width-${width}`,
    use: { viewport: { width, height }, hasTouch: width < 1024 },
  })),
});
