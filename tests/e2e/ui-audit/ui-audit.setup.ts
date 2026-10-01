import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import inventorySetup from '../protected-ui-inventory.setup';
import { uiAuditEnvironment } from '../../../playwright.ui-audit.config';
import { localPhpBinary } from '../support/test-environment';

const origin = 'http://127.0.0.1:8127';

export default async function setup() {
  await inventorySetup();
  execFileSync(localPhpBinary('PROTECTED_UI_E2E_PHP'), [
    'artisan', 'db:seed', '--class=Database\\Seeders\\ProtectedUiPersonaSeeder', '--force',
  ], { cwd: process.cwd(), stdio: 'inherit', env: uiAuditEnvironment });

  const personas = JSON.parse(readFileSync('test-results/protected-ui-auth/personas.json', 'utf8')) as Record<string, string>;
  const browser = await chromium.launch();
  try {
    for (const [persona, employeeId] of Object.entries(personas)) {
      // Same supported sign-in path as the canonical fixture setup; no auth shortcut.
      const context = await browser.newContext({ serviceWorkers: 'block' });
      const page = await context.newPage();
      await page.goto(`${origin}/login`);
      await page.getByLabel('Employee ID or email').fill(employeeId);
      await page.getByLabel('Password', { exact: true }).fill(uiAuditEnvironment.PROTECTED_UI_E2E_PASSWORD);
      await page.getByRole('button', { name: 'Sign in', exact: true }).click();
      await page.waitForURL(url => url.pathname !== '/login', { timeout: 15_000 });
      const landing = new URL(page.url()).pathname;
      expect(landing, `${persona} must not be held at an onboarding/password gate`).not.toMatch(/set-password|city-email|member-onboarding/);
      console.log(`UI audit persona ${persona}: landed on ${landing}`);
      await context.storageState({ path: `test-results/protected-ui-auth/persona-${persona}.json` });
      await context.close();
    }
  } finally {
    await browser.close();
  }
}
