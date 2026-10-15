import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import inventorySetup from '../protected-ui-inventory.setup';
import { uiAuditEnvironment } from '../../../playwright.ui-audit.config';
import { localPhpBinary } from '../support/test-environment';
import { signInPersona } from './persona-sign-in';

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
      const landing = await signInPersona(browser, persona, employeeId, `test-results/protected-ui-auth/persona-${persona}.json`);
      console.log(`UI audit persona ${persona}: landed on ${landing}`);
    }
  } finally {
    await browser.close();
  }
}
