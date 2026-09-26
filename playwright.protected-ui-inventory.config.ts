import { defineConfig } from '@playwright/test';
import canonical, { protectedUiEnvironment } from './playwright.protected-ui.config';

if (!canonical.webServer || Array.isArray(canonical.webServer)) {
  throw new Error('Inventory requires the canonical single isolated test server.');
}

export const protectedUiInventoryEnvironment = {
  ...protectedUiEnvironment, CACHE_STORE: 'file',
  WORKGROUP_AI_ENABLED: 'false', WORKGROUP_AI_WORKER_URL: '', WORKGROUP_AI_WORKER_SECRET: '',
};

export default defineConfig({
  ...canonical,
  testMatch: /protected-ui-inventory\.spec\.ts/,
  globalSetup: './tests/e2e/protected-ui-inventory.setup.ts',
  webServer: {
    ...canonical.webServer,
    env: protectedUiInventoryEnvironment,
  },
  projects: [{ name: 'inventory' }],
  workers: 1, retries: 0, timeout: 30 * 60_000,
  outputDir: 'test-results/protected-ui-inventory',
  reporter: [['list'], ['json', { outputFile: 'test-results/protected-ui-inventory-report/results.json' }]],
});
