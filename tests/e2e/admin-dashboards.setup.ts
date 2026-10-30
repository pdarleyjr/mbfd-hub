import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { closeSync, mkdirSync, openSync } from 'node:fs';
import { adminDashboardsEnvironment, chromiumExecutable } from '../../playwright.admin-dashboards.config';
import { localPhpBinary } from './support/test-environment';

const personas = { admin: '99881', member: '99882' } as const;

export default async function setup() {
  closeSync(openSync(adminDashboardsEnvironment.DB_DATABASE, 'a'));
  const options = { cwd: process.cwd(), stdio: 'inherit' as const, env: adminDashboardsEnvironment };
  const php = localPhpBinary('ADMIN_DASHBOARDS_E2E_PHP');
  execFileSync(php, ['artisan', 'config:clear', '--quiet'], options);
  execFileSync(php, ['artisan', 'migrate:fresh', '--force', '--quiet'], options);
  execFileSync(php, ['artisan', 'db:seed', '--class=Database\\Seeders\\AdminDashboardsE2ESeeder', '--force', '--quiet'], options);
  execFileSync(php, ['artisan', 'cache:clear', '--quiet'], options);
  execFileSync(php, ['artisan', 'filament:assets', '--quiet'], options);
  // Test-only fixture sources go into this run's own config cache, never into config/dashboards.php.
  execFileSync(php, ['artisan', 'config:cache', '--quiet'], options);
  execFileSync(php, ['tests/e2e/support/add-dashboard-fixture-sources.php', adminDashboardsEnvironment.APP_CONFIG_CACHE], options);
  mkdirSync('test-results/admin-dashboards-auth', { recursive: true });

  const browser = await chromium.launch(chromiumExecutable ? { executablePath: chromiumExecutable } : {});
  try {
    for (const [name, employeeId] of Object.entries(personas)) {
      const context = await browser.newContext({ baseURL: adminDashboardsEnvironment.APP_URL, serviceWorkers: 'block' });
      const page = await context.newPage();
      await page.goto('/login');
      await page.getByLabel('Employee ID or email').fill(employeeId);
      await page.getByLabel('Password', { exact: true }).fill(adminDashboardsEnvironment.ADMIN_DASHBOARDS_E2E_PASSWORD);
      await page.getByRole('button', { name: 'Sign in', exact: true }).click();
      await expect(page).toHaveURL(url => url.pathname !== '/login', { timeout: 150_000 });
      await context.storageState({ path: `test-results/admin-dashboards-auth/${name}.json` });
      await context.close();
    }
  } finally {
    await browser.close();
  }
}
