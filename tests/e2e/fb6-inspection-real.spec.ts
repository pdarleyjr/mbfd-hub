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

function fixture<T>(command: string, ...args: string[]): T {
  const output = execFileSync(localPhpBinary('FLEET_CHANGEOUT_E2E_PHP'), [...phpArguments, FIXTURE, command, ...args], {
    cwd: process.cwd(), env: fleetChangeoutEnvironment, encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'],
  });

  return JSON.parse(output.trim().split('\n').pop() ?? 'null') as T;
}

interface Fb6State {
  boat: { vehicle_number: string | null; unit_master_vehicle_id: number | null };
  inspections: Array<{ actor_user_id: number; vehicle_number: string | null; unit_number: string | null; processing_status: string; review_status: string; designation_at_time: string; checklist_evidence: string | null }>;
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
  const allDuties = page.getByRole('button', { name: 'Mark all duties completed' });
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

test('Station 6 opens the boat, the daily readings are on screen, and they are saved', async ({ browser }, info) => {
  const { page, problems } = await member(browser, info, 'member');
  const { station_id: station } = fixture<{ station_id: number }>('fb6');

  await page.goto(`/daily/stations/${station}`);
  await page.getByRole('tab', { name: 'Apparatus', exact: true }).click();
  const card = page.getByRole('article', { name: 'FB6' });
  await expect(card).toBeVisible({ timeout: 60_000 });
  await expect(card.getByText('Unit: FB6', { exact: true })).toBeVisible();
  await expect(card.getByRole('link', { name: 'Change vehicle' })).toHaveCount(0);
  await card.getByRole('link', { name: 'Start Inspection' }).click();

  await expect(page.getByRole('heading', { name: 'FB6', exact: true, level: 1 })).toBeVisible({ timeout: 60_000 });
  await page.getByRole('button', { name: 'Checklist details', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Engine & Generator' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Fuel & Operating Readings' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Crew Equipment & Radios' })).toBeVisible();
  await page.getByLabel('Port engine hours (hours)').fill('1204.5');
  await page.getByLabel('Starboard engine running voltage (volts)').fill('13.8');
  await page.getByLabel('Current fuel level').fill('75');
  await page.getByLabel('SCUBA equipment ID numbers').fill('T-1, T-2');
  await page.getByLabel('Deckhand radio number').fill('6104');
  await page.locator('#fb6-shift').selectOption('B');
  const allDuties = page.getByRole('button', { name: 'Mark all duties completed' });
  if (await allDuties.count() > 0) await allDuties.click();
  await page.getByRole('button', { name: 'Continue: Apparatus' }).click();

  const nextArea = page.getByRole('button', { name: 'Next area' });
  for (let area = 0; area < 30; area += 1) {
    await page.getByRole('button', { name: 'Confirm all present' }).click();
    if (await nextArea.count() === 0) break;
    await nextArea.click();
  }
  await page.locator('.inspection-action-bar .inspection-review-button').click();
  await expect(page.getByRole('heading', { name: 'Review & Sign Inspection' })).toBeVisible();
  const canvas = page.locator('canvas');
  await canvas.scrollIntoViewIfNeeded();
  const box = await canvas.boundingBox();
  if (!box) throw new Error('The signature pad is not visible.');
  await page.mouse.move(box.x + 24, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + 160, box.y + 20, { steps: 6 });
  await page.mouse.up();
  await page.getByRole('button', { name: 'Submit Inspection' }).click();
  await expect(page.getByRole('heading', { name: 'Inspection recorded' })).toBeVisible({ timeout: 60_000 });
  expect(problems).toEqual([]);

  const evidence = fixture<Fb6State>('fb6-state').inspections.at(-1)?.checklist_evidence;
  const recorded = Object.fromEntries((JSON.parse(evidence ?? '[]') as Array<{ id: string; value: unknown }>).map((field) => [field.id, field.value]));
  expect(recorded).toMatchObject({
    'fb6-port-engine-hours': 1204.5,
    'fb6-star-engine-voltage': 13.8,
    'fb6-fuel-level': 75,
    'fb6-scuba-ids': 'T-1, T-2',
    'fb6-deck-hand-radio-number': '6104',
    'fb6-shift': 'B',
  });

  // The station shows the boat as done.
  await page.goto(`/daily/stations/${station}`);
  await page.getByRole('tab', { name: 'Apparatus', exact: true }).click();
  await expect(page.getByRole('article', { name: 'FB6' }).getByText('Counts as completed')).toBeVisible({ timeout: 60_000 });
});

test('a concern is reported, the next member sees it live with its history, and answers survive the refresh', async ({ browser }, info) => {
  const reporter = await member(browser, info, 'member');

  await completeTheBoat(reporter.page);
  await reporter.page.getByRole('button', { name: 'Add a concern or note' }).click();
  await reporter.page.getByLabel('What did you notice?').fill('Port foam line coupling is leaking');
  await reporter.page.getByLabel('Where is it? (optional)').fill('Port Side Deck Upper Storage');
  await reporter.page.getByRole('radio', { name: 'Problem that needs attention' }).check();
  await reporter.page.getByRole('button', { name: 'Add another' }).click();
  await reporter.page.getByLabel('What did you notice?').last().fill('Tide was unusually low at the lift this morning');
  await reporter.page.getByRole('radio', { name: 'Note for the record: no action needed' }).last().check();
  await reporter.page.getByRole('button', { name: 'Submit Inspection' }).click();
  await expect(reporter.page.getByRole('heading', { name: 'Inspection recorded' })).toBeVisible({ timeout: 60_000 });
  expect(reporter.problems).toEqual([]);

  const next = await member(browser, info, 'second');
  next.page.on('dialog', (dialog) => { void dialog.accept(); });
  await next.page.goto('/daily/vehicle-inspections/fb6');
  await expect(next.page.getByRole('heading', { name: '1 open issue on this apparatus' })).toBeVisible({ timeout: 60_000 });
  await next.page.getByText('Other recorded findings (1)').click();
  const card = next.page.locator('.inspection-unlocated-findings .issue-card');
  await expect(card).toContainText('Port foam line coupling is leaking');
  await expect(card).toContainText('Awaiting verification');
  await expect(card).toContainText(/Reported by .+ on /);
  await next.page.getByText(/^Recent notes/).click();
  await expect(next.page.locator('.findings-note')).toContainText('Tide was unusually low');

  // This member has started: the answer on screen has to survive Logistics acting on the issue.
  await next.page.getByRole('button', { name: 'Confirm all present' }).click();
  await expect(next.page.locator('.inspection-progress')).toContainText(/^[1-9]/);
  const before = await next.page.locator('.inspection-progress small').innerText();

  fixture('fb6-issue-action', 'acknowledge');
  await next.page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect(card).toContainText('Acknowledged by Logistics', { timeout: 20_000 });
  await expect(card.locator('[data-latest-action]')).toContainText('Acknowledged');
  await expect(next.page.locator('.inspection-progress small')).toHaveText(before);

  await card.getByText(/^Show history/).click();
  const timeline = card.locator('.issue-card__timeline');
  await expect(timeline).toContainText('Issue reported');
  await expect(timeline).toContainText('Acknowledged');

  fixture('fb6-issue-action', 'resolve');
  await next.page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect(next.page.getByRole('heading', { name: 'No open issues on this apparatus' })).toBeVisible({ timeout: 20_000 });
  await expect(next.page.locator('.inspection-progress small')).toHaveText(before);
  // Closed, not gone: it is still in the boat's history, with how it ended and why.
  await next.page.getByText(/^Earlier closed issues \(1\)/).click();
  const closed = next.page.locator('.issue-card', { hasText: 'Port foam line coupling is leaking' }).last();
  await expect(closed).toContainText('Resolved');
  await closed.getByText(/^Show history/).click();
  await expect(closed.locator('.issue-card__timeline')).toContainText('New hose fitted');
  expect(next.problems).toEqual([]);
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
