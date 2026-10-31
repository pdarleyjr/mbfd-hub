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
 * The tests run in order in one serial group. Scenario B continues from the database scenario A leaves; every other
 * scenario starts by rebuilding the fleet rows.
 */

const SCREENS = join(process.cwd(), 'test-results', 'fleet-changeout-screens');
const FIXTURE = join(process.cwd(), 'tests', 'e2e', 'support', 'fleet-changeout-fixture.php');

interface Row { [column: string]: string | number | null }
interface State { trucks: Row[]; tickets: Row[]; defects: Row[]; alerts: Row[]; changeouts: Row[]; items: Row[] }
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

/** Tomorrow's date in Eastern time, which is when the doc says a member's submission stops being direct. */
function easternDate(daysAhead: number): string {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/New_York', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(Date.now() + daysAhead * 86_400_000));

  return parts;
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
const primary = (page: Page, name: string | RegExp) => page.getByRole('button', { name, exact: typeof name === 'string' });
const pickTruck = (page: Page, number: string) => page.getByRole('radio', { name: new RegExp(`^${number}\\b`) }).check();
const exceptions = (page: Page) => page.getByTestId('changeout-exceptions');
const stationUrl = (stationId: number) => `/daily/stations/${stationId}`;

/** Opens a station page and starts the changeout from the alert card whose title contains the text. */
async function startFromAlert(page: Page, stationId: number, titleText: string) {
  await page.goto(stationUrl(stationId));
  const card = page.getByTestId('unit-alert').filter({ hasText: titleText });
  await expect(card).toBeVisible({ timeout: 30_000 });
  await card.getByRole('link', { name: 'Start Changeout' }).click();
  await expect(heading(page, 'Select replacement truck')).toBeVisible();
}

/** Starts a changeout from the "Change vehicle" link on the unit's card, for a unit with no alert to start from. */
async function startFromUnitCard(page: Page, stationId: number, designation: string) {
  await page.goto(stationUrl(stationId));
  await page.getByRole('tab', { name: 'Apparatus' }).click();
  const card = page.locator('article').filter({ hasText: designation }).filter({ has: page.getByRole('link', { name: 'Change vehicle' }) });
  await expect(card.first()).toBeVisible({ timeout: 30_000 });
  await card.first().getByRole('link', { name: 'Change vehicle' }).click();
  await expect(heading(page, 'Select replacement truck')).toBeVisible();
}

async function toEquipment(page: Page, number: string) {
  await pickTruck(page, number);
  await primary(page, 'Continue').click();
  await expect(heading(page, 'Transfer equipment')).toBeVisible();
}

async function leaveOnTruck(page: Page, name: string, note?: string) {
  await page.getByLabel('Search equipment').fill(name);
  await page.getByRole('button', { name: new RegExp(`^${name}`) }).first().click();
  await page.getByRole('radio', { name: 'Leave on truck' }).check();
  if (note) await page.getByLabel('Short note (optional)').fill(note);
  await page.getByRole('button', { name: 'Done with this item' }).click();
  await page.getByLabel('Search equipment').fill('');
}

/** Truck, equipment, review and send with every default, and the Done screen's headline. */
async function completeDefaultChangeout(page: Page, number: string, finalButton: string | RegExp = 'Complete Changeout') {
  await toEquipment(page, number);
  await primary(page, 'Continue').click();
  await expect(heading(page, 'Review')).toBeVisible();
  await primary(page, finalButton).click();
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

test('A. Rescue 44 moves to reserve 1033 and the Med Box waits for Fleet to verify it', async () => {
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
  await expect(heading(page, 'Select replacement truck')).toBeVisible();
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44, truck 17503, Station 4');
  await pickTruck(page, '1033');
  await expect(page.getByText('Fleet will confirm this truck.')).toHaveCount(0);
  await shot(page, '02-truck-step');

  await primary(page, 'Continue').click();
  await expect(heading(page, 'Transfer equipment')).toBeVisible();
  const allEquipment = page.getByTestId('changeout-transfer-all');
  await expect(allEquipment).toContainText('moving to truck 1033');
  await expect(allEquipment).toContainText('Only change what is different.');
  const waiting = page.getByRole('region', { name: 'These need their own sign-off' });
  await expect(waiting).toContainText('Med Box');
  await expect(waiting).toContainText('Narcotic Book');
  await expect(waiting).toContainText('Narcotic Seal #');
  await shot(page, '03-equipment-step');

  await leaveOnTruck(page, 'Stair Chair', 'Needed on the old truck for the repair crew');
  await expect(exceptions(page)).toContainText('Stair Chair');
  await expect(allEquipment).toContainText('1 exception');
  await shot(page, '04-exceptions');

  await primary(page, 'Continue').click();
  await expect(heading(page, 'Review')).toBeVisible();
  await expect(page.getByTestId('changeout-review-exceptions')).toContainText('Needed on the old truck for the repair crew');
  await expect(page.getByRole('region', { name: 'Will wait for verification' })).toContainText('Med Box');
  await expect(page.getByText('open issues stay with truck 17503').or(page.getByText('open issue stays with truck 17503'))).toBeVisible();
  await shot(page, '05-review');

  await primary(page, 'Complete Changeout').click();
  await expect(page.getByRole('heading', { level: 1 })).toContainText('R 44 is now on truck 1033.', { timeout: 30_000 });
  const done = page.getByText(/\d+ moved, 1 left on truck 17503, 3 still open\./);
  await expect(done).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Waiting for verification' })).toBeVisible();
  await shot(page, '06-done');

  // The database says the same thing.
  const after = state();
  const moved = truck(after, '1033');
  const old = truck(after, '17503');
  expect(moved).toMatchObject({ slug: 'r44', designation: 'R 44', unit_id: 'R44', requirement: 'required', template: 'rescue', status: 'In Service', station_number: '4', vehicle_number: '1033' });
  expect(old).toMatchObject({ slug: 'r17503', unit_id: 'R-17503', name: 'RESCUE 17503', requirement: 'reserve', status: 'Maintenance', vehicle_number: '17503' });
  expect(after.tickets).toEqual([expect.objectContaining({ id: 1, status: 'submitted', vehicle_number: '17503' })]);
  expect(after.defects).toEqual([expect.objectContaining({ id: 1, status: 'open', resolved: 0, vehicle_number: '17503' })]);
  expect(after.changeouts).toHaveLength(1);
  const changeout = after.changeouts[0];
  expect(changeout).toMatchObject({ status: 'completed', handoff_status: 'pending_verification', from_vehicle_number: '17503', to_vehicle_number: '1033', unit_slug: 'r44', alert_id: world.r44Alert });
  const changeoutLines = lines(after, Number(changeout.id));
  const controlled = changeoutLines.filter((line) => line.custody_status === 'pending_verification');
  expect(controlled.map((line) => line.item_name).sort()).toEqual(['Med Box', 'Narcotic Book', 'Narcotic Seal #']);
  const left = changeoutLines.filter((line) => line.custody_status === 'left_behind');
  expect(left).toEqual([expect.objectContaining({ item_name: 'Stair Chair', handling: 'leave', note: 'Needed on the old truck for the repair crew' })]);
  expect(changeoutLines.filter((line) => line.custody_status === 'moved').length + left.length + controlled.length).toBe(changeoutLines.length);
  expect(after.alerts.find((alert) => alert.id === world.r44Alert)).toMatchObject({ status: 'active', completed_by_changeout_id: null });
  expect(after.alerts.find((alert) => alert.id === world.e1Alert)).toMatchObject({ status: 'active' });

  // The alert stays on the station page and says what it is waiting for.
  await page.goto(stationUrl(world.stations['4']));
  const waitingCard = page.getByTestId('unit-alert').filter({ hasText: 'Rescue 44' });
  await expect(waitingCard).toContainText('Waiting on verification');
  await shot(page, '07-station-awaiting-verification');

  // The Fleet manager verifies the controlled lines on the receipt page.
  const receipt = fleet.page;
  await receipt.goto(`/daily/changeouts/${changeout.id}?return_to=${encodeURIComponent(stationUrl(world.stations['4']).replace('/daily', ''))}`);
  await expect(receipt.getByRole('heading', { level: 1 })).toContainText('R 44 is now on truck 1033.', { timeout: 30_000 });
  const verifyHeading = receipt.getByRole('heading', { name: 'Waiting for verification' });
  await expect(verifyHeading).toBeVisible();
  await expect(receipt.getByRole('heading', { name: 'Left on truck 17503' })).toBeVisible();
  await expect(receipt.getByText('Needed on the old truck for the repair crew')).toBeVisible();
  await shot(receipt, '08-receipt-with-verify');
  for (let remaining = 3; remaining > 0; remaining -= 1) {
    await receipt.getByRole('button', { name: 'Verify', exact: true }).first().click();
    await receipt.getByLabel('Verification note (optional)').fill(`Counted and sealed (${remaining})`);
    await receipt.getByRole('button', { name: 'Record verification' }).click();
    // The line leaves the waiting list once the server has recorded it and the page has re-read the record.
    await expect(receipt.locator('section[aria-labelledby="changeout-waiting"] li')).toHaveCount(remaining - 1);
  }
  await expect(receipt.getByRole('heading', { name: 'Verified' })).toBeVisible();
  await expect(verifyHeading).toHaveCount(0);
  await shot(receipt, '09-receipt-verified');

  const verified = state();
  expect(lines(verified, Number(changeout.id)).filter((line) => line.custody_status === 'verified').map((line) => line.item_name).sort()).toEqual(['Med Box', 'Narcotic Book', 'Narcotic Seal #']);
  expect(verified.changeouts[0]).toMatchObject({ handoff_status: 'complete' });
  expect(verified.alerts.find((alert) => alert.id === world.r44Alert)).toMatchObject({ status: 'completed', completed_by_changeout_id: changeout.id });
  expect(verified.tickets[0]).toMatchObject({ status: 'submitted', vehicle_number: '17503' });

  await page.goto(stationUrl(world.stations['4']));
  await expect(page.getByRole('tablist')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('unit-alert').filter({ hasText: 'Rescue 44' })).toHaveCount(0);
  await shot(page, '10-station-after-completion');
});

test('B. Fleet releases 17503 and R 44 goes back with the earlier changeout in view', async () => {
  const before = state();
  expect(truck(before, '1033')).toMatchObject({ slug: 'r44' });
  fixture('release', '17503');
  const released = state();
  expect(truck(released, '17503')).toMatchObject({ status: 'Available' });
  expect(released.tickets[0]).toMatchObject({ status: 'completed' });
  expect(released.defects[0]).toMatchObject({ status: 'open' });

  const { page } = member;
  await startFromUnitCard(page, world.stations['4'], 'R 44');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44, truck 1033, Station 4');
  const previous = page.getByRole('region', { name: 'Previous changeout' });
  await expect(previous).toContainText('Truck 17503 to 1033');
  await expect(previous).toContainText('Left behind');
  await expect(previous).toContainText('Stair Chair: Needed on the old truck for the repair crew');
  const own = page.getByRole('radio', { name: /^17503\b/ });
  await expect(own).toBeVisible();
  await expect(page.locator('label').filter({ has: own })).toContainText('Back to its own truck');
  await pickTruck(page, '17503');
  await expect(page.getByText('Fleet will confirm this truck.')).toHaveCount(0);
  await shot(page, '11-return-with-previous-summary');

  await primary(page, 'Continue').click();
  await expect(heading(page, 'Transfer equipment')).toBeVisible();
  await expect(page.getByText('Last time: Needed on the old truck for the repair crew').first()).toBeAttached();
  await shot(page, '12-return-equipment');
  await primary(page, 'Continue').click();
  await expect(heading(page, 'Review')).toBeVisible();
  await primary(page, 'Complete Changeout').click();
  await expect(page.getByRole('heading', { level: 1 })).toContainText('R 44 is now on truck 17503.', { timeout: 30_000 });
  await shot(page, '13-return-done');

  const after = state();
  expect(after.changeouts).toHaveLength(2);
  expect(after.changeouts[0]).toMatchObject({ id: 1, from_vehicle_number: '17503', to_vehicle_number: '1033', status: 'completed' });
  expect(after.changeouts[1]).toMatchObject({ from_vehicle_number: '1033', to_vehicle_number: '17503', status: 'completed', return_of_changeout_id: 1 });
  expect(truck(after, '17503')).toMatchObject({ slug: 'r44', designation: 'R 44', requirement: 'required', status: 'In Service' });
  expect(truck(after, '1033')).toMatchObject({ slug: 'r1033', unit_id: 'R-1033', requirement: 'reserve' });
  expect(after.changeouts[0]).toMatchObject({ handoff_status: 'complete' });
});

// ================================================================================================ C

test('C. Engine 1 moves to reserve engine 002-18 and back, and the leading zeros stay', async () => {
  resetWorld();
  const { page } = member;
  await startFromAlert(page, world.stations['1'], 'Engine 1');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('E 1, truck 20503, Station 1');
  await expect(page.getByRole('radio', { name: /^002-18\b/ })).toBeVisible();
  await completeDefaultChangeout(page, '002-18');
  await expect(page.getByRole('heading', { level: 1 })).toContainText('E 1 is now on truck 002-18.', { timeout: 30_000 });

  let snapshot = state();
  expect(truck(snapshot, '002-18')).toMatchObject({ slug: 'e1', designation: 'E 1', vehicle_number: '002-18', status: 'In Service', requirement: 'required' });
  expect(truck(snapshot, '20503')).toMatchObject({ slug: 'e11', designation: 'E 11', vehicle_number: '20503', requirement: 'reserve' });
  expect(snapshot.changeouts).toEqual([expect.objectContaining({ from_vehicle_number: '20503', to_vehicle_number: '002-18', status: 'completed' })]);
  await shot(page, '14-engine-done');

  await startFromUnitCard(page, world.stations['1'], 'E 1');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('E 1, truck 002-18, Station 1');
  await expect(page.getByRole('region', { name: 'Previous changeout' })).toContainText('Truck 20503 to 002-18');
  await expect(page.locator('label').filter({ has: page.getByRole('radio', { name: /^20503\b/ }) })).toContainText('Back to its own truck');
  await completeDefaultChangeout(page, '20503');
  await expect(page.getByRole('heading', { level: 1 })).toContainText('E 1 is now on truck 20503.', { timeout: 30_000 });

  snapshot = state();
  expect(truck(snapshot, '20503')).toMatchObject({ slug: 'e1', designation: 'E 1', requirement: 'required', status: 'In Service' });
  expect(truck(snapshot, '002-18')).toMatchObject({ slug: 'e11', designation: 'E 11', vehicle_number: '002-18', requirement: 'reserve' });
  expect(snapshot.changeouts).toHaveLength(2);
  expect(snapshot.changeouts[1]).toMatchObject({ from_vehicle_number: '002-18', to_vehicle_number: '20503', return_of_changeout_id: 1 });
});

// ================================================================================================ D

test('D. A future date, and a unit with no alert, wait for Fleet and then move with the saved equipment choices', async ({ browser }, info) => {
  resetWorld(easternDate(2));
  const { page } = member;
  await startFromAlert(page, world.stations['4'], 'Rescue 44');
  await pickTruck(page, '1033');
  await expect(page.getByText('Fleet will confirm this truck. Your work is saved either way.')).toBeVisible();
  await primary(page, 'Continue').click();
  await expect(heading(page, 'Transfer equipment')).toBeVisible();
  await leaveOnTruck(page, 'Stair Chair', 'Stays for the repair crew');
  await primary(page, 'Continue').click();
  await expect(heading(page, 'Review')).toBeVisible();
  await expect(page.getByText('Fleet will confirm truck 1033.')).toBeVisible();
  await shot(page, '15-fleet-confirm-review');
  await primary(page, 'Send to Fleet').click();
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Sent to Fleet. Your equipment choices are saved.', { timeout: 30_000 });
  await expect(page.getByText('Nothing changes on either truck until Fleet decides.')).toBeVisible();
  await shot(page, '16-sent-to-fleet');

  let snapshot = state();
  expect(snapshot.changeouts).toEqual([expect.objectContaining({ status: 'pending_fleet', from_vehicle_number: '17503', to_vehicle_number: '1033' })]);
  const pendingId = Number(snapshot.changeouts[0].id);
  expect(truck(snapshot, '17503')).toMatchObject({ slug: 'r44', status: 'In Service' });
  expect(truck(snapshot, '1033')).toMatchObject({ slug: 'r1033', status: 'Available' });
  expect(snapshot.alerts.find((alert) => alert.id === world.r44Alert)).toMatchObject({ status: 'active' });

  // Fleet decides in the admin page.
  const admin = fleet.page;
  await admin.goto(`/admin/apparatus-changeouts/${pendingId}`);
  await expect(admin.getByRole('button', { name: 'Approve' }).first()).toBeVisible({ timeout: 90_000 });
  await shot(admin, '17-admin-pending-changeout');
  await admin.getByRole('button', { name: 'Approve' }).first().click();
  await admin.getByRole('button', { name: 'Approve and move the unit' }).click();
  await expect(admin.getByRole('button', { name: 'Approve and move the unit' })).toHaveCount(0, { timeout: 60_000 });
  await expect.poll(() => state().changeouts[0].status, { timeout: 30_000 }).toBe('completed');
  await shot(admin, '18-admin-approved-changeout');

  snapshot = state();
  expect(truck(snapshot, '1033')).toMatchObject({ slug: 'r44', designation: 'R 44', status: 'In Service', station_number: '4' });
  expect(truck(snapshot, '17503')).toMatchObject({ slug: 'r17503', status: 'Maintenance' });
  expect(snapshot.changeouts).toHaveLength(1);
  expect(lines(snapshot, pendingId).filter((line) => line.custody_status === 'left_behind')).toEqual([expect.objectContaining({ item_name: 'Stair Chair', note: 'Stays for the repair crew' })]);
  expect(lines(snapshot, pendingId).filter((line) => line.custody_status === 'pending_verification')).toHaveLength(3);
  expect(snapshot.tickets[0]).toMatchObject({ status: 'submitted', vehicle_number: '17503' });

  // A unit with no alert at all waits for Fleet the same way.
  resetWorld();
  fixture('clear-alert', 'e1');
  const noAlert = await signIn(browser, info, 'member');
  const phone = noAlert.page;
  await startFromUnitCard(phone, world.stations['1'], 'E 1');
  await completeDefaultChangeout(phone, '002-18', 'Send to Fleet');
  await expect(phone.getByRole('heading', { level: 1 })).toHaveText('Sent to Fleet. Your equipment choices are saved.', { timeout: 30_000 });
  snapshot = state();
  expect(snapshot.changeouts).toEqual([expect.objectContaining({ status: 'pending_fleet', unit_slug: 'e1', alert_id: null })]);
  expect(truck(snapshot, '20503')).toMatchObject({ slug: 'e1' });
  await noAlert.context.close();
});

// ================================================================================================ E

test('E1. Interrupted work comes back after a reload, and a send that is lost is not sent twice', async () => {
  resetWorld();
  const { page } = member;
  await startFromAlert(page, world.stations['4'], 'Rescue 44');
  await toEquipment(page, '1033');
  await leaveOnTruck(page, 'Stair Chair', 'Kept for the second crew');
  const wizardUrl = page.url();

  await page.reload();
  await expect(page.getByText('Your saved work is back.')).toBeVisible({ timeout: 30_000 });
  await expect(heading(page, 'Transfer equipment')).toBeVisible();
  await expect(exceptions(page)).toContainText('Stair Chair');
  await expect(exceptions(page)).toContainText('Kept for the second crew');
  expect(page.url()).toBe(wizardUrl);
  await shot(page, '19-restored-after-reload');

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
  await shot(page, '20-not-sent');

  await primary(page, 'Try again').click();
  await expect(page.getByRole('alert').filter({ hasText: notSent })).toBeVisible({ timeout: 30_000 });
  expect(state().changeouts).toHaveLength(1); // the server committed it, the phone does not know

  await primary(page, 'Try again').click();
  await expect(page.getByRole('heading', { level: 1 })).toContainText('R 44 is now on truck 1033.', { timeout: 30_000 });
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

test('E2. A second member finishes the move first, and the first member gets the conflict message and no second move', async ({ browser }, info) => {
  resetWorld();
  const first = member.page;
  await startFromAlert(first, world.stations['4'], 'Rescue 44');
  await toEquipment(first, '1033');
  await primary(first, 'Continue').click();
  await expect(heading(first, 'Review')).toBeVisible();

  const other = await signIn(browser, info, 'second');
  try {
    await startFromAlert(other.page, world.stations['4'], 'Rescue 44');
    await completeDefaultChangeout(other.page, '1033');
    await expect(other.page.getByRole('heading', { level: 1 })).toContainText('R 44 is now on truck 1033.', { timeout: 30_000 });
    expect(other.problems).toEqual([]);
  } finally {
    await other.context.close();
  }

  await primary(first, 'Complete Changeout').click();
  const conflict = first.getByRole('alert').filter({ hasText: 'R 44 is on truck 1033 now.' });
  await expect(conflict).toBeVisible({ timeout: 30_000 });
  await expect(primary(first, 'Complete Changeout')).toBeDisabled();
  await shot(first, '21-stale-conflict');

  const after = state();
  expect(after.changeouts).toHaveLength(1);
  expect(after.changeouts[0]).toMatchObject({ submitted_by_name: 'Riley Second', status: 'completed' });
  expect(after.items.every((item) => item.changeout_id === after.changeouts[0].id)).toBe(true);
  expect(truck(after, '1033')).toMatchObject({ slug: 'r44' });
  expect(truck(after, '17503')).toMatchObject({ slug: 'r17503' });

  // The first member can carry the work onto the unit's current truck, and the server still refuses a second move.
  await first.getByRole('button', { name: 'Review with the current truck' }).click();
  await expect(first.getByRole('heading', { level: 1 })).toHaveText('R 44, truck 1033, Station 4', { timeout: 30_000 });
  expect(state().changeouts).toHaveLength(1);
});

// ================================================================================================ F

test('F. A second account on the same phone never sees the first member\'s draft', async () => {
  resetWorld();
  const { page, context } = member;
  await startFromAlert(page, world.stations['4'], 'Rescue 44');
  await toEquipment(page, '1033');
  await leaveOnTruck(page, 'Stair Chair', 'First member only');
  const wizardUrl = page.url();
  const draftKeys = () => page.evaluate(() => Object.keys(localStorage).filter((key) => key.startsWith('mbfd_changeout_draft_')));
  expect(await draftKeys()).toHaveLength(1);

  // Same browser profile and storage; a different person signs in.
  const secondState = JSON.parse(readFileSync(join(authDirectory, 'second.json'), 'utf8')) as { cookies: Parameters<BrowserContext['addCookies']>[0] };
  const memberState = await context.storageState();
  await context.clearCookies();
  await context.addCookies(secondState.cookies);
  await page.goto(wizardUrl);
  await expect(heading(page, 'Select replacement truck')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText('Your saved work is back.')).toHaveCount(0);
  await expect(page.getByRole('radio', { name: /^1033\b/ })).not.toBeChecked();
  await pickTruck(page, '1033');
  await primary(page, 'Continue').click();
  await expect(heading(page, 'Transfer equipment')).toBeVisible();
  await expect(page.getByTestId('changeout-exceptions')).toHaveCount(0);
  await expect(page.getByText('First member only')).toHaveCount(0);
  await shot(page, '22-shared-phone-second-member');
  expect(await draftKeys()).toHaveLength(2);

  // The first member's own draft is still theirs when they come back.
  await context.clearCookies();
  await context.addCookies(memberState.cookies);
  await page.goto(wizardUrl);
  await expect(page.getByText('Your saved work is back.')).toBeVisible({ timeout: 30_000 });
  await expect(exceptions(page)).toContainText('First member only');
  expect(state().changeouts).toHaveLength(0);
});

// ================================================================================================ desktop

test('G. The Fleet receipt reads well at desktop width', async ({ browser }, info) => {
  const desktop = await signIn(browser, info, 'fleet', { width: 1440, height: 900 });
  try {
    resetWorld();
    // A completed changeout with open controlled lines, made through the UI as the member.
    const { page } = member;
    // Scenario F left the first member's saved draft on this phone, which is correct; this one starts clean.
    await page.evaluate(() => localStorage.clear());
    await startFromAlert(page, world.stations['4'], 'Rescue 44');
    await completeDefaultChangeout(page, '1033');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('R 44 is now on truck 1033.', { timeout: 30_000 });

    await desktop.page.goto(`/daily/changeouts/${state().changeouts[0].id}`);
    await expect(desktop.page.getByRole('heading', { name: 'Waiting for verification' })).toBeVisible({ timeout: 30_000 });
    await shot(desktop.page, '23-desktop-receipt');
    await desktop.page.goto(stationUrl(world.stations['4']));
    await expect(desktop.page.getByTestId('unit-alert')).toBeVisible({ timeout: 30_000 });
    await shot(desktop.page, '24-desktop-station-alert');
    expect(desktop.problems).toEqual([]);
  } finally {
    await desktop.context.close();
  }
});
