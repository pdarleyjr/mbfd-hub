import { expect, test, type Page } from '@playwright/test';

const employeeId = process.env.OPERATIONAL_FORMS_E2E_EMPLOYEE_ID ?? 'E214';
const password = process.env.OPERATIONAL_FORMS_E2E_PASSWORD ?? 'OperationalForms!1';
const adminEmployeeId = process.env.OPERATIONAL_FORMS_E2E_ADMIN_EMPLOYEE_ID ?? 'E215';
const adminPassword = process.env.OPERATIONAL_FORMS_E2E_ADMIN_PASSWORD ?? 'OperationalFormsAdmin!1';

async function login(page: Page, id: string, secret: string) {
  await page.goto('/login');
  await page.getByLabel('Employee ID').fill(id);
  await page.getByLabel('Password').fill(secret);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).not.toHaveURL(/\/login$/);
}

async function sign(page: Page) {
  const pad = page.locator('canvas.pf-signature-canvas');
  await pad.scrollIntoViewIfNeeded();
  const box = await pad.boundingBox();
  if (!box) throw new Error('signature pad not visible');
  await page.mouse.move(box.x + 30, box.y + box.height / 2);
  await page.mouse.down();
  for (let step = 1; step <= 12; step += 1) await page.mouse.move(box.x + 30 + step * 18, box.y + box.height / 2 + (step % 2 ? -14 : 14));
  await page.mouse.up();
  await expect(page.getByText('Signed', { exact: true })).toBeVisible();
}

async function next(page: Page) {
  await page.getByRole('button', { name: /^Next:/ }).click();
}

async function noSidewaysScroll(page: Page) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(1);
}

test.describe.serial('Vehicle Accident Report: driver then supervisor', () => {
  test('the driver completes, previews, signs and submits their side', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'The serial workflow runs once.');
    await login(page, employeeId, password);
    await page.goto('/employee/forms');
    const card = page.locator('[data-library-link="accident-report"]');
    await expect(card).toBeVisible();
    // A firefighter does not see the supervisor-only card.
    await expect(page.locator('[data-library-link="supervisor-accident-report"]')).toHaveCount(0);
    await card.getByRole('link', { name: 'Start a report' }).click();
    await expect(page).toHaveURL(/form=accident-report/);

    await expect(page.getByRole('heading', { name: 'Vehicle Accident Report', level: 1 })).toBeVisible();
    await expect(page.getByText(/Both sides of this report must be completed/).first()).toBeVisible();
    await expect(page.getByText(/does not send the report to Risk Management/).first()).toBeVisible();
    // The member is the driver; no employee search for a non-supervisor.
    await expect(page.getByText('Employee involved (driver)')).toBeVisible();

    await page.getByRole('searchbox').fill('4512');
    await page.getByRole('button', { name: /Vehicle 4512/ }).click();
    await expect(page.locator('.pf-save')).toHaveText('Saved', { timeout: 15_000 });
    await expect(page).toHaveURL(/report=[0-9A-Za-z]{26}/);
    await expect(page.getByLabel(/City Vehicle #/)).toHaveValue('4512');
    await expect(page.getByLabel(/Employee's Name/)).toHaveValue('Operational Forms Test Member');

    await next(page);
    await page.getByLabel(/Date of Accident/).fill('2026-11-05');
    await page.getByLabel(/^Time/).fill('14:35');
    await page.getByLabel(/Location of Accident/).fill('1701 Meridian Ave, rear lot');
    await next(page);
    await page.getByLabel(/Was anyone injured\? Explain/).fill('No injuries.');
    await next(page);
    await page.getByRole('group', { name: /Was supervisor notified after accident/ }).getByText('Yes', { exact: true }).click();
    await page.getByRole('group', { name: /Were Police on the scene/ }).getByText('No', { exact: true }).click();
    await next(page);
    await page.getByLabel(/Driver's description of accident/).fill('Backing with a spotter, the rear step touched a bollard.');
    await expect(page.getByText(/of 8,000 characters/)).toBeVisible();
    await next(page);
    await page.getByRole('button', { name: /Captain Operational Forms Test Admin/ }).click();
    await expect(page.locator('.pf-save')).toHaveText('Saved', { timeout: 15_000 });
    await next(page);

    await expect(page.getByRole('heading', { name: 'Still needed before you can submit' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Preview official PDF' }).click();
    await expect(page.getByRole('dialog').getByText(/Page 1 of/)).toBeVisible({ timeout: 30_000 });
    await page.getByRole('button', { name: 'Close preview' }).click();

    await page.getByLabel(/I certify this report is accurate/).check();
    await sign(page);
    await page.getByRole('button', { name: 'Submit driver report' }).click();
    await expect(page.getByRole('heading', { name: 'Driver report submitted · awaiting supervisor' })).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('.pf-result-number')).toHaveText(/^AR-\d{6}-[A-Z0-9]{4}$/);
    await expect(page.getByText(/produced after the supervisor completes their part/)).toBeVisible();
  });

  test('the supervisor sees the report waiting and completes their part', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'The serial workflow runs once.');
    await login(page, adminEmployeeId, adminPassword);
    await page.goto('/employee/forms');
    await expect(page.getByRole('heading', { name: "Awaiting your supervisor's part (1)" })).toBeVisible();
    await expect(page.locator('[data-library-link="supervisor-accident-report"]')).toBeVisible();
    await page.getByRole('link', { name: 'Open reports' }).click();
    await page.locator('.pf-report-row.is-awaiting').first().click();

    await expect(page.getByRole('heading', { name: "Vehicle Accident Report: supervisor's part" })).toBeVisible();
    await expect(page.getByText('Backing with a spotter, the rear step touched a bollard.')).toBeVisible();
    await next(page);
    await expect(page.getByLabel(/Date of Report/)).not.toHaveValue('');
    await page.getByLabel(/Length of time driver with the City/).fill('12');
    await page.getByLabel(/Experience with this type of vehicle/).fill('6');
    await page.getByRole('group', { name: /Notice of Injury Report/ }).getByText('N/A', { exact: true }).click();
    await page.getByLabel(/Total # of hours on duty/).fill('14');
    await page.getByRole('group', { name: /Vehicle Condition Form/ }).getByText('Yes', { exact: true }).click();
    await page.getByRole('group', { name: /Reasonable Observation Form/ }).getByText('No', { exact: true }).click();
    await page.getByRole('group', { name: /CDL was Drug Test/ }).getByText('N/A', { exact: true }).click();
    await next(page);
    for (const factor of ['Excessive Hours', 'Speeding', 'Sickness', 'Physical Defect', 'Alcohol', 'Drugs']) {
      await page.getByRole('group', { name: new RegExp(`^${factor}`) }).getByText('No', { exact: true }).click();
    }
    await page.getByLabel(/Number of accidents in the past 18 months/).fill('0');
    await next(page);
    await page.getByRole('textbox', { name: /^Comments/ }).fill('Recommend a refresher on backing with a spotter.');
    await next(page);
    await expect(page.locator('.pf-save')).toHaveText('Saved', { timeout: 15_000 });
    await page.getByLabel(/supervisor's part of this report is accurate/).check();
    await sign(page);
    await page.getByRole('button', { name: "Submit supervisor's part" }).click();
    await expect(page.getByRole('heading', { name: "Supervisor's part submitted · report complete" })).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole('link', { name: 'Download PDF' })).toBeVisible({ timeout: 60_000 });
  });
});

test('Interoffice Memorandum to someone else: preview and download, nothing kept', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop' && testInfo.project.name !== 'phone', 'Desktop and one phone are sufficient.');
  await login(page, employeeId, password);
  await page.goto('/employee/forms?form=memorandum');
  await expect(page.getByRole('heading', { name: 'Interoffice Memorandum' })).toBeVisible();
  await page.getByText('Someone else (download / email)').click();
  await expect(page.getByLabel('From')).not.toHaveValue('');
  await page.getByLabel('To', { exact: true }).fill('Chief of Department');
  await page.getByLabel('Subject').fill('Hose testing equipment');
  await page.getByRole('textbox', { name: 'Memorandum' }).fill('This memorandum requests a second hose tester.\n\nStation 2 shares one with Station 1.');
  await noSidewaysScroll(page);
  await page.getByRole('button', { name: 'Preview PDF' }).click();
  await expect(page.getByRole('dialog').getByText(/Page 1 of/)).toBeVisible({ timeout: 30_000 });
  await page.getByRole('button', { name: 'Close preview' }).click();
  const download = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Download PDF' }).click();
  expect((await download).suggestedFilename()).toMatch(/^MBFD Memorandum - Hose testing equipment - \d{4}-\d{2}-\d{2}\.pdf$/);
  await expect(page.getByText(/Send it to Chief of Department/)).toBeVisible();
});

test('Support Services route fixes To and From', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'phone', 'Layout check on a phone.');
  await login(page, employeeId, password);
  await page.goto('/employee/forms?form=memorandum');
  await page.getByText('Support Services', { exact: true }).click();
  await expect(page.locator('.pf-fixed').first()).toHaveText('Support Services');
  await expect(page.getByLabel('From')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Send to Support Services' })).toBeVisible();
  await noSidewaysScroll(page);
});
