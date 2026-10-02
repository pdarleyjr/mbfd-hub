import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { closeSync, mkdirSync, openSync } from 'node:fs';
import { protectedUiEnvironment } from '../../playwright.protected-ui.config';
import { localPhpBinary } from './support/test-environment';

export default async function setup() {
  closeSync(openSync(protectedUiEnvironment.DB_DATABASE, 'a'));
  const options = { cwd: process.cwd(), stdio: 'inherit' as const, env: protectedUiEnvironment };
  const php = localPhpBinary('PROTECTED_UI_E2E_PHP');
  execFileSync(php, ['artisan', 'migrate:fresh', '--force'], options);
  execFileSync(php, ['artisan', 'db:seed', '--class=Database\\Seeders\\ProtectedUiE2ESeeder', '--force'], options);
  execFileSync(php, ['artisan', 'filament:assets'], options);
  mkdirSync('test-results/protected-ui-auth', { recursive: true });
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    await page.goto('http://127.0.0.1:8127/login');
    await page.getByLabel('Employee ID or email').fill('99871');
    await page.getByLabel('Password', { exact: true }).fill(protectedUiEnvironment.PROTECTED_UI_E2E_PASSWORD);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.waitForURL(url => url.pathname !== '/login');
    const adminResponse = await page.goto('http://127.0.0.1:8127/admin', { waitUntil: 'domcontentloaded' });
    mkdirSync('test-results/protected-ui-setup', { recursive: true });
    await page.screenshot({ path: 'test-results/protected-ui-setup/admin.png', fullPage: true });
    console.log(`Protected UI authentication destination: ${new URL(page.url()).pathname}; status: ${adminResponse?.status()}`);
    await expect(page.locator('.fi-sidebar')).toBeAttached();
    expect(new URL(page.url()).pathname).toBe('/admin');
    await page.context().storageState({ path: 'test-results/protected-ui-auth/state.json' });
  } finally {
    await browser.close();
  }
}
