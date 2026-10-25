import { expect, test, type Page } from '@playwright/test';
import { resolveBlueprint } from '../../resources/js/daily-checkout/src/data/apparatusBlueprints';
import type { InspectionSubmission } from '../../resources/js/daily-checkout/src/types';
import { readDrafts } from './support/daily-checkout-drafts';
import { mobileApparatus, mobileInspectionFixture } from './support/daily-checkout-mobile';

/**
 * One-time vehicle information (design 11-vehicle-identification-design.md), every API response mocked.
 * Server contract: `vehicle_identification` next to the checklist, answers sent as
 * `vehicle_identification: { questions_version, answers }` with the checkout, and
 * POST /api/public/apparatuses/{id}/vehicle-identification for the Captain 5 page.
 */

const engine2 = mobileApparatus[0];
const VERSION = 'a'.repeat(64);
const VIN = '1FTFW1ET5DFC10312';
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const NOTE = "One-time question: this information is needed only once to create this unit's vehicle record or match it to the Master Vehicle Inventory.";
const HELP = "If it isn't on the vehicle or you can't read it, choose Information not available.";
const NOT_NOW = "Can't get to it right now (unit in service, away, or blocked)";

const reasons = [
  { code: 'not_accessible_now', label: NOT_NOW, needs_note: false },
  { code: 'not_present', label: "It isn't on this vehicle (no plate, plate missing, ID worn off)", needs_note: false },
  { code: 'other', label: 'Other (short note required)', needs_note: true },
];

const identification = {
  required: true,
  note: NOTE,
  kind: 'vehicle',
  questions: [
    { field: 'plate', label: 'License plate', hint: 'Check the rear bumper.', input: 'text', max: 32, current_value: null, confirm: false },
    { field: 'vin', label: 'VIN', hint: '17 characters, driver door jamb or windshield.', input: 'text', max: 32, current_value: null, confirm: false },
    { field: 'year', label: 'Model year', hint: null, input: 'text', max: 4, current_value: null, confirm: false },
    { field: 'model', label: 'Model', hint: null, input: 'text', max: 64, current_value: 'F-250', confirm: true },
  ],
  reasons,
  questions_version: VERSION,
};

const navigation = (page: Page) => page.getByRole('navigation', { name: 'Inspection workspace' });
const vehicleSelect = (page: Page) => page.getByRole('combobox', { name: 'Physical vehicle', exact: true });
const card = (page: Page) => page.locator('[data-vehicle-identification]');
const group = (page: Page, name: string) => page.getByRole('group', { name, exact: true });
const notAvailable = (page: Page, name: string) => group(page, name).getByRole('checkbox', { name: 'Information not available' });
const continueButton = (page: Page) => page.locator('.inspection-review-button');

async function openMemberStep(page: Page) {
  await navigation(page).getByRole('button', { name: 'Member / Vehicle Info', exact: true }).click();
  await expect(vehicleSelect(page)).toBeVisible();
}

async function chooseShiftA(page: Page) {
  await page.getByRole('combobox', { name: 'Shift', exact: true }).selectOption('A');
}

/** Answers every question: a typed plate, VIN not available now, a typed year, and the model confirmed. */
async function answerAll(page: Page) {
  await group(page, 'License plate').getByRole('textbox').fill('abc123');
  await notAvailable(page, 'VIN').check();
  await group(page, 'VIN').getByRole('radio', { name: NOT_NOW }).check();
  await group(page, 'Model year').getByRole('textbox').fill('2019');
  await group(page, 'Model').getByRole('radio', { name: 'Yes, that is what is on the vehicle' }).check();
}

const expectedAnswers = {
  questions_version: VERSION,
  answers: [
    { field: 'plate', value: 'ABC123' },
    { field: 'vin', unavailable_reason: 'not_accessible_now', note: null },
    { field: 'year', value: '2019' },
    { field: 'model', value: 'F-250' },
  ],
};

test.describe('one-time vehicle information in the Daily Checkout', () => {
  const noQuestions: Array<[string, unknown]> = [
    ['the server sends no block', undefined],
    ['the server says nothing is required', { required: false, note: '', kind: 'vehicle', questions: [], reasons: [], questions_version: VERSION }],
  ];

  for (const [name, block] of noQuestions) {
    test(`no card and no extra gate when ${name}`, async ({ page }) => {
      await mobileInspectionFixture(page, engine2, block === undefined ? {} : { vehicleIdentification: block });
      await openMemberStep(page);

      await expect(card(page)).toHaveCount(0);
      await expect(page.getByText('One-time vehicle information')).toHaveCount(0);
      await chooseShiftA(page);
      await continueButton(page).click();
      await expect(vehicleSelect(page)).toHaveCount(0);
    });
  }

  test('the card shows the server note and one block per question with large touch targets', async ({ page }) => {
    await mobileInspectionFixture(page, engine2, { vehicleIdentification: identification });
    await openMemberStep(page);

    await expect(card(page)).toHaveCount(1);
    await expect(card(page).getByRole('heading', { name: 'One-time vehicle information' })).toBeVisible();
    await expect(card(page)).toContainText(NOTE);
    await expect(card(page).getByRole('group')).toHaveCount(4);
    await expect(group(page, 'License plate')).toContainText('Check the rear bumper.');
    await expect(group(page, 'Model')).toContainText('The Hub has F-250 on record.');

    const plate = group(page, 'License plate').getByRole('textbox');
    await plate.fill('abc 12-x');
    await expect(plate).toHaveValue('ABC 12-X');
    const year = group(page, 'Model year').getByRole('textbox');
    // Typed one key at a time, as a crew member would: letters are dropped and the fifth digit never lands.
    await year.pressSequentially('20a1b9x72');
    await expect(year).toHaveValue('2019');

    await expect(group(page, 'Model').getByRole('radio')).toHaveCount(2);
    await expect(group(page, 'Model').getByRole('textbox')).toHaveCount(0);
    await group(page, 'Model').getByRole('radio', { name: 'No, I will enter what is on the vehicle' }).check();
    await expect(group(page, 'Model').getByRole('textbox')).toBeVisible();

    await notAvailable(page, 'VIN').check();
    await expect(group(page, 'VIN').getByRole('textbox')).toHaveCount(0);
    await expect(group(page, 'VIN').getByRole('radiogroup', { name: 'Why is it not available?' }).getByRole('radio')).toHaveCount(3);
    await expect(group(page, 'VIN').getByLabel('Short note (required)')).toHaveCount(0);
    await group(page, 'VIN').getByRole('radio', { name: /^Other/ }).check();
    await expect(group(page, 'VIN').getByLabel('Short note (required)')).toBeVisible();

    const heights = await card(page).locator('input[type="text"], label:has(input[type="checkbox"]), label:has(input[type="radio"])').evaluateAll(
      (elements) => elements.map((element) => element.getBoundingClientRect().height),
    );
    expect(heights.length).toBeGreaterThan(6);
    for (const height of heights) expect(height).toBeGreaterThanOrEqual(44);
  });

  test('Continue is blocked until each question is answered or marked not available with its reason', async ({ page }) => {
    const api = await mobileInspectionFixture(page, engine2, { vehicleIdentification: identification });
    await openMemberStep(page);
    await chooseShiftA(page);
    await continueButton(page).click();

    // Nothing answered: still on the step, every question says what is missing, focus goes to the first one.
    await expect(vehicleSelect(page)).toBeVisible();
    await expect(card(page).getByRole('alert')).toHaveCount(4);
    await expect(group(page, 'License plate').getByRole('alert')).toHaveText('Enter it, or choose Information not available.');
    await expect(group(page, 'Model').getByRole('alert')).toHaveText('Confirm it, or enter what is on the vehicle.');
    await expect(group(page, 'License plate').getByRole('textbox')).toBeFocused();

    await group(page, 'License plate').getByRole('textbox').fill('ab12');
    await group(page, 'VIN').getByRole('textbox').fill('abc');
    await continueButton(page).click();
    await expect(group(page, 'License plate').getByRole('alert')).toHaveCount(0);
    await expect(group(page, 'VIN').getByRole('alert')).toHaveText('A VIN has 17 letters and numbers. ' + HELP);

    await group(page, 'VIN').getByRole('textbox').fill(VIN.toLowerCase());
    await expect(group(page, 'VIN').getByRole('textbox')).toHaveValue(VIN);
    await group(page, 'Model year').getByRole('textbox').fill('19');
    await continueButton(page).click();
    await expect(group(page, 'VIN').getByRole('alert')).toHaveCount(0);
    await expect(group(page, 'Model year').getByRole('alert')).toHaveText('Enter a year from 1900 to 2099.');

    // Not available needs a reason, and the "Other" reason also needs a short note.
    await notAvailable(page, 'Model year').check();
    await continueButton(page).click();
    await expect(group(page, 'Model year').getByRole('alert')).toHaveText('Choose why it is not available.');
    await group(page, 'Model year').getByRole('radio', { name: /^Other/ }).check();
    await continueButton(page).click();
    await expect(group(page, 'Model year').getByRole('alert')).toHaveText('Add a short note.');
    await group(page, 'Model year').getByLabel('Short note (required)').fill('Year is not on the door tag');
    await continueButton(page).click();
    await expect(group(page, 'Model year').getByRole('alert')).toHaveCount(0);

    // The last question: confirm it, or enter another value.
    await expect(vehicleSelect(page)).toBeVisible();
    await group(page, 'Model').getByRole('radio', { name: 'No, I will enter what is on the vehicle' }).check();
    await continueButton(page).click();
    await expect(group(page, 'Model').getByRole('alert')).toHaveText('Enter it, or choose Information not available.');
    await group(page, 'Model').getByRole('textbox').fill('F-350');
    await continueButton(page).click();

    await expect(vehicleSelect(page)).toHaveCount(0);
    expect(api.submissions).toEqual([]);
  });

  test('values the server would drop are refused with a pointer to Information not available', async ({ page }) => {
    const NOT_REAL = `That is not a real value. ${HELP}`;
    await mobileInspectionFixture(page, engine2, { vehicleIdentification: {
      ...identification,
      questions: [
        identification.questions[0],
        { field: 'vehicle_number', label: 'Fleet vehicle number', hint: null, input: 'text', max: 32, current_value: null, confirm: false },
        { field: 'make', label: 'Make', hint: null, input: 'text', max: 64, current_value: null, confirm: false },
      ],
    } });
    await openMemberStep(page);
    await chooseShiftA(page);

    const cases: Array<[string, string, string]> = [
      ['License plate', 'A', `A plate has 2 to 16 letters and numbers. ${HELP}`],
      ['License plate', 'n/a', NOT_REAL],
      ['License plate', '-', NOT_REAL],
      ['License plate', 'aaaa', NOT_REAL],
      ['Fleet vehicle number', 'abc', `A vehicle number has 4 to 32 characters: letters, numbers, spaces and . / # -. ${HELP}`],
      ['Fleet vehicle number', 'unknown', NOT_REAL],
      ['Fleet vehicle number', '0', NOT_REAL],
      // X is a placeholder for an identifier, but a real model (Tesla Model X), so make and model accept it.
      ['License plate', 'x', NOT_REAL],
      ['Fleet vehicle number', 'X', NOT_REAL],
      ['Make', 'none', NOT_REAL],
      ['Make', 'n/a', NOT_REAL],
      ['Make', '0', NOT_REAL],
      ['Make', '-', NOT_REAL],
    ];
    for (const [label, value, message] of cases) {
      await group(page, label).getByRole('textbox').fill(value);
      await continueButton(page).click();
      await expect(group(page, label).getByRole('alert'), `${label}: ${value}`).toHaveText(message);
    }

    await group(page, 'License plate').getByRole('textbox').fill('ab 1-2');
    await group(page, 'Fleet vehicle number').getByRole('textbox').fill('E-2/12');
    await group(page, 'Make').getByRole('textbox').fill('Pierce');
    await continueButton(page).click();
    await expect(vehicleSelect(page)).toHaveCount(0);
  });

  test('make and model accept X (Tesla Model X) while placeholders are still refused', async ({ page }) => {
    await mobileInspectionFixture(page, engine2, { vehicleIdentification: {
      ...identification,
      questions: [
        { field: 'make', label: 'Make', hint: null, input: 'text', max: 64, current_value: null, confirm: false },
        { field: 'model', label: 'Model', hint: null, input: 'text', max: 64, current_value: null, confirm: false },
      ],
    } });
    await openMemberStep(page);
    await chooseShiftA(page);

    await group(page, 'Make').getByRole('textbox').fill('Tesla');
    await group(page, 'Model').getByRole('textbox').fill('unknown');
    await continueButton(page).click();
    await expect(group(page, 'Model').getByRole('alert')).toHaveText(`That is not a real value. ${HELP}`);
    await expect(group(page, 'Make').getByRole('alert')).toHaveCount(0);

    await group(page, 'Model').getByRole('textbox').fill('x');
    await continueButton(page).click();
    await expect(vehicleSelect(page)).toHaveCount(0);
  });

  test('a boat asks for a hull ID of 6 to 32 letters and numbers', async ({ page }) => {
    await mobileInspectionFixture(page, engine2, { vehicleIdentification: {
      ...identification,
      kind: 'boat',
      questions: [{ field: 'vin', label: 'Hull ID', hint: null, input: 'text', max: 32, current_value: null, confirm: false }],
    } });
    await openMemberStep(page);
    await chooseShiftA(page);
    await group(page, 'Hull ID').getByRole('textbox').fill('ab12');
    await continueButton(page).click();
    await expect(group(page, 'Hull ID').getByRole('alert')).toHaveText(`A hull ID has 6 to 32 letters and numbers. ${HELP}`);
    await group(page, 'Hull ID').getByRole('textbox').fill('abc-123');
    await continueButton(page).click();
    await expect(vehicleSelect(page)).toHaveCount(0);
  });

  test('a different physical vehicle hides the card, skips the gate, and the answers return with the assigned vehicle', async ({ page }) => {
    await mobileInspectionFixture(page, engine2, {
      fleetVehicles: [{ vehicle_number: 'TEST-205', designation: 'E5', role: 'frontline' }],
      vehicleIdentification: identification,
    });
    await openMemberStep(page);
    await group(page, 'License plate').getByRole('textbox').fill('ab12');

    await vehicleSelect(page).selectOption('TEST-205');
    await expect(card(page)).toHaveCount(0);
    await page.getByRole('group', { name: 'Was a truck swap completed?' }).getByRole('radio', { name: 'Yes, swap completed' }).check();
    await chooseShiftA(page);
    await continueButton(page).click();
    await expect(vehicleSelect(page)).toHaveCount(0);

    await openMemberStep(page);
    await page.getByRole('button', { name: 'I picked wrong', exact: true }).click();
    await expect(card(page)).toHaveCount(1);
    await expect(group(page, 'License plate').getByRole('textbox')).toHaveValue('AB12');
  });

  test('a checkout for a different physical vehicle is not blocked and sends no vehicle_identification key', async ({ page }) => {
    test.setTimeout(120_000);
    const api = await mobileInspectionFixture(page, engine2, {
      fleetVehicles: [{ vehicle_number: 'TEST-205', designation: 'E5', role: 'frontline' }],
      vehicleIdentification: identification,
    });
    await completeChecklist(page, api.checklist);
    await openMemberStep(page);
    await vehicleSelect(page).selectOption('TEST-205');
    await page.getByRole('group', { name: 'Was a truck swap completed?' }).getByRole('radio', { name: 'Yes, swap completed' }).check();
    await chooseShiftA(page);
    await page.getByRole('button', { name: 'Review & Sign', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Review & Sign Inspection' })).toBeVisible();
    await sign(page);
    await page.getByRole('button', { name: 'Submit Inspection', exact: true }).click();

    await expect.poll(() => api.submissions.length).toBe(1);
    expect(api.submissions[0].physical_vehicle).toEqual({ vehicle_number: 'TEST-205', swap_answer: 'swap_completed' });
    expect(Object.keys(api.submissions[0])).not.toContain('vehicle_identification');
  });

  test('answers are kept in the draft and come back after a reload', async ({ page }) => {
    await mobileInspectionFixture(page, engine2, { vehicleIdentification: identification });
    await openMemberStep(page);
    await group(page, 'License plate').getByRole('textbox').fill('ab12');
    await notAvailable(page, 'VIN').check();
    await group(page, 'VIN').getByRole('radio', { name: NOT_NOW }).check();

    await expect.poll(async () => (await readDrafts(page))[0]?.data.vehicleIdentification).toEqual({
      plate: { mode: 'value', value: 'AB12', reason: '', note: '' },
      vin: { mode: 'unavailable', value: '', reason: 'not_accessible_now', note: '' },
    });

    await page.reload();
    await expect(page.getByRole('heading', { name: engine2.name, exact: true })).toBeVisible();
    await openMemberStep(page);
    await expect(group(page, 'License plate').getByRole('textbox')).toHaveValue('AB12');
    await expect(notAvailable(page, 'VIN')).toBeChecked();
    await expect(group(page, 'VIN').getByRole('radio', { name: NOT_NOW })).toBeChecked();
  });

  test('the checkout cannot be reviewed until answered, then sends the answers with the payload', async ({ page }) => {
    test.setTimeout(120_000);
    const api = await mobileInspectionFixture(page, engine2, { vehicleIdentification: identification });
    await completeChecklist(page, api.checklist);
    await openMemberStep(page);
    await chooseShiftA(page);

    await page.getByRole('button', { name: 'Review & Sign', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Review & Sign Inspection' })).toHaveCount(0);
    await expect(card(page).getByRole('alert')).toHaveCount(4);
    expect(api.submissions).toEqual([]);

    await answerAll(page);
    await page.getByRole('button', { name: 'Review & Sign', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Review & Sign Inspection' })).toBeVisible();
    await sign(page);
    await page.getByRole('button', { name: 'Submit Inspection', exact: true }).click();

    await expect.poll(() => api.submissions.length).toBe(1);
    expect(api.submissions[0].vehicle_identification).toEqual(expectedAnswers);
  });

  test('a checkout with no questions sends no vehicle_identification key', async ({ page }) => {
    test.setTimeout(120_000);
    const api = await mobileInspectionFixture(page, engine2);
    await completeChecklist(page, api.checklist);
    await openMemberStep(page);
    await chooseShiftA(page);
    await page.getByRole('button', { name: 'Review & Sign', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Review & Sign Inspection' })).toBeVisible();
    await sign(page);
    await page.getByRole('button', { name: 'Submit Inspection', exact: true }).click();

    await expect.poll(() => api.submissions.length).toBe(1);
    expect(Object.keys(api.submissions[0])).not.toContain('vehicle_identification');
  });

  test('the offline queue stores the answers and replays the same payload', async ({ page, context }) => {
    test.setTimeout(120_000);
    const api = await mobileInspectionFixture(page, engine2, { vehicleIdentification: identification });
    await completeChecklist(page, api.checklist);
    await openMemberStep(page);
    await chooseShiftA(page);
    await answerAll(page);
    await page.getByRole('button', { name: 'Review & Sign', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Review & Sign Inspection' })).toBeVisible();
    await sign(page);

    await context.setOffline(true);
    const close = page.getByRole('button', { name: 'Close notification' });
    if (await close.isVisible()) await close.click();
    await page.getByRole('button', { name: 'Submit Inspection', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Inspection saved on this device' })).toBeVisible();
    const rows = await queued(page);
    expect(rows).toHaveLength(1);
    expect(rows[0].data.vehicle_identification).toEqual(expectedAnswers);
    expect(api.submissions).toHaveLength(0);

    await context.setOffline(false);
    await expect.poll(() => api.submissions.length).toBe(1);
    expect(api.submissions[0]).toEqual(rows[0].data);
    expect(api.submissions[0].vehicle_identification).toEqual(expectedAnswers);
    await expect.poll(async () => (await queued(page)).length).toBe(0);
  });
});

test.describe('one-time vehicle information on the Captain 5 page', () => {
  const workspace = (withBlock: boolean, canAnswer: boolean) => ({
    ...(withBlock ? {
      apparatus_id: 63,
      vehicle_identification: {
        required: true,
        note: NOTE,
        can_answer: canAnswer,
        kind: 'vehicle',
        questions: [{ field: 'plate', label: 'License plate', hint: null, input: 'text', max: 32, current_value: 'XD1904', confirm: true }],
        reasons,
        questions_version: VERSION,
      },
    } : { apparatus_id: 63 }),
    access: { view: true, act: true, configure: false, acts_as: 'captain5', authority: 'on_duty_captain5', authority_label: 'Captain 5 (on duty)' },
    on_duty: { duty_date: '2026-10-24', shift: 'B', source: 'bid_holder', resolved: true, name: 'Alex Rivera', rank: 'Capt.', acting_until: null, acting_request_id: null },
    duty_date: '2026-10-24',
    counts: { open: 0, needs_acknowledgement: 0, ordered: 0, ready_for_pickup: 0, overdue: 0, awaiting_verification: 0, significant: 0, rescue: 0, medical: 0 },
    rescue_dailies: [],
    issues: [],
    handoff: { duty_date: '2026-10-24', notes: [] },
    acting: { can_approve: false, mine: null, pending: [], choices: { '12h': null, '24h': null } },
    generated_at: '2026-10-24T12:00:00Z',
  });

  async function mockCaptain5(page: Page, options: { withBlock: boolean; failFirstSave?: boolean; canAnswer?: boolean }) {
    const saves: Array<Record<string, unknown>> = [];
    let saved = false;
    let failNext = options.failFirstSave === true;
    await page.route('**/images/**', (route) => route.fulfill({ status: 204 }));
    await page.route('**/ems/api/**', (route) => route.fulfill({ status: 403, json: { message: 'Forbidden' } }));
    await page.route('**/api/**', async (route) => {
      const path = new URL(route.request().url()).pathname;
      if (path === '/api/me/context') {
        return route.fulfill({ json: {
          version: 1,
          identity: { user_id: 401, has_personnel_profile: true },
          personnel: { employee_profile_id: 1_401, employee_number: 'E401', name: 'Alex Rivera', rank: 'Captain' },
          offline: { security_version: 1 }, session: { authenticated: true },
          authorization: { capabilities: { command_300_access: false } },
        } });
      }
      if (path === '/api/captain5/workspace') return route.fulfill({ json: workspace(options.withBlock && !saved, options.canAnswer !== false) });
      if (path === '/api/captain5/communications/summary') return route.fulfill({ json: { unread_count: 0, latest_activity_at: null } });
      if (path === '/api/public/apparatuses/63/vehicle-identification' && route.request().method() === 'POST') {
        saves.push(route.request().postDataJSON());
        if (failNext) {
          failNext = false;
          return route.fulfill({ status: 503, json: { message: 'The server had a problem. Try again in a moment.' } });
        }
        saved = true;
        return route.fulfill({ json: { vehicle_identification: { required: false, note: '', kind: 'vehicle', questions: [], reasons, questions_version: VERSION } } });
      }
      return route.fulfill({ status: 404, json: { message: `Unmocked route ${path}` } });
    });
    await page.goto('/daily/captain-5');
    await expect(page.getByTestId('captain5-page')).toBeVisible();

    return saves;
  }

  test('no card when the workspace carries no questions', async ({ page }) => {
    await mockCaptain5(page, { withBlock: false });
    await expect(page.getByTestId('captain5-vehicle-identification')).toHaveCount(0);
    await expect(page.getByText('One-time vehicle information')).toHaveCount(0);
  });

  test('a person who cannot answer sees the questions read-only with no save button', async ({ page }) => {
    const saves = await mockCaptain5(page, { withBlock: true, canAnswer: false });
    const readOnly = page.locator('[data-vehicle-identification][data-read-only]');
    await expect(readOnly).toBeVisible();
    await expect(readOnly).toContainText('License plate');
    await expect(readOnly).toContainText('The Hub has XD1904 on record.');
    await expect(readOnly).toContainText('Fleet or a Captain 5 officer can answer this.');
    await expect(readOnly.getByRole('textbox')).toHaveCount(0);
    await expect(readOnly.getByRole('radio')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Save vehicle information' })).toHaveCount(0);
    expect(saves).toHaveLength(0);
  });

  test('the card confirms the plate, keeps its answers after a failed save, and retries with the same submission id', async ({ page }) => {
    const saves = await mockCaptain5(page, { withBlock: true, failFirstSave: true });
    const panel = page.getByTestId('captain5-vehicle-identification');
    await expect(panel.getByRole('heading', { name: 'One-time vehicle information' })).toBeVisible();
    await expect(group(page, 'License plate')).toContainText('The Hub has XD1904 on record.');

    await panel.getByRole('button', { name: 'Save vehicle information' }).click();
    await expect(group(page, 'License plate').getByRole('alert')).toHaveText('Confirm it, or enter what is on the vehicle.');
    expect(saves).toHaveLength(0);

    await group(page, 'License plate').getByRole('radio', { name: 'No, I will enter what is on the vehicle' }).check();
    await group(page, 'License plate').getByRole('textbox').fill('xk4560');
    await panel.getByRole('button', { name: 'Save vehicle information' }).click();
    await expect(panel.getByRole('alert')).toContainText('The server had a problem.');
    await expect(group(page, 'License plate').getByRole('textbox')).toHaveValue('XK4560');
    expect(saves).toHaveLength(1);

    await panel.getByRole('button', { name: 'Save vehicle information' }).click();
    await expect(page.getByTestId('captain5-vehicle-identification-saved')).toBeVisible();
    await expect(panel).toHaveCount(0);
    expect(saves).toHaveLength(2);
    expect(saves[0]).toEqual(saves[1]);
    expect(saves[1]).toEqual({
      client_submission_id: expect.stringMatching(UUID_PATTERN),
      questions_version: VERSION,
      answers: [{ field: 'plate', value: 'XK4560' }],
    });
  });
});

async function completeChecklist(page: Page, checklist: { compartments: Array<{ id: string; items: Array<{ id?: string; name: string; inputType?: string }> }>; officerChecklist: Array<{ id: string; required: boolean; inputType: string }> }) {
  await navigation(page).getByRole('button', { name: 'Checklist details' }).click();
  const details = page.locator('details').filter({ has: page.locator('summary', { hasText: /^Additional details/ }) });
  if (await details.count() && !(await details.evaluate(element => (element as HTMLDetailsElement).open))) await details.locator('summary').click();
  for (const field of checklist.officerChecklist) {
    if (!field.required || field.id === 'vehicle_num' || field.id === 'inspection_date') continue;
    await page.locator(`#${field.id}`).fill(field.id === 'mileage' ? '42520' : field.inputType === 'number' || field.inputType === 'percentage' ? '75' : 'Normal');
  }
  await navigation(page).getByRole('button', { name: 'Apparatus', exact: true }).click();
  const profile = resolveBlueprint('engine2');
  for (const compartment of checklist.compartments) {
    const change = page.getByRole('button', { name: 'Change area', exact: true });
    if (await change.isVisible()) await change.click();
    const view = profile?.views.find(entry => entry.zones.some(zone => zone.compartmentId === compartment.id));
    if (view) {
      await page.getByLabel('Apparatus views', { exact: true }).getByRole('button', { name: view.label, exact: true }).click();
      await page.locator(`.blueprint-zone[data-area-id="${compartment.id}"]`).click();
    } else {
      const other = page.locator('.inspection-other-areas');
      if (!(await other.evaluate(element => (element as HTMLDetailsElement).open))) await other.locator('summary').click();
      await page.locator(`button[data-area-id="${compartment.id}"]`).click();
    }
    await page.getByRole('button', { name: 'Confirm all present' }).click();
    for (const [index, item] of compartment.items.entries()) {
      if (!item.inputType || item.inputType === 'checkbox') continue;
      await page.getByRole('button', { name: `Record ${item.name}`, exact: true }).click();
      await page.locator(`[id="value-${item.id ?? `${compartment.id}-item-${index + 1}`}"]`).fill('0007-RADIO');
      await page.getByRole('button', { name: `Record ${item.name}`, exact: true }).click();
    }
  }
}

async function sign(page: Page) {
  const canvas = page.locator('canvas');
  await canvas.scrollIntoViewIfNeeded();
  const box = await canvas.boundingBox();
  if (!box) throw new Error('Signature canvas is not visible');
  await page.mouse.move(box.x + 24, box.y + box.height / 2);
  await page.mouse.down();
  for (let step = 1; step <= 8; step += 1) await page.mouse.move(box.x + 24 + (box.width - 48) * step / 8, box.y + box.height / 2 + step);
  await page.mouse.up();
  await expect.poll(() => canvas.evaluate(element => {
    const surface = element as HTMLCanvasElement;
    const pixels = surface.getContext('2d')!.getImageData(0, 0, surface.width, surface.height).data;
    return pixels.some((value, index) => index % 4 === 3 && value > 0);
  })).toBe(true);
}

async function queued(page: Page) {
  return page.evaluate(async () => {
    const db = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('mbfd-daily-checkout');
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    const rows = await new Promise<Array<{ data: InspectionSubmission }>>((resolve, reject) => {
      const request = db.transaction('dailyCheckoutSubmissions', 'readonly').objectStore('dailyCheckoutSubmissions').getAll();
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    db.close();
    return rows;
  });
}
