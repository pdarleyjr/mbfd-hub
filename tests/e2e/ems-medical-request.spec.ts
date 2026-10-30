import { expect, test, type Page } from '@playwright/test';

/*
 * Medical Inventory Request (Employee Portal → Forms): Airway Bag, ALS Med Bag/Box and the
 * ALS Transport Vehicle weekly inventory, on a phone, a tablet and a laptop.
 *   npx vite build
 *   npx playwright test -c playwright.operational-forms.config.ts ems-medical-request --project=phone --project=tablet-portrait --project=laptop
 */

const employeeId = process.env.OPERATIONAL_FORMS_E2E_EMPLOYEE_ID ?? 'E214';
const password = process.env.OPERATIONAL_FORMS_E2E_PASSWORD ?? 'OperationalForms!1';
const FORM_URL = '/employee/forms?form=medical-inventory-request';

const VEHICLE_SECTIONS = [
  'Drugs', 'I.V. / Needles', 'Airways / Oxygen', 'Intubation / Suction', 'Trauma Supplies', 'PPE / Decon', 'LifePack 15 Supplies', 'Miscellaneous',
];

async function openForm(page: Page) {
  await page.goto(FORM_URL);
  await page.getByLabel('Employee ID').fill(employeeId);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page.getByRole('heading', { name: 'Medical Inventory Request', level: 1 })).toBeVisible({ timeout: 30_000 });
}

async function noSidewaysScroll(page: Page) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow, 'the page must not scroll sideways').toBeLessThanOrEqual(1);
}

test('the three lists are one tap apart, the vehicle sheet reads in sheet order, and nothing scrolls sideways', async ({ page }) => {
  await openForm(page);
  await noSidewaysScroll(page);

  const chooser = page.getByRole('navigation', { name: 'Choose a list' });
  await expect(chooser.getByRole('button')).toHaveCount(3);
  await expect(chooser.getByRole('button', { name: /Airway Bag/ })).toHaveAttribute('aria-pressed', 'true');
  for (const button of await chooser.getByRole('button').all()) {
    const box = await button.boundingBox();
    expect(box?.height ?? 0, 'touch target height').toBeGreaterThanOrEqual(44);
  }

  await chooser.getByRole('button', { name: /ALS Transport Vehicle/ }).click();
  await expect(page.getByRole('heading', { name: /ALS Transport Vehicle/, level: 2 })).toBeVisible();
  const headings = page.locator('.ems-category-title');
  await expect(headings).toHaveText(VEHICLE_SECTIONS);
  await expect(page.locator('.ems-form')).toHaveCount(1);

  // The first section is open; items show the sheet's AMOUNT and "Fill if <" as par and refill level.
  const adenocard = page.locator('.ems-item', { hasText: 'Adenocard 12 mg' });
  await expect(adenocard).toContainText('Par 4, refill under 2');
  await noSidewaysScroll(page);

  await page.getByRole('button', { name: 'Open all sections' }).click();
  await expect(page.locator('.ems-category-body:not([hidden])')).toHaveCount(VEHICLE_SECTIONS.length);
  await expect(page.locator('.ems-form .ems-item')).toHaveCount(222);
  await noSidewaysScroll(page);
  await page.getByRole('button', { name: 'Close all sections' }).click();
  await expect(page.locator('.ems-category-body:not([hidden])')).toHaveCount(0);
});

test('quantity controls are touch sized with 16px text, search crosses all lists, and a request can be sent', async ({ page }, testInfo) => {
  test.skip(!['phone', 'tablet-portrait', 'laptop'].includes(testInfo.project.name), 'One send per device class is enough.');
  await openForm(page);

  const search = page.getByLabel('Search items in every list');
  expect(await search.evaluate((el) => parseFloat(getComputedStyle(el).fontSize))).toBeGreaterThanOrEqual(16);
  await search.fill('narcan');
  await expect(page.getByRole('status').filter({ hasText: /matching item/ })).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Choose a list' })).toHaveCount(0);
  await expect(page.locator('.ems-form')).toHaveCount(2); // the ALS Med Bag and the Transport Vehicle both list Narcan

  const vehicleNarcan = page.locator('#ems-form-als-transport-vehicle').locator('xpath=ancestor::section[1]').locator('.ems-item', { hasText: 'Narcan' });
  const quantity = vehicleNarcan.getByLabel('Narcan, number needed');
  expect(await quantity.evaluate((el) => parseFloat(getComputedStyle(el).fontSize))).toBeGreaterThanOrEqual(16);
  for (const stepper of await vehicleNarcan.locator('.ems-step').all()) {
    const box = await stepper.boundingBox();
    expect(box?.width ?? 0).toBeGreaterThanOrEqual(44);
    expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
  }
  await vehicleNarcan.getByRole('button', { name: 'One more Narcan' }).click();
  await vehicleNarcan.getByRole('button', { name: 'One more Narcan' }).click();
  await expect(quantity).toHaveValue('2');
  await expect(page.getByLabel('Request summary').getByRole('status')).toHaveText('1 item');

  await page.getByRole('button', { name: 'Clear search' }).click();
  await expect(page.getByRole('navigation', { name: 'Choose a list' }).getByRole('button', { name: /ALS Transport Vehicle/ })).toContainText('1 needed');

  await page.locator('#ems-target').selectOption({ label: 'Rescue 2' });
  await noSidewaysScroll(page);
  await page.getByRole('button', { name: 'Review and send' }).click();
  const review = page.getByRole('dialog', { name: 'Review your request' });
  await expect(review).toContainText('ALS Transport Vehicle');
  await expect(review).toContainText('Narcan');
  await review.getByRole('button', { name: /^Send/ }).click();
  await expect(page.getByRole('heading', { name: 'Request sent' })).toBeVisible({ timeout: 30_000 });
});
