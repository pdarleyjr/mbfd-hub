import { defineConfig } from '@playwright/test';
import inventory, { protectedUiInventoryEnvironment } from './playwright.protected-ui-inventory.config';

if (!inventory.webServer || Array.isArray(inventory.webServer)) {
  throw new Error('UI audit requires the canonical single isolated test server.');
}

export const uiAuditEnvironment = protectedUiInventoryEnvironment;

export default defineConfig({
  ...inventory,
  testMatch: /ui-audit\/(persona-access|role-crawl|module-acceptance)\.spec\.ts/,
  globalSetup: './tests/e2e/ui-audit/ui-audit.setup.ts',
  projects: [{ name: 'ui-audit' }],
  workers: 1, retries: 0, timeout: 60 * 60_000,
  outputDir: 'test-results/ui-audit',
  reporter: [['list'], ['json', { outputFile: 'test-results/ui-audit-report/results.json' }]],
});
