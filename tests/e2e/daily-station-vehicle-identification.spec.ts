import { expect, test, type Locator, type Page } from '@playwright/test';

/**
 * One-time vehicle information on the station page (design 12-autopair-design.md, section 3), every API
 * response mocked. A unit with no Daily Checkout and no Captain 5 page (production: A 2 at Station 2)
 * arrives inside the existing station detail response as
 * `vehicle_identification_units: [{ apparatus_id, designation, vehicle_identification }]`. Answers POST to
 * /api/public/apparatuses/{id}/vehicle-identification. There is no new GET.
 */

const STATION_ID = 2;
const A2_ID = 77;
const SECOND_ID = 78;
const VERSION = 'a'.repeat(64);
const SECOND_VERSION = 'b'.repeat(64);
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const NOTE = "One-time question: this information is needed only once to create this unit's vehicle record or match it to the Master Vehicle Inventory.";
const NOT_PRESENT = "It isn't on this vehicle (no plate, plate missing, ID worn off)";

const reasons = [
  { code: 'not_accessible_now', label: "Can't get to it right now (unit in service, away, or blocked)", needs_note: false },
  { code: 'not_present', label: NOT_PRESENT, needs_note: false },
  { code: 'other', label: 'Other (short note required)', needs_note: true },
];

const plateQuestion = { field: 'plate', label: 'License plate', hint: 'Check the rear bumper.', input: 'text', max: 32, current_value: null, confirm: false };
const yearQuestion = { field: 'year', label: 'Model year', hint: null, input: 'text', max: 4, current_value: null, confirm: false };

const block = (overrides: Record<string, unknown> = {}) => ({
  required: true,
  note: NOTE,
  can_answer: true,
  kind: 'vehicle',
  questions: [plateQuestion],
  reasons,
  questions_version: VERSION,
  ...overrides,
});

const a2 = (overrides: Record<string, unknown> = {}) => ({ apparatus_id: A2_ID, designation: 'A 2', vehicle_identification: block(overrides) });

const station = (extra: Record<string, unknown>) => ({
  id: STATION_ID,
  station_number: 2,
  name: 'Station 2',
  address: '2300 Pine Tree Drive',
  city: 'Miami Beach',
  state: 'FL',
  zip_code: '33140',
  phone: '',
  is_active: true,
  rooms: [],
  apparatuses: [],
  assigned_apparatus_count: 0,
  assigned_personnel_count: 0,
  dorm_beds_count: 0,
  assigned_units: [],
  ...extra,
});

interface Mock {
  saves: Array<{ path: string; body: Record<string, unknown> }>;
  gets: string[];
}

type SaveHandler = (path: string, body: Record<string, unknown>, attempt: number) => { status: number; json: unknown };

/** Mocks the API for the station page; `onSave` answers each POST (default: nothing left to answer). */
async function openStation(page: Page, stationExtra: Record<string, unknown>, onSave?: SaveHandler): Promise<Mock> {
  const mock: Mock = { saves: [], gets: [] };
  await page.route('**/images/**', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    if (request.method() === 'GET') mock.gets.push(path);
    if (path === '/api/me/context') {
      return route.fulfill({ json: {
        version: 1,
        identity: { user_id: 401, has_personnel_profile: true },
        personnel: { employee_profile_id: 1_401, employee_number: 'E401', name: 'Alex Rivera', rank: 'Captain' },
        offline: { security_version: 1 },
        session: { authenticated: true },
        authorization: { capabilities: { command_300_access: false } },
      } });
    }
    if (path === `/api/public/stations/${STATION_ID}`) return route.fulfill({ json: station(stationExtra) });
    if (path === `/api/public/stations/${STATION_ID}/service-tickets`) return route.fulfill({ json: { data: [], meta: { total: 0 } } });
    if (request.method() === 'POST' && /^\/api\/public\/apparatuses\/\d+\/vehicle-identification$/.test(path)) {
      const body = request.postDataJSON() as Record<string, unknown>;
      mock.saves.push({ path, body });
      const answer = onSave?.(path, body, mock.saves.length) ?? { status: 200, json: { vehicle_identification: block({ required: false, questions: [] }) } };

      return route.fulfill(answer);
    }
    return route.fulfill({ status: 404, json: { message: `Unmocked route ${path}` } });
  });
  await page.goto(`/daily/stations/${STATION_ID}`);
  await expect(page.getByRole('heading', { name: `Station ${STATION_ID}`, level: 1 })).toBeVisible();

  return mock;
}

const section = (page: Page) => page.getByTestId('station-vehicle-identification');
const unitCard = (page: Page, designation: string) => page.getByRole('article', { name: `${designation} one-time vehicle information` });
const field = (scope: Page | Locator, name: string) => scope.getByRole('group', { name, exact: true });
const save = (page: Page) => page.getByRole('button', { name: 'Save vehicle information' });

test.describe('one-time vehicle information on the station page', () => {
  test('an A 2 shaped unit shows its card with the one-time note, the question and a large Save button', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    const mock = await openStation(page, { vehicle_identification_units: [a2()] });

    await expect(section(page)).toBeVisible();
    await expect(unitCard(page, 'A 2')).toBeVisible();
    await expect(unitCard(page, 'A 2').getByRole('heading', { name: 'One-time vehicle information' })).toBeVisible();
    await expect(unitCard(page, 'A 2')).toContainText(NOTE);
    await expect(field(page, 'License plate')).toContainText('Check the rear bumper.');
    await expect(field(page, 'License plate').getByRole('checkbox', { name: 'Information not available' })).toBeVisible();

    const plate = field(page, 'License plate').getByRole('textbox');
    await plate.fill('ab 12-x');
    await expect(plate).toHaveValue('AB 12-X');

    const heights = await unitCard(page, 'A 2').locator('input[type="text"], label:has(input[type="checkbox"]), button').evaluateAll(
      (elements) => elements.map((element) => element.getBoundingClientRect().height),
    );
    expect(heights.length).toBeGreaterThanOrEqual(3);
    for (const height of heights) expect(height).toBeGreaterThanOrEqual(44);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

    // Everything arrived inside the existing station read model: no GET for identification exists.
    expect(mock.gets.filter((path) => path.includes('vehicle-identification'))).toEqual([]);
    expect(mock.saves).toHaveLength(0);
  });

  test('answering posts the typed value to the unit endpoint, then the card goes away with a saved status', async ({ page }) => {
    const mock = await openStation(page, { vehicle_identification_units: [a2()] });

    await field(page, 'License plate').getByRole('textbox').fill('ab12cd');
    await save(page).click();

    await expect(page.getByTestId('station-vehicle-identification-saved')).toHaveText('Vehicle information saved for A 2. Thank you.');
    await expect(unitCard(page, 'A 2')).toHaveCount(0);
    await expect(page.locator('[data-vehicle-identification]')).toHaveCount(0);
    expect(mock.saves).toHaveLength(1);
    expect(mock.saves[0].path).toBe(`/api/public/apparatuses/${A2_ID}/vehicle-identification`);
    expect(mock.saves[0].body).toEqual({
      client_submission_id: expect.stringMatching(UUID_PATTERN),
      questions_version: VERSION,
      answers: [{ field: 'plate', value: 'AB12CD' }],
    });
  });

  test('Information not available needs a reason (and a note for Other) and posts the reason', async ({ page }) => {
    const mock = await openStation(page, { vehicle_identification_units: [a2()] });

    await field(page, 'License plate').getByRole('checkbox', { name: 'Information not available' }).check();
    await expect(field(page, 'License plate').getByRole('textbox')).toHaveCount(0);
    await expect(field(page, 'License plate').getByRole('radiogroup', { name: 'Why is it not available?' }).getByRole('radio')).toHaveCount(3);

    await save(page).click();
    await expect(field(page, 'License plate').getByRole('alert')).toHaveText('Choose why it is not available.');
    expect(mock.saves).toHaveLength(0);

    await field(page, 'License plate').getByRole('radio', { name: /^Other/ }).check();
    await save(page).click();
    await expect(field(page, 'License plate').getByRole('alert')).toHaveText('Add a short note.');
    expect(mock.saves).toHaveLength(0);

    await field(page, 'License plate').getByRole('radio', { name: NOT_PRESENT }).check();
    await save(page).click();
    await expect(unitCard(page, 'A 2')).toHaveCount(0);
    expect(mock.saves).toHaveLength(1);
    expect(mock.saves[0].body).toEqual({
      client_submission_id: expect.stringMatching(UUID_PATTERN),
      questions_version: VERSION,
      answers: [{ field: 'plate', unavailable_reason: 'not_present', note: null }],
    });
  });

  test('an empty question cannot be saved: it says what is missing and sends nothing', async ({ page }) => {
    const mock = await openStation(page, { vehicle_identification_units: [a2()] });

    await save(page).click();
    await expect(field(page, 'License plate').getByRole('alert')).toHaveText('Enter it, or choose Information not available.');
    await expect(field(page, 'License plate').getByRole('textbox')).toBeFocused();
    expect(mock.saves).toHaveLength(0);

    await field(page, 'License plate').getByRole('textbox').fill('a');
    await save(page).click();
    await expect(field(page, 'License plate').getByRole('alert')).toContainText('A plate has 2 to 16 letters and numbers.');
    expect(mock.saves).toHaveLength(0);
  });

  test('a model of X is a real answer for make and model, but a plate of X is refused', async ({ page }) => {
    const modelQuestion = { field: 'model', label: 'Model', hint: null, input: 'text', max: 64, current_value: null, confirm: false };
    const mock = await openStation(page, { vehicle_identification_units: [a2({ questions: [plateQuestion, modelQuestion] })] });

    await field(page, 'License plate').getByRole('textbox').fill('x');
    await field(page, 'Model').getByRole('textbox').fill('x');
    await save(page).click();
    await expect(field(page, 'License plate').getByRole('alert')).toContainText('That is not a real value.');
    await expect(field(page, 'Model').getByRole('alert')).toHaveCount(0);
    expect(mock.saves).toHaveLength(0);

    await field(page, 'License plate').getByRole('textbox').fill('ab12cd');
    await save(page).click();
    await expect(unitCard(page, 'A 2')).toHaveCount(0);
    expect(mock.saves[0].body.answers).toEqual([{ field: 'plate', value: 'AB12CD' }, { field: 'model', value: 'x' }]);
  });

  test('answered questions are hidden using the response; the card stays only for what is still open', async ({ page }) => {
    const mock = await openStation(
      page,
      { vehicle_identification_units: [a2({ questions: [plateQuestion, yearQuestion] })] },
      (_path, _body, attempt) => ({
        status: 200,
        json: { vehicle_identification: attempt === 1 ? block({ questions: [yearQuestion], questions_version: SECOND_VERSION }) : block({ required: false, questions: [] }) },
      }),
    );

    await field(page, 'License plate').getByRole('textbox').fill('ab12cd');
    await field(page, 'Model year').getByRole('textbox').fill('2019');
    await save(page).click();

    await expect(field(page, 'License plate')).toHaveCount(0);
    await expect(field(page, 'Model year')).toBeVisible();
    await expect(unitCard(page, 'A 2')).toBeVisible();
    await expect(unitCard(page, 'A 2').getByRole('status')).toContainText('Saved.');
    await expect(field(page, 'Model year').getByRole('textbox')).toHaveValue('');

    await field(page, 'Model year').getByRole('textbox').fill('2020');
    await save(page).click();
    await expect(unitCard(page, 'A 2')).toHaveCount(0);
    expect(mock.saves).toHaveLength(2);
    expect(mock.saves[0].body.answers).toEqual([{ field: 'plate', value: 'AB12CD' }, { field: 'year', value: '2019' }]);
    expect(mock.saves[1].body).toEqual({
      client_submission_id: expect.stringMatching(UUID_PATTERN),
      questions_version: SECOND_VERSION,
      answers: [{ field: 'year', value: '2020' }],
    });
  });

  test('a failed save keeps the answers and shows the error; the retry reuses the same submission id', async ({ page }) => {
    const mock = await openStation(
      page,
      { vehicle_identification_units: [a2()] },
      (_path, _body, attempt) => (attempt === 1
        ? { status: 503, json: { message: 'The server had a problem. Try again in a moment.' } }
        : { status: 200, json: { vehicle_identification: block({ required: false, questions: [] }) } }),
    );

    await field(page, 'License plate').getByRole('textbox').fill('xk4560');
    await save(page).click();
    await expect(unitCard(page, 'A 2').getByRole('alert')).toContainText('The server had a problem.');
    await expect(field(page, 'License plate').getByRole('textbox')).toHaveValue('XK4560');
    await expect(save(page)).toBeEnabled();

    await save(page).click();
    await expect(unitCard(page, 'A 2')).toHaveCount(0);
    expect(mock.saves).toHaveLength(2);
    expect(mock.saves[0].body).toEqual(mock.saves[1].body);
    expect(mock.saves[1].body.client_submission_id).toMatch(UUID_PATTERN);
  });

  test('offline the save is blocked with a clear message and sends nothing', async ({ page, context }) => {
    const mock = await openStation(page, { vehicle_identification_units: [a2()] });

    await field(page, 'License plate').getByRole('textbox').fill('ab12cd');
    await context.setOffline(true);
    await expect(page.getByText('You are offline. Connect to save this.')).toBeVisible();
    await expect(save(page)).toBeDisabled();
    expect(mock.saves).toHaveLength(0);

    await context.setOffline(false);
    await expect(save(page)).toBeEnabled();
    await expect(field(page, 'License plate').getByRole('textbox')).toHaveValue('AB12CD');
  });

  test('each unit saves to its own endpoint and only the answered unit leaves', async ({ page }) => {
    const mock = await openStation(page, { vehicle_identification_units: [
      a2(),
      { apparatus_id: SECOND_ID, designation: 'RB 2', vehicle_identification: block({ questions: [yearQuestion], questions_version: SECOND_VERSION }) },
    ] });

    await expect(page.getByTestId('station-vehicle-identification-unit')).toHaveCount(2);
    await field(unitCard(page, 'RB 2'), 'Model year').getByRole('textbox').fill('2018');
    await unitCard(page, 'RB 2').getByRole('button', { name: 'Save vehicle information' }).click();

    await expect(unitCard(page, 'RB 2')).toHaveCount(0);
    await expect(unitCard(page, 'A 2')).toBeVisible();
    expect(mock.saves).toHaveLength(1);
    expect(mock.saves[0].path).toBe(`/api/public/apparatuses/${SECOND_ID}/vehicle-identification`);
    expect(mock.saves[0].body.questions_version).toBe(SECOND_VERSION);
  });

  test('a person who cannot answer sees the questions read-only with no inputs and no Save button', async ({ page }) => {
    const mock = await openStation(page, { vehicle_identification_units: [
      a2({ can_answer: false, questions: [{ ...plateQuestion, current_value: 'XD1904', confirm: true }] }),
    ] });

    const readOnly = unitCard(page, 'A 2').locator('[data-vehicle-identification][data-read-only]');
    await expect(readOnly).toBeVisible();
    await expect(readOnly).toContainText('License plate');
    await expect(readOnly).toContainText('The Hub has XD1904 on record.');
    await expect(readOnly.getByRole('textbox')).toHaveCount(0);
    await expect(readOnly.getByRole('radio')).toHaveCount(0);
    await expect(readOnly.getByRole('checkbox')).toHaveCount(0);
    await expect(save(page)).toHaveCount(0);
    expect(mock.saves).toHaveLength(0);
  });

  const nothingToShow: Array<[string, Record<string, unknown>]> = [
    ['the key is missing (older server)', {}],
    ['the list is empty', { vehicle_identification_units: [] }],
    ['the value is not a list', { vehicle_identification_units: { apparatus_id: A2_ID } }],
    ['every unit has no open question', { vehicle_identification_units: [a2({ required: false, questions: [] }), a2({ questions: [] })] }],
    ['a unit is malformed', { vehicle_identification_units: [{ designation: 'A 2', vehicle_identification: block() }, { apparatus_id: 'x', vehicle_identification: block() }, null] }],
  ];
  for (const [name, extra] of nothingToShow) {
    test(`renders nothing when ${name}`, async ({ page }) => {
      await openStation(page, extra);

      await expect(section(page)).toHaveCount(0);
      await expect(page.getByText('One-time vehicle information')).toHaveCount(0);
      await expect(page.locator('[data-vehicle-identification]')).toHaveCount(0);
      await expect(page.getByRole('tab', { name: 'Overview' })).toBeVisible();
    });
  }
});
