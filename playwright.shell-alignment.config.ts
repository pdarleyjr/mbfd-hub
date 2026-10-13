import { defineConfig } from '@playwright/test';
import protectedUi from './playwright.protected-ui.config';

// The shell-alignment suite runs on the protected-UI harness (isolated SQLite database, seeded
// fixtures, loopback server on 127.0.0.1:8127) and replaces only the viewport matrix.
const viewports = [
  { width: 1920, height: 1080 },
  { width: 1440, height: 900 },
  { width: 1366, height: 768 },
  { width: 1280, height: 720 },
  { width: 1024, height: 768 },
  { width: 820, height: 1180 },
  { width: 390, height: 844 },
];

export default defineConfig({
  ...protectedUi,
  testMatch: /(?:shell-(?:alignment|baseline)|surface-system|phone-layer)\.spec\.ts/,
  outputDir: 'test-results/shell-alignment',
  reporter: [
    ['list'],
    ['json', { outputFile: 'test-results/shell-alignment-report/results.json' }],
  ],
  projects: viewports.map(({ width, height }) => ({
    name: `vp-${width}x${height}`,
    use: { viewport: { width, height }, hasTouch: width < 1024 },
  })),
});
