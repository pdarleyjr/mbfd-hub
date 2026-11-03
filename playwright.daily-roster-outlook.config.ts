import { defineConfig } from '@playwright/test';
import { resolve } from 'node:path';
import dailyRoster from './playwright.daily-roster.config';
import { protectedUiEnvironment } from './playwright.protected-ui.config';

// The Daily Roster harness with TeleStaff's 7-day outlook on, at the narrowest phones, a tablet and desktop.
// Its own config cache, so the outlook setting never leaks into the other protected-UI suites.
const environment = {
  ...protectedUiEnvironment,
  APP_CONFIG_CACHE: resolve('bootstrap/cache/protected-ui-outlook-config.php'),
  DAILY_ROSTER_MODE: 'publish',
  DAILY_ROSTER_OUTLOOK_MODE: 'publish',
};

const viewports = [
  { width: 320, height: 700 },
  { width: 390, height: 844 },
  { width: 768, height: 1024 },
  { width: 1280, height: 800 },
];

export default defineConfig({
  ...dailyRoster,
  globalSetup: './tests/e2e/daily-roster-outlook.setup.ts',
  testMatch: /daily-roster-outlook\.spec\.ts/,
  outputDir: 'test-results/daily-roster-outlook',
  reporter: [
    ['list'],
    ['json', { outputFile: 'test-results/daily-roster-outlook-report/results.json' }],
  ],
  webServer: { ...(dailyRoster.webServer as object), env: environment },
  projects: viewports.map(({ width, height }) => ({
    name: `width-${width}`,
    use: { viewport: { width, height }, hasTouch: width < 1024 },
  })),
});
