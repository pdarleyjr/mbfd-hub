import { defineConfig } from '@playwright/test';
import protectedUi from './playwright.protected-ui.config';

export default defineConfig({
  ...protectedUi,
  testMatch: /protected-ui-zoom\.spec\.ts/,
  timeout: 180_000,
  outputDir: 'test-results/protected-ui-zoom',
  reporter: [
    ['list'],
    ['json', { outputFile: 'test-results/protected-ui-zoom-report/results.json' }],
  ],
});
