import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { closeSync, mkdirSync, openSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { employeeLayoutEnvironment } from '../../playwright.employee-layout.config';
import { localPhpBinary } from './support/test-environment';

const users = [
  { name: 'member', employeeId: '99002', passwordVariable: 'PERSONNEL_REQUESTS_E2E_MEMBER_PASSWORD' },
  { name: 'officer', employeeId: '99001', passwordVariable: 'PERSONNEL_REQUESTS_E2E_OFFICER_PASSWORD' },
];

export default async function setup() {
  closeSync(openSync(employeeLayoutEnvironment.DB_DATABASE, 'a'));
  const options = { cwd: process.cwd(), stdio: 'inherit' as const, env: employeeLayoutEnvironment };
  const php = localPhpBinary('EMPLOYEE_LAYOUT_E2E_PHP');
  execFileSync(php, ['artisan', 'migrate:fresh', '--force'], options);
  execFileSync(php, ['artisan', 'db:seed', '--class=Database\\Seeders\\PersonnelRequestsE2ESeeder', '--force'], options);
  execFileSync(php, ['artisan', 'db:seed', '--class=Database\\Seeders\\BidAssignmentsE2ESeeder', '--force'], options);
  execFileSync(php, ['artisan', 'filament:assets'], options);
  const authDir = process.env.EMPLOYEE_LAYOUT_AUTH_DIR ?? join(tmpdir(), 'mbfd-employee-layout-auth');
  mkdirSync(authDir, { recursive: true });

  const browser = await chromium.launch();
  try {
    for (const user of users) {
      const context = await browser.newContext({ baseURL: employeeLayoutEnvironment.APP_URL });
      const page = await context.newPage();
      await page.goto('/employee/dashboard');
      await page.getByLabel('Employee ID').fill(user.employeeId);
      await page.getByLabel('Password').fill(employeeLayoutEnvironment[user.passwordVariable]);
      await page.getByRole('button', { name: /sign in/i }).click({ noWaitAfter: true });
      // The first request after migrate compiles every view on a cold PHP server.
      await expect(page).toHaveURL(/\/employee\/dashboard$/, { timeout: 150_000 });
      await context.storageState({ path: join(authDir, `${user.name}.json`) });
      await context.close();
    }
  } finally {
    await browser.close();
  }
}
