import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { narcoticsCheckEnvironment, phpExtensionArguments } from '../../playwright.narcotics-check.config';
import { localPhpBinary } from './support/test-environment';

export const personas = {
  member: '99401',
  other: '99402',
  captain5: '99403',
  second: '99404',
} as const;

export type PersonaName = keyof typeof personas;

/** Sign-in state lives outside test-results/ so another Playwright run cannot delete it. */
export const authDirectory = process.env.NARCOTICS_CHECK_AUTH_DIR ?? join(tmpdir(), 'mbfd-narcotics-check-auth');

export default async function setup() {
  const options = { cwd: process.cwd(), stdio: 'inherit' as const, env: narcoticsCheckEnvironment };
  const php = localPhpBinary('NARCOTICS_CHECK_E2E_PHP');
  execFileSync(php, [...phpExtensionArguments, 'artisan', 'migrate:fresh', '--force', '--quiet'], options);
  execFileSync(php, [...phpExtensionArguments, 'artisan', 'db:seed', '--class=Database\\Seeders\\NarcoticsChecksE2ESeeder', '--force', '--quiet'], options);
  mkdirSync(authDirectory, { recursive: true });

  const browser = await chromium.launch();
  try {
    for (const [name, employeeId] of Object.entries(personas)) {
      const context = await browser.newContext({ baseURL: narcoticsCheckEnvironment.APP_URL });
      const page = await context.newPage();
      await page.goto('/login');
      await page.getByLabel('Employee ID').fill(employeeId);
      await page.getByLabel('Password').fill(narcoticsCheckEnvironment.NARCOTICS_CHECK_E2E_PASSWORD);
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
