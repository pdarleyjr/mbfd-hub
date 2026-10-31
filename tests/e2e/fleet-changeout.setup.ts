import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fleetChangeoutEnvironment, phpArguments } from '../../playwright.fleet-changeout.config';
import { localPhpBinary } from './support/test-environment';

export const personas = {
  member: '99501',
  second: '99502',
  fleet: '99503',
} as const;

export type PersonaName = keyof typeof personas;

/** Sign-in state lives outside test-results/ so another Playwright run cannot delete it. */
export const authDirectory = process.env.FLEET_CHANGEOUT_AUTH_DIR ?? join(tmpdir(), 'mbfd-fleet-changeout-auth');

export default async function setup() {
  const options = { cwd: process.cwd(), stdio: 'inherit' as const, env: fleetChangeoutEnvironment };
  const php = localPhpBinary('FLEET_CHANGEOUT_E2E_PHP');
  execFileSync(php, [...phpArguments, 'artisan', 'migrate:fresh', '--force', '--quiet'], options);
  execFileSync(php, [...phpArguments, 'artisan', 'db:seed', '--class=Database\\Seeders\\FleetChangeoutE2ESeeder', '--force', '--quiet'], options);
  mkdirSync(authDirectory, { recursive: true });
  rmSync(join(process.cwd(), 'test-results', 'fleet-changeout-screens'), { recursive: true, force: true });

  const browser = await chromium.launch();
  try {
    for (const [name, employeeId] of Object.entries(personas)) {
      const context = await browser.newContext({ baseURL: fleetChangeoutEnvironment.APP_URL });
      const page = await context.newPage();
      await page.goto('/login');
      await page.getByLabel('Employee ID').fill(employeeId);
      await page.getByLabel('Password').fill(fleetChangeoutEnvironment.FLEET_CHANGEOUT_E2E_PASSWORD);
      await page.getByRole('button', { name: /sign in/i }).click({ noWaitAfter: true });
      // The first request after migrate compiles every view on a cold PHP server.
      await expect(page).toHaveURL((url) => url.pathname !== '/login', { timeout: 150_000 });
      await context.storageState({ path: join(authDirectory, `${name}.json`) });
      await context.close();
    }
  } finally {
    await browser.close();
  }
}
