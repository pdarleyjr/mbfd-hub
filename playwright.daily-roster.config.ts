import { defineConfig } from '@playwright/test';
import protectedUi from './playwright.protected-ui.config';

// Runs on the protected-UI harness (isolated SQLite database, seeded admin, loopback server) and adds
// a synthetic roster. The widths are the ones the page must never overflow at.
const viewports = [
  { width: 320, height: 700 },
  { width: 360, height: 780 },
  { width: 390, height: 844 },
  { width: 768, height: 1024 },
  { width: 1024, height: 768 },
  { width: 1280, height: 800 },
  { width: 1440, height: 900 },
];

export default defineConfig({
  ...protectedUi,
  globalSetup: './tests/e2e/daily-roster.setup.ts',
  testMatch: /daily-roster\.spec\.ts/,
  // artisan serve answers one request at a time, and a busy runner makes first renders slow.
  timeout: 120_000,
  expect: { timeout: 20_000 },
  outputDir: 'test-results/daily-roster',
  reporter: [
    ['list'],
    ['json', { outputFile: 'test-results/daily-roster-report/results.json' }],
  ],
  projects: viewports.map(({ width, height }) => ({
    name: `width-${width}`,
    use: { viewport: { width, height }, hasTouch: width < 1024 },
  })),
});
