import { expect, test, type Browser, type BrowserContext, type Page, type TestInfo } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fleetChangeoutEnvironment, phpArguments } from '../../playwright.fleet-changeout.config';
import { authDirectory, type PersonaName } from './fleet-changeout.setup';
import { localPhpBinary } from './support/test-environment';

/*
 * Vehicle changeouts and unit alerts (docs/apparatus-changeouts.md) through the real Daily app, the real Laravel API
 * and a real SQLite database. Nothing here is mocked: the only network interference is the deliberate failure in
 * scenario E. Fixture: database/seeders/FleetChangeoutE2ESeeder.php and support/fleet-changeout-fixture.php.
 *
 * A member commits a changeout with no approval of any kind. The tests run in order in one serial group. Scenario B
 * continues from the database scenario A leaves; every other scenario starts by rebuilding the fleet rows.
 */

const SCREENS = join(process.cwd(), 'test-results', 'fleet-changeout-screens');
const FIXTURE = join(process.cwd(), 'tests', 'e2e', 'support', 'fleet-changeout-fixture.php');
const TRUCK_HEADING = 'What truck are you moving to?';
const EQUIPMENT_HEADING = 'What did you take and leave?';

interface Row { [column: string]: string | number | null }
interface State { trucks: Row[]; tickets: Row[]; defects: Row[]; alerts: Row[]; changeouts: Row[]; items: Row[]; notifications: Row[] }
interface World { stations: Record<string, number>; trucks: Record<string, number>; r44Alert: number; e1Alert: number }

function fixture<T>(command: string, ...args: string[]): T {
  const output = execFileSync(localPhpBinary('FLEET_CHANGEOUT_E2E_PHP'), [...phpArguments, FIXTURE, command, ...args], {
    cwd: process.cwd(), env: fleetChangeoutEnvironment, encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'],
  });

  return JSON.parse(output.trim().split('\n').pop() ?? 'null') as T;
}

const state = (): State => fixture<State>('state');
const truck = (snapshot: State, number: string): Row => {
  const found = snapshot.trucks.find((row) => row.vehicle_number === number);
  if (!found) throw new Error(`No truck ${number}`);

  return found;
};

let world: World;
const resetWorld = (alertDate?: string): World => { world = fixture<World>('reset', ...(alertDate ? [alertDate] : [])); return world; };

/** A date in Eastern time. A future date makes no difference to a member: they can commit whenever the truck moves. */
function easternDate(daysAhead: number): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'America/New_York', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(Date.now() + daysAhead * 86_400_000));
}

// ------------------------------------------------------------------------------------------------ sessions

interface Session { context: BrowserContext; page: Page; problems: string[] }

async function signIn(browser: Browser, info: TestInfo, persona: PersonaName, viewport?: { width: number; height: number }): Promise<Session> {
  const use = info.project.use;
  const context = await browser.newContext({
    baseURL: use.baseURL,
    viewport: viewport ?? use.viewport ?? undefined,
    isMobile: viewport ? false : use.isMobile,
    hasTouch: viewport ? false : use.hasTouch,
    deviceScaleFactor: viewport ? 1 : use.deviceScaleFactor,
    userAgent: viewport ? undefined : use.userAgent,
    serviceWorkers: 'block',
    colorScheme: 'light',
    storageState: join(authDirectory, `${persona}.json`),
  });
  const page = await context.newPage();
  const problems: string[] = [];
  page.on('pageerror', (error) => problems.push(`pageerror: ${error.message}`));
  page.on('response', (response) => {
    const url = new URL(response.url());
    // Expected refusals are asserted by the scenario itself; a server error never is.
    if (url.pathname.startsWith('/api/') && response.status() >= 500) problems.push(`${response.status()} ${response.request().method()} ${url.pathname}`);
  });

  return { context, page, problems };
}

// ------------------------------------------------------------------------------------------------ helpers

mkdirSync(SCREENS, { recursive: true });
const shot = (page: Page, name: string) => page.screenshot({ path: join(SCREENS, `${name}.png`), fullPage: true });

const heading = (page: Page, name: string) => page.getByRole('heading', { name, exact: true });
const h1 = (page: Page) => page.getByRole('heading', { level: 1 });
const primary = (page: Page, name: string | RegExp) => page.getByRole('button', { name, exact: typeof name === 'string' });
const pickTruck = (page: Page, number: string) => page.getByRole('radio', { name: new RegExp(`^${number}\\b`) }).check();
const leftList = (page: Page) => page.getByTestId('changeout-left-list');
const tallies = (page: Page) => page.getByTestId('changeout-summary');
const stationUrl = (stationId: number) => `/daily/stations/${stationId}`;

/** Opens a station page and starts the changeout from the alert card whose title contains the text. */
async function startFromAlert(page: Page, stationId: number, titleText: string) {
  await page.goto(stationUrl(stationId));
  const card = page.getByTestId('unit-alert').filter({ hasText: titleText });
  await expect(card).toBeVisible({ timeout: 30_000 });
  await card.getByRole('link', { name: 'Start Changeout' }).click();
  await expect(heading(page, TRUCK_HEADING)).toBeVisible();
}

/** Starts a changeout from the "Change vehicle" link on the unit's card, for a unit with no alert to start from. */
async function startFromUnitCard(page: Page, stationId: number, designation: string) {
  await page.goto(stationUrl(stationId));
  await page.getByRole('tab', { name: 'Apparatus' }).click();
  const card = page.locator('article').filter({ hasText: designation }).filter({ has: page.getByRole('link', { name: 'Change vehicle' }) });
  await expect(card.first()).toBeVisible({ timeout: 30_000 });
  await card.first().getByRole('link', { name: 'Change vehicle' }).click();
  await expect(heading(page, TRUCK_HEADING)).toBeVisible();
}

async function toEquipment(page: Page, number: string) {
  await pickTruck(page, number);
  await primary(page, 'Continue').click();
  await expect(heading(page, EQUIPMENT_HEADING)).toBeVisible();
}

async function markLeft(page: Page, name: string, note?: string) {
  await page.getByLabel('Find an item').fill(name);
  await page.getByRole('button', { name: new RegExp(`^${name}`) }).first().click();
  if (note) await page.getByLabel(`Note for ${name} (optional)`).fill(note);
  await page.getByLabel('Find an item').fill('');
}

/** Truck, equipment, review and send with nothing left behind. */
async function completeDefaultChangeout(page: Page, number: string) {
  await toEquipment(page, number);
  await primary(page, 'Continue').click();
  await expect(heading(page, 'Review')).toBeVisible();
  await primary(page, 'Complete Changeout').click();
}

const lines = (snapshot: State, changeoutId: number) => snapshot.items.filter((item) => item.changeout_id === changeoutId);

test.describe.configure({ mode: 'serial' });

let member: Session;
let second: Session;
let fleet: Session;

test.beforeAll(async ({ browser }, info) => {
  member = await signIn(browser, info, 'member');
  second = await signIn(browser, info, 'second');
  fleet = await signIn(browser, info, 'fleet');
});

test.afterAll(async () => {
  await Promise.all([member, second, fleet].map((session) => session?.context.close()));
});

test.afterEach(() => {
  for (const session of [member, second, fleet]) {
    expect(session?.problems ?? [], 'server errors or script errors seen by the browser').toEqual([]);
  }
});

// ================================================================================================ A and B

test('A. Rescue 44 moves to reserve 1033 from its alert, one item stays, and it is done with no approval', async () => {
  resetWorld();
  const { page } = member;
  await page.goto(stationUrl(world.stations['4']));

  const card = page.getByTestId('unit-alert').filter({ hasText: 'Rescue 44' });
  await expect(card).toBeVisible({ timeout: 30_000 });
  await expect(card).toContainText('Truck 17503');
  await expect(card).toContainText('R 44');
  await expect(card).toContainText('Reason: Exhaust repair');
  await expect(card).toContainText('Planned');
  await expect(card).not.toContainText('Planned date has passed');
  await shot(page, '01-station-alert');

  await card.getByRole('link', { name: 'Start Changeout' }).click();
  await expect(heading(page, TRUCK_HEADING)).toBeVisible();
  await expect(h1(page)).toHaveText('R 44, truck 17503, Station 4');
  await pickTruck(page, '1033');
  await expect(page.getByTestId('changeout-what-happens')).toHaveText('Truck 17503 will be marked Out of Service.');
  await shot(page, '02-truck-step');

  await primary(page, 'Continue').click();
  await expect(heading(page, EQUIPMENT_HEADING)).toBeVisible();
  const allEquipment = page.getByTestId('changeout-took-all');
  await expect(allEquipment).toContainText(/Took all \d+ items/);
  await expect(allEquipment).toContainText('Moved to truck 1033.');
  const everything = await page.getByRole('button', { name: /\d+ items?/ }).count();
  expect(everything).toBeGreaterThan(1);
  await shot(page, '03-equipment-step');

  await markLeft(page, 'Stair Chair', 'Needed on the old truck for the repair crew');
  await expect(leftList(page)).toContainText('Stair Chair');
  await expect(leftList(page)).toContainText('Needed on the old truck for the repair crew');
  await expect(tallies(page)).toHaveText(/^Took \d+ · Left 1$/);
  await shot(page, '04-one-left');

  // A narcotics item is the member's to leave like anything else.
  const firstCompartment = page.getByRole('button', { name: /\d+ items?/ }).first();
  await firstCompartment.click();
  await shot(page, '05-compartment-open');
  await firstCompartment.click();

  await primary(page, 'Continue').click();
  await expect(heading(page, 'Review')).toBeVisible();
  await expect(page.getByTestId('changeout-review-left')).toContainText('Needed on the old truck for the repair crew');
  await expect(page.getByText('open issues stay with truck 17503').or(page.getByText('open issue stays with truck 17503'))).toBeVisible();
  await shot(page, '06-review');

  await primary(page, 'Complete Changeout').click();
  await expect(h1(page)).toHaveText('R 44 is now on truck 1033. Truck 17503 is marked Out of Service.', { timeout: 30_000 });
  await expect(page.getByText('Fleet has been told. Nobody needs to approve this.')).toBeVisible();
  await expect(page.getByText('Stair Chair: Needed on the old truck for the repair crew')).toBeVisible();
  const checkout = page.getByRole('link', { name: 'Start Daily Checkout on truck 1033' });
  await expect(checkout).toHaveAttribute('href', '/daily/apparatus/r44');
  await expect(page.getByRole('link', { name: 'Back to station' })).toBeVisible();
  await shot(page, '07-done');

  // The database says the same thing.
  const after = state();
  const moved = truck(after, '1033');
  const old = truck(after, '17503');
  expect(moved).toMatchObject({ slug: 'r44', designation: 'R 44', unit_id: 'R44', requirement: 'required', template: 'rescue', status: 'In Service', station_number: '4', vehicle_number: '1033' });
  expect(old).toMatchObject({ slug: 'r17503', unit_id: 'R-17503', name: 'RESCUE 17503', requirement: 'reserve', status: 'Out of Service', vehicle_number: '17503' });
  expect(after.tickets).toEqual([expect.objectContaining({ id: 1, status: 'submitted', vehicle_number: '17503' })]);
  expect(after.defects).toEqual([expect.objectContaining({ id: 1, status: 'open', resolved: 0, vehicle_number: '17503' })]);
  expect(after.changeouts).toHaveLength(1);
  const changeout = after.changeouts[0];
  expect(changeout).toMatchObject({ status: 'completed', from_vehicle_number: '17503', to_vehicle_number: '1033', unit_slug: 'r44', alert_id: world.r44Alert });
  const changeoutLines = lines(after, Number(changeout.id));
  const left = changeoutLines.filter((line) => line.custody_status === 'left_behind');
  expect(left).toEqual([expect.objectContaining({ item_name: 'Stair Chair', handling: 'leave', note: 'Needed on the old truck for the repair crew' })]);
  // Every other item went with the crew, narcotics included, and nothing waits on anyone.
  expect(changeoutLines.filter((line) => line.custody_status === 'moved').length).toBe(changeoutLines.length - 1);
  expect(after.alerts.find((alert) => alert.id === world.r44Alert)).toMatchObject({ status: 'completed', completed_by_changeout_id: changeout.id });
  expect(after.alerts.find((alert) => alert.id === world.e1Alert)).toMatchObject({ status: 'active' });

  // Fleet has been told.
  const told = after.notifications.filter((row) => String(row.data).replaceAll('\\/', '/').includes(`/admin/apparatus-changeouts/${changeout.id}`));
  expect(told.length).toBeGreaterThan(0);

  // The alert is gone from the station page.
  await page.goto(stationUrl(world.stations['4']));
  await expect(page.getByRole('tablist')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('unit-alert').filter({ hasText: 'Rescue 44' })).toHaveCount(0);
  await shot(page, '08-station-after-completion');

  // The record is read-only, for the member and for Fleet alike.
  const receipt = fleet.page;
  await receipt.goto(`/daily/changeouts/${changeout.id}`);
  await expect(h1(receipt)).toContainText('R 44 is now on truck 1033.', { timeout: 30_000 });
  await expect(receipt.getByRole('heading', { name: 'Left on truck 17503' })).toBeVisible();
  await expect(receipt.getByText('Needed on the old truck for the repair crew')).toBeVisible();
  await expect(receipt.getByText('Casey Member')).toBeVisible();
  await expect(receipt.getByRole('button', { name: 'Verify' })).toHaveCount(0);
  await shot(receipt, '09-receipt');
});

test('B. R 44 goes back to its own truck with the earlier changeout in view and nothing preset to Left', async () => {
  const before = state();
  expect(truck(before, '1033')).toMatchObject({ slug: 'r44' });

  const { page } = member;
  await startFromUnitCard(page, world.stations['4'], 'R 44');
  await expect(h1(page)).toHaveText('R 44, truck 1033, Station 4');
  const previous = page.getByRole('region', { name: 'Previous changeout' });
  await expect(previous).toContainText('Truck 17503 to 1033');
  await expect(previous).toContainText('Left on truck 17503');
  await expect(previous).toContainText('Stair Chair: Needed on the old truck for the repair crew');
  const own = page.getByRole('radio', { name: /^17503\b/ });
  await expect(own).toBeVisible();
  await expect(page.locator('label').filter({ has: own })).toContainText('Back to its own truck');
  await pickTruck(page, '17503');
  await expect(page.getByTestId('changeout-what-happens')).toHaveText('Truck 1033 will be marked Out of Service.');
  await shot(page, '10-return-with-previous-summary');

  await primary(page, 'Continue').click();
  await expect(heading(page, EQUIPMENT_HEADING)).toBeVisible();
  await expect(page.getByTestId('changeout-took-all')).toContainText(/Took all \d+ items/);
  await expect(leftList(page)).toHaveCount(0);
  await page.getByLabel('Find an item').fill('Stair Chair');
  await expect(page.getByText('Left on truck 17503 last time: Needed on the old truck for the repair crew')).toBeVisible();
  await expect(page.getByRole('button', { name: /^Stair Chair/ }).first()).toHaveAccessibleName(/Took$/);
  await shot(page, '11-return-equipment');
  await page.getByLabel('Find an item').fill('');

  await primary(page, 'Continue').click();
  await expect(heading(page, 'Review')).toBeVisible();
  await primary(page, 'Complete Changeout').click();
  await expect(h1(page)).toHaveText('R 44 is now on truck 17503. Truck 1033 is marked Out of Service.', { timeout: 30_000 });
  await shot(page, '12-return-done');

  const after = state();
  expect(after.changeouts).toHaveLength(2);
  expect(after.changeouts[0]).toMatchObject({ id: 1, from_vehicle_number: '17503', to_vehicle_number: '1033', status: 'completed' });
  expect(after.changeouts[1]).toMatchObject({ from_vehicle_number: '1033', to_vehicle_number: '17503', status: 'completed', return_of_changeout_id: 1 });
  expect(lines(after, Number(after.changeouts[1].id)).filter((line) => line.custody_status === 'left_behind')).toEqual([]);
  expect(truck(after, '17503')).toMatchObject({ slug: 'r44', designation: 'R 44', requirement: 'required', status: 'In Service' });
  expect(truck(after, '1033')).toMatchObject({ slug: 'r1033', unit_id: 'R-1033', requirement: 'reserve', status: 'Out of Service' });
});

// ================================================================================================ C

test('C. Engine 1 moves to reserve engine 002-18 and back, and the leading zeros stay', async () => {
  resetWorld();
  const { page } = member;
  await startFromAlert(page, world.stations['1'], 'Engine 1');
  await expect(h1(page)).toHaveText('E 1, truck 20503, Station 1');
  await expect(page.getByRole('radio', { name: /^002-18\b/ })).toBeVisible();
  await completeDefaultChangeout(page, '002-18');
  await expect(h1(page)).toContainText('E 1 is now on truck 002-18.', { timeout: 30_000 });
  await expect(page.getByRole('link', { name: 'Start Daily Checkout on truck 002-18' })).toBeVisible();

  let snapshot = state();
  expect(truck(snapshot, '002-18')).toMatchObject({ slug: 'e1', designation: 'E 1', vehicle_number: '002-18', status: 'In Service', requirement: 'required' });
  expect(truck(snapshot, '20503')).toMatchObject({ vehicle_number: '20503', requirement: 'reserve', status: 'Out of Service' });
  expect(snapshot.changeouts).toEqual([expect.objectContaining({ from_vehicle_number: '20503', to_vehicle_number: '002-18', status: 'completed' })]);
  await shot(page, '13-engine-done');

  await startFromUnitCard(page, world.stations['1'], 'E 1');
  await expect(h1(page)).toHaveText('E 1, truck 002-18, Station 1');
  await expect(page.getByRole('region', { name: 'Previous changeout' })).toContainText('Truck 20503 to 002-18');
  await expect(page.locator('label').filter({ has: page.getByRole('radio', { name: /^20503\b/ }) })).toContainText('Back to its own truck');
  await completeDefaultChangeout(page, '20503');
  await expect(h1(page)).toContainText('E 1 is now on truck 20503.', { timeout: 30_000 });

  snapshot = state();
  expect(truck(snapshot, '20503')).toMatchObject({ slug: 'e1', designation: 'E 1', requirement: 'required', status: 'In Service' });
  expect(truck(snapshot, '002-18')).toMatchObject({ vehicle_number: '002-18', requirement: 'reserve', status: 'Out of Service' });
  expect(snapshot.changeouts).toHaveLength(2);
  expect(snapshot.changeouts[1]).toMatchObject({ from_vehicle_number: '002-18', to_vehicle_number: '20503', return_of_changeout_id: 1 });
});

// ================================================================================================ D

test('D. A future date, or no alert at all, makes no difference: the member commits at once and Fleet sees the row', async ({ browser }, info) => {
  resetWorld(easternDate(2));
  const { page } = member;
  await startFromAlert(page, world.stations['4'], 'Rescue 44');
  await completeDefaultChangeout(page, '1033');
  await expect(h1(page)).toContainText('R 44 is now on truck 1033.', { timeout: 30_000 });
  await expect(page.getByText('Fleet has been told. Nobody needs to approve this.')).toBeVisible();
  let snapshot = state();
  expect(snapshot.changeouts).toEqual([expect.objectContaining({ status: 'completed', from_vehicle_number: '17503', to_vehicle_number: '1033' })]);
  expect(truck(snapshot, '1033')).toMatchObject({ slug: 'r44', status: 'In Service' });
  expect(truck(snapshot, '17503')).toMatchObject({ status: 'Out of Service' });
  await shot(page, '14-future-date-done');

  resetWorld();
  fixture('clear-alert', 'e1');
  const noAlert = await signIn(browser, info, 'member');
  const phone = noAlert.page;
  await startFromUnitCard(phone, world.stations['1'], 'E 1');
  await expect(primary(phone, 'Send to Fleet')).toHaveCount(0);
  await completeDefaultChangeout(phone, '002-18');
  await expect(h1(phone)).toContainText('E 1 is now on truck 002-18.', { timeout: 30_000 });
  snapshot = state();
  expect(snapshot.changeouts).toEqual([expect.objectContaining({ status: 'completed', unit_slug: 'e1', alert_id: null })]);
  expect(truck(snapshot, '002-18')).toMatchObject({ slug: 'e1' });
  await shot(phone, '15-no-alert-done');
  await noAlert.context.close();

  // The row is there for an admin, already done.
  const admin = fleet.page;
  await admin.goto('/admin/apparatus-changeouts');
  const row = admin.getByRole('row').filter({ hasText: '002-18' });
  await expect(row.first()).toBeVisible({ timeout: 90_000 });
  await expect(row.first()).toContainText('E 1');
  await shot(admin, '16-admin-changeouts-list');
  await expect(admin.getByRole('button', { name: 'Approve' })).toHaveCount(0);
});

// ================================================================================================ E

test('E1. Interrupted work comes back after a reload, and a send that is lost is not sent twice', async () => {
  resetWorld();
  const { page } = member;
  await startFromAlert(page, world.stations['4'], 'Rescue 44');
  await toEquipment(page, '1033');
  await markLeft(page, 'Stair Chair', 'Kept for the second crew');
  const wizardUrl = page.url();

  await page.reload();
  await expect(page.getByText('Your saved work is back.')).toBeVisible({ timeout: 30_000 });
  await expect(heading(page, EQUIPMENT_HEADING)).toBeVisible();
  await expect(leftList(page)).toContainText('Stair Chair');
  await expect(leftList(page)).toContainText('Kept for the second crew');
  expect(page.url()).toBe(wizardUrl);
  await shot(page, '17-restored-after-reload');

  await primary(page, 'Continue').click();
  await expect(heading(page, 'Review')).toBeVisible();
  const submissions: string[] = [];
  let attempts = 0;
  await page.route('**/api/public/apparatuses/*/changeouts', async (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    attempts += 1;
    submissions.push((route.request().postDataJSON() as { client_submission_id: string }).client_submission_id);
    if (attempts === 1) return route.abort('internetdisconnected'); // never reaches the server
    if (attempts === 2) { await route.fetch(); return route.abort('internetdisconnected'); } // saved, but the answer is lost

    return route.continue();
  });

  await primary(page, 'Complete Changeout').click();
  const notSent = 'Not sent. Your work is saved on this phone. Try again when you have signal.';
  await expect(page.getByRole('alert').filter({ hasText: notSent })).toBeVisible({ timeout: 30_000 });
  expect(state().changeouts).toHaveLength(0);
  await shot(page, '18-not-sent');

  await primary(page, 'Try again').click();
  await expect(page.getByRole('alert').filter({ hasText: notSent })).toBeVisible({ timeout: 30_000 });
  expect(state().changeouts).toHaveLength(1); // the server committed it, the phone does not know

  await primary(page, 'Try again').click();
  await expect(h1(page)).toContainText('R 44 is now on truck 1033.', { timeout: 30_000 });
  expect(attempts).toBe(3);
  expect(new Set(submissions).size).toBe(1);
  await page.unroute('**/api/public/apparatuses/*/changeouts');

  const after = state();
  expect(after.changeouts).toHaveLength(1);
  expect(after.items.filter((item) => item.custody_status === 'left_behind')).toEqual([expect.objectContaining({ item_name: 'Stair Chair', note: 'Kept for the second crew' })]);
  expect(truck(after, '1033')).toMatchObject({ slug: 'r44' });
  expect(truck(after, '17503')).toMatchObject({ slug: 'r17503' });
  const leftover = await page.evaluate(() => Object.keys(localStorage).filter((key) => key.startsWith('mbfd_changeout_draft_')));
  expect(leftover).toEqual([]);
});

test('E2. A second member finishes the move first, and the first member gets a stale message that keeps the work', async ({ browser }, info) => {
  resetWorld();
  const first = member.page;
  await startFromAlert(first, world.stations['4'], 'Rescue 44');
  await toEquipment(first, '1033');
  await markLeft(first, 'Stair Chair', 'First crew note');
  await primary(first, 'Continue').click();
  await expect(heading(first, 'Review')).toBeVisible();

  const other = await signIn(browser, info, 'second');
  try {
    await startFromAlert(other.page, world.stations['4'], 'Rescue 44');
    await completeDefaultChangeout(other.page, '1033');
    await expect(h1(other.page)).toContainText('R 44 is now on truck 1033.', { timeout: 30_000 });
    expect(other.problems).toEqual([]);
  } finally {
    await other.context.close();
  }

  await primary(first, 'Complete Changeout').click();
  const conflict = first.getByRole('alert').filter({ hasText: 'R 44 is on truck 1033 now.' });
  await expect(conflict).toBeVisible({ timeout: 30_000 });
  await expect(primary(first, 'Complete Changeout')).toBeDisabled();
  await expect(first.getByTestId('changeout-review-left')).toContainText('First crew note');
  await shot(first, '19-stale-conflict');

  const after = state();
  expect(after.changeouts).toHaveLength(1);
  expect(after.changeouts[0]).toMatchObject({ submitted_by_name: 'Riley Second', status: 'completed' });
  expect(after.items.every((item) => item.changeout_id === after.changeouts[0].id)).toBe(true);
  expect(truck(after, '1033')).toMatchObject({ slug: 'r44' });
  expect(truck(after, '17503')).toMatchObject({ slug: 'r17503' });

  // The first member's work follows the unit onto its current truck, and nothing is moved twice.
  await first.getByRole('button', { name: 'Review with the current truck' }).click();
  await expect(h1(first)).toHaveText('R 44, truck 1033, Station 4', { timeout: 30_000 });
  await expect(first.getByRole('alert').filter({ hasText: 'R 44 is on truck 1033 now.' })).toBeVisible();
  expect(state().changeouts).toHaveLength(1);
  await shot(first, '20-stale-kept-work');
});

// ================================================================================================ F

test('F. A second account on the same phone never sees the first member\'s draft', async () => {
  resetWorld();
  const { page, context } = member;
  await page.evaluate(() => localStorage.clear());
  await startFromAlert(page, world.stations['4'], 'Rescue 44');
  await toEquipment(page, '1033');
  await markLeft(page, 'Stair Chair', 'First member only');
  const wizardUrl = page.url();
  const draftKeys = () => page.evaluate(() => Object.keys(localStorage).filter((key) => key.startsWith('mbfd_changeout_draft_')));
  expect(await draftKeys()).toHaveLength(1);

  // Same browser profile and storage; a different person signs in.
  const secondState = JSON.parse(readFileSync(join(authDirectory, 'second.json'), 'utf8')) as { cookies: Parameters<BrowserContext['addCookies']>[0] };
  const memberState = await context.storageState();
  await context.clearCookies();
  await context.addCookies(secondState.cookies);
  await page.goto(wizardUrl);
  await expect(heading(page, TRUCK_HEADING)).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText('Your saved work is back.')).toHaveCount(0);
  await expect(page.getByRole('radio', { name: /^1033\b/ })).not.toBeChecked();
  await pickTruck(page, '1033');
  await primary(page, 'Continue').click();
  await expect(heading(page, EQUIPMENT_HEADING)).toBeVisible();
  await expect(leftList(page)).toHaveCount(0);
  await expect(page.getByText('First member only')).toHaveCount(0);
  await shot(page, '21-shared-phone-second-member');
  expect(await draftKeys()).toHaveLength(2);

  // The first member's own draft is still theirs when they come back.
  await context.clearCookies();
  await context.addCookies(memberState.cookies);
  await page.goto(wizardUrl);
  await expect(page.getByText('Your saved work is back.')).toBeVisible({ timeout: 30_000 });
  await expect(leftList(page)).toContainText('First member only');
  expect(state().changeouts).toHaveLength(0);
});

// ================================================================================================ G

test('G. A typed number nobody has on file still completes, and Fleet is left to fill in the truck', async () => {
  resetWorld();
  const { page } = member;
  await page.evaluate(() => localStorage.clear());
  await startFromAlert(page, world.stations['4'], 'Rescue 44');

  await page.getByRole('button', { name: 'Different truck' }).click();
  await page.getByLabel('Or type the number on the truck').fill('77-01');
  await expect(page.getByTestId('changeout-what-happens')).toContainText('Truck 77-01 is not on the list yet.');
  await shot(page, '22-typed-unknown-truck');
  await primary(page, 'Continue').click();
  await expect(heading(page, EQUIPMENT_HEADING)).toBeVisible();
  await primary(page, 'Continue').click();
  await primary(page, 'Complete Changeout').click();

  await expect(h1(page)).toContainText('R 44 is now on truck 77-01.', { timeout: 30_000 });
  await expect(page.getByText("Fleet will add the truck's details.")).toBeVisible();
  await shot(page, '23-typed-unknown-done');

  const after = state();
  expect(after.changeouts).toEqual([expect.objectContaining({ status: 'completed', to_vehicle_number: '77-01' })]);
  expect(truck(after, '77-01')).toMatchObject({ slug: 'r44', status: 'In Service' });
  expect(truck(after, '17503')).toMatchObject({ status: 'Out of Service' });
});

// ================================================================================================ desktop

test('H. The receipt and the station card read well at desktop width', async ({ browser }, info) => {
  const desktop = await signIn(browser, info, 'fleet', { width: 1440, height: 900 });
  try {
    resetWorld();
    const { page } = member;
    await page.evaluate(() => localStorage.clear());
    await startFromAlert(page, world.stations['4'], 'Rescue 44');
    await completeDefaultChangeout(page, '1033');
    await expect(h1(page)).toContainText('R 44 is now on truck 1033.', { timeout: 30_000 });

    await desktop.page.goto(`/daily/changeouts/${state().changeouts[0].id}`);
    await expect(desktop.page.getByText(/^Took \d+, left 0$/)).toBeVisible({ timeout: 30_000 });
    await shot(desktop.page, '24-desktop-receipt');
    await desktop.page.goto(stationUrl(world.stations['1']));
    await expect(desktop.page.getByTestId('unit-alert')).toBeVisible({ timeout: 30_000 });
    await shot(desktop.page, '25-desktop-station-alert');
    expect(desktop.problems).toEqual([]);
  } finally {
    await desktop.context.close();
  }
});
