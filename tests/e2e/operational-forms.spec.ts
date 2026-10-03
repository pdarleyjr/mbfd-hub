import { expect, test } from '@playwright/test';

const employeeId = process.env.OPERATIONAL_FORMS_E2E_EMPLOYEE_ID ?? 'E214';
const password = process.env.OPERATIONAL_FORMS_E2E_PASSWORD ?? 'OperationalForms!1';
const adminEmployeeId = process.env.OPERATIONAL_FORMS_E2E_ADMIN_EMPLOYEE_ID ?? 'E215';
const adminPassword = process.env.OPERATIONAL_FORMS_E2E_ADMIN_PASSWORD ?? 'OperationalFormsAdmin!1';

async function login(page: import('@playwright/test').Page, id: string, secret: string) {
  await page.getByLabel('Employee ID').fill(id);
  await page.getByLabel('Password').fill(secret);
  await page.getByRole('button', { name: /sign in/i }).click();
}

test('admin uses the canonical home control to enter the Admin panel', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'One desktop actual-click acceptance is sufficient.');

  await page.goto('/');
  await expect(page).toHaveURL(/\/login$/);
  await login(page, adminEmployeeId, adminPassword);
  await expect(page).toHaveURL(/\/$/);

  const adminPanel = page.getByRole('link', { name: 'Admin Panel' });
  await expect(adminPanel).toHaveAttribute('href', /\/admin$/);
  await adminPanel.click();
  await expect(page).toHaveURL(/\/admin(?:\/)?$/, { timeout: 30_000 });
  await expect(page.locator('.fi-main')).toBeVisible();
});

test('admin can open Department Updates management', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'One desktop admin acceptance is sufficient.');

  await page.goto('/');
  await login(page, adminEmployeeId, adminPassword);
  await page.goto('/admin/department-updates');

  await expect(page).toHaveURL(/\/admin\/department-updates$/);
  await expect(page.getByRole('heading', { name: 'Department Updates' })).toBeVisible();
  await expect(page.getByText('Department Operations Briefing')).toBeVisible();
  await expect(page.getByRole('link', { name: /new department update/i })).toBeVisible();
});

test('entitled home exposes the exact unified Quick Access destinations', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'One desktop content acceptance is sufficient.');

  await page.goto('/');
  await login(page, adminEmployeeId, adminPassword);
  await expect(page.getByRole('heading', { name: 'Quick Access' })).toBeVisible();

  const cards = page.locator('[data-quick-access-card]');
  await expect(cards).toHaveCount(7);
  await expect(cards).toHaveText([
    /Station \/ Vehicles \/ Equipment/,
    /Employee Portal/,
    /ICS Forms/,
    /Workgroup Dashboard/,
    /Pump Panel/,
    /Videos/,
    /Media Control/,
  ]);
  await expect(page.getByRole('link', { name: /Station \/ Vehicles \/ Equipment/i })).toHaveAttribute('href', /\/daily\/stations$/);
  await expect(page.getByRole('link', { name: /ICS Forms/i })).toHaveAttribute('href', /\/employee\/forms$/);
  await expect(page.getByRole('link', { name: /Workgroup Dashboard/i })).toHaveAttribute('href', /\/workgroups$/);
  await expect(page.getByRole('link', { name: /Pump Panel/i })).toHaveAttribute('href', 'https://pdarleyjr.github.io/puc-sim-manual-ui/');
  await expect(page.getByRole('link', { name: /Videos/i })).toHaveAttribute('href', 'https://videos.mbfdhub.com');
  await expect(page.getByRole('link', { name: /Media Control/i })).toHaveAttribute('href', 'https://media.mbfdhub.com/api/auth/hub/start');
  await expect(page.getByText('MBFD Support Assistant')).toHaveCount(0);
  await expect(page.locator('[data-home-column="incidents"]')).toHaveCount(0);
});

test('authorized user enters Workgroups without a second login', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'One desktop actual-click acceptance is sufficient.');

  await page.goto('/');
  await login(page, adminEmployeeId, adminPassword);
  await page.getByRole('link', { name: /Workgroup Dashboard/i }).click();
  await expect(page).toHaveURL(/\/workgroups(?:\/dashboard)?\/?$/, { timeout: 30_000 });
  await expect(page).not.toHaveURL(/\/login/);
});

test('employee can enter the controlled forms workspace and start an ICS 214', async ({ page }, testInfo) => {
  await page.goto('/employee/forms');
  await expect(page).toHaveURL(/\/login/);

  await page.getByLabel('Employee ID').fill(employeeId);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();

  await expect(page).toHaveURL(/\/employee\/forms$/, { timeout: 30_000 });
  await expect(page.getByRole('heading', { name: 'Operational Forms' })).toBeVisible();
  await expect(page.locator('.fi-sidebar')).toBeHidden();
  await expect(page.locator('.fi-topbar')).toBeHidden();
  await expect(page.locator('.fi-sidebar-close-overlay')).toBeHidden();
  await expect(page.getByRole('link', { name: 'MBFD Hub home', exact: true })).toHaveAttribute('href', '/');
  await expect(page.getByText('ICS 214 — Activity Log')).toBeVisible();
  await expect(page.getByText(/FROC-LOG-001-FF/)).toBeVisible();
  await page.screenshot({ path: testInfo.outputPath(`operational-forms-library-${testInfo.project.name}.png`), fullPage: true });

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
  if (!testInfo.project.name.includes('phone')) {
    await expect(page.locator('.of-save-state.saved')).toBeVisible();
  }
  const commandTargets = await page.locator('.of-commandbar button').evaluateAll((buttons) => buttons.map((button) => {
    const rect = button.getBoundingClientRect();
    return { width: rect.width, height: rect.height };
  }));
  if (testInfo.project.name.includes('phone')) {
    expect(commandTargets.every(({ width, height }) => width >= 44 && height >= 44)).toBe(true);
    const viewportFit = await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
    expect(viewportFit).toBe(true);
  }
  await expect(page.getByRole('button', { name: 'Generate PDF' })).toBeVisible();
  await page.screenshot({ path: testInfo.outputPath(`operational-forms-ics-editor-${testInfo.project.name}.png`), fullPage: true });
});

test('employee dashboard header returns members to the main MBFD Hub', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'phone', 'One touch-device header acceptance is sufficient.');

  await page.goto('/employee/dashboard');
  await expect(page).toHaveURL(/\/login/);
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
  await home.click();
  await expect(page).toHaveURL(/\/$/);
  await expect(page.getByRole('heading', { name: 'Quick Access' })).toBeVisible();

  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto('/employee/dashboard');
  const panelBrandHome = page.getByRole('link', { name: 'MBFD Hub Home', exact: true });
  await expect(panelBrandHome).toHaveAttribute('href', '/');
  await panelBrandHome.click();
  await expect(page).toHaveURL(/\/$/);
  await expect(page.getByRole('heading', { name: 'Quick Access' })).toBeVisible();
  await page.screenshot({ path: testInfo.outputPath('employee-dashboard-home-phone.png'), fullPage: true });
});

test('home layout is ordered, aligned, touch-safe, and overflow-free from 320px through 4K', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'The responsive matrix is exercised in one authenticated browser session.');

  await page.goto('/');
  await login(page, adminEmployeeId, adminPassword);

  const viewports = [
    { name: 'phone-320', width: 320, height: 568 },
    { name: 'phone-390', width: 390, height: 844 },
    { name: 'tablet-portrait', width: 768, height: 1024 },
    { name: 'tablet-landscape', width: 1024, height: 768 },
    { name: 'desktop', width: 1440, height: 1000 },
    { name: 'wide-desktop', width: 2560, height: 1440 },
    { name: '4k', width: 3840, height: 2160 },
  ];

  for (const viewport of viewports) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.waitForTimeout(400);
    const primary = page.locator('[data-home-column="primary"]');
    const updates = page.locator('[data-home-section="department-updates"]');
    const quickAccess = page.locator('[data-home-section="quick-access"]');
    await expect(primary).toBeVisible();
    await expect(updates).toBeVisible();
    await expect(quickAccess).toBeVisible();
    await expect(page.locator('[data-home-column="incidents"]')).toHaveCount(0);
    await expect(updates.getByRole('link', { name: 'Department Operations Briefing' })).toBeVisible();

    const [layoutBox, primaryBox, updateBox, quickBox] = await Promise.all([
      page.locator('.home-layout').boundingBox(),
      primary.boundingBox(),
      updates.boundingBox(),
      quickAccess.boundingBox(),
    ]);
    expect(layoutBox).not.toBeNull();
    expect(primaryBox).not.toBeNull();
    expect(updateBox).not.toBeNull();
    expect(quickBox).not.toBeNull();
    expect(Math.abs(primaryBox!.width - layoutBox!.width)).toBeLessThanOrEqual(1);
    expect(updateBox!.y + updateBox!.height).toBeLessThanOrEqual(quickBox!.y + 1);

    const layout = await page.evaluate(() => ({
      clientWidth: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      targets: Array.from(document.querySelectorAll<HTMLElement>('[data-important-target]')).map((target) => {
        const rect = target.getBoundingClientRect();
        return { width: rect.width, height: rect.height, left: rect.left, right: rect.right };
      }),
    }));
    expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth + 1);
    expect(layout.targets.length).toBeGreaterThanOrEqual(7);
    expect(layout.targets.every(({ width, height, left, right }) => (
      width >= 44 && height >= 44 && left >= -1 && right <= layout.clientWidth + 1
    ))).toBe(true);

    await page.screenshot({ path: testInfo.outputPath(`home-${viewport.name}.png`), fullPage: true });
  }
});

test('employee can submit an arbitrary completed file from the Forms library', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'One desktop upload acceptance is sufficient.');

  await page.goto('/employee/forms');
  await page.getByLabel('Employee ID').fill(employeeId);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/employee\/forms$/, { timeout: 30_000 });

  const upload = page.locator('.of-upload-card');
  await expect(upload.getByRole('heading', { name: 'Send a completed file to Forms administration' })).toBeVisible();
  await upload.getByLabel('File name').fill('E2E command notes');
  await upload.locator('input[type=file]').setInputFiles({
    name: 'command-notes.txt',
    mimeType: 'text/plain',
    buffer: Buffer.from('Operational Forms E2E file submission'),
  });
  await upload.getByRole('button', { name: 'Submit completed file' }).click();
  await expect(page.getByText(/was submitted as a completed form/)).toBeVisible();

  const row = page.locator('.of-record-table tbody tr').filter({ hasText: 'E2E command notes' });
  await expect(row).toContainText('Submitted file');
  await expect(row).toContainText('Completed');
  await expect(row.getByRole('button', { name: 'Open document for E2E command notes' })).toBeVisible();
});

test('employee creates a F-ROC and imports R6 activity notes into the real editor', async ({ page }, testInfo) => {
  await page.goto('/employee/forms');
  await expect(page).toHaveURL(/\/login/);
  await page.getByLabel('Employee ID').fill(employeeId);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/employee\/forms$/, { timeout: 30_000 });
  await expect(page.locator('.fi-sidebar-close-overlay')).toBeHidden();
  await expect(page.locator('.of-record-table tbody tr').filter({ hasText: 'E2E Controlled ICS 214' })).toBeVisible();
  const initialRecordCount = await page.locator('.of-record-table tbody tr').count();

  await page.locator('.of-form-card').filter({ hasText: 'FROC-LOG-001-FF' }).getByRole('button', { name: 'Create form' }).click();
  await expect(page.getByRole('heading', { name: 'General information' })).toBeVisible();
  const assistant = page.locator('details.of-import');
  await expect(assistant).not.toHaveAttribute('open', '');
  await assistant.getByText('Optional: Import activity notes with AI').click();
  await expect(page.getByLabel('Paste activity notes')).toBeVisible();
  await page.getByLabel('Unit designation').fill('R6');
  await page.locator('input[type=file]').setInputFiles({
    name: 'Bronze Game Activity Log.txt',
    mimeType: 'text/plain',
    buffer: Buffer.from([
      '[7/18/26, 3:06:31 PM] Test Member: R6 completed ALS inventory and equipment check. R6 starting mileage: 113969. R6 en-route to staging area.',
      '[7/18/26, 3:36:31 PM] Test Member: R6 back in service.',
    ].join('\n')),
  });
  await page.getByRole('button', { name: 'Analyze and add to form' }).click();
  await expect(page.getByText('Activity notes added')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText(/Negative mileage requires/i)).toHaveCount(0);
  await expect(page.getByLabel('Event ID / event name')).toHaveValue('Bronze Game Activity Log');

  await page.getByRole('button', { name: 'Mileage', exact: true }).click();
  await expect(page.getByLabel('Start odo. row 1')).toHaveValue('113969');
  await expect(page.getByLabel('End odo. row 1')).toHaveValue('');
  await expect(page.getByText(/Pending — complete the odometer pair/)).toBeVisible();
  await page.getByLabel('End odo. row 1').fill('113979');

  await page.getByRole('button', { name: 'Labor', exact: true }).click();
  await expect(page.getByLabel('Category activity 1')).toHaveValue('B');
  await expect(page.getByLabel(/End · AI estimate/)).toBeVisible();
  await page.getByLabel(/End · AI estimate/).fill('15:40');
  await page.waitForTimeout(1_500);
  if (!testInfo.project.name.includes('phone')) await expect(page.locator('.of-save-state.saved')).toBeVisible();

  await page.reload();
  await page.locator('.of-record-table tbody tr').filter({ hasText: 'Bronze Game Activity Log' }).first().getByRole('button', { name: /Open Bronze Game Activity Log/ }).click();
  await page.getByRole('button', { name: 'Labor', exact: true }).click();
  await expect(page.getByLabel('Description of work performed')).toBeVisible();
  await expect(page.getByLabel(/End · AI estimate/)).toHaveValue('15:40');
  await page.getByRole('button', { name: 'General information', exact: true }).click();
  await page.locator('details.of-import').getByText('Optional: Import activity notes with AI').click();
  await page.getByLabel('Unit designation').fill('R6');
  await page.locator('input[type=file]').setInputFiles({
    name: 'Bronze Game Activity Log.txt',
    mimeType: 'text/plain',
    buffer: Buffer.from([
      '[7/18/26, 3:06:31 PM] Test Member: R6 completed ALS inventory and equipment check. R6 starting mileage: 113969. R6 en-route to staging area.',
      '[7/18/26, 3:36:31 PM] Test Member: R6 back in service.',
    ].join('\n')),
  });
  await page.getByRole('button', { name: 'Analyze and add to form' }).click();
  await expect(page.getByText('Activity notes added')).toBeVisible({ timeout: 30_000 });
  await page.getByRole('button', { name: 'Undo this import' }).click();
  await expect(page.getByText('Activity notes added')).toHaveCount(0);
  await page.getByRole('button', { name: 'Labor', exact: true }).click();
  await expect(page.locator('.of-labor-row')).toHaveCount(1);
  await page.getByRole('button', { name: 'Forms library' }).click();
  await expect(page.locator('.of-record-table tbody tr')).toHaveCount(initialRecordCount + 1);

  const touchTargets = await page.locator('button:visible').evaluateAll((buttons) => buttons.map((button) => {
    const rect = button.getBoundingClientRect();
    return { width: rect.width, height: rect.height };
  }));
  if (testInfo.project.name.includes('phone')) expect(touchTargets.every(({ width, height }) => width >= 44 && height >= 44)).toBe(true);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);
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
  await page.screenshot({ path: testInfo.outputPath('operational-forms-pdf-ready-desktop.png'), fullPage: true });
  await page.locator('.of-document-ready').getByRole('button', { name: 'View latest PDF', exact: true }).click();
  await expect(page.getByText('Page 1 of 1')).toBeVisible({ timeout: 15_000 });
  await expect.poll(() => page.locator('.of-preview-content canvas').evaluate((canvas: HTMLCanvasElement) => canvas.width)).toBeGreaterThan(500);
  await page.screenshot({ path: testInfo.outputPath('operational-forms-pdf-preview-desktop.png'), fullPage: true });
});

test('admin Forms resource exposes separate controlled-form tabs', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'Admin visual acceptance runs once on desktop.');

  await page.goto('/admin/operational-forms');
  await expect(page).toHaveURL(/\/login/);
  await page.getByLabel('Employee ID').fill(adminEmployeeId);
  await page.getByLabel('Password').fill(adminPassword);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/admin\/operational-forms$/, { timeout: 30_000 });
  await expect(page.getByText('ICS 214', { exact: true }).first()).toBeVisible();
  await expect(page.getByText('F-ROC Daily Activity Reports', { exact: true })).toBeVisible();
  await expect(page.getByText('Submitted files', { exact: true })).toBeVisible();
  await expect(page.getByText('E2E Controlled ICS 214')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByRole('button', { name: 'Move to Trash' }).first()).toBeVisible();
  await page.screenshot({ path: testInfo.outputPath('operational-forms-admin-tabs-desktop.png'), fullPage: true });
});

test('admin archives, finds, trashes and restores an actual member upload without losing its document', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'One full Admin evidence lifecycle acceptance is sufficient.');
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const title = `[QA TEST] Evidence lifecycle ${Date.now()}`;
  await page.goto('/employee/forms');
  await login(page, adminEmployeeId, adminPassword);
  await expect(page).toHaveURL(/\/employee\/forms$/, { timeout: 30_000 });
  const upload = page.locator('.of-upload-card');
  await upload.getByLabel('File name').fill(title);
  await upload.locator('input[type=file]').setInputFiles({
    name: 'qa-evidence.txt', mimeType: 'text/plain', buffer: Buffer.from('[QA TEST] Retained private operational evidence'),
  });
  const submitted = page.waitForResponse((response) => response.url().endsWith('/employee/forms/api/uploads') && response.request().method() === 'POST');
  await upload.getByRole('button', { name: 'Submit completed file' }).click();
  const response = await submitted;
  expect(response.status()).toBe(201);
  const { record } = await response.json();
  const documentId = record.documents[0].id;
  await expect(page.locator('.of-record-table tbody tr').filter({ hasText: title })).toBeVisible();

  const confirmAction = async (name: string, reason?: string) => {
    await page.getByRole('button', { name, exact: true }).first().click();
    const dialog = page.getByRole('dialog').filter({ has: page.getByRole('button', { name: /^(Confirm|Submit)$/ }) });
    await expect(dialog).toBeVisible();
    if (reason) await dialog.getByLabel('Reason (optional)').fill(reason);
    await dialog.getByRole('button', { name: /^(Confirm|Submit)$/ }).click();
    await expect(dialog).toBeHidden();
  };
  const findInVisibility = async (visibility: string) => {
    await page.goto('/admin/operational-forms');
    await page.getByRole('button', { name: /filter/i }).first().click();
    await page.getByLabel('Visibility').selectOption(visibility);
    await page.getByRole('heading', { name: 'Operational Forms', exact: true }).click();
    await expect(page.locator('tbody tr').filter({ hasText: title })).toBeVisible();
  };

  await page.goto(`/admin/operational-forms/${record.id}`);
  await expect(page.getByText('Document history')).toBeVisible();
  await confirmAction('Archive', '[QA TEST] Reviewed');
  await expect(page.getByRole('button', { name: 'Restore', exact: true })).toBeVisible();
  await findInVisibility('archived');
  await page.goto(`/admin/operational-forms/${record.id}`);
  await confirmAction('Restore');
  await confirmAction('Move to Trash');
  await expect(page).toHaveURL(/\/admin\/operational-forms$/);
  await findInVisibility('trash');
  await page.goto(`/admin/operational-forms/${record.id}`);
  await expect(page.getByText('Trash', { exact: true })).toBeVisible();
  const retained = await page.request.get(`/admin/operational-forms/documents/${documentId}/download`);
  expect(retained.status()).toBe(200);
  expect((await retained.body()).toString()).toBe('[QA TEST] Retained private operational evidence');
  await expect(page.getByText('Delete PDF', { exact: true })).toHaveCount(0);
  await confirmAction('Restore');
  await page.goto('/employee/forms');
  await expect(page.locator('.of-record-table tbody tr').filter({ hasText: title })).toBeVisible();
  await page.goto(`/admin/operational-forms/${record.id}`);
  await page.screenshot({ path: testInfo.outputPath('operational-forms-archive-trash-restore.png'), fullPage: true });
  await confirmAction('Move to Trash');
  testInfo.annotations.push({ type: 'qa-record', description: `${record.id}; retained document ${documentId}; safely trashed` });
  expect(errors).toEqual([]);
});
