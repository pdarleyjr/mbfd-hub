import { expect, test, type Page } from '@playwright/test';

/*
 * ALS Transport Inventory Form (Employee Portal → Forms): Airway Bag, ALS Med Bag/Box, the
 * ALS Transport Vehicle weekly inventory and Handtevy, on a phone, a tablet and a laptop.
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
  await expect(page.getByRole('heading', { name: 'ALS Transport Inventory Form', level: 1 })).toBeVisible({ timeout: 30_000 });
}

async function noSidewaysScroll(page: Page) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow, 'the page must not scroll sideways').toBeLessThanOrEqual(1);
}

test('the lists are one tap apart, the vehicle sheet reads in sheet order, and nothing scrolls sideways', async ({ page }) => {
  await openForm(page);
  await noSidewaysScroll(page);

  const chooser = page.getByRole('navigation', { name: 'Choose a list' });
  expect(await chooser.getByRole('button').count(), 'Airway Bag, ALS Med Bag/Box, ALS Transport Vehicle and (once imported) Handtevy').toBeGreaterThanOrEqual(3);
  await expect(chooser.getByRole('button', { name: /ALS Med Bag/ })).toBeVisible();
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
  await expect(adenocard.locator('.ems-amount')).toHaveText('Carry 4');
  await expect(adenocard.locator('.ems-refill')).toHaveText('Refill under 2');
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

  const search = page.getByLabel('Search supplies');
  expect(await search.evaluate((el) => parseFloat(getComputedStyle(el).fontSize))).toBeGreaterThanOrEqual(16);
  await search.fill('narcan');
  await expect(page.getByRole('status').filter({ hasText: /matching item/ })).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Choose a list' })).toHaveCount(0);
  // One flat list of results, each saying where it lives: the ALS Med Bag and the Transport Vehicle both list Narcan.
  const results = page.locator('.ems-results .ems-item');
  await expect(results.filter({ hasText: 'ALS Med Bag/Box ›' }).first()).toBeVisible();

  const vehicleNarcan = results.filter({ hasText: 'ALS Transport Vehicle ›' }).filter({ hasText: 'Narcan' }).first();
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
  await expect(page.getByRole('navigation', { name: 'Choose a list' }).getByRole('button', { name: /ALS Transport Vehicle/ })).toContainText('1 entered');

  await page.getByRole('radio', { name: 'RESCUE R2', exact: true }).click();
  await noSidewaysScroll(page);
  await page.getByRole('button', { name: 'Review and send' }).click();
  const review = page.getByRole('dialog', { name: 'Review your request' });
  await expect(review).toContainText('ALS Transport Vehicle');
  await expect(review).toContainText('Narcan');
  await review.getByRole('button', { name: /^Send/ }).click();
  await expect(page.getByRole('heading', { name: 'Request sent' })).toBeVisible({ timeout: 30_000 });
});
