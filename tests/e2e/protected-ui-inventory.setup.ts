import { execFileSync } from 'node:child_process';
import canonicalSetup from './protected-ui.setup';
import { protectedUiInventoryEnvironment } from '../../playwright.protected-ui-inventory.config';
import { localPhpBinary } from './support/test-environment';
import { chromium } from '@playwright/test';
import { readFileSync } from 'node:fs';

export default async function setup() {
  await canonicalSetup();
  execFileSync(localPhpBinary('PROTECTED_UI_E2E_PHP'), [
    'artisan', 'db:seed', '--class=Database\\Seeders\\ProtectedUiInventorySeeder', '--force',
  ], {
    cwd: process.cwd(), stdio: 'inherit',
    env: protectedUiInventoryEnvironment,
  });
  const routes = JSON.parse(readFileSync('test-results/protected-ui-auth/fixture-routes.json', 'utf8'));
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    await page.goto(new URL(routes['/member-onboarding/invite'], 'http://127.0.0.1:8127').href);
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await page.waitForURL(url => url.pathname === '/member-onboarding');
    await page.context().storageState({ path: 'test-results/protected-ui-auth/onboarding-state.json' });
  } finally {
    await browser.close();
  }
}
