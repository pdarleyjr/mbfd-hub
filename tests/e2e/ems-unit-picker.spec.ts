import { expect, test, type Page } from '@playwright/test';

/*
 * The unit picker on the Medical Inventory Request: one tap picks exactly that unit, and it is named in words
 * at every step. R2/R22 and R4/R44 differ by one character; none of them may ever turn into the other.
 *   npx vite build
 *   npx playwright test -c playwright.operational-forms.config.ts ems-unit-picker --project=phone --project=laptop
 */

const employeeId = process.env.OPERATIONAL_FORMS_E2E_EMPLOYEE_ID ?? 'E214';
const password = process.env.OPERATIONAL_FORMS_E2E_PASSWORD ?? 'OperationalForms!1';
const FORM_URL = '/employee/forms?form=medical-inventory-request';
const PAIRS = ['RESCUE R2', 'RESCUE R22', 'RESCUE R4', 'RESCUE R44'];

test.beforeEach(({}, testInfo) => {
  test.skip(!['laptop', 'phone'].includes(testInfo.project.name), 'Phone and laptop cover the touch and keyboard paths.');
});

async function signIn(page: Page) {
  await page.goto(FORM_URL);
  await page.getByLabel('Employee ID').fill(employeeId);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page.getByRole('heading', { name: 'Medical Inventory Request', level: 1 })).toBeVisible({ timeout: 30_000 });
}

const radio = (page: Page, name: string) => page.getByRole('radio', { name, exact: true });
const addGloves = (page: Page) => page.locator('.ems-item', { hasText: 'Gloves' }).first().getByRole('button', { name: /One more/ }).click();

test('the picker is a radio group of large buttons, Rescue units first, and arrow keys move one unit at a time', async ({ page }) => {
  await signIn(page);
  const group = page.getByRole('radiogroup', { name: 'Completing this form for:' });
  await expect(group).toBeVisible();
  const names = await group.getByRole('radio').allInnerTexts();
  expect(names.slice(0, 7).map((text) => text.trim())).toEqual(['RESCUE R1', 'RESCUE R11', 'RESCUE R2', 'RESCUE R22', 'RESCUE R3', 'RESCUE R4', 'RESCUE R44']);
  expect(names.at(-1)?.trim()).toBe('Detail / Special Event');
  for (const button of await group.getByRole('radio').all()) {
    const box = await button.boundingBox();
    expect(box?.height ?? 0, 'touch target height').toBeGreaterThanOrEqual(44);
    expect(box?.width ?? 0, 'touch target width').toBeGreaterThanOrEqual(44);
  }
  await expect(page.locator('#ems-target-confirm')).toContainText('No unit chosen yet');
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow, 'no sideways scroll').toBeLessThanOrEqual(1);

  // Keyboard: Tab reaches the group once; arrows choose the next unit, not a longer neighbour.
  await radio(page, 'RESCUE R2').focus();
  await page.keyboard.press('ArrowRight');
  await expect(radio(page, 'RESCUE R22')).toBeChecked();
  await expect(radio(page, 'RESCUE R2')).not.toBeChecked();
  await expect(page.locator('#ems-target-confirm')).toContainText('For: RESCUE R22');
  await page.keyboard.press('ArrowLeft');
  await expect(radio(page, 'RESCUE R2')).toBeChecked();
  await expect(page.locator('#ems-target-confirm')).toContainText('For: RESCUE R2');
  await expect(page.locator('#ems-target-confirm strong')).toHaveText('RESCUE R2');
  await expect(radio(page, 'RESCUE R2')).toBeFocused();

  // Other units live under a disclosure and are named in words too.
  await page.getByRole('button', { name: /^Other units/ }).click();
  await page.locator('#ems-other-select').selectOption({ label: 'ENGINE 1' });
  await expect(page.locator('#ems-target-confirm')).toContainText('For: ENGINE 1');
  for (const name of PAIRS) await expect(radio(page, name)).not.toBeChecked();
});

for (const unit of PAIRS) {
  test(`${unit}: one tap, the same unit at every step, and the stored unit is its own id`, async ({ page }) => {
    const sent: { apparatus_id?: number; label_seen?: string }[] = [];
    page.on('request', (request) => {
      if (request.method() === 'POST' && request.url().endsWith('/employee/forms/api/ems/requests')) sent.push(request.postDataJSON().target);
    });
    await signIn(page);
    await addGloves(page);

    const button = radio(page, unit);
    const choice = await button.getAttribute('data-choice');
    await button.click();
    await expect(button).toBeChecked();
    await expect(page.locator('#ems-target-confirm strong')).toHaveText(unit);
    for (const other of PAIRS.filter((name) => name !== unit)) await expect(radio(page, other)).not.toBeChecked();

    // The draft keeps it: reload, and the same unit is chosen.
    await page.waitForTimeout(700);
    await page.reload();
    await expect(radio(page, unit)).toBeChecked();
    await expect(page.locator('#ems-target-confirm strong')).toHaveText(unit);

    await page.getByRole('button', { name: 'Review and send' }).click();
    const review = page.getByRole('dialog', { name: 'Review your request' });
    await expect(review.locator('.ems-unit-banner strong')).toHaveText(unit);
    await review.getByRole('button', { name: /^Send/ }).click();

    await expect(page.getByRole('heading', { name: 'Request sent' })).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('.ems-unit-banner strong')).toHaveText(unit);
    expect(sent).toHaveLength(1);
    expect(`unit:${sent[0].apparatus_id}`, 'the id sent is the id of the button tapped').toBe(choice);
    expect(sent[0].label_seen).toBe(unit);

    await page.getByRole('button', { name: 'My medical requests' }).click();
    await expect(page.locator('.ems-request-unit').first()).toHaveText(unit);
  });
}

test('a request the server refuses because the unit does not match asks for the unit again and keeps everything else', async ({ page }) => {
  await signIn(page);
  await page.route('**/employee/forms/api/ems/requests', (route) => (route.request().method() === 'POST'
    ? route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ ok: false, error: { code: 'unit_mismatch', message: 'The unit you picked does not match what was sent. Please choose the unit again and send the request again.', fields: { 'target.apparatus_id': ['Choose the unit again.'] } } }) })
    : route.continue()));
  await addGloves(page);
  await page.locator('#ems-note').fill('keep this note');
  await radio(page, 'RESCUE R2').click();
  await page.getByRole('button', { name: 'Review and send' }).click();
  await page.getByRole('dialog', { name: 'Review your request' }).getByRole('button', { name: /^Send/ }).click();

  await expect(page.locator('#ems-target-error')).toContainText('choose the unit again');
  await expect(page.locator('#ems-target-confirm')).toContainText('No unit chosen yet');
  await expect(page.locator('#ems-note')).toHaveValue('keep this note');
  await expect(page.locator('.ems-item', { hasText: 'Gloves' }).first().getByLabel(/number needed/)).toHaveValue('1');
});

test('edit and resend keeps the unit that was picked', async ({ page }) => {
  await signIn(page);
  await addGloves(page);
  await radio(page, 'RESCUE R4').click();
  await page.route('**/employee/forms/api/ems/requests', (route) => (route.request().method() === 'POST' ? route.abort('internetdisconnected') : route.continue()));
  await page.getByRole('button', { name: 'Review and send' }).click();
  await page.getByRole('dialog', { name: 'Review your request' }).getByRole('button', { name: /^Send/ }).click();
  await expect(page.getByRole('heading', { name: 'Saved on this device' })).toBeVisible({ timeout: 30_000 });

  await page.unroute('**/employee/forms/api/ems/requests');
  await page.route('**/employee/forms/api/ems/requests', (route) => (route.request().method() === 'POST'
    ? route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ ok: false, error: { code: 'invalid_quantity', message: 'Refused for the test.', fields: { lines: ['x'] } } }) })
    : route.continue()));
  await page.getByRole('button', { name: 'Try sending now' }).click();
  await page.goto(`${FORM_URL}&view=mine`);
  await page.getByRole('button', { name: /Edit and resend|Edit again/ }).first().click();
  await expect(radio(page, 'RESCUE R4')).toBeChecked();
  await expect(radio(page, 'RESCUE R44')).not.toBeChecked();
  await expect(page.locator('#ems-target-confirm strong')).toHaveText('RESCUE R4');
});
