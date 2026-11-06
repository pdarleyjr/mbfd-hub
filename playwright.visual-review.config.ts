import { defineConfig } from '@playwright/test';
import audit from './playwright.ui-audit.config';

// Seeded personas and the isolated test server from the UI audit; captures key screens only.
export default defineConfig({
  ...audit,
  testMatch: /ui-audit\/visual-review\.spec\.ts/,
  projects: [{ name: 'visual-review' }],
  timeout: 10 * 60_000,
  outputDir: 'test-results/visual-review-output',
  reporter: [['list']],
});
