import { expect, test, type Page, type Route } from '@playwright/test';

/**
 * The redesigned station page, every API response mocked: basic facts in the header without growing it, the true
 * Overview (units, checkouts, requests), the whole checkout in a sheet, and the noon alert.
 */

const STATION_ID = 29;

const station = {
  id: STATION_ID,
  station_number: 1,
  name: 'Station 1',
  address: '1051 Jefferson Ave.',
  city: 'Miami Beach',
  state: 'FL',
  zip_code: '33139',
  phone: '305.673.7135',
  latitude: 25.78,
  longitude: -80.13,
  is_active: true,
  rooms: [],
  apparatuses: [],
  assigned_apparatus_count: 4,
  assigned_personnel_count: 14,
  dorm_beds_count: 14,
  assigned_units: ['E1', 'L1', 'R1', 'R11'],
};

const unit = (designation: string, overrides: Record<string, unknown> = {}) => ({
  apparatus_id: designation.length + designation.charCodeAt(0),
  designation,
  vehicle_number: '20503',
  type: 'Engine',
  slug: designation.toLowerCase().replace(/\s+/g, '-'),
  frontline: true,
  state: 'not_submitted',
  inspection_id: null,
  submitted_at: null,
  ...overrides,
});

const e1 = unit('E 1', { apparatus_id: 1, state: 'submitted', inspection_id: 501, submitted_at: '2026-10-31T11:42:00+00:00' });
const r1 = unit('R 1', { apparatus_id: 2, type: 'Rescue' });
const r3 = unit('R 3', { apparatus_id: 3, type: 'Rescue', state: 'out_of_service' });

const checkoutRow = {
  id: 501,
  apparatus_id: 1,
  designation: 'E 1',
  vehicle_number: '20503',
  type: 'Engine',
  submitted_by: 'Lieutenant Alex Rivera',
  shift: 'B',
  submitted_at: '2026-10-31T11:42:00+00:00',
  status: 'Accepted',
  needs_attention: false,
};

const stationRequest = {
  id: 9,
  request_number: 'SR-2026-0009',
  station_id: STATION_ID,
  room_name_snapshot: 'Day Room',
  request_type: 'repair_service',
  title: 'Recliner footrest broken',
  description: 'Footrest will not lock.',
  priority: 'normal',
  status: 'pending',
  is_open: true,
  created_at: '2026-10-30T14:00:00+00:00',
  updated_at: '2026-10-30T14:00:00+00:00',
  items: [],
  updates: [],
};

const overview = (overrides: Record<string, unknown> = {}) => ({
  station_id: STATION_ID,
  as_of: '2026-10-31T13:00:00+00:00',
  shift_window: { starts_at: '2026-10-31T11:00:00+00:00', ends_at: '2026-11-01T12:00:00+00:00', alert_from: '2026-10-31T16:00:00+00:00' },
  units: [e1, r1, r3],
  checkouts: [checkoutRow],
  requests: [stationRequest],
  requests_total: 1,
  alert: { active: false, units: [] },
  ...overrides,
});

interface Mock {
  overview: unknown;
  overviewStatus: number;
  overviewRequests: Array<{ background: boolean }>;
}

async function openStation(page: Page, initial: unknown = overview()): Promise<Mock> {
  const mock: Mock = { overview: initial, overviewStatus: 200, overviewRequests: [] };
  await page.route('**/images/**', (route) => (new URL(route.request().url()).pathname.includes('/stations/station-1')
    ? route.fulfill({ path: 'public/images/stations/station-1.webp', contentType: 'image/webp' })
    : route.fulfill({ status: 204 })));
  await page.route('**/api/**', async (route: Route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    if (path === '/api/me/context') {
      return route.fulfill({ json: {
        version: 1,
        identity: { user_id: 401, has_personnel_profile: true },
        personnel: { employee_profile_id: 1_401, employee_number: 'E401', name: 'Alex Rivera', rank: 'Lieutenant' },
        offline: { security_version: 1 },
        session: { authenticated: true },
        authorization: { capabilities: { command_300_access: false } },
      } });
    }
    if (path === `/api/public/stations/${STATION_ID}`) return route.fulfill({ json: station });
    if (path === `/api/public/stations/${STATION_ID}/overview`) {
      mock.overviewRequests.push({ background: request.headers()['x-hub-background-refresh'] === '1' });
      return route.fulfill({ status: mock.overviewStatus, json: mock.overview as object });
    }
    if (path === `/api/public/stations/${STATION_ID}/checkouts/501`) {
      return route.fulfill({ json: { checkout: { ...checkoutRow, engine_hours: '1234.5', miles: 40210, compartments: [
        { name: 'Front Cab', items: [
          { name: 'Lights (Check)', status: 'Present', value: null, notes: null, has_photo: false },
          { name: 'Siren (Check)', status: 'Damaged', value: null, notes: 'Cracked horn', has_photo: true },
        ] },
        { name: 'Rear Compartment', items: [{ name: 'Oxygen', status: 'Present', value: '1800', notes: null, has_photo: false }] },
      ] } } });
    }
    return route.fulfill({ json: { data: [], meta: { total: 0 } } });
  });
  await page.goto(`/daily/stations/${STATION_ID}`);
  await expect(page.getByRole('heading', { level: 1, name: 'Station 1' })).toBeVisible();
  return mock;
}

test('the header carries the basic facts once, and the Overview no longer repeats them', async ({ page }) => {
  await openStation(page);

  const facts = page.getByRole('definition').filter({ hasText: /^14$/ });
  await expect(facts).toHaveCount(2);
  const header = page.getByTestId('station-header');
  await expect(header.getByText('Apparatus', { exact: true })).toBeVisible();
  await expect(header.getByText('Personnel', { exact: true })).toBeVisible();
  await expect(header.getByText('Dorm beds', { exact: true })).toBeVisible();
  for (const name of ['E1', 'L1', 'R1', 'R11']) await expect(header.getByText(name, { exact: true })).toBeVisible();

  await expect(page.getByText('1051 Jefferson Ave.')).toHaveCount(1);
  await expect(page.getByRole('heading', { name: 'Location' })).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Station Information' })).toHaveCount(0);
  await expect(page.getByText('Coordinates')).toHaveCount(0);
});

test('the Overview shows who has turned in, the checkouts and the requests', async ({ page }) => {
  await openStation(page);

  await expect(page.getByTestId('station-unit-count')).toHaveText('1 of 2 turned in');
  const tiles = page.getByTestId('station-unit-tile');
  await expect(tiles).toHaveCount(3);
  await expect(tiles.nth(0)).toContainText('E 1');
  await expect(tiles.nth(0)).toContainText('Turned in 7:42 AM');
  await expect(tiles.nth(1)).toContainText('Not turned in');
  await expect(tiles.nth(1)).toHaveAttribute('href', `/daily/vehicle-inspections/r-1?return_to=${encodeURIComponent(`/stations/${STATION_ID}`)}`);
  await expect(tiles.nth(2)).toContainText('Out of service');

  const checkout = page.getByTestId('station-checkout-row');
  await expect(checkout).toContainText('E 1');
  await expect(checkout).toContainText('#20503');
  await expect(checkout).toContainText('Lieutenant Alex Rivera');
  await expect(checkout).not.toContainText('Siren');

  await expect(page.getByTestId('station-request-row')).toContainText('Recliner footrest broken');
});

test('a checkout row opens the whole checkout in a sheet that closes back to the row', async ({ page }) => {
  await openStation(page);
  const row = page.getByTestId('station-checkout-row');
  await row.click();

  const sheet = page.getByTestId('station-checkout-sheet');
  await expect(sheet.getByRole('heading', { name: 'E 1 checkout' })).toBeVisible();
  await expect(sheet.getByText('Lieutenant Alex Rivera')).toBeVisible();
  await expect(sheet.getByText('40,210')).toBeVisible();
  await expect(sheet.getByText('3 items checked, 1 problem reported')).toBeVisible();
  // The compartment with a problem is already open; the rest open on a tap.
  await expect(sheet.getByText('Cracked horn')).toBeVisible();
  await expect(sheet.getByText('Photo attached')).toBeVisible();
  await expect(sheet.getByText('Oxygen')).toBeHidden();
  await sheet.getByText('Rear Compartment').click();
  await expect(sheet.getByText('Oxygen')).toBeVisible();
  await expect(sheet.getByText('Recorded: 1800')).toBeVisible();

  await page.keyboard.press('Escape');
  await expect(sheet).toHaveCount(0);
  await expect(row).toBeFocused();
});

test('a turned-in unit tile opens the same checkout', async ({ page }) => {
  await openStation(page);
  await page.getByTestId('station-unit-tile').first().click();
  await expect(page.getByTestId('station-checkout-sheet').getByRole('heading', { name: 'E 1 checkout' })).toBeVisible();
});

test('a request row opens its details', async ({ page }) => {
  await openStation(page);
  await page.getByTestId('station-request-row').click();
  const sheet = page.getByTestId('station-request-sheet');
  await expect(sheet.getByText('SR-2026-0009')).toBeVisible();
  await expect(sheet.getByText('Footrest will not lock.')).toBeVisible();
});

test('the alert shows only when the server says it is due, on any tab, and appears without a reload', async ({ page }) => {
  const clock = page.clock;
  await clock.install();
  const mock = await openStation(page);
  const alert = page.getByTestId('station-shift-alert');
  await expect(alert).toHaveCount(0);

  mock.overview = overview({ alert: { active: true, units: [r1] } });
  await clock.fastForward(61_000);
  await expect(alert).toBeVisible();
  await expect(alert).toContainText('No inspection this shift for R 1.');
  await expect(alert.getByRole('link', { name: 'Start R 1' })).toHaveAttribute('href', `/daily/vehicle-inspections/r-1?return_to=${encodeURIComponent(`/stations/${STATION_ID}`)}`);
  expect(mock.overviewRequests.at(0)?.background).toBe(false);
  expect(mock.overviewRequests.at(-1)?.background).toBe(true);

  await page.getByRole('tab', { name: 'Rooms' }).click();
  await expect(alert).toBeVisible();

  mock.overview = overview({ alert: { active: false, units: [] } });
  await clock.fastForward(61_000);
  await expect(alert).toHaveCount(0);
});

test('a failed refresh keeps what is on screen; a failed first load offers a retry and leaves the page usable', async ({ page }) => {
  const clock = page.clock;
  await clock.install();
  const mock = await openStation(page);
  await expect(page.getByTestId('station-checkout-row')).toBeVisible();

  mock.overviewStatus = 500;
  const failedRefresh = page.waitForResponse((response) => response.url().endsWith('/overview') && response.status() === 500);
  await clock.fastForward(61_000);
  await (await failedRefresh).finished();
  await clock.runFor(100);
  await expect(page.getByTestId('station-checkout-row')).toBeVisible();
  await expect(page.getByText('The station overview could not be loaded', { exact: true })).toHaveCount(0);

  await page.reload();
  await expect(page.getByText('The station overview could not be loaded', { exact: true })).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Station actions' })).toBeVisible();
  await expect(page.getByTestId('station-shift-alert')).toHaveCount(0);

  mock.overviewStatus = 200;
  await page.getByRole('button', { name: 'Retry' }).click();
  await expect(page.getByTestId('station-checkout-row')).toBeVisible();
});

test('polling waits while the page is hidden and catches up when it comes back', async ({ page }) => {
  const clock = page.clock;
  await clock.install();
  const mock = await openStation(page);
  await expect.poll(() => mock.overviewRequests.length).toBe(1);

  await page.evaluate(() => Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' }));
  await clock.fastForward(61_000);
  await clock.fastForward(61_000);
  await clock.runFor(100);
  expect(mock.overviewRequests).toHaveLength(1);

  await page.evaluate(() => {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await expect.poll(() => mock.overviewRequests.length).toBe(2);
  expect(mock.overviewRequests[1].background).toBe(true);
});

test('a page left open goes to sign-in once the session has ended', async ({ page }) => {
  const clock = page.clock;
  await clock.install();
  const mock = await openStation(page);
  await expect(page.getByTestId('station-checkout-row')).toBeVisible();

  mock.overview = { message: 'Unauthenticated.' };
  mock.overviewStatus = 401;
  await page.route('**/login**', (route) => route.fulfill({ contentType: 'text/html', body: '<h1>Sign in</h1>' }));
  await clock.fastForward(61_000);
  await expect.poll(() => new URL(page.url()).pathname).toBe('/login');
});

test('empty states explain themselves', async ({ page }) => {
  await openStation(page, overview({ units: [], checkouts: [], requests: [], requests_total: 0 }));
  await expect(page.getByText('No checkouts yet this shift')).toBeVisible();
  await expect(page.getByText('No open or recent requests')).toBeVisible();
  await expect(page.getByText('No units owe a daily inspection here')).toBeVisible();
});

// Measured on the page before this redesign with the same mock: the identity block (photo, name, address, phone,
// and now the facts) and the whole header including the action links.
const BASELINE = [
  { width: 320, height: 568, identity: 325, header: 718 },
  { width: 390, height: 844, identity: 301, header: 666 },
  { width: 640, height: 900, identity: 173, header: 366 },
  { width: 768, height: 1024, identity: 173, header: 366 },
  { width: 1280, height: 720, identity: 189, header: 322 },
  { width: 1920, height: 1080, identity: 189, header: 322 },
];

for (const { width, height, identity, header } of BASELINE) {
  test(`at ${width}px the header is not taller than before, nothing overflows and targets stay tappable`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height });
    await openStation(page, overview({ alert: { active: true, units: [r1] } }));
    await expect(page.getByTestId('station-shift-alert')).toBeVisible();

    const headerBox = await page.getByTestId('station-header').boundingBox();
    const actionsBox = await page.getByRole('navigation', { name: 'Station actions' }).boundingBox();
    if (!headerBox || !actionsBox) throw new Error('The station header did not render.');
    expect(actionsBox.y - headerBox.y).toBeLessThanOrEqual(identity);
    expect(headerBox.height).toBeLessThanOrEqual(header);

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);

    const undersized = await page.locator('[data-testid="station-overview"] a, [data-testid="station-overview"] button, [data-testid="station-header"] a, [data-testid="station-shift-alert"] a').evaluateAll((elements) => elements
      .map((element) => ({ text: element.textContent, box: element.getBoundingClientRect() }))
      .filter(({ box }) => box.height < 44 || box.width < 44)
      .map(({ text, box }) => `${text}: ${Math.round(box.width)}x${Math.round(box.height)}`));
    expect(undersized).toEqual([]);

    const shot = testInfo.outputPath(`station-${width}.png`);
    await page.screenshot({ path: shot, fullPage: true });
    await testInfo.attach(`station ${width}`, { path: shot, contentType: 'image/png' });
  });
}
