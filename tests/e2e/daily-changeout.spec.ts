import { expect, test, type Page, type Route } from '@playwright/test';
import { mobileApparatus, mobileInspectionFixture } from './support/daily-checkout-mobile';

/**
 * The apparatus changeout on a firefighter's phone, every API response mocked: the station card, the three steps, and
 * what happens when the signal drops or the records moved underneath the member. Nobody approves a changeout.
 */

const STATION_ID = 32;
const TRUCK_ID = 52;
const NEW_TRUCK_ID = 57;
const ALERT_ID = 12;
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const VERSION = 'a'.repeat(64);
const NOT_SENT = 'Not sent. Your work is saved on this phone. Try again when you have signal.';
const TRUCK_HEADING = 'What truck are you moving to?';
const EQUIPMENT_HEADING = 'What did you take and leave?';
const DONE_MESSAGE = 'R 44 is now on truck 1033. Truck 17503 is Out of Service.';

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
  date_passed: false,
  workflow: { state: 'planned', label: 'Planned' },
  action: { kind: 'start', label: 'Start Changeout', apparatus_id: TRUCK_ID, alert_id: ALERT_ID },
  ...overrides,
});

const item = (key: string, name: string, overrides: Record<string, unknown> = {}) => ({
  item_key: key,
  name,
  expected_quantity: null,
  previous: null,
  ...overrides,
});

const context = (overrides: Record<string, unknown> = {}) => ({
  unit: { apparatus_id: TRUCK_ID, designation: 'R 44', slug: 'r44', vehicle_number: '17503', station_id: STATION_ID, station_label: 'Station 4', status: 'In Service' },
  alert: { id: ALERT_ID, title: 'Upcoming Apparatus Changeout: Rescue 44', reason: 'Exhaust repair', schedule_label: 'Sunday, November 1, afternoon', vehicle_number: '17503', planned_replacement_apparatus_id: null, changeout_id: null },
  mode: 'start',
  candidates: [
    { apparatus_id: NEW_TRUCK_ID, vehicle_number: '1033', label: 'Rescue 1033', location: 'Station 2', status: 'Available', is_return: false },
    { apparatus_id: 59, vehicle_number: '1035', label: 'Rescue 1035', location: 'Station 3', status: 'Reserve', is_return: false },
  ],
  other_vehicles: [{ apparatus_id: 58, vehicle_number: '1034', label: 'Rescue 1034', location: 'Station 2', status: 'In Service', listed_as: 'R 4' }],
  equipment: {
    checklist_version: VERSION,
    usable: true,
    compartments: [
      { id: 'front_cab', name: 'Front Cab', items: [item('front_cab-item-1', 'Portable radio', { expected_quantity: 2 }), item('front_cab-item-2', 'Flashlight')] },
      { id: 'rear', name: 'Rear Compartment', items: [item('rear-item-1', 'Spare oxygen bottle'), item('rear-item-2', 'Stair chair')] },
      { id: 'narcotics', name: 'Narcotics', items: [item('field:seal', 'Narcotic Seal #')] },
    ],
  },
  open_issues_on_truck: 3,
  previous_changeout: null,
  ...overrides,
});

const summary = (overrides: Record<string, unknown> = {}) => ({
  id: 8,
  status: 'completed',
  unit_designation: 'R 44',
  from_vehicle_number: '17503',
  to_vehicle_number: '1033',
  to_apparatus_id: NEW_TRUCK_ID,
  station_id: STATION_ID,
  effective_at: '2026-11-01T20:10:00Z',
  old_truck_status: 'Out of Service',
  alert_id: ALERT_ID,
  alert_completed: true,
  return_of_changeout_id: null,
  submitted_by_name: 'Alex Rivera',
  moved: 4,
  left_behind: [{ name: 'Spare oxygen bottle', note: 'Stays with the truck' }],
  next_daily_checkout_path: '/daily/apparatus/r44',
  message: DONE_MESSAGE,
  new_truck_record: false,
  displaced_unit: null,
  ...overrides,
});

interface Mock {
  contextGets: string[];
  posts: Array<{ path: string; body: Record<string, unknown> }>;
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
  const mock: Mock = { contextGets: [], posts: [], alertsAnswer: { status: options.alertStatus ?? 200 } };
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
    if (/^\/api\/public\/changeouts\/\d+$/.test(path)) return route.fulfill({ json: { changeout: options.changeout ?? summary() } });

    return route.fulfill({ status: 404, json: { message: `Unmocked route ${path}` } });
  });

  return mock;
}

const wizardUrl = `/daily/changeouts/new/${TRUCK_ID}?alert_id=${ALERT_ID}&return_to=${encodeURIComponent(`/stations/${STATION_ID}`)}`;
const heading = (page: Page, name: string) => page.getByRole('heading', { name, exact: true });
const h1 = (page: Page) => page.getByRole('heading', { level: 1 });
const primary = (page: Page, name: string | RegExp) => page.getByRole('button', { name, exact: typeof name === 'string' });
const pickTruck = (page: Page, number: string) => page.getByRole('radio', { name: new RegExp(`^${number}\\b`) }).check();
const leftList = (page: Page) => page.getByTestId('changeout-left-list');
const tallies = (page: Page) => page.getByTestId('changeout-summary');

async function toEquipment(page: Page, number = '1033') {
  await page.goto(wizardUrl);
  await expect(heading(page, TRUCK_HEADING)).toBeVisible();
  await pickTruck(page, number);
  await primary(page, 'Continue').click();
  await expect(heading(page, EQUIPMENT_HEADING)).toBeVisible();
}

async function markLeft(page: Page, name: string, note?: string) {
  await page.getByLabel('Find an item').fill(name);
  await page.getByRole('button', { name: new RegExp(`^${name}`) }).click();
  if (note) await page.getByLabel(`Note for ${name} (optional)`).fill(note);
  await page.getByLabel('Find an item').fill('');
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
    await expect(heading(page, TRUCK_HEADING)).toBeVisible();
    await expect(h1(page)).toHaveText('R 44, truck 17503, Station 4');
  });

  test('says when the planned date has passed and still offers the changeout', async ({ page }) => {
    await mockApi(page, { alerts: [alert({ date_passed: true })] });
    await page.goto(`/daily/stations/${STATION_ID}`);

    await expect(page.getByTestId('unit-alert')).toContainText('Planned date has passed');
    await expect(page.getByRole('link', { name: 'Start Changeout' })).toBeVisible();
  });

  test('a vehicle already changed offers a handoff, and an action with nothing to do has no link', async ({ page }) => {
    await mockApi(page, { alerts: [
      alert({ workflow: { state: 'vehicle_changed', label: 'Vehicle changed' }, action: { kind: 'handoff', label: 'Record what moved', apparatus_id: NEW_TRUCK_ID, alert_id: ALERT_ID } }),
      alert({ id: 13, title: 'Recall notice', workflow: null, action: { kind: 'none', label: '', apparatus_id: null, alert_id: null } }),
    ] });
    await page.goto(`/daily/stations/${STATION_ID}`);

    await expect(page.getByRole('link', { name: 'Record what moved' })).toHaveAttribute('href', new RegExp(`/daily/changeouts/new/${NEW_TRUCK_ID}\\?`));
    await expect(page.getByTestId('unit-alert').filter({ hasText: 'Recall notice' }).getByRole('link')).toHaveCount(0);
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
  test('truck, one item Left, review, done: sends only that item and offers the new truck its checkout', async ({ page }) => {
    const mock = await mockApi(page);
    await toEquipment(page);

    await expect(page.getByTestId('changeout-took-all')).toContainText('Took all 5 items');
    await expect(tallies(page)).toHaveText('Took 5 · Left 0');
    await expect(page.getByRole('button', { name: /^Front Cab/ })).toHaveAttribute('aria-expanded', 'false');

    await markLeft(page, 'Spare oxygen bottle', 'Stays with the truck');
    await expect(leftList(page)).toContainText('Spare oxygen bottle');
    await expect(leftList(page)).toContainText('Stays with the truck');
    await expect(page.getByTestId('changeout-took-all')).toContainText('Took 4 of 5 items');
    await expect(tallies(page)).toHaveText('Took 4 · Left 1');

    await primary(page, 'Continue').click();
    await expect(heading(page, 'Review')).toBeVisible();
    await expect(page.getByTestId('changeout-review-left')).toContainText('Spare oxygen bottle');
    await expect(page.getByTestId('changeout-review-left')).toContainText('Stays with the truck');
    await page.getByLabel('Note (optional)').fill('Moved at Station 2.');
    await primary(page, 'Complete Changeout').click();

    await expect(h1(page)).toHaveText(DONE_MESSAGE);
    await expect(h1(page)).toBeFocused();
    await expect(page.getByText('Fleet has been told. Nobody needs to approve this.')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Left on truck 17503' })).toBeVisible();
    await expect(page.getByText('Spare oxygen bottle: Stays with the truck')).toBeVisible();
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
      exceptions: [{ item_key: 'rear-item-1', handling: 'leave', note: 'Stays with the truck' }],
      note: 'Moved at Station 2.',
    });

    // The draft is gone, and a reload stays on Done instead of starting a new changeout.
    const leftovers = await page.evaluate(() => Object.keys(localStorage).filter((key) => key.startsWith('mbfd_changeout_')));
    expect(leftovers).toEqual([]);
    await page.reload();
    await expect(h1(page)).toHaveText(DONE_MESSAGE);
    expect(mock.posts).toHaveLength(1);
  });

  test('a row switches between Took and Left in place, and Undo in the Left list puts it back', async ({ page, browserName }) => {
    await mockApi(page);
    await toEquipment(page);
    await page.getByRole('button', { name: /^Front Cab/ }).click();

    const radio = page.getByRole('button', { name: /^Portable radio/ });
    await expect(radio).toHaveAccessibleName(/Took$/);
    await expect(page.getByLabel('Note for Portable radio (optional)')).toHaveCount(0);
    await radio.click();
    await expect(radio).toHaveAccessibleName(/Left$/);
    // Safari does not focus a button on a tap, so there is no focus to keep there.
    if (browserName !== 'webkit') await expect(radio).toBeFocused();
    await expect(page.getByLabel('Note for Portable radio (optional)')).toBeVisible();
    await expect(page.getByRole('status').filter({ hasText: 'Portable radio: Left on truck 17503.' })).toBeAttached();

    await radio.click();
    await expect(radio).toHaveAccessibleName(/Took$/);
    await expect(leftList(page)).toHaveCount(0);

    await radio.click();
    await page.getByRole('button', { name: 'Undo Portable radio' }).click();
    await expect(radio).toHaveAccessibleName(/Took$/);
    await expect(tallies(page)).toHaveText('Took 5 · Left 0');
  });

  test('tapping a row far down the list does not move it from under the thumb', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 500 });
    await mockApi(page);
    await toEquipment(page);
    for (const name of [/^Rear Compartment/, /^Narcotics/, /^Front Cab/]) await page.getByRole('button', { name }).click();

    const row = page.getByRole('button', { name: /^Narcotic Seal/ });
    await row.scrollIntoViewIfNeeded();
    const before = (await row.boundingBox())?.y ?? 0;
    await row.click();
    await expect(leftList(page)).toContainText('Narcotic Seal #');
    const after = (await row.boundingBox())?.y ?? 0;
    expect(Math.abs(after - before)).toBeLessThan(3);
  });

  test('Left this compartment marks every item, and the same control undoes it', async ({ page }) => {
    const mock = await mockApi(page);
    await toEquipment(page);
    await page.getByRole('button', { name: /^Rear Compartment/ }).click();

    await page.getByRole('button', { name: 'Stair chair Took' }).click();
    await page.getByLabel('Note for Stair chair (optional)').fill('Too big');
    await page.getByRole('button', { name: 'Left this compartment' }).click();
    await expect(tallies(page)).toHaveText('Took 3 · Left 2');
    await expect(leftList(page)).toContainText('Spare oxygen bottle');
    await expect(leftList(page)).toContainText('Too big');
    await expect(page.getByRole('button', { name: /^Rear Compartment/ })).toContainText('2 left');

    await page.getByRole('button', { name: 'Took this compartment' }).click();
    await expect(tallies(page)).toHaveText('Took 5 · Left 0');
    await page.getByRole('button', { name: 'Left this compartment' }).click();

    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();
    await expect(h1(page)).toHaveText(DONE_MESSAGE);
    expect(mock.posts[0].body.exceptions).toEqual([
      { item_key: 'rear-item-1', handling: 'leave', note: null },
      { item_key: 'rear-item-2', handling: 'leave', note: null },
    ]);
  });

  test('search finds an item in a closed compartment and hides the whole-compartment shortcut', async ({ page }) => {
    await mockApi(page);
    await toEquipment(page);

    await page.getByLabel('Find an item').fill('narcotic seal');
    await expect(page.getByRole('button', { name: /^Narcotic Seal/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /^Flashlight/ })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Left this compartment' })).toHaveCount(0);
    await page.getByLabel('Find an item').fill('nothing like this');
    await expect(page.getByText('No equipment matches that search.')).toBeVisible();
  });

  test('every item is the member\'s to leave: no item is controlled, verified or counted', async ({ page }) => {
    const mock = await mockApi(page);
    await toEquipment(page);
    await markLeft(page, 'Narcotic Seal #');
    await page.getByRole('button', { name: /^Front Cab/ }).click();

    const text = await page.locator('[data-hub-ui="2"]').innerText();
    for (const banned of ['sign-off', 'verif', 'Controlled', 'Missing', 'Damaged', 'Not sure', 'Expected', 'Quantity', 'Fleet will confirm']) {
      expect(text.toLowerCase()).not.toContain(banned.toLowerCase());
    }
    await expect(page.getByRole('button', { name: 'One less' })).toHaveCount(0);

    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();
    await expect(h1(page)).toHaveText(DONE_MESSAGE);
    expect(mock.posts[0].body.exceptions).toEqual([{ item_key: 'field:seal', handling: 'leave', note: null }]);
  });

  test('says what happens to the old truck, and preselects the planned truck without restricting the choice', async ({ page }) => {
    const mock = await mockApi(page, { contextFor: () => ({ json: context({
      alert: { id: ALERT_ID, title: 'Upcoming', reason: 'Exhaust repair', schedule_label: 'Sunday', vehicle_number: '17503', planned_replacement_apparatus_id: 59, changeout_id: null },
    }) }) });
    await page.goto(wizardUrl);

    await expect(page.getByRole('radio', { name: /^1035\b/ })).toBeChecked();
    await expect(page.getByTestId('changeout-what-happens')).toHaveText('Truck 17503 will be marked Out of Service.');
    await expect(page.getByText('Reason: Exhaust repair')).toBeVisible();

    // A preselection on its own is nothing to save or restore.
    const stored = await page.evaluate(() => Object.keys(localStorage).filter((key) => key.startsWith('mbfd_changeout_draft_')));
    expect(stored).toEqual([]);
    await page.reload();
    await expect(page.getByText('Your saved work is back.')).toHaveCount(0);

    await pickTruck(page, '1033');
    await primary(page, 'Continue').click();
    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();
    await expect(h1(page)).toHaveText(DONE_MESSAGE);
    expect(mock.posts[0].body).toMatchObject({ alert_id: ALERT_ID, to_apparatus_id: NEW_TRUCK_ID });
  });

  test('Different truck: a truck listed as another unit says that unit moves to the old truck', async ({ page }) => {
    const mock = await mockApi(page, { onPost: (route) => route.fulfill({ status: 201, json: { changeout: summary({ to_vehicle_number: '1034', message: 'R 44 is now on truck 1034.', displaced_unit: 'R 4', left_behind: [] }), replayed: false } }) });
    await page.goto(wizardUrl);

    await expect(page.getByRole('radio', { name: /^1034\b/ })).toHaveCount(0);
    await page.getByRole('button', { name: 'Different truck' }).click();
    const card = page.locator('label').filter({ has: page.getByRole('radio', { name: /^1034\b/ }) });
    await expect(card).toContainText('Listed as R 4');
    await expect(page.getByText('that unit moves to truck 17503')).toBeVisible();

    await pickTruck(page, '1034');
    await expect(page.getByTestId('changeout-what-happens')).toContainText('R 4 moves to truck 17503.');
    await primary(page, 'Continue').click();
    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();

    await expect(h1(page)).toHaveText('R 44 is now on truck 1034.');
    await expect(page.getByText('R 4 is now on truck 17503.')).toBeVisible();
    await expect(page.getByText('Took all 4 items. Nothing was left on truck 17503.')).toBeVisible();
    expect(mock.posts[0].body).toMatchObject({ to_apparatus_id: 58, to_vehicle_number: null });
  });

  test('a typed number is validated, and an unknown truck completes the same way', async ({ page }) => {
    const mock = await mockApi(page, { onPost: (route) => route.fulfill({ status: 201, json: { changeout: summary({ to_vehicle_number: 'NEW-9', to_apparatus_id: 70, message: 'R 44 is now on truck NEW-9.', new_truck_record: true, left_behind: [] }), replayed: false } }) });
    await page.goto(wizardUrl);

    await page.getByRole('button', { name: 'Different truck' }).click();
    const typed = page.getByLabel('Or type the number on the truck');
    await primary(page, 'Continue').click();
    await expect(page.getByRole('alert')).toContainText('Choose the truck');
    await typed.fill('<b>');
    await expect(page.getByRole('alert')).toContainText('Letters, numbers');
    await typed.fill('17503');
    await expect(page.getByRole('alert')).toContainText('truck R 44 is on now');
    await typed.fill('NEW-9');
    await expect(page.getByRole('alert')).toHaveCount(0);
    await expect(page.getByTestId('changeout-what-happens')).toContainText('Truck NEW-9 is not on the list yet.');

    await primary(page, 'Continue').click();
    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();

    await expect(h1(page)).toHaveText('R 44 is now on truck NEW-9.');
    await expect(page.getByText("Fleet will add the truck's details.")).toBeVisible();
    expect(mock.posts[0].body).toMatchObject({ to_apparatus_id: null, to_vehicle_number: 'NEW-9' });
  });

  test('going back to its own truck is flagged and shows last time, with nothing preset to Left', async ({ page }) => {
    const mock = await mockApi(page, { contextFor: () => ({ json: context({
      candidates: [{ apparatus_id: 60, vehicle_number: '17503', label: 'Rescue 17503', location: 'Shop', status: 'Available', is_return: true }],
      unit: { apparatus_id: TRUCK_ID, designation: 'R 44', slug: 'r44', vehicle_number: '1033', station_id: STATION_ID, station_label: 'Station 4', status: 'In Service' },
      previous_changeout: { id: 7, from_apparatus_id: 60, from_vehicle_number: '17503', to_vehicle_number: '1033', effective_at: '2026-11-01T20:10:00Z', moved: 3, left_behind: [{ name: 'Stair chair', note: 'Stayed on 17503' }], submitted_by_name: 'Alex Rivera' },
      equipment: { checklist_version: VERSION, usable: true, compartments: [{ id: 'rear', name: 'Rear Compartment', items: [
        item('rear-item-1', 'Spare oxygen bottle', { previous: { handling: 'transfer', note: null } }),
        item('rear-item-2', 'Stair chair', { previous: { handling: 'leave', note: 'Stayed on 17503' } }),
      ] }] },
    }) }) });
    await page.goto(wizardUrl);

    await expect(page.getByText('Back to its own truck')).toBeVisible();
    const previous = page.getByRole('region', { name: 'Previous changeout' });
    await expect(previous).toContainText('Truck 17503 to 1033');
    await expect(previous).toContainText('Stair chair: Stayed on 17503');
    await pickTruck(page, '17503');
    await primary(page, 'Continue').click();

    await expect(page.getByTestId('changeout-took-all')).toContainText('Took all 2 items');
    await expect(leftList(page)).toHaveCount(0);
    await page.getByRole('button', { name: /^Rear Compartment/ }).click();
    await expect(page.getByText('Left on truck 17503 last time: Stayed on 17503')).toBeVisible();
    await expect(page.getByRole('button', { name: /^Stair chair/ })).toHaveAccessibleName(/Took$/);
    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();
    await expect(h1(page)).toHaveText(DONE_MESSAGE);
    expect(mock.posts[0].body.exceptions).toEqual([]);
  });

  test('when the list is not available the member can still finish, with or without a note', async ({ page }) => {
    const mock = await mockApi(page, { contextFor: () => ({ json: context({ equipment: { checklist_version: null, usable: false, compartments: [] } }) }) });
    await toEquipment(page);

    await expect(page.getByText('is not available right now')).toBeVisible();
    await expect(tallies(page)).toHaveCount(0);
    await page.getByLabel('What did you take, and what did you leave? (optional)').fill('Everything except the stair chair.');
    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();
    await expect(h1(page)).toHaveText(DONE_MESSAGE);
    expect(mock.posts[0].body).toMatchObject({ checklist_version: null, exceptions: [], note: 'Everything except the stair chair.' });
  });

  test('a handoff after the unit already moved needs no truck choice', async ({ page }) => {
    const mock = await mockApi(page, { contextFor: () => ({ json: context({
      mode: 'handoff_only',
      candidates: [],
      other_vehicles: [],
      unit: { apparatus_id: NEW_TRUCK_ID, designation: 'R 44', slug: 'r44', vehicle_number: '1033', station_id: STATION_ID, station_label: 'Station 4', status: 'In Service' },
      alert: { id: ALERT_ID, title: 'Upcoming', reason: 'Exhaust repair', schedule_label: 'Sunday', vehicle_number: '17503', planned_replacement_apparatus_id: null, changeout_id: null },
    }) }) });
    await page.goto(wizardUrl);

    await expect(page.getByText('R 44 is already on truck 1033.')).toBeVisible();
    await expect(page.getByRole('radio')).toHaveCount(0);
    await primary(page, 'Continue').click();
    await primary(page, 'Continue').click();
    await expect(page.getByText('From truck')).toBeVisible();
    await primary(page, 'Record Handoff').click();
    await expect(h1(page)).toHaveText(DONE_MESSAGE);
    expect(mock.posts[0].body).toMatchObject({ expected_vehicle_number: '1033', to_apparatus_id: NEW_TRUCK_ID, to_vehicle_number: null });
  });

  test('the Done screen has no waiting state of any kind', async ({ page }) => {
    await mockApi(page);
    await toReview(page);
    await expect(primary(page, 'Send to Fleet')).toHaveCount(0);
    await primary(page, 'Complete Changeout').click();
    await expect(h1(page)).toHaveText(DONE_MESSAGE);
    const text = await page.locator('[data-hub-ui="2"]').innerText();
    for (const banned of ['Waiting', 'pending', 'verification', 'Sent to Fleet']) expect(text.toLowerCase()).not.toContain(banned.toLowerCase());
  });
});

test.describe('interruptions and poor signal', () => {
  test('work survives a reload, Back between steps loses nothing, and Start over clears it', async ({ page }) => {
    await mockApi(page);
    await toEquipment(page);
    await markLeft(page, 'Stair chair');

    await page.goBack();
    await expect(heading(page, TRUCK_HEADING)).toBeVisible();
    await expect(page.getByRole('radio', { name: /^1033/ })).toBeChecked();
    await page.goForward();
    await expect(leftList(page)).toContainText('Stair chair');

    await page.goto(wizardUrl);
    await expect(page.getByText('Your saved work is back.')).toBeVisible();
    await expect(heading(page, EQUIPMENT_HEADING)).toBeVisible();
    await expect(leftList(page)).toContainText('Stair chair');

    await page.getByRole('button', { name: 'Start over' }).click();
    await page.getByRole('button', { name: 'Clear my choices' }).click();
    await expect(heading(page, TRUCK_HEADING)).toBeVisible();
    await expect(page.getByRole('radio', { name: /^1033/ })).not.toBeChecked();
    const leftovers = await page.evaluate(() => Object.keys(localStorage).filter((key) => key.startsWith('mbfd_changeout_draft_')));
    expect(leftovers).toEqual([]);
  });

  test('a draft saved by the earlier version on the phone is ignored rather than breaking the page', async ({ page }) => {
    await mockApi(page);
    await page.addInitScript(() => {
      localStorage.setItem('mbfd_changeout_draft_52_401', JSON.stringify({ version: 1, apparatusId: 52, userId: 401, clientSubmissionId: 'old', exceptions: { 'rear-item-1': { handling: 'review' } }, typedVehicle: '', note: '', savedAt: Date.now() }));
    });
    await page.goto(wizardUrl);
    await expect(heading(page, TRUCK_HEADING)).toBeVisible();
    await expect(page.getByText('Your saved work is back.')).toHaveCount(0);
  });

  test('focus goes to the step heading on every step change', async ({ page }) => {
    await mockApi(page);
    await toEquipment(page);
    await expect(page.locator('#changeout-step-heading')).toBeFocused();
    await primary(page, 'Continue').click();
    await expect(page.locator('#changeout-step-heading')).toHaveText('Review');
    await expect(page.locator('#changeout-step-heading')).toBeFocused();
    await primary(page, 'Back').click();
    await expect(page.locator('#changeout-step-heading')).toHaveText(EQUIPMENT_HEADING);
  });

  test('no signal: says Not sent, keeps everything, and a retry after reload reuses the same id', async ({ page }) => {
    const mock = await mockApi(page, {
      onPost: (route, attempt) => (attempt < 3
        ? route.abort('internetdisconnected')
        : route.fulfill({ status: 200, json: { changeout: summary(), replayed: true } })),
    });
    await toEquipment(page);
    await markLeft(page, 'Stair chair', 'Too big');
    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();

    await expect(page.getByRole('alert')).toHaveText(NOT_SENT);
    await expect(primary(page, 'Try again')).toBeEnabled();
    await expect(page.getByTestId('changeout-review-left')).toContainText('Too big');
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
    await expect(h1(page)).toHaveText(DONE_MESSAGE);

    expect(mock.posts).toHaveLength(3);
    expect(mock.posts[1].body).toEqual(mock.posts[0].body);
    expect(mock.posts[2].body).toEqual(mock.posts[0].body);
  });

  test('opening a saved draft with no signal uses the list saved on the phone', async ({ page }) => {
    const mock = await mockApi(page, { contextFor: (_path, attempt) => (attempt === 1 ? { json: context() } : 'abort') });
    await toEquipment(page);
    await markLeft(page, 'Stair chair');
    await page.goto(wizardUrl);

    await expect(page.getByText('this is the list saved on this phone')).toBeVisible();
    await expect(leftList(page)).toContainText('Stair chair');
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
    await expect(h1(page)).toHaveText(DONE_MESSAGE);
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
    await expect(heading(page, TRUCK_HEADING)).toBeVisible();
    await expect(page.getByText('could not save your work')).toHaveCount(0);

    await pickTruck(page, '1033');
    await expect(page.getByText('This phone could not save your work.')).toBeVisible();
    await expect(page.getByText('Saved on this phone until it is sent.')).toHaveCount(0);
    await primary(page, 'Continue').click();
    await primary(page, 'Continue').click();
    const stored = await page.evaluate(() => Object.keys(localStorage).filter((key) => key.startsWith('mbfd_changeout_')));
    expect(stored).toEqual([]);

    await primary(page, 'Complete Changeout').click();
    await expect(h1(page)).toHaveText(DONE_MESSAGE);
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
    await markLeft(page, 'Stair chair');
    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();

    await expect(page.getByRole('alert').filter({ hasText: 'Not sent.' })).toContainText('only on this page');
    await primary(page, 'Try again').click();
    await page.getByRole('button', { name: 'Review with the current truck' }).click();
    await expect(h1(page)).toHaveText('R 44, truck 1040, Station 4');
    await expect(page.getByTestId('changeout-review-left')).toContainText('Stair chair');
    await primary(page, 'Complete Changeout').click();
    await expect(h1(page)).toHaveText(DONE_MESSAGE);
    expect(mock.posts[2].body.exceptions).toEqual(mock.posts[0].body.exceptions);
  });
});

test.describe('Change vehicle, with no alert', () => {
  test('starts from the unit card, sends no alert id, and completes straight away', async ({ page }) => {
    const mock = await mockApi(page, {
      alerts: [],
      station: { apparatuses: [{ id: TRUCK_ID, name: 'Rescue 44', unit_id: 'R 44', designation: 'R 44', vehicle_number: '17503', type: 'rescue', slug: 'r44', status: 'In Service', daily_checkout_requirement: 'required' }] },
      contextFor: () => ({ json: context({ alert: null }) }),
    });
    await page.goto(`/daily/stations/${STATION_ID}`);
    await page.getByRole('tab', { name: 'Apparatus' }).click();
    await page.getByRole('link', { name: 'Change vehicle' }).click();

    await expect(heading(page, TRUCK_HEADING)).toBeVisible();
    await expect(h1(page)).toHaveText('R 44, truck 17503, Station 4');
    expect(mock.contextGets[0]).toBe(`/api/public/apparatuses/${TRUCK_ID}/changeout-context`);

    await pickTruck(page, '1033');
    await primary(page, 'Continue').click();
    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();
    await expect(h1(page)).toHaveText(DONE_MESSAGE);
    expect(mock.posts[0].body).toMatchObject({ alert_id: null, to_apparatus_id: NEW_TRUCK_ID, expected_unit_slug: 'r44' });
  });

  test('a link from the checkout names the truck, so the member only confirms it', async ({ page }) => {
    const mock = await mockApi(page);
    await page.goto(`/daily/changeouts/new/${TRUCK_ID}?truck=1034&return_to=${encodeURIComponent('/apparatus/r44')}`);

    await expect(page.getByRole('radio', { name: /^1034\b/ })).toBeChecked();
    await primary(page, 'Continue').click();
    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();
    await expect(h1(page)).toHaveText(DONE_MESSAGE);
    expect(mock.posts[0].body).toMatchObject({ to_apparatus_id: 58 });
  });

  test('a link naming a truck not on the list starts with that number typed', async ({ page }) => {
    await mockApi(page);
    await page.goto(`/daily/changeouts/new/${TRUCK_ID}?truck=NEW-9`);

    await expect(page.getByLabel('Or type the number on the truck')).toHaveValue('NEW-9');
    await expect(page.getByTestId('changeout-what-happens')).toContainText('Truck NEW-9 is not on the list yet.');
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
  test('CHANGEOUT_STALE shows the current truck and reviews with it, keeping Left items that still exist', async ({ page }) => {
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
    await markLeft(page, 'Stair chair');
    await markLeft(page, 'Flashlight');
    await primary(page, 'Continue').click();
    await primary(page, 'Complete Changeout').click();

    await expect(page.getByRole('alert')).toContainText('R 44 is on truck 1040 now.');
    await expect(primary(page, 'Complete Changeout')).toBeDisabled();
    await page.getByRole('button', { name: 'Review with the current truck' }).click();

    await expect(page).toHaveURL(/\/daily\/changeouts\/new\/70\?/);
    await expect(h1(page)).toHaveText('R 44, truck 1040, Station 4');
    await expect(page.getByRole('alert').filter({ hasText: 'These are no longer on the list: Flashlight.' })).toBeVisible();
    await expect(heading(page, 'Review')).toBeVisible();
    await primary(page, 'Complete Changeout').click();
    await expect(h1(page)).toHaveText(DONE_MESSAGE);

    expect(mock.posts[1].path).toBe('/api/public/apparatuses/70/changeouts');
    expect(mock.posts[1].body).toMatchObject({
      client_submission_id: mock.posts[0].body.client_submission_id,
      expected_vehicle_number: '1040',
      checklist_version: 'b'.repeat(64),
      exceptions: [{ item_key: 'rear-item-2', handling: 'leave', note: null }],
    });
  });

  test('CHANGEOUT_STALE reads the current truck from a current object, and the copy can be discarded', async ({ page }) => {
    await mockApi(page, { onPost: (route) => route.fulfill({ status: 409, json: { message: 'Another member already moved it. Nothing was saved.', code: 'CHANGEOUT_STALE', current: { vehicle_number: '1033', apparatus_id: NEW_TRUCK_ID } } }) });
    await toReview(page);
    await primary(page, 'Complete Changeout').click();

    await expect(page.getByRole('alert')).toContainText('R 44 is on truck 1033 now.');
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
    await expect(h1(page)).toHaveText(DONE_MESSAGE);
    expect(mock.posts[1].body.client_submission_id).toMatch(UUID_PATTERN);
    expect(mock.posts[1].body.client_submission_id).not.toBe(mock.posts[0].body.client_submission_id);
  });

  test('a validation refusal keeps the work and shows the server message', async ({ page }) => {
    await mockApi(page, { onPost: (route) => route.fulfill({ status: 422, json: { message: 'That truck number is too long.' } }) });
    await toReview(page);
    await primary(page, 'Complete Changeout').click();

    await expect(page.getByRole('alert')).toContainText('That truck number is too long.');
    await primary(page, 'Back').click();
    await expect(heading(page, EQUIPMENT_HEADING)).toBeVisible();
  });
});

test.describe('the receipt', () => {
  const lines = [
    { id: 2, item_key: 'rear-item-2', name: 'Stair chair', custody_status: 'left_behind', compartment: 'Rear Compartment', handling: 'leave', note: 'Too big' },
    { id: 3, item_key: 'rear-item-1', name: 'Spare oxygen bottle', custody_status: 'moved', compartment: 'Rear Compartment', handling: 'transfer', note: null },
    { id: 4, item_key: 'field:seal', name: 'Narcotic Seal #', custody_status: 'moved', compartment: 'Narcotics', handling: 'transfer', note: null },
  ];

  test('is read-only: from, to, what was taken and left, notes, who and when, and no actions', async ({ page }) => {
    await mockApi(page, { changeout: summary({ lines, note: 'Moved at Station 2.', moved: 2, left_behind: [{ name: 'Stair chair', note: 'Too big' }] }) });
    await page.goto(`/daily/changeouts/8?return_to=${encodeURIComponent(`/stations/${STATION_ID}`)}`);

    await expect(h1(page)).toHaveText(DONE_MESSAGE);
    await expect(page.getByText('From truck')).toBeVisible();
    await expect(page.getByText('To truck')).toBeVisible();
    await expect(page.getByText('Alex Rivera')).toBeVisible();
    await expect(page.getByText('Took 2, left 1')).toBeVisible();
    await expect(page.getByText('Moved at Station 2.')).toBeVisible();
    await expect(page.getByRole('region', { name: 'Left on truck 17503' })).toContainText('Too big');
    await page.getByText('Took (2)').click();
    await expect(page.getByText('Narcotic Seal #')).toBeVisible();

    const main = page.locator('[data-hub-ui="2"]');
    await expect(main.getByRole('button', { name: 'Verify' })).toHaveCount(0);
    await expect(main.getByRole('link', { name: /Start Daily Checkout|Return to truck/ })).toHaveCount(0);
    expect((await main.innerText()).toLowerCase()).not.toContain('verif');
  });

  test('a record with no lines still shows its counts and left items', async ({ page }) => {
    await mockApi(page, { changeout: summary({ moved: 4, left_behind: [{ name: 'Stair chair', note: 'Too big' }] }) });
    await page.goto('/daily/changeouts/8');

    await expect(page.getByText('Took 4, left 1')).toBeVisible();
    await expect(page.getByRole('region', { name: 'Left on truck 17503' })).toContainText('Too big');
  });

  test('a record that cannot be read offers Retry and a way back', async ({ page }) => {
    await mockApi(page);
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
    await expect(heading(page, TRUCK_HEADING)).toBeVisible();
    const fits = () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
    expect(await fits()).toBe(true);
    await page.getByRole('button', { name: 'Different truck' }).click();
    await page.getByLabel('Or type the number on the truck').fill('1034');
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

    await primary(page, 'Continue').click();
    await expect(heading(page, 'Review')).toBeVisible();
    expect(await fits()).toBe(true);
    await primary(page, 'Complete Changeout').click();
    await expect(h1(page)).toHaveText(DONE_MESSAGE);
    expect(await fits()).toBe(true);
  });

  test('radio groups and the step list are real semantics', async ({ page }) => {
    await mockApi(page);
    await page.goto(wizardUrl);

    await expect(page.getByRole('radiogroup', { name: TRUCK_HEADING }).getByRole('radio')).toHaveCount(2);
    await expect(page.locator('[aria-current="step"]')).toHaveText('1. Truck');
  });

  test('reduced motion: truck cards do not animate', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await mockApi(page);
    await page.goto(wizardUrl);
    const transition = await page.locator('label:has(input[type="radio"])').first().evaluate((element) => getComputedStyle(element).transitionProperty);
    expect(transition).toBe('none');
  });
});
