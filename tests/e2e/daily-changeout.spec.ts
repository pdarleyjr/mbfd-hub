import { expect, test, type Page, type Route } from '@playwright/test';
import { mobileApparatus, mobileInspectionFixture } from './support/daily-checkout-mobile';

/**
 * The apparatus changeout on a firefighter's phone, every API response mocked: the station card, the three steps, and
 * what happens when the signal drops or the records moved underneath the member (docs/apparatus-changeouts.md).
 */

const STATION_ID = 32;
const TRUCK_ID = 52;
const NEW_TRUCK_ID = 57;
const ALERT_ID = 12;
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const VERSION = 'a'.repeat(64);
const NOT_SENT = 'Not sent. Your work is saved on this phone. Try again when you have signal.';

test.use({ viewport: { width: 390, height: 844 } });

const alert = (overrides: Record<string, unknown> = {}) => ({
  id: ALERT_ID,
  category: 'upcoming_swap',
  category_label: 'Upcoming vehicle change',
  title: 'Upcoming Apparatus Changeout: Rescue 44',
  message: 'Rescue 44 moves to a reserve truck for an exhaust repair.',
  reason: 'Exhaust repair',
  instructions: null,
  unit_designation: 'R 44',
  vehicle_number: '17503',
  current_vehicle_number: '17503',
  current_apparatus_id: TRUCK_ID,
  schedule_label: 'Sunday, November 1, afternoon',
  scheduled_date: '2026-11-01',
  scheduled_window: 'afternoon',
  scheduled_at: null,
  date_passed: false,
  workflow: { state: 'planned', label: 'Planned', changeout_id: null },
  action: { kind: 'start', label: 'Start Changeout', apparatus_id: TRUCK_ID, alert_id: ALERT_ID, changeout_id: null },
  ...overrides,
});

const item = (key: string, name: string, overrides: Record<string, unknown> = {}) => ({
  item_key: key,
  name,
  expected_quantity: null,
  controlled: false,
  default_handling: 'transfer',
  previous: null,
  ...overrides,
});

const context = (overrides: Record<string, unknown> = {}) => ({
  unit: { apparatus_id: TRUCK_ID, designation: 'R 44', slug: 'r44', vehicle_number: '17503', station_id: STATION_ID, station_label: 'Station 4', status: 'In Service' },
  alert: { id: ALERT_ID, title: 'Upcoming Apparatus Changeout: Rescue 44', reason: 'Exhaust repair', schedule_label: 'Sunday, November 1, afternoon' },
  mode: 'start',
  candidates: [
    { apparatus_id: NEW_TRUCK_ID, vehicle_number: '1033', label: 'Rescue 1033', location: 'Station 2', status: 'Available', direct: true, is_return: false },
    { apparatus_id: 59, vehicle_number: '1035', label: 'Rescue 1035', location: 'Station 3', status: 'Reserve', direct: false, is_return: false },
  ],
  other_vehicles: [{ apparatus_id: 58, vehicle_number: '1034', label: 'Rescue 1034', location: 'Station 2', status: 'In Service' }],
  equipment: {
    checklist_version: VERSION,
    usable: true,
    compartments: [
      { id: 'front_cab', name: 'Front Cab', items: [item('front_cab-item-1', 'Portable radio', { expected_quantity: 2 }), item('front_cab-item-2', 'Flashlight')] },
      { id: 'rear', name: 'Rear Compartment', items: [item('rear-item-1', 'Spare oxygen bottle'), item('rear-item-2', 'Stair chair')] },
      { id: 'controlled', name: 'Controlled', items: [item('field:seal', 'Narcotic Seal #', { controlled: true, default_handling: 'review' })] },
    ],
  },
  open_issues_on_truck: 3,
  previous_changeout: null,
  pending_changeout: null,
  can_fleet_commit: false,
  ...overrides,
});

const summary = (overrides: Record<string, unknown> = {}) => ({
  id: 8,
  status: 'completed',
  handoff_status: 'pending_verification',
  unit_designation: 'R 44',
  from_vehicle_number: '17503',
  to_vehicle_number: '1033',
  effective_at: '2026-11-01T20:10:00Z',
  moved: 3,
  left_behind: 1,
  open: 1,
  open_items: [{ name: 'Narcotic Seal #', status: 'pending_verification' }],
  alert_completed: false,
  can_verify: false,
  next_daily_checkout_path: '/daily/apparatus/r44',
  message: 'R 44 is now on truck 1033.',
  ...overrides,
});

interface Mock {
  contextGets: string[];
  posts: Array<{ path: string; body: Record<string, unknown> }>;
  verifies: Array<Record<string, unknown>>;
  alertsAnswer: { status: number | 'abort' };
}

interface Options {
  alerts?: unknown[];
  alertStatus?: number | 'abort';
  identityStatus?: number;
  contextFor?: (path: string, attempt: number) => { status?: number; json: unknown } | 'abort';
  onPost?: (route: Route, attempt: number) => Promise<void> | void;
  changeout?: unknown;
  station?: Record<string, unknown>;
}

async function mockApi(page: Page, options: Options = {}): Promise<Mock> {
  const mock: Mock = { contextGets: [], posts: [], verifies: [], alertsAnswer: { status: options.alertStatus ?? 200 } };
  await page.route('**/images/**', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    if (path === '/api/me/context') {
      if (options.identityStatus) return route.fulfill({ status: options.identityStatus, json: { message: 'No' } });

      return route.fulfill({ json: {
        version: 1,
        identity: { user_id: 401, has_personnel_profile: true },
        personnel: { employee_profile_id: 1401, employee_number: 'E401', name: 'Alex Rivera', rank: 'Captain' },
        offline: { security_version: 1 },
        session: { authenticated: true },
        authorization: { capabilities: { command_300_access: false } },
      } });
    }
    if (path === `/api/public/stations/${STATION_ID}`) {
      return route.fulfill({ json: { id: STATION_ID, station_number: 4, name: 'Station 4', address: '6860 Indian Creek Dr', city: 'Miami Beach', state: 'FL', zip_code: '33141', phone: '', is_active: true, rooms: [], apparatuses: [], assigned_apparatus_count: 1, assigned_personnel_count: 3, dorm_beds_count: 3, assigned_units: ['R 44'], ...options.station } });
    }
    if (path === `/api/public/stations/${STATION_ID}/service-tickets`) return route.fulfill({ json: { data: [], meta: { total: 0 } } });
    if (path === `/api/public/stations/${STATION_ID}/operational-alerts`) {
      const { status } = mock.alertsAnswer;
      if (status === 'abort') return route.abort('internetdisconnected');
      if (status !== 200) return route.fulfill({ status, json: { message: 'No' } });

      return route.fulfill({ json: { alerts: options.alerts ?? [alert()] } });
    }
    if (/^\/api\/public\/apparatuses\/\d+\/changeout-context$/.test(path)) {
      mock.contextGets.push(`${path}${new URL(request.url()).search}`);
      const answer = options.contextFor?.(path, mock.contextGets.length) ?? { json: context() };
      if (answer === 'abort') return route.abort('internetdisconnected');

      return route.fulfill({ status: answer.status ?? 200, json: answer.json });
    }
    if (request.method() === 'POST' && /^\/api\/public\/apparatuses\/\d+\/changeouts$/.test(path)) {
      mock.posts.push({ path, body: request.postDataJSON() as Record<string, unknown> });
      if (options.onPost) return options.onPost(route, mock.posts.length);

      return route.fulfill({ status: 201, json: { changeout: summary(), replayed: false } });
    }
    if (request.method() === 'POST' && /\/verify$/.test(path)) {
      mock.verifies.push(request.postDataJSON() as Record<string, unknown>);

      return route.fulfill({ json: { changeout: summary() } });
    }
    if (/^\/api\/public\/changeouts\/\d+$/.test(path)) return route.fulfill({ json: { changeout: options.changeout ?? summary() } });

    return route.fulfill({ status: 404, json: { message: `Unmocked route ${path}` } });
  });

  return mock;
}

const wizardUrl = `/daily/changeouts/new/${TRUCK_ID}?alert_id=${ALERT_ID}&return_to=${encodeURIComponent(`/stations/${STATION_ID}`)}`;
const heading = (page: Page, name: string) => page.getByRole('heading', { name, exact: true });
const primary = (page: Page, name: string | RegExp) => page.getByRole('button', { name, exact: typeof name === 'string' });
const pickTruck = (page: Page, number: string) => page.getByRole('radio', { name: new RegExp(`^${number}\\b`) }).check();
const exceptions = (page: Page) => page.getByTestId('changeout-exceptions');

async function toEquipment(page: Page, number = '1033') {
  await page.goto(wizardUrl);
  await expect(heading(page, 'Select replacement truck')).toBeVisible();
  await pickTruck(page, number);
  await primary(page, 'Continue').click();
  await expect(heading(page, 'Transfer equipment')).toBeVisible();
}

async function leaveOnTruck(page: Page, name: string, note?: string) {
  await page.getByLabel('Search equipment').fill(name);
  await page.getByRole('button', { name: new RegExp(`^${name}`) }).last().click();
  await page.getByRole('radio', { name: 'Leave on truck' }).check();
  if (note) await page.getByLabel('Short note (optional)').fill(note);
  await page.getByRole('button', { name: 'Done with this item' }).click();
  await page.getByLabel('Search equipment').fill('');
}

async function toReview(page: Page) {
  await toEquipment(page);
  await primary(page, 'Continue').click();
  await expect(heading(page, 'Review')).toBeVisible();
}

test.describe('the station card', () => {
  test('reads plainly, sits above the tabs and starts the changeout', async ({ page }) => {
    await mockApi(page);
    await page.goto(`/daily/stations/${STATION_ID}`);

    const card = page.getByTestId('unit-alert');
    await expect(card.getByRole('heading', { name: 'Upcoming Apparatus Changeout: Rescue 44' })).toBeVisible();
    await expect(card).toContainText('Truck 17503 · Sunday, November 1, afternoon');
    await expect(card).toContainText('Rescue 44 moves to a reserve truck');
    await expect(card).toContainText('Planned');
    await expect(card).not.toContainText('Planned date has passed');

    const cardBox = await card.boundingBox();
    const tabsBox = await page.getByRole('tablist').boundingBox();
    expect(cardBox && tabsBox && cardBox.y < tabsBox.y).toBe(true);

    await card.getByRole('link', { name: 'Start Changeout' }).click();
    await expect(heading(page, 'Select replacement truck')).toBeVisible();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44, truck 17503, Station 4');
  });

  test('says when the planned date has passed, and shows a view link for one waiting on Fleet', async ({ page }) => {
    await mockApi(page, { alerts: [alert({
      date_passed: true,
      workflow: { state: 'awaiting_fleet', label: 'Waiting on Fleet', changeout_id: 8 },
      action: { kind: 'view', label: 'See the changeout', apparatus_id: TRUCK_ID, alert_id: ALERT_ID, changeout_id: 8 },
    })] });
    await page.goto(`/daily/stations/${STATION_ID}`);

    await expect(page.getByTestId('unit-alert')).toContainText('Planned date has passed');
    await expect(page.getByRole('link', { name: 'See the changeout' })).toHaveAttribute('href', /\/daily\/changeouts\/8\?/);
  });

  const silent: Array<[string, Options]> = [['the list is empty', { alerts: [] }], ['the check answers 404', { alertStatus: 404 }]];
  for (const [name, options] of silent) {
    test(`renders nothing when ${name}`, async ({ page }) => {
      await mockApi(page, options);
      await page.goto(`/daily/stations/${STATION_ID}`);
      await expect(page.getByRole('tab', { name: 'Overview' })).toBeVisible();
      await expect(page.getByText('Could not check for unit alerts.')).toHaveCount(0);
      await expect(page.getByTestId('unit-alerts')).toHaveCount(0);
    });
  }

  const quiet: Array<[string, Options]> = [['a 500', { alertStatus: 500 }], ['a 429', { alertStatus: 429 }], ['a dropped connection', { alertStatus: 'abort' }]];
  for (const [name, options] of quiet) {
    test(`says one quiet line after ${name}, and Try again recovers`, async ({ page }) => {
      const mock = await mockApi(page, options);
      await page.goto(`/daily/stations/${STATION_ID}`);

      await expect(page.getByText('Could not check for unit alerts.')).toBeVisible();
      await expect(page.getByTestId('unit-alert')).toHaveCount(0);
      mock.alertsAnswer = { status: 200 };
      await page.getByRole('button', { name: 'Try again' }).click();
      await expect(page.getByTestId('unit-alert')).toBeVisible();
      await expect(page.getByText('Could not check for unit alerts.')).toHaveCount(0);
    });
  }

  test('stays silent offline, and checks again when the signal returns', async ({ page, context: browser }) => {
    const mock = await mockApi(page, { alertStatus: 500 });
    await page.goto(`/daily/stations/${STATION_ID}`);
    await expect(page.getByText('Could not check for unit alerts.')).toBeVisible();

    await browser.setOffline(true);
    await expect(page.getByText('Could not check for unit alerts.')).toHaveCount(0);

    mock.alertsAnswer = { status: 200 };
    await browser.setOffline(false);
    await expect(page.getByTestId('unit-alert')).toBeVisible();
  });

  test('an expired session uses the sign-in path', async ({ page }) => {
    await mockApi(page, { alertStatus: 401 });
    await page.route('**/login**', (route) => route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Sign in</title>' }));
    await page.goto(`/daily/stations/${STATION_ID}`);
    await page.waitForURL(/\/login\?session_expired=1/);
  });
});

test.describe('the changeout flow', () => {
  test('truck, one exception, review, done: sends only the exception and offers the new truck its checkout', async ({ page }) => {
    const mock = await mockApi(page);
    await toEquipment(page);

    await expect(page.getByTestId('changeout-transfer-all')).toContainText('Transfer all equipment');
    await expect(page.getByTestId('changeout-transfer-all')).toContainText('4 of 4 items moving to truck 1033');
    await expect(page.getByRole('button', { name: /^Front Cab/ })).toHaveAttribute('aria-expanded', 'false');

    await leaveOnTruck(page, 'Spare oxygen bottle', 'Stays with the truck');
    await expect(exceptions(page)).toContainText('Spare oxygen bottle');
    await expect(exceptions(page)).toContainText('Leave on truck');
    await expect(page.getByTestId('changeout-transfer-all')).toContainText('3 of 4 items');

    await primary(page, 'Continue').click();
    await expect(heading(page, 'Review')).toBeVisible();
    await expect(page.getByTestId('changeout-review-exceptions')).toContainText('Spare oxygen bottle');
    await expect(page.getByTestId('changeout-review-exceptions')).toContainText('Stays with the truck');
    await page.getByLabel('Note for Fleet (optional)').fill('Moved at Station 2.');
    await primary(page, 'Complete Changeout').click();

    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44 is now on truck 1033.');
    await expect(page.getByRole('heading', { level: 1 })).toBeFocused();
    await expect(page.getByText('Narcotic Seal #')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Start Daily Checkout on truck 1033' })).toHaveAttribute('href', '/daily/apparatus/r44');
    await expect(page.getByRole('link', { name: 'Back to station' })).toHaveAttribute('href', `/daily/stations/${STATION_ID}`);

    expect(mock.posts).toHaveLength(1);
    expect(mock.posts[0].path).toBe(`/api/public/apparatuses/${TRUCK_ID}/changeouts`);
    expect(mock.posts[0].body).toEqual({
      client_submission_id: expect.stringMatching(UUID_PATTERN),
      expected_vehicle_number: '17503',
      expected_unit_slug: 'r44',
      alert_id: ALERT_ID,
      to_apparatus_id: NEW_TRUCK_ID,
      to_vehicle_number: null,
      checklist_version: VERSION,
      exceptions: [{ item_key: 'rear-item-1', handling: 'leave', quantity: null, condition: 'ok', note: 'Stays with the truck' }],
      note: 'Moved at Station 2.',
    });

    // The draft is gone, and a reload stays on Done instead of starting a new changeout.
    const leftovers = await page.evaluate(() => Object.keys(localStorage).filter((key) => key.startsWith('mbfd_changeout_')));
    expect(leftovers).toEqual([]);
    await page.reload();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44 is now on truck 1033.');
    expect(mock.posts).toHaveLength(1);
  });

  test('a quantity stepper appears only when the count is known, and a partial count is sent', async ({ page }) => {
    const mock = await mockApi(page);
    await toEquipment(page);
    await page.getByRole('button', { name: /^Front Cab/ }).click();

    await page.getByRole('button', { name: /^Flashlight/ }).click();
    await expect(page.getByRole('button', { name: 'One less' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Done with this item' }).click();

    await page.getByRole('button', { name: /^Portable radio/ }).click();
    await page.getByRole('button', { name: 'One less' }).click();
    await expect(page.locator('output')).toHaveText('1 of 2');
    await expect(page.getByRole('button', { name: 'One less' })).toBeDisabled();
    await page.getByRole('button', { name: 'Done with this item' }).click();
    // Focus returns to the row the member was on; the same line now also heads the Exceptions list.
    await expect(page.getByRole('button', { name: /^Portable radio/ })).toHaveCount(2);
    await expect(page.getByRole('button', { name: /^Portable radio/ }).last()).toBeFocused();
    await expect(exceptions(page)).toContainText('Moving 1 of 2');

    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44 is now on truck 1033.');
    expect(mock.posts[0].body.exceptions).toEqual([{ item_key: 'front_cab-item-1', handling: 'transfer', quantity: 1, condition: 'ok', note: null }]);
  });

  test('controlled lines are name only, with no control that could mark them moved', async ({ page }) => {
    const mock = await mockApi(page);
    await toEquipment(page);

    const signOff = page.getByRole('region', { name: 'These need their own sign-off' });
    await expect(signOff).toContainText('Narcotic Seal #');
    await expect(signOff).toContainText('waiting for verification');
    await expect(signOff.getByRole('button')).toHaveCount(0);
    await page.getByLabel('Search equipment').fill('Narcotic');
    await expect(page.getByText('No equipment matches that search.')).toBeVisible();
    await page.getByLabel('Search equipment').fill('');

    await primary(page, 'Continue').click();
    await expect(page.getByRole('region', { name: 'Will wait for verification' })).toContainText('Narcotic Seal #');
    await primary(page, 'Complete Changeout').click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44 is now on truck 1033.');
    expect(mock.posts[0].body.exceptions).toEqual([]);
  });

  test('a truck Fleet must confirm says so at every step and ends in plain words', async ({ page }) => {
    const mock = await mockApi(page, { onPost: (route) => route.fulfill({ status: 201, json: { changeout: summary({ status: 'pending_fleet', to_vehicle_number: 'NEW-9', next_daily_checkout_path: null, open_items: [] }), replayed: false } }) });
    await page.goto(wizardUrl);

    await page.getByRole('button', { name: 'Truck not listed?' }).click();
    await page.getByRole('radio', { name: 'Enter the truck number' }).check();
    await primary(page, 'Continue').click();
    await expect(page.getByRole('alert')).toContainText('Enter the truck number.');
    await page.getByLabel('Number on the truck').fill('<b>');
    await expect(page.getByRole('alert')).toContainText('Letters, numbers');
    await page.getByLabel('Number on the truck').fill('17503');
    await expect(page.getByRole('alert')).toContainText('truck R 44 is on now');
    await page.getByLabel('Number on the truck').fill('NEW-9');
    await expect(page.getByRole('status').filter({ hasText: 'Fleet will confirm this truck.' })).toBeVisible();

    await primary(page, 'Continue').click();
    await primary(page, 'Continue').click();
    await expect(page.getByText('Fleet will confirm truck NEW-9.')).toBeVisible();
    await primary(page, 'Send to Fleet').click();

    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Sent to Fleet. Your equipment choices are saved.');
    await expect(page.getByRole('link', { name: /Start Daily Checkout/ })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Back to station' })).toBeVisible();
    expect(mock.posts[0].body).toMatchObject({ to_apparatus_id: null, to_vehicle_number: 'NEW-9' });
  });

  test('going back to its own truck is flagged, shows last time, and starts from what happened then', async ({ page }) => {
    const mock = await mockApi(page, { contextFor: () => ({ json: context({
      candidates: [{ apparatus_id: 60, vehicle_number: '17503', label: 'Rescue 17503', location: 'Shop', status: 'Available', direct: true, is_return: true }],
      unit: { apparatus_id: TRUCK_ID, designation: 'R 44', slug: 'r44', vehicle_number: '1033', station_id: STATION_ID, station_label: 'Station 4', status: 'In Service' },
      previous_changeout: { id: 7, from_vehicle_number: '17503', to_vehicle_number: '1033', effective_at: '2026-11-01T20:10:00Z', moved: 3, left_behind: [{ name: 'Stair chair', note: 'Stayed on 17503' }], open: [{ name: 'Flashlight', status: 'needs_review' }], submitted_by_name: 'Alex Rivera' },
      equipment: { checklist_version: VERSION, usable: true, compartments: [{ id: 'rear', name: 'Rear Compartment', items: [
        item('rear-item-1', 'Spare oxygen bottle', { previous: { custody_status: 'moved', handling: 'transfer', note: null, return_handling: 'transfer', return_quantity: null } }),
        item('rear-item-2', 'Stair chair', { previous: { custody_status: 'left_behind', handling: 'leave', note: 'Stayed on 17503', return_handling: 'leave', return_quantity: null } }),
      ] }] },
    }) }) });
    await page.goto(wizardUrl);

    await expect(page.getByRole('radio', { name: /^17503/ })).toBeVisible();
    await expect(page.getByText('Back to its own truck')).toBeVisible();
    await expect(page.getByRole('region', { name: 'Previous changeout' })).toContainText('Stair chair: Stayed on 17503');
    await expect(page.getByRole('region', { name: 'Previous changeout' })).toContainText('Flashlight (needs review)');
    await pickTruck(page, '17503');
    await primary(page, 'Continue').click();

    await expect(page.getByTestId('changeout-transfer-all')).toContainText('1 of 2 items');
    await expect(exceptions(page)).toContainText('Stair chair');
    await expect(exceptions(page)).toContainText('Last time: Stayed on 17503');
    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44 is now on truck 1033.');
    expect(mock.posts[0].body.exceptions).toEqual([]);
  });

  test('when the list could not be loaded the member can still continue with a note', async ({ page }) => {
    const mock = await mockApi(page, { contextFor: () => ({ json: context({ equipment: { checklist_version: null, usable: false, compartments: [] } }) }) });
    await toEquipment(page);

    await expect(page.getByText('could not be loaded. Fleet will see this.')).toBeVisible();
    await primary(page, 'Continue').click();
    await expect(page.getByRole('alert')).toContainText('Add a short note');
    await page.getByLabel('What moved, and what stayed?').fill('Everything moved except the stair chair.');
    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44 is now on truck 1033.');
    expect(mock.posts[0].body).toMatchObject({ checklist_version: null, exceptions: [], note: 'Everything moved except the stair chair.' });
  });

  test('a unit with a changeout already waiting shows that one instead of a second form', async ({ page }) => {
    const mock = await mockApi(page, { contextFor: () => ({ json: context({ pending_changeout: summary({ id: 9, status: 'pending_fleet', submitted_by_name: 'Sam Lee' }) }) }) });
    await page.goto(wizardUrl);

    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44 already has a changeout waiting on Fleet');
    await expect(page.getByText('Sam Lee sent it for truck 1033.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'See the one waiting on Fleet' })).toHaveAttribute('href', /\/daily\/changeouts\/9\?/);
    expect(mock.posts).toHaveLength(0);
  });

  test('a handoff after the unit already moved needs no truck choice', async ({ page }) => {
    const mock = await mockApi(page, { contextFor: () => ({ json: context({
      mode: 'handoff_only',
      candidates: [],
      other_vehicles: [],
      unit: { apparatus_id: NEW_TRUCK_ID, designation: 'R 44', slug: 'r44', vehicle_number: '1033', station_id: STATION_ID, station_label: 'Station 4', status: 'In Service' },
      alert: { id: ALERT_ID, title: 'Upcoming', reason: 'Exhaust repair', schedule_label: 'Sunday', vehicle_number: '17503' },
    }) }) });
    await page.goto(wizardUrl);

    await expect(page.getByText('R 44 is already on truck 1033.')).toBeVisible();
    await expect(page.getByRole('radio')).toHaveCount(0);
    await primary(page, 'Continue').click();
    await primary(page, 'Continue').click();
    await expect(page.getByText('From truck')).toBeVisible();
    await primary(page, 'Record Handoff').click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44 is now on truck 1033.');
    expect(mock.posts[0].body).toMatchObject({ expected_vehicle_number: '1033', to_apparatus_id: NEW_TRUCK_ID, to_vehicle_number: null });
  });
});

test.describe('interruptions and poor signal', () => {
  test('work survives a reload, Back between steps loses nothing, and Start over clears it', async ({ page }) => {
    await mockApi(page);
    await toEquipment(page);
    await leaveOnTruck(page, 'Stair chair');

    await page.goBack();
    await expect(heading(page, 'Select replacement truck')).toBeVisible();
    await expect(page.getByRole('radio', { name: /^1033/ })).toBeChecked();
    await page.goForward();
    await expect(exceptions(page)).toContainText('Stair chair');

    await page.goto(wizardUrl);
    await expect(page.getByText('Your saved work is back.')).toBeVisible();
    await expect(heading(page, 'Transfer equipment')).toBeVisible();
    await expect(exceptions(page)).toContainText('Stair chair');

    await page.getByRole('button', { name: 'Start over' }).click();
    await page.getByRole('button', { name: 'Clear my choices' }).click();
    await expect(heading(page, 'Select replacement truck')).toBeVisible();
    await expect(page.getByRole('radio', { name: /^1033/ })).not.toBeChecked();
    const leftovers = await page.evaluate(() => Object.keys(localStorage).filter((key) => key.startsWith('mbfd_changeout_draft_')));
    expect(leftovers).toEqual([]);
  });

  test('focus goes to the step heading on every step change', async ({ page }) => {
    await mockApi(page);
    await toEquipment(page);
    await expect(page.locator('#changeout-step-heading')).toBeFocused();
    await primary(page, 'Continue').click();
    await expect(page.locator('#changeout-step-heading')).toHaveText('Review');
    await expect(page.locator('#changeout-step-heading')).toBeFocused();
    await primary(page, 'Back').click();
    await expect(page.locator('#changeout-step-heading')).toHaveText('Transfer equipment');
  });

  test('no signal: says Not sent, keeps everything, and a retry after reload reuses the same id', async ({ page }) => {
    const mock = await mockApi(page, {
      onPost: (route, attempt) => (attempt < 3
        ? route.abort('internetdisconnected')
        : route.fulfill({ status: 200, json: { changeout: summary(), replayed: true } })),
    });
    await toEquipment(page);
    await leaveOnTruck(page, 'Stair chair', 'Too big');
    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();

    await expect(page.getByRole('alert')).toHaveText(NOT_SENT);
    await expect(primary(page, 'Try again')).toBeEnabled();
    await expect(page.getByTestId('changeout-review-exceptions')).toContainText('Too big');
    const busy = await page.evaluate(() => Object.keys(localStorage).some((key) => key.startsWith('mbfd_changeout_draft_')));
    expect(busy).toBe(true);

    // Closing the app and coming back on no signal: no list needs fetching to retry.
    const before = mock.contextGets.length;
    await page.goto(wizardUrl);
    await expect(page.getByRole('alert')).toHaveText(NOT_SENT);
    await expect(heading(page, 'Review')).toBeVisible();
    expect(mock.contextGets.length).toBe(before);

    await primary(page, 'Try again').click();
    await expect(page.getByRole('alert')).toHaveText(NOT_SENT);
    await primary(page, 'Try again').click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44 is now on truck 1033.');

    expect(mock.posts).toHaveLength(3);
    expect(mock.posts[1].body).toEqual(mock.posts[0].body);
    expect(mock.posts[2].body).toEqual(mock.posts[0].body);
  });

  test('opening a saved draft with no signal uses the list saved on the phone', async ({ page }) => {
    const mock = await mockApi(page, { contextFor: (_path, attempt) => (attempt === 1 ? { json: context() } : 'abort') });
    await toEquipment(page);
    await leaveOnTruck(page, 'Stair chair');
    await page.goto(wizardUrl);

    await expect(page.getByText('this is the list saved on this phone')).toBeVisible();
    await expect(exceptions(page)).toContainText('Stair chair');
    expect(mock.contextGets.length).toBeGreaterThan(1);
  });

  test('no signal and nothing saved: says so, with Retry and a way back', async ({ page }) => {
    await mockApi(page, { contextFor: () => 'abort' });
    await page.goto(wizardUrl);

    await expect(page.getByRole('alert')).toContainText('This changeout could not be loaded');
    await expect(page.getByRole('button', { name: 'Retry' })).toBeVisible();
    await expect(page.locator('[data-hub-back]')).toBeVisible();
  });

  test('a double tap sends once', async ({ page }) => {
    let release: () => void = () => undefined;
    const held = new Promise<void>((resolve) => { release = resolve; });
    const mock = await mockApi(page, { onPost: async (route) => {
      await held;
      await route.fulfill({ status: 201, json: { changeout: summary(), replayed: false } });
    } });
    await toReview(page);

    const button = primary(page, 'Complete Changeout');
    await button.dblclick({ force: true }).catch(() => undefined);
    await expect(primary(page, 'Sending…')).toBeDisabled();
    release();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44 is now on truck 1033.');
    expect(mock.posts).toHaveLength(1);
  });

  test('an expired session goes to sign-in and the draft survives', async ({ page }) => {
    await mockApi(page, { onPost: (route) => route.fulfill({ status: 401, json: { message: 'Unauthenticated.' } }) });
    await page.route('**/login**', (route) => route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Sign in</title>' }));
    await toReview(page);
    await primary(page, 'Complete Changeout').click();

    await page.waitForURL(/\/login\?session_expired=1/);
    const draftKeys = await page.evaluate(() => Object.keys(localStorage).filter((key) => key.startsWith('mbfd_changeout_draft_')));
    expect(draftKeys).toHaveLength(1);
  });
});

test.describe('a shared phone with no known member', () => {
  test('stores nothing, stays quiet until there is work to lose, and still sends', async ({ page }) => {
    const mock = await mockApi(page, { identityStatus: 503 });
    await page.goto(wizardUrl);
    await expect(heading(page, 'Select replacement truck')).toBeVisible();
    await expect(page.getByText('could not save your work')).toHaveCount(0);

    await pickTruck(page, '1033');
    await expect(page.getByText('This phone could not save your work.')).toBeVisible();
    await expect(page.getByText('Saved on this phone until it is sent.')).toHaveCount(0);
    await primary(page, 'Continue').click();
    await primary(page, 'Continue').click();
    const stored = await page.evaluate(() => Object.keys(localStorage).filter((key) => key.startsWith('mbfd_changeout_')));
    expect(stored).toEqual([]);

    await primary(page, 'Complete Changeout').click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44 is now on truck 1033.');
    expect(mock.posts).toHaveLength(1);
  });

  test('a failed send says the work is only on this page, and a stale move keeps it in memory', async ({ page }) => {
    const mock = await mockApi(page, {
      identityStatus: 503,
      onPost: (route, attempt) => {
        if (attempt === 1) return route.abort('internetdisconnected');
        if (attempt === 2) return route.fulfill({ status: 409, json: { message: 'It moved. Nothing was saved.', code: 'CHANGEOUT_STALE', current_vehicle_number: '1040', current_apparatus_id: 70 } });

        return route.fulfill({ status: 201, json: { changeout: summary(), replayed: false } });
      },
      contextFor: (path) => (path.includes(`/${TRUCK_ID}/`)
        ? { json: context() }
        : { json: context({ unit: { apparatus_id: 70, designation: 'R 44', slug: 'r44', vehicle_number: '1040', station_id: STATION_ID, station_label: 'Station 4', status: 'In Service' } }) }),
    });
    await toEquipment(page);
    await leaveOnTruck(page, 'Stair chair');
    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();

    await expect(page.getByRole('alert').filter({ hasText: 'Not sent.' })).toContainText('only on this page');
    await primary(page, 'Try again').click();
    await page.getByRole('button', { name: 'Review with the current truck' }).click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44, truck 1040, Station 4');
    await expect(page.getByTestId('changeout-review-exceptions')).toContainText('Stair chair');
    await primary(page, 'Complete Changeout').click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44 is now on truck 1033.');
    expect(mock.posts[2].body.exceptions).toEqual(mock.posts[0].body.exceptions);
  });
});

test.describe('Change vehicle, with no alert', () => {
  test('starts from the station card, sends no alert id, and Fleet confirms unless the server says direct', async ({ page }) => {
    const mock = await mockApi(page, {
      alerts: [],
      station: { apparatuses: [{ id: TRUCK_ID, name: 'Rescue 44', unit_id: 'R 44', designation: 'R 44', vehicle_number: '17503', type: 'rescue', slug: 'r44', status: 'In Service', daily_checkout_requirement: 'required' }] },
      contextFor: () => ({ json: context({
        alert: null,
        candidates: [{ apparatus_id: NEW_TRUCK_ID, vehicle_number: '1033', label: 'Rescue 1033', location: 'Station 2', status: 'Available', direct: false, is_return: false }],
      }) }),
      onPost: (route) => route.fulfill({ status: 201, json: { changeout: summary({ status: 'pending_fleet', next_daily_checkout_path: null, open_items: [] }), replayed: false } }),
    });
    await page.goto(`/daily/stations/${STATION_ID}`);
    await page.getByRole('tab', { name: 'Apparatus' }).click();
    await page.getByRole('link', { name: 'Change vehicle' }).click();

    await expect(heading(page, 'Select replacement truck')).toBeVisible();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44, truck 17503, Station 4');
    expect(mock.contextGets[0]).toBe(`/api/public/apparatuses/${TRUCK_ID}/changeout-context`);

    await pickTruck(page, '1033');
    await expect(page.getByRole('status').filter({ hasText: 'Fleet will confirm this truck.' })).toBeVisible();
    await primary(page, 'Continue').click();
    await primary(page, 'Continue').click();
    await primary(page, 'Send to Fleet').click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Sent to Fleet. Your equipment choices are saved.');
    expect(mock.posts[0].body).toMatchObject({ alert_id: null, to_apparatus_id: NEW_TRUCK_ID, expected_unit_slug: 'r44' });
  });

  test('an alert before its date still follows direct: a false direct says Fleet will confirm', async ({ page }) => {
    await mockApi(page, { contextFor: () => ({ json: context({
      candidates: [{ apparatus_id: NEW_TRUCK_ID, vehicle_number: '1033', label: 'Rescue 1033', location: 'Station 2', status: 'Available', direct: false, is_return: false }],
    }) }) });
    await toEquipment(page);
    await primary(page, 'Continue').click();
    await expect(primary(page, 'Send to Fleet')).toBeVisible();
  });
});

test.describe('the next checklist after a changeout', () => {
  const inspect = async (page: Page, options: Parameters<typeof mobileInspectionFixture>[2]) => {
    await mobileInspectionFixture(page, mobileApparatus[0], options);
  };

  test('names what was left on the old truck at the top of the checklist step', async ({ page }) => {
    await inspect(page, { changeoutHandoff: {
      from_vehicle_number: '17503',
      effective_at: '2026-11-01T20:10:00Z',
      left_behind: [{ name: 'Stair chair', note: 'Too big for this truck' }, { name: 'Spare bag', note: null }],
    } });

    const note = page.getByRole('region', { name: 'Left on truck 17503 at the last changeout' });
    await expect(note).toBeVisible();
    await expect(note).toContainText('Stair chair: Too big for this truck');
    await expect(note).toContainText('Spare bag');
    await page.getByRole('navigation', { name: 'Inspection workspace' }).getByRole('button', { name: 'Member / Vehicle Info', exact: true }).click();
    await expect(note).toHaveCount(0);
  });

  const nothing: Array<[string, Parameters<typeof mobileInspectionFixture>[2]]> = [
    ['an old cached response has no key', {}],
    ['the key is null', { changeoutHandoff: null }],
    ['nothing was left behind', { changeoutHandoff: { from_vehicle_number: '17503', effective_at: null, left_behind: [] } }],
  ];
  for (const [name, options] of nothing) {
    test(`shows nothing when ${name}`, async ({ page }) => {
      await inspect(page, options);
      await expect(page.getByRole('navigation', { name: 'Inspection workspace' })).toBeVisible();
      await expect(page.getByText('at the last changeout')).toHaveCount(0);
    });
  }
});

test.describe('when the records moved', () => {
  test('CHANGEOUT_STALE shows the current truck and reviews with it, keeping choices that still apply', async ({ page }) => {
    const message = 'R 44 is no longer on truck 17503. It is on truck 1040 now. Nothing was saved, and your choices are still on this screen.';
    const mock = await mockApi(page, {
      onPost: (route, attempt) => (attempt === 1
        ? route.fulfill({ status: 409, json: { message, code: 'CHANGEOUT_STALE', current_vehicle_number: '1040', current_apparatus_id: 70, unit_designation: 'R 44' } })
        : route.fulfill({ status: 201, json: { changeout: summary(), replayed: false } })),
      contextFor: (path) => (path.includes(`/${TRUCK_ID}/`)
        ? { json: context() }
        : { json: context({
          unit: { apparatus_id: 70, designation: 'R 44', slug: 'r44', vehicle_number: '1040', station_id: STATION_ID, station_label: 'Station 4', status: 'In Service' },
          equipment: { checklist_version: 'b'.repeat(64), usable: true, compartments: [{ id: 'rear', name: 'Rear Compartment', items: [item('rear-item-2', 'Stair chair')] }] },
        }) }),
    });
    await toEquipment(page);
    await leaveOnTruck(page, 'Stair chair');
    await leaveOnTruck(page, 'Flashlight');
    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();

    await expect(page.getByRole('alert')).toContainText('R 44 is on truck 1040 now.');
    await expect(primary(page, 'Complete Changeout')).toBeDisabled();
    await page.getByRole('button', { name: 'Review with the current truck' }).click();

    await expect(page).toHaveURL(/\/daily\/changeouts\/new\/70\?/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44, truck 1040, Station 4');
    await expect(page.getByRole('alert').filter({ hasText: 'These choices no longer apply: Flashlight.' })).toBeVisible();
    await expect(heading(page, 'Review')).toBeVisible();
    await primary(page, 'Complete Changeout').click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44 is now on truck 1033.');

    expect(mock.posts[1].path).toBe('/api/public/apparatuses/70/changeouts');
    expect(mock.posts[1].body).toMatchObject({
      client_submission_id: mock.posts[0].body.client_submission_id,
      expected_vehicle_number: '1040',
      checklist_version: 'b'.repeat(64),
      exceptions: [{ item_key: 'rear-item-2', handling: 'leave', quantity: null, condition: 'ok', note: null }],
    });
  });

  test('CHANGEOUT_TRUCK_UNAVAILABLE goes back to the truck step with fresh choices and equipment kept', async ({ page }) => {
    const message = 'Truck 1033 is no longer available for this change. Nothing was saved, and your choices are still on this screen.';
    const mock = await mockApi(page, {
      onPost: (route, attempt) => (attempt === 1
        ? route.fulfill({ status: 409, json: { message, code: 'CHANGEOUT_TRUCK_UNAVAILABLE' } })
        : route.fulfill({ status: 201, json: { changeout: summary({ to_vehicle_number: '1035', message: 'R 44 is now on truck 1035.' }), replayed: false } })),
      contextFor: (_path, attempt) => ({ json: attempt === 1 ? context() : context({
        candidates: [{ apparatus_id: 59, vehicle_number: '1035', label: 'Rescue 1035', location: 'Station 3', status: 'Reserve', direct: true, is_return: false }],
      }) }),
    });
    await toEquipment(page);
    await leaveOnTruck(page, 'Stair chair');
    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();

    await expect(heading(page, 'Select replacement truck')).toBeVisible();
    await expect(page.getByRole('alert').filter({ hasText: 'Truck 1033 is no longer available' })).toBeVisible();
    await expect(page.getByRole('radio', { name: /^1033/ })).toHaveCount(0);
    await pickTruck(page, '1035');
    await primary(page, 'Continue').click();
    await expect(exceptions(page)).toContainText('Stair chair');
    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44 is now on truck 1035.');
    expect(mock.posts[1].body).toMatchObject({ to_apparatus_id: 59 });
    expect(mock.posts[1].body.exceptions).toEqual(mock.posts[0].body.exceptions);
  });

  test('CHANGEOUT_CHECKLIST_CHANGED reloads the list and stays on Review instead of refusing forever', async ({ page }) => {
    const mock = await mockApi(page, {
      onPost: (route, attempt) => (attempt === 1
        ? route.fulfill({ status: 409, json: { message: 'The equipment list for this truck changed while you were working. Nothing was saved.', code: 'CHANGEOUT_CHECKLIST_CHANGED' } })
        : route.fulfill({ status: 201, json: { changeout: summary(), replayed: false } })),
      contextFor: (_path, attempt) => ({ json: attempt === 1 ? context() : context({ equipment: { ...context().equipment, checklist_version: 'c'.repeat(64) } }) }),
    });
    await toEquipment(page);
    await leaveOnTruck(page, 'Stair chair');
    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();

    await expect(page.getByRole('alert').filter({ hasText: 'equipment list for this truck changed' })).toBeVisible();
    await expect(heading(page, 'Review')).toBeVisible();
    await primary(page, 'Complete Changeout').click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44 is now on truck 1033.');
    expect(mock.posts[1].body.checklist_version).toBe('c'.repeat(64));
  });

  test('CHANGEOUT_ALREADY_PENDING shows the existing one with a link', async ({ page }) => {
    await mockApi(page, { onPost: (route) => route.fulfill({ status: 409, json: { message: 'Fleet already has a vehicle change waiting for R 44. Nothing new was saved.', code: 'CHANGEOUT_ALREADY_PENDING', changeout_id: 9 } }) });
    await toReview(page);
    await primary(page, 'Complete Changeout').click();

    await expect(page.getByRole('alert')).toContainText('Fleet already has a vehicle change waiting for R 44.');
    await expect(page.getByRole('link', { name: 'See that record' })).toHaveAttribute('href', '/daily/changeouts/9');
    await expect(primary(page, 'Complete Changeout')).toBeDisabled();
    await page.getByRole('button', { name: 'Discard my copy' }).click();
    await expect(page).toHaveURL(new RegExp(`/daily/stations/${STATION_ID}$`));
  });

  test('CHANGEOUT_REPLAY_CONFLICT shows what happened before using a new id', async ({ page }) => {
    const mock = await mockApi(page, {
      onPost: (route, attempt) => (attempt === 1
        ? route.fulfill({ status: 409, json: { message: 'This was already sent with different choices. Nothing new was saved.', code: 'CHANGEOUT_REPLAY_CONFLICT', changeout_id: 8 } })
        : route.fulfill({ status: 201, json: { changeout: summary(), replayed: false } })),
    });
    await toReview(page);
    await primary(page, 'Complete Changeout').click();

    await expect(page.getByRole('alert')).toContainText('An earlier send from this phone had different choices.');
    await expect(page.getByRole('link', { name: 'See what was saved' })).toHaveAttribute('href', '/daily/changeouts/8');
    expect(mock.posts).toHaveLength(1);
    await page.getByRole('button', { name: 'Send what is on this screen' }).click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44 is now on truck 1033.');
    expect(mock.posts[1].body.client_submission_id).toMatch(UUID_PATTERN);
    expect(mock.posts[1].body.client_submission_id).not.toBe(mock.posts[0].body.client_submission_id);
  });

  test('a validation refusal keeps the work and shows the server message', async ({ page }) => {
    await mockApi(page, { onPost: (route) => route.fulfill({ status: 422, json: { message: 'Stair chair has no set count on this truck.' } }) });
    await toReview(page);
    await primary(page, 'Complete Changeout').click();

    await expect(page.getByRole('alert')).toContainText('Stair chair has no set count');
    await primary(page, 'Back').click();
    await expect(heading(page, 'Transfer equipment')).toBeVisible();
  });
});

test.describe('the receipt', () => {
  const lines = [
    { id: 1, item_key: 'field:seal', name: 'Narcotic Seal #', controlled: true, custody_status: 'pending_verification' },
    { id: 2, item_key: 'rear-item-2', name: 'Stair chair', controlled: false, custody_status: 'left_behind', compartment_name: 'Rear Compartment', handling: 'leave', condition: 'ok', note: 'Too big' },
    { id: 3, item_key: 'rear-item-1', name: 'Spare oxygen bottle', controlled: false, custody_status: 'moved', compartment_name: 'Rear Compartment', handling: 'transfer', condition: 'ok' },
  ];

  test('shows what moved, what stayed and what waits, and verifies with a note where allowed', async ({ page }) => {
    const mock = await mockApi(page, { changeout: summary({ can_verify: true, lines }) });
    await page.goto(`/daily/changeouts/8?return_to=${encodeURIComponent(`/stations/${STATION_ID}`)}`);

    await expect(page.getByRole('heading', { level: 1 })).toHaveText('R 44 is now on truck 1033.');
    await expect(page.getByText('Completed')).toBeVisible();
    await expect(page.getByRole('region', { name: 'Waiting for verification' })).toContainText('Narcotic Seal #');
    await expect(page.getByRole('region', { name: 'Left on truck 17503' })).toContainText('Too big');
    await expect(page.getByText('Moved (1)')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Back to station' })).toHaveAttribute('href', `/daily/stations/${STATION_ID}`);

    await page.getByRole('button', { name: 'Verify' }).click();
    await page.getByLabel('Verification note (optional)').fill('Seal 4471 checked');
    await page.getByRole('button', { name: 'Record verification' }).click();
    await expect.poll(() => mock.verifies).toEqual([{ note: 'Seal 4471 checked' }]);
  });

  test('a member without authority sees no Verify button', async ({ page }) => {
    await mockApi(page, { changeout: summary({ can_verify: false, lines }) });
    await page.goto('/daily/changeouts/8');

    await expect(page.getByRole('region', { name: 'Waiting for verification' })).toContainText('Narcotic Seal #');
    await expect(page.getByRole('button', { name: 'Verify' })).toHaveCount(0);
  });

  test('a record that cannot be read offers Retry and a way back', async ({ page }) => {
    await page.route('**/api/public/changeouts/404', (route) => route.fulfill({ status: 404, json: { message: 'Not found.' } }));
    await mockApi(page);
    await page.unroute('**/api/public/changeouts/404');
    await page.route('**/api/public/changeouts/404', (route) => route.fulfill({ status: 404, json: { message: 'Not found.' } }));
    await page.goto('/daily/changeouts/404');

    await expect(page.getByRole('alert')).toContainText('Not found.');
    await expect(page.getByRole('button', { name: 'Retry' })).toBeVisible();
  });
});

test.describe('on a small phone', () => {
  test.use({ viewport: { width: 320, height: 640 } });

  test('nothing overflows and every control is a 44px target at 320 wide', async ({ page }) => {
    await mockApi(page);
    await page.goto(wizardUrl);
    await expect(heading(page, 'Select replacement truck')).toBeVisible();
    const fits = () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
    expect(await fits()).toBe(true);

    await pickTruck(page, '1033');
    await primary(page, 'Continue').click();
    await page.getByRole('button', { name: /^Front Cab/ }).click();
    await page.getByRole('button', { name: /^Portable radio/ }).click();
    expect(await fits()).toBe(true);

    const main = page.locator('[data-hub-ui="2"]');
    const heights = await main.locator('label:has(input[type="radio"]), input[type="text"], input[type="search"], button:not([data-hub-back])').evaluateAll(
      (elements) => elements.map((element) => ({ text: element.textContent?.trim().slice(0, 30) ?? '', height: element.getBoundingClientRect().height })),
    );
    expect(heights.length).toBeGreaterThan(5);
    for (const { text, height } of heights) expect(height, text).toBeGreaterThanOrEqual(44);

    await page.getByRole('button', { name: 'Done with this item' }).click();
    await primary(page, 'Continue').click();
    await expect(heading(page, 'Review')).toBeVisible();
    expect(await fits()).toBe(true);
  });

  test('radio groups and the step list are real semantics', async ({ page }) => {
    await mockApi(page);
    await page.goto(wizardUrl);

    await expect(page.getByRole('radiogroup', { name: 'Select replacement truck' }).getByRole('radio')).toHaveCount(2);
    await expect(page.locator('[aria-current="step"]')).toHaveText('1. Truck');
  });
});
