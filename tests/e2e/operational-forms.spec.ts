import { expect, test } from '@playwright/test';

const employeeId = process.env.OPERATIONAL_FORMS_E2E_EMPLOYEE_ID ?? 'E214';
const password = process.env.OPERATIONAL_FORMS_E2E_PASSWORD ?? 'OperationalForms!1';
const adminEmail = process.env.OPERATIONAL_FORMS_E2E_ADMIN_EMAIL ?? 'forms-admin@example.test';
const adminPassword = process.env.OPERATIONAL_FORMS_E2E_ADMIN_PASSWORD ?? 'OperationalFormsAdmin!1';

test('updated home exposes the exact operational destinations', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'One desktop visual is sufficient for the home navigation acceptance.');
  await page.goto('/');
  await expect(page.getByRole('link', { name: /Stations \/ Vehicles/i })).toHaveAttribute('href', /\/daily\/stations$/);
  await expect(page.getByRole('link', { name: /Open operational forms/i })).toHaveAttribute('href', /\/employee\/forms$/);
  await page.screenshot({ path: 'tests/e2e/screenshots/operational-forms-home-desktop.png', fullPage: true });
});

test('employee can enter the controlled forms workspace and start an ICS 214', async ({ page }, testInfo) => {
  await page.goto('/employee/forms');
  await expect(page).toHaveURL(/\/employee\/login/);

  await page.getByLabel('Employee ID').fill(employeeId);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();

  await expect(page).toHaveURL(/\/employee\/forms$/, { timeout: 30_000 });
  await expect(page.getByRole('heading', { name: 'Operational Forms' })).toBeVisible();
  await expect(page.locator('.fi-sidebar')).toBeHidden();
  await expect(page.locator('.fi-topbar')).toBeHidden();
  await expect(page.locator('.fi-sidebar-close-overlay')).toBeHidden();
  await expect(page.getByRole('link', { name: 'MBFD Hub home' })).toHaveAttribute('href', '/');
  await expect(page.getByText('ICS 214 — Activity Log')).toBeVisible();
  await expect(page.getByText(/FROC-LOG-001-FF/)).toBeVisible();
  await page.screenshot({ path: `tests/e2e/screenshots/operational-forms-library-${testInfo.project.name}.png`, fullPage: true });

  await page.locator('.of-form-card').filter({ hasText: 'ICS 214' }).getByRole('button', { name: 'Create form' }).click();
  await expect(page.getByText('Incident and operational period')).toBeVisible();
  await page.getByLabel('Incident name').fill('Browser Acceptance Exercise');
  await page.getByLabel('Unit name / designators').fill('Rescue Group');
  await page.getByLabel('From date').fill('2026-07-18');
  await page.getByLabel('From time').fill('08:00');
  await expect(page.getByLabel('Incident name')).toHaveValue('Browser Acceptance Exercise');
  await page.waitForTimeout(1_500);
  await expect(page.getByLabel('Incident name')).toHaveValue('Browser Acceptance Exercise');
  await expect(page.getByLabel('Unit name / designators')).toHaveValue('Rescue Group');
  if (!testInfo.project.name.startsWith('phone')) {
    await expect(page.locator('.of-save-state.saved')).toBeVisible();
  }
  const commandTargets = await page.locator('.of-commandbar button').evaluateAll((buttons) => buttons.map((button) => {
    const rect = button.getBoundingClientRect();
    return { width: rect.width, height: rect.height };
  }));
  if (testInfo.project.name.startsWith('phone')) {
    expect(commandTargets.every(({ width, height }) => width >= 44 && height >= 44)).toBe(true);
    const viewportFit = await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
    expect(viewportFit).toBe(true);
  }
  await expect(page.getByRole('button', { name: 'Generate PDF' })).toBeVisible();
  await page.screenshot({ path: `tests/e2e/screenshots/operational-forms-ics-editor-${testInfo.project.name}.png`, fullPage: true });
});

test('employee dashboard header returns members to the main MBFD Hub', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'phone', 'One touch-device header acceptance is sufficient.');

  await page.goto('/employee/login');
  await page.getByLabel('Employee ID').fill(employeeId);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/employee\/dashboard$/, { timeout: 30_000 });

  const sidebarOverlay = page.locator('.fi-sidebar-close-overlay');
  if (await sidebarOverlay.isVisible()) {
    await page.evaluate(() => (window as any).Alpine?.store('sidebar')?.close());
    await expect(sidebarOverlay).toBeHidden();
  }

  const home = page.getByRole('link', { name: 'Return to MBFD Hub home' });
  await expect(home).toBeVisible();
  await expect(home).toHaveAttribute('href', '/');
  const target = await home.boundingBox();
  expect(target?.width).toBeGreaterThanOrEqual(44);
  expect(target?.height).toBeGreaterThanOrEqual(44);
  await page.screenshot({ path: 'tests/e2e/screenshots/employee-dashboard-home-phone.png', fullPage: true });
});

test('phone F-ROC repeating rows become touch-friendly field cards', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'phone-small', 'Focused phone-only repeating-row acceptance.');

  await page.goto('/employee/login');
  await page.getByLabel('Employee ID').fill(employeeId);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/employee\/dashboard/, { timeout: 30_000 });
  await page.goto('/employee/forms');
  await expect(page.locator('.fi-sidebar-close-overlay')).toBeHidden();

  await page.locator('.of-form-card').filter({ hasText: 'FROC-LOG-001-FF' }).getByRole('button', { name: 'Create form' }).click();
  await page.getByRole('button', { name: 'Labor', exact: true }).click();
  await page.getByRole('button', { name: 'Add row' }).click();
  await page.getByLabel('Work performed row 1').fill('Phone field-card acceptance entry');
  await expect(page.getByLabel('Work performed row 1')).toHaveValue('Phone field-card acceptance entry');
  await expect(page.locator('.of-edit-table td[data-label="Work performed"]')).toHaveCSS('display', 'grid');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);
  await page.screenshot({ path: 'tests/e2e/screenshots/operational-forms-froc-labor-phone-small.png', fullPage: true });
});

test('desktop employee previews a generated flattened ICS PDF', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'Controlled PDF visual acceptance runs once on desktop.');

  await page.goto('/employee/forms');
  await page.getByLabel('Employee ID').fill(employeeId);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/employee\/forms$/, { timeout: 30_000 });
  const seededRecord = page.locator('.of-record-table tbody tr').filter({ hasText: 'E2E Controlled ICS 214' });
  await seededRecord.getByRole('button', { name: /Open E2E Controlled ICS 214/ }).click();
  await expect(page.getByText('Latest controlled PDF')).toBeVisible();
  await page.screenshot({ path: 'tests/e2e/screenshots/operational-forms-pdf-ready-desktop.png', fullPage: true });
  await page.locator('.of-document-ready').getByRole('button', { name: 'View latest PDF', exact: true }).click();
  await expect(page.getByText('Page 1 of 1')).toBeVisible({ timeout: 15_000 });
  await expect.poll(() => page.locator('.of-preview-content canvas').evaluate((canvas: HTMLCanvasElement) => canvas.width)).toBeGreaterThan(500);
  await page.screenshot({ path: 'tests/e2e/screenshots/operational-forms-pdf-preview-desktop.png', fullPage: true });
});

test('admin Forms resource exposes separate controlled-form tabs', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'Admin visual acceptance runs once on desktop.');

  await page.goto('/admin/login');
  await page.getByLabel('Email address').fill(adminEmail);
  await page.getByLabel('Password').fill(adminPassword);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/admin\/?$/, { timeout: 30_000 });
  await page.goto('/admin/operational-forms');
  await expect(page.getByText('ICS 214', { exact: true }).first()).toBeVisible();
  await expect(page.getByText('F-ROC Daily Activity Reports', { exact: true })).toBeVisible();
  await expect(page.getByText('E2E Controlled ICS 214')).toBeVisible({ timeout: 30_000 });
  await page.screenshot({ path: 'tests/e2e/screenshots/operational-forms-admin-tabs-desktop.png', fullPage: true });
});
