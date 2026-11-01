import { expect, test, type Browser, type Page, type TestInfo } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';
import { fleetChangeoutEnvironment, phpArguments } from '../../playwright.fleet-changeout.config';
import { authDirectory, type PersonaName } from './fleet-changeout.setup';
import { localPhpBinary } from './support/test-environment';

/*
 * Fire Boat 6 through the real Daily app, the real Laravel API and a real SQLite database. Nothing is mocked, because mocked
 * API success is what let this checkout look healthy while no member could ever submit it: the boat has no fleet vehicle ID.
 */

const FIXTURE = join(process.cwd(), 'tests', 'e2e', 'support', 'fleet-changeout-fixture.php');

function fixture<T>(command: string): T {
  const output = execFileSync(localPhpBinary('FLEET_CHANGEOUT_E2E_PHP'), [...phpArguments, FIXTURE, command], {
    cwd: process.cwd(), env: fleetChangeoutEnvironment, encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'],
  });

  return JSON.parse(output.trim().split('\n').pop() ?? 'null') as T;
}

interface Fb6State {
  boat: { vehicle_number: string | null; unit_master_vehicle_id: number | null };
  inspections: Array<{ actor_user_id: number; vehicle_number: string | null; unit_number: string | null; processing_status: string; review_status: string; designation_at_time: string }>;
  sessions: Array<{ actor_user_id: number; submitted_inspection_id: number | null }>;
  changeouts: unknown[];
}

async function member(browser: Browser, info: TestInfo, persona: PersonaName): Promise<{ page: Page; requests: string[]; problems: string[] }> {
  const context = await browser.newContext({
    baseURL: info.project.use.baseURL,
    viewport: info.project.use.viewport ?? undefined,
    serviceWorkers: 'block',
    storageState: join(authDirectory, `${persona}.json`),
  });
  const page = await context.newPage();
  const requests: string[] = [];
  const problems: string[] = [];
  page.on('pageerror', (error) => problems.push(`pageerror: ${error.message}`));
  page.on('response', (response) => {
    const url = new URL(response.url());
    if (!url.pathname.startsWith('/api/public/apparatuses/')) return;
    requests.push(`${response.request().method()} ${url.pathname} ${response.status()}`);
    if (response.status() >= 400) problems.push(`${response.status()} ${url.pathname}`);
  });

  return { page, requests, problems };
}

async function completeTheBoat(page: Page): Promise<void> {
  await page.goto('/daily/vehicle-inspections/fb6');
  await expect(page.getByRole('heading', { name: 'FB6', exact: true, level: 1 })).toBeVisible({ timeout: 60_000 });
  await expect(page.locator('.inspection-identity').getByText(/^Vehicle /)).toHaveCount(0);

  const nextArea = page.getByRole('button', { name: 'Next area' });
  for (let area = 0; area < 30; area += 1) {
    await page.getByRole('button', { name: 'Confirm all present' }).click();
    if (await nextArea.count() === 0) break;
    await nextArea.click();
  }

  await page.locator('.inspection-action-bar .inspection-review-button').click();
  await expect(page.getByRole('heading', { name: 'Checklist Details' })).toBeVisible();
  await page.locator('#fb6-shift').selectOption('A');
  const allDuties = page.getByRole('button', { name: 'Mark all due duties as present' });
  if (await allDuties.count() > 0) await allDuties.click();
  await page.getByRole('button', { name: 'Review & Sign', exact: true }).click();

  await expect(page.getByRole('heading', { name: 'Review & Sign Inspection' })).toBeVisible();
  await expect(page.getByText('Physical vehicle', { exact: true })).toHaveCount(0);
  const canvas = page.locator('canvas');
  await canvas.scrollIntoViewIfNeeded();
  const box = await canvas.boundingBox();
  if (!box) throw new Error('The signature pad is not visible.');
  await page.mouse.move(box.x + 24, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + 120, box.y + 20, { steps: 6 });
  await page.mouse.move(box.x + 200, box.y + box.height - 20, { steps: 6 });
  await page.mouse.up();
}

test.describe.configure({ mode: 'serial' });

test('a member completes Fire Boat 6 with no vehicle ID and the server records it', async ({ browser }, info) => {
  fixture('fb6');
  const { page, requests, problems } = await member(browser, info, 'member');

  await completeTheBoat(page);
  await page.getByRole('button', { name: 'Submit Inspection' }).click();
  await expect(page.getByRole('heading', { name: 'Inspection recorded' })).toBeVisible({ timeout: 60_000 });

  expect(requests.some((line) => /^POST \/api\/public\/apparatuses\/\d+\/inspections 201$/.test(line))).toBe(true);
  expect(problems).toEqual([]);
  const state = fixture<Fb6State>('fb6-state');
  expect(state.boat).toEqual({ vehicle_number: null, unit_master_vehicle_id: null });
  expect(state.inspections).toHaveLength(1);
  expect(state.inspections[0]).toMatchObject({ vehicle_number: null, unit_number: null, processing_status: 'accepted', review_status: 'approved', designation_at_time: 'FB6' });
  expect(state.changeouts).toEqual([]);
});

test('two members with the boat open at once both finish, and neither is locked out', async ({ browser }, info) => {
  const first = await member(browser, info, 'second');
  const second = await member(browser, info, 'fleet');
  const before = fixture<Fb6State>('fb6-state').inspections.length;

  await completeTheBoat(first.page);
  await completeTheBoat(second.page);

  await first.page.getByRole('button', { name: 'Submit Inspection' }).click();
  await expect(first.page.getByRole('heading', { name: 'Inspection recorded' })).toBeVisible({ timeout: 60_000 });
  await second.page.getByRole('button', { name: 'Submit Inspection' }).click();
  await expect(second.page.getByRole('heading', { name: 'Inspection recorded' })).toBeVisible({ timeout: 60_000 });

  expect(first.problems).toEqual([]);
  expect(second.problems).toEqual([]);
  expect(fixture<Fb6State>('fb6-state').inspections.length).toBe(before + 2);
});
