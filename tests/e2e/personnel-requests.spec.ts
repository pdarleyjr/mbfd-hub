import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { writeFile } from 'node:fs/promises';

const qaPng = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');

function requiredPassword(name: string): string {
  const password = process.env[name];
  if (!password) {
    throw new Error(`${name} must be supplied by the personnel-request Playwright configuration.`);
  }

  return password;
}

async function loginEmployee(page: Page, employeeId: string, password: string): Promise<void> {
  await page.goto('/employee/dashboard');
  await page.getByLabel('Employee ID').fill(employeeId);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/employee\/dashboard$/, { timeout: 20_000 });
}

async function expectViewportFit(page: Page): Promise<void> {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);
}

async function screenshot(page: Page, testInfo: TestInfo, name: string, fullPage = true): Promise<void> {
  await page.screenshot({ path: testInfo.outputPath(`${name}.png`), fullPage });
}

test('homepage exposes the exact station and uniform-specific destinations', async ({ page }, testInfo) => {
  await page.goto('/login');
  await page.getByLabel('Employee ID').fill('99003');
  await page.getByLabel('Password').fill(requiredPassword('PERSONNEL_REQUESTS_E2E_ADMIN_PASSWORD'));
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/$/);
  const stations = page.getByRole('link', { name: 'Stations', exact: true });
  await expect(stations).toBeVisible();
  await expect(stations).toHaveAttribute('href', '/daily/stations');
  await expect(page.getByText('request approved uniform items')).toBeVisible();
  await expectViewportFit(page);
  await screenshot(page, testInfo, 'homepage');
});

test('employee uniform, request ledger, and expiration pages are responsive and touch ready', async ({ page }, testInfo) => {
  await loginEmployee(page, '99002', requiredPassword('PERSONNEL_REQUESTS_E2E_MEMBER_PASSWORD'));

  await page.goto('/employee/request-equipment');
  await expect(page.getByRole('heading', { name: 'Request Uniforms' })).toBeVisible();
  await expect(page.getByText('Structural firefighting PPE is handled by an authorized officer')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Submit Uniform Request' })).toBeVisible();
  await expect(page.locator('.employee-global-back a')).toBeVisible();
  await expectViewportFit(page);
  for (const target of await page.locator('.employee-global-back a, .pr-primary-action').evaluateAll((elements) => elements.map((element) => {
    const rect = element.getBoundingClientRect();
    return { width: rect.width, height: rect.height };
  }))) {
    expect(target.height).toBeGreaterThanOrEqual(48);
  }
  await screenshot(page, testInfo, 'uniform-request');

  await page.goto('/employee/my-equipment-page');
  await expect(page.locator('.ep-expiration-soon')).toContainText('Expiring Soon');
  await expect(page.getByText('E2E Structural Firefighting Helmet')).toBeVisible();
  await expectViewportFit(page);

  await page.goto('/employee/my-requests');
  await expect(page.getByText('Uniform and personnel equipment requests')).toBeVisible();
  await expectViewportFit(page);
  await screenshot(page, testInfo, 'request-ledger');
});

test('officer PPE page preserves station return target and supports pointer signature input', async ({ page }, testInfo) => {
  await loginEmployee(page, '99001', requiredPassword('PERSONNEL_REQUESTS_E2E_OFFICER_PASSWORD'));
  await page.goto('/employee/personnel-equipment-request?station_id=1&return_to=/daily/stations/1');

  await expect(page.getByRole('heading', { name: 'Personnel Equipment Request' })).toBeVisible();
  await expect(page.getByLabel('Authenticated officer Captain').getByText('Captain — Avery Officer — 99001')).toBeVisible();
  await expect(page.getByText('Every station searches the same complete department roster.')).toBeVisible();
  const back = page.locator('.employee-global-back a');
  await expect(back).toHaveAttribute('href', '/daily/stations/1');
  await expect(back).toContainText('Back to Station');

  const sidebarOverlay = page.locator('.fi-sidebar-close-overlay');
  if (await sidebarOverlay.isVisible()) {
    const viewport = page.viewportSize();
    expect(viewport).not.toBeNull();
    await page.touchscreen.tap(viewport!.width - 8, viewport!.height - 8);
    await expect(sidebarOverlay).toBeHidden();
  }

  const beneficiary = page.locator('[wire\\:key*="beneficiary_employee_id"] [role="combobox"]');
  await beneficiary.click();
  await page.keyboard.type('morgan');
  await page.getByRole('option', { name: 'Firefighter — Morgan Member — 99002' }).click();
  await page.getByRole('button', { name: 'Next' }).click();
  const equipmentSelect = page.getByLabel('Equipment*', { exact: true });
  await expect(equipmentSelect).toBeVisible({ timeout: 20_000 });
  await expect(page.getByText('A police report may be required')).toBeVisible();
  await equipmentSelect.selectOption('structural_firefighting_helmet');
  await page.getByLabel('Reason*', { exact: true }).selectOption('damaged');
  await page.getByRole('button', { name: 'Next' }).click();

  const review = page.locator('.ppe-review');
  await expect(review).toContainText('Firefighter — Morgan Member — 99002');
  await expect(review).toContainText('Structural Firefighting Helmet');
  await expect(review).toContainText('Damaged');
  const canvas = page.locator('canvas[aria-label="Officer signature pad"]');
  await expect(canvas).toBeVisible();
  const box = await canvas.boundingBox();
  expect(box).not.toBeNull();
  if (box) {
    await page.mouse.move(box.x + 16, box.y + box.height - 35);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width - 25, box.y + 30, { steps: 12 });
    await page.mouse.up();
  }
  await expectViewportFit(page);
  await screenshot(page, testInfo, 'officer-ppe');
});

test('logistics administrator sees the single personnel workspace and lifecycle summaries', async ({ page }, testInfo) => {
  await page.goto('/admin');
  await page.getByLabel('Employee ID').fill('99003');
  await page.getByLabel('Password').fill(requiredPassword('PERSONNEL_REQUESTS_E2E_ADMIN_PASSWORD'));
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL(/\/admin(?!\/login)/, { timeout: 20_000 });
  await page.goto('/admin/personnel-uniforms-equipment/overview', { waitUntil: 'domcontentloaded' });

  await expect(page.getByRole('heading', { name: 'Personnel Uniforms / Equipment' }).first()).toBeVisible();
  await expect(page.getByText('Uniform Requests')).toBeVisible();
  await expect(page.getByText('Equipment Requests')).toBeVisible();
  await expect(page.getByText('Expiring Soon')).toBeVisible();
  await expectViewportFit(page);
  await screenshot(page, testInfo, 'admin-overview', false);
});

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Employee ID').fill('99003');
  await page.getByLabel('Password').fill(requiredPassword('PERSONNEL_REQUESTS_E2E_ADMIN_PASSWORD'));
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/$/);
}

async function submitAdminModal(page: Page): Promise<void> {
  const modal = page.locator('.fi-modal-window:visible').last();
  await expect(modal).toBeVisible({ timeout: 30_000 });
  await modal.locator('button[type="submit"]').last().click();
  await expect(modal).toBeHidden({ timeout: 30_000 });
}

async function setArchiveVisibility(page: Page, value: 'active' | 'archived' | 'all'): Promise<void> {
  const table = page.locator('.fi-ta');
  await expect(table).toBeVisible();
  await expect(table).not.toHaveClass(/\banimate-pulse\b/);
  await page.getByRole('button', { name: /^Filters?(?: \d+)?$/i }).click();
  const visibility = page.getByLabel('Visibility', { exact: true });
  await expect(visibility).toBeVisible();
  if (await visibility.inputValue() !== value) {
    const [filtered] = await Promise.all([
      page.waitForResponse(response => {
        if (new URL(response.url()).pathname !== '/livewire/update' || response.request().method() !== 'POST') return false;
        const payload = response.request().postDataJSON() as { components?: { updates?: Record<string, unknown> }[] };
        return payload.components?.some(component => component.updates?.['tableFilters.archive_state.value'] === value) ?? false;
      }, { timeout: 30_000 }),
      visibility.selectOption(value),
    ]);
    expect(filtered.status()).toBe(200);
    expect(await filtered.finished()).toBeNull();
  }
  await page.keyboard.press('Escape');
}

async function archiveFindRestore(page: Page, adminUrl: string, indexUrl: string, reference: string): Promise<void> {
  await page.goto(adminUrl);
  await page.getByRole('button', { name: 'Archive', exact: true }).click();
  await page.getByLabel('Archive reason (optional)').fill('[QA TEST] Historical browser workflow evidence.');
  await submitAdminModal(page);
  await page.goto(indexUrl);
  await setArchiveVisibility(page, 'active');
  await expect(page.getByText(reference, { exact: true })).toHaveCount(0);
  await setArchiveVisibility(page, 'archived');
  await expect(page.getByText(reference, { exact: true }).first()).toBeVisible({ timeout: 30_000 });
  await setArchiveVisibility(page, 'all');
  await expect(page.getByText(reference, { exact: true }).first()).toBeVisible({ timeout: 30_000 });
  await page.goto(adminUrl);
  await page.getByRole('button', { name: 'Restore', exact: true }).click();
  await submitAdminModal(page);
  await expect(page.getByRole('button', { name: 'Archive', exact: true })).toBeVisible({ timeout: 30_000 });
  await page.goto(indexUrl);
  await setArchiveVisibility(page, 'active');
  await expect(page.getByText(reference, { exact: true }).first()).toBeVisible({ timeout: 30_000 });
  await page.goto(adminUrl);
  await page.getByRole('button', { name: 'Archive', exact: true }).click();
  await page.getByLabel('Archive reason (optional)').fill('[QA TEST] Finished; retain evidence.');
  await submitAdminModal(page);
}

async function completePersonnelLifecycle(admin: Page, member: Page, memberPath: string, equipment: boolean): Promise<void> {
  const publicId = memberPath.split('/').at(-1)!;
  const adminUrl = `/admin/personnel-uniforms-equipment/personnel-requests/${publicId}`;
  await member.goto(memberPath);
  const reference = await member.getByRole('heading', { level: 1 }).innerText();
  await admin.goto(adminUrl);
  await admin.getByRole('button', { name: 'Acknowledge', exact: true }).click();
  await admin.getByLabel('Employee-visible note').fill('[QA TEST] Request acknowledged.');
  await submitAdminModal(admin);
  await admin.getByRole('button', { name: 'Request Information', exact: true }).click();
  await admin.getByLabel('Additional Explanation', { exact: true }).check();
  if (equipment) await admin.getByLabel('Photo of Damage', { exact: true }).check();
  await admin.getByLabel('Instructions visible to employee').fill('[QA TEST] Describe the replacement need.');
  await admin.getByLabel('Internal note', { exact: true }).fill('[QA TEST] Private personnel processing note.');
  await submitAdminModal(admin);
  await member.reload();
  await expect(member.getByText('[QA TEST] Describe the replacement need.', { exact: true }).first()).toBeVisible();
  await expect(member.getByText('[QA TEST] Private personnel processing note.', { exact: true })).toHaveCount(0);
  if (equipment) {
    await member.getByLabel('Document type', { exact: true }).selectOption('damage_photo');
    await member.getByLabel('PDF, JPEG, or PNG (maximum 10 MB)', { exact: true }).setInputFiles({ name: 'qa-damage-photo.png', mimeType: 'image/png', buffer: qaPng });
    await member.getByRole('button', { name: 'Upload securely', exact: true }).click();
  }
  await member.getByLabel('Your response', { exact: true }).fill('[QA TEST] Replacement needed for workflow verification.');
  await member.getByRole('button', { name: /^Send (?:additional )?response$/i }).click();
  await admin.reload();
  await expect(admin.getByText('[QA TEST] Replacement needed for workflow verification.', { exact: true })).toBeVisible();
  for (const label of ['Mark Ordered', 'Mark Arrived', 'Ready for Pickup']) {
    await admin.getByRole('button', { name: label, exact: true }).click();
    await submitAdminModal(admin);
  }
  await admin.getByRole('button', { name: equipment ? 'Assign PPE' : 'Issue Uniform', exact: true }).click();
  await admin.getByLabel('Request item', { exact: false }).selectOption({ index: 1 });
  if (!equipment) {
    await admin.locator('[wire\\:key*="uniform_id"] [role="combobox"]').click();
    await admin.keyboard.type('T-Shirt');
    await admin.getByRole('option', { name: 'T-Shirt — L — 20 on hand', exact: true }).click();
  }
  await admin.getByLabel('Notes', { exact: true }).fill('[QA TEST] Issued only in disposable browser database.');
  await submitAdminModal(admin);
  await admin.getByRole('button', { name: 'Complete', exact: true }).click();
  await admin.getByLabel('Employee-visible note').fill('[QA TEST] Fulfillment completed.');
  await submitAdminModal(admin);
  await admin.getByRole('button', { name: 'Add Note', exact: true }).click();
  await admin.getByLabel('Employee-visible note').fill('[QA TEST] Pickup confirmed.');
  await submitAdminModal(admin);
  await member.reload();
  await expect(member.getByText('Completed', { exact: true }).first()).toBeVisible();
  await expect(member.getByText('[QA TEST] Pickup confirmed.', { exact: true })).toBeVisible();
  await archiveFindRestore(admin, adminUrl, '/admin/personnel-uniforms-equipment/personnel-requests', reference);
  if (equipment) {
    const attachment = admin.getByRole('link', { name: 'qa-damage-photo.png', exact: true });
    const retained = await admin.request.get((await attachment.getAttribute('href'))!);
    expect(retained.status()).toBe(200);
    expect(await retained.body()).toEqual(qaPng);
  }
}

test('real uniform submission follows the complete member and Admin lifecycle through reversible archive', async ({ page, browser, baseURL }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'One isolated complete uniform workflow.');
  test.setTimeout(240_000);
  await loginEmployee(page, '99002', requiredPassword('PERSONNEL_REQUESTS_E2E_MEMBER_PASSWORD'));
  await page.goto('/employee/request-equipment');
  const recentRequests = page.locator('.uo-recent-request');
  const previousRequestCount = await recentRequests.count();
  const shirt = page.locator('[data-product="t_shirt"]');
  await shirt.getByRole('spinbutton', { name: /quantity/i }).fill('1');
  await shirt.getByLabel('Size', { exact: false }).selectOption('L');
  await page.getByRole('button', { name: 'Submit Uniform Request', exact: true }).click();
  await expect(recentRequests).toHaveCount(previousRequestCount + 1);
  await expect(recentRequests.first()).toContainText('Pending');
  await expect(recentRequests.first()).toHaveAttribute('href', /\/employee\/my-requests\/[0-9A-Z]{26}$/);
  const memberPath = (await recentRequests.first().getAttribute('href'))!;
  await testInfo.attach('uniform-record', { body: JSON.stringify({ memberPath, publicId: memberPath.split('/').at(-1) }), contentType: 'application/json' });
  const context = await browser.newContext({ baseURL });
  try {
    const admin = await context.newPage();
    await loginAdmin(admin);
    await completePersonnelLifecycle(admin, page, memberPath, false);
    await screenshot(admin, testInfo, 'uniform-archived', false);
  } finally {
    await context.close();
  }
});

test('real officer PPE submission retains signature, beneficiary and complete Admin/member lifecycle', async ({ page, browser, baseURL }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'One isolated signed PPE workflow.');
  test.setTimeout(240_000);
  await loginEmployee(page, '99001', requiredPassword('PERSONNEL_REQUESTS_E2E_OFFICER_PASSWORD'));
  await page.goto('/employee/personnel-equipment-request?station_id=1');
  await page.locator('[wire\\:key*="beneficiary_employee_id"] [role="combobox"]').click();
  await page.keyboard.type('morgan');
  await page.getByRole('option', { name: 'Firefighter — Morgan Member — 99002', exact: true }).click();
  await page.getByRole('button', { name: 'Next', exact: true }).click();
  await page.getByLabel('Equipment*', { exact: true }).selectOption('other');
  await page.getByLabel('Reason*', { exact: true }).selectOption('damaged');
  await page.getByRole('textbox', { name: 'Describe other equipment*', exact: true }).fill('[QA TEST] Browser protective equipment.');
  await page.getByRole('button', { name: 'Next', exact: true }).click();
  const canvas = page.locator('canvas[aria-label="Officer signature pad"]');
  await expect(canvas).toBeVisible();
  await canvas.scrollIntoViewIfNeeded();
  const box = await canvas.boundingBox();
  expect(box).not.toBeNull();
  await page.mouse.move(box!.x + 15, box!.y + box!.height - 25);
  await page.mouse.down();
  await page.mouse.move(box!.x + box!.width - 20, box!.y + 25, { steps: 12 });
  await page.mouse.up();
  await expect(page.locator('.signature-hint')).toBeHidden();
  await page.getByRole('button', { name: 'Sign & Submit Request', exact: true }).click();
  await expect(page).toHaveURL(/\/employee\/dashboard$/, { timeout: 20_000 });
  const memberContext = await browser.newContext({ baseURL });
  const adminContext = await browser.newContext({ baseURL });
  try {
    const member = await memberContext.newPage();
    await loginEmployee(member, '99002', requiredPassword('PERSONNEL_REQUESTS_E2E_MEMBER_PASSWORD'));
    await member.goto('/employee/my-requests');
    const row = member.locator('.mr-row').filter({ hasText: '[QA TEST] Browser protective equipment.' });
    const memberPath = (await row.getAttribute('href'))!;
    await testInfo.attach('ppe-record', { body: JSON.stringify({ memberPath, publicId: memberPath.split('/').at(-1), beneficiary: '99002', officer: '99001' }), contentType: 'application/json' });
    const admin = await adminContext.newPage();
    await loginAdmin(admin);
    await completePersonnelLifecycle(admin, member, memberPath, true);
    await screenshot(admin, testInfo, 'ppe-archived', false);
  } finally {
    await memberContext.close();
    await adminContext.close();
  }
});

async function drawRequestSignature(page: Page, label: string): Promise<void> {
  const canvas = page.locator(`canvas[aria-label="${label}"]`);
  await expect(canvas).toBeVisible();
  await canvas.scrollIntoViewIfNeeded();
  const box = await canvas.boundingBox();
  expect(box).not.toBeNull();
  await page.mouse.move(box!.x + 15, box!.y + box!.height - 25);
  await page.mouse.down();
  await page.mouse.move(box!.x + box!.width - 20, box!.y + 25, { steps: 12 });
  await page.mouse.up();
}

test('real station repair and equipment requests preserve signed submissions, every operational status, public replies and archive discovery', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'One isolated run of each authoritative Station Request type.');
  test.setTimeout(900_000);
  await loginAdmin(page);
  for (const type of ['repair_service', 'equipment']) {
    await page.goto(`/daily/forms-hub/station-request?station_id=1&type=${type}`);
    if (type === 'equipment') await page.getByRole('button', { name: 'Equipment Request one or more station equipment items.', exact: true }).click();
    await page.getByRole('combobox', { name: 'Requesting employee *', exact: true }).selectOption({ label: 'Personnel E2E Admin — Captain' });
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await page.getByLabel('Short title *', { exact: true }).fill(`[QA TEST] Browser ${type} workflow`);
    await page.getByLabel('Description and operational impact *', { exact: true }).fill('[QA TEST] Isolated browser processing verification.');
    await page.getByLabel('Item name *', { exact: true }).fill('[QA TEST] Browser station item');
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    if (type === 'equipment') {
      await drawRequestSignature(page, 'Requesting member signature pad');
      await drawRequestSignature(page, 'Company officer signature pad');
      await page.getByRole('button', { name: 'Continue', exact: true }).click();
    }
    const submitted = page.waitForResponse(response => response.url().includes('/api/public/station_request') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Submit station request', exact: true }).click();
    const response = await submitted;
    expect(response.status()).toBe(201);
    const record = (await response.json()).data as { id: number; request_number: string };
    await testInfo.attach(`${type}-record`, { body: JSON.stringify(record), contentType: 'application/json' });
    const adminUrl = `/admin/station-requests/${record.id}`;
    for (const status of ['acknowledged', 'under_review', 'approved', 'scheduled', 'ordered', 'in_progress', 'awaiting_parts', 'awaiting_vendor', 'on_hold', 'completed']) {
      await page.goto(adminUrl);
      await page.getByRole('button', { name: 'Update Status', exact: true }).click();
      await page.getByLabel('Status', { exact: false }).first().selectOption(status);
      await page.getByLabel('Assigned To', { exact: true }).selectOption({ label: 'Personnel E2E Admin' });
      await page.getByLabel(/Assigned vendor/i).fill('[QA TEST] Browser vendor');
      await page.getByLabel('Public Update', { exact: true }).fill(`[QA TEST] ${type}: ${status}`);
      await page.getByLabel('Internal Note', { exact: true }).fill('[QA TEST] Private station workflow note.');
      await submitAdminModal(page);
      await page.goto('/daily/stations/1');
      await page.getByRole('button', { name: /^Requests/ }).click();
      if (status === 'completed') await page.getByRole('button', { name: 'All history', exact: true }).click();
      await expect(page.getByText(`[QA TEST] ${type}: ${status}`, { exact: false }).first()).toBeVisible();
      await expect(page.getByText('[QA TEST] Private station workflow note.', { exact: true })).toHaveCount(0);
    }
    await archiveFindRestore(page, adminUrl, '/admin/station-requests', record.request_number);
    await screenshot(page, testInfo, `${type}-archived`, false);
  }
});

test('real apparatus service request progresses through scheduling, parts, notes, completion and archive without vehicle checkout', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'One isolated Service Ticket workflow using the QA apparatus.');
  test.setTimeout(240_000);
  await loginAdmin(page);
  await page.goto('/employee/apparatus-service-request?station_id=1&apparatus_id=1');
  await page.getByLabel('Short issue summary', { exact: false }).fill('[QA TEST] Browser apparatus service');
  await page.getByLabel('What did you observe?', { exact: false }).fill('[QA TEST] Service workflow verification on the disposable QA unit.');
  await page.getByRole('button', { name: 'Submit Service Request', exact: true }).click();
  await expect(page).toHaveURL(/\/employee\/dashboard$/);
  await page.goto('/admin/apparatus-service-tickets');
  const row = page.locator('tr').filter({ hasText: '[QA TEST] Browser apparatus service' });
  await expect(row).toBeVisible();
  const reference = (await row.innerText()).match(/AST-\d{4}-\d+/)![0];
  const view = row.getByRole('link', { name: 'View', exact: true });
  const adminUrl = (await view.getAttribute('href'))!;
  await testInfo.attach('apparatus-record', { body: JSON.stringify({ id: Number(new URL(adminUrl, page.url()).pathname.split('/').at(-1)), reference, adminUrl, apparatusId: 1 }), contentType: 'application/json' });
  await view.click();
  for (const label of ['Acknowledge', 'Schedule', 'Start Work', 'Wait for Parts', 'Start Work', 'Complete']) {
    await page.getByRole('button', { name: label, exact: true }).click();
    if (label === 'Schedule') {
      await page.getByLabel('Scheduled For', { exact: false }).fill(new Date(Date.now() + 86_400_000).toISOString().slice(0, 16));
      await page.getByLabel('Service Type', { exact: false }).fill('[QA TEST] Electrical verification');
      await page.getByLabel('Service Location', { exact: false }).fill('[QA TEST] Disposable test shop');
    }
    await page.getByRole('textbox', { name: /^Public Update\*?$/ }).fill(`[QA TEST] Fleet: ${label}`);
    await page.getByLabel('Internal Note', { exact: true }).fill('[QA TEST] Private mechanic note.');
    if (label === 'Complete') await page.getByLabel('Resolution Summary', { exact: false }).fill('[QA TEST] Service workflow completed.');
    await submitAdminModal(page);
  }
  await page.getByRole('button', { name: 'Add Note', exact: true }).click();
  await page.getByLabel('Public Update', { exact: true }).fill('[QA TEST] Fleet history retained.');
  await submitAdminModal(page);
  await page.getByRole('button', { name: 'Change Unit Status', exact: true }).click();
  await page.getByLabel('Official Apparatus Status', { exact: false }).selectOption('Maintenance');
  await page.getByLabel('Station-facing Status Message', { exact: false }).fill('[QA TEST] Safe status action on QA apparatus.');
  await submitAdminModal(page);
  await page.getByRole('button', { name: 'Change Unit Status', exact: true }).click();
  await page.getByLabel('Official Apparatus Status', { exact: false }).selectOption('In Service');
  await page.getByLabel('Station-facing Status Message', { exact: false }).fill('[QA TEST] QA unit restored to its original status.');
  await submitAdminModal(page);
  await page.goto('/daily/stations/1');
  await page.getByRole('button', { name: /Service \/ Repair/ }).click();
  await page.getByRole('button', { name: 'All history', exact: true }).click();
  await expect(page.getByText('[QA TEST] Browser apparatus service', { exact: true })).toBeVisible();
  await expect(page.getByText('[QA TEST] QA unit restored to its original status.', { exact: false }).first()).toBeVisible();
  await expect(page.getByText('[QA TEST] Private mechanic note.', { exact: true })).toHaveCount(0);
  await archiveFindRestore(page, adminUrl, '/admin/apparatus-service-tickets', reference);
  await screenshot(page, testInfo, 'apparatus-service-archived', false);
});

test('real Hub Support submission, member reply, resolution, reopen, close and archive preserve evidence', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'One isolated real workflow run; responsive surfaces have separate coverage.');
  test.setTimeout(240_000);
  await loginAdmin(page);
  await page.goto('/support/issues/create');
  await page.getByLabel('What went wrong?').fill('[QA TEST] Browser support workflow verification.');
  await page.getByLabel('+ Add screenshot or file', { exact: true }).setInputFiles({ name: 'qa-support-screen.png', mimeType: 'image/png', buffer: qaPng });
  await page.getByRole('button', { name: 'Send', exact: true }).click();
  await expect(page).toHaveURL(/\/support\/issues\/\d+$/);
  const memberUrl = page.url();
  const recordId = new URL(memberUrl).pathname.split('/').at(-1)!;
  const attachmentUrl = (await page.getByRole('link', { name: 'qa-support-screen.png', exact: true }).getAttribute('href'))!;
  const reference = (await page.getByText(/^Reference: HUB-/).innerText()).replace('Reference: ', '');
  await testInfo.attach('support-record', { body: JSON.stringify({ recordId, memberUrl, reference }), contentType: 'application/json' });
  const adminUrl = `/admin/hub-support-tickets/${recordId}`;
  await page.goto(adminUrl);
  await page.getByRole('button', { name: 'Acknowledge', exact: true }).click();
  await submitAdminModal(page);
  await page.getByRole('button', { name: 'Start Work', exact: true }).click();
  await submitAdminModal(page);
  await page.getByRole('button', { name: 'Request Information', exact: true }).click();
  await page.getByLabel('Member-visible response').fill('[QA TEST] Which screen failed?');
  await page.getByLabel('Internal note').fill('[QA TEST] Private diagnostic note.');
  await submitAdminModal(page);
  await page.goto(memberUrl);
  await expect(page.getByText('[QA TEST] Which screen failed?', { exact: true })).toBeVisible();
  await expect(page.getByText('[QA TEST] Private diagnostic note.', { exact: true })).toHaveCount(0);
  await page.getByLabel('Reply', { exact: true }).fill('[QA TEST] The inventory screen.');
  await page.getByRole('button', { name: 'Send Reply', exact: true }).click();
  await page.goto(adminUrl);
  await expect(page.getByText('[QA TEST] The inventory screen.', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Resolve', exact: true }).click();
  await page.getByLabel('Resolution summary').fill('[QA TEST] Verified corrected behavior.');
  await submitAdminModal(page);
  await page.goto(memberUrl);
  await expect(page.getByText('[QA TEST] Verified corrected behavior.', { exact: true }).first()).toBeVisible();
  await page.getByLabel('Reply', { exact: true }).fill('[QA TEST] Reopen verification.');
  await page.getByRole('button', { name: 'Send Reply', exact: true }).click();
  await expect(page.getByText("We're working on it", { exact: true })).toBeVisible();
  await page.goto(adminUrl);
  await page.getByRole('button', { name: 'Resolve', exact: true }).click();
  await page.getByLabel('Resolution summary').fill('[QA TEST] Reopen verification resolved.');
  await submitAdminModal(page);
  await page.getByRole('button', { name: 'Close', exact: true }).click();
  await submitAdminModal(page);
  await archiveFindRestore(page, adminUrl, '/admin/hub-support-tickets', reference);
  await screenshot(page, testInfo, 'support-archived', false);
  await page.goto(memberUrl);
  await expect(page.getByText('Closed', { exact: true })).toBeVisible();
  await expect(page.getByText('[QA TEST] Reopen verification resolved.', { exact: true }).first()).toBeVisible();
  const retained = await page.request.get(attachmentUrl);
  expect(retained.status()).toBe(200);
  expect(await retained.body()).toEqual(qaPng);
});

test('real station inventory snapshot and supply request appear in their station Admin context', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'One isolated real inventory and supply workflow run.');
  test.setTimeout(240_000);
  await loginAdmin(page);
  await page.goto('/daily/forms-hub/station-inventory');
  await page.getByRole('button', { name: 'Shift B', exact: true }).click();
  await page.getByLabel('Station', { exact: false }).selectOption({ label: 'Station 1' });
  await page.getByRole('button', { name: 'Open station inventory', exact: true }).click();
  await expect(page.getByText('[QA TEST] Browser inventory gloves', { exact: true })).toBeVisible();
  await page.getByLabel('Notes (optional)', { exact: true }).fill('[QA TEST] Real browser inventory snapshot.');
  const submitted = page.waitForResponse(response => response.url().includes('/api/v2/station-inventory/1/submissions') && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Submit inventory record', exact: true }).click();
  const response = await submitted;
  expect(response.status()).toBe(201);
  const recordId = (await response.json()).submission_id as number;
  await testInfo.attach('inventory-record', { body: JSON.stringify({ recordId, stationId: 1 }), contentType: 'application/json' });
  await expect(page.getByRole('button', { name: 'Inventory record submitted', exact: true })).toBeDisabled();
  await page.getByRole('button', { name: 'Special Supply Requests', exact: true }).click();
  await page.getByRole('button', { name: '+ Add New Request', exact: true }).click();
  await page.getByPlaceholder('Describe what supplies you need...').fill('[QA TEST] Real browser station supply request.');
  const supplySubmitted = page.waitForResponse(result => result.url().includes('/api/v2/station-inventory/1/supply-requests') && result.request().method() === 'POST');
  await page.getByRole('button', { name: 'Submit', exact: true }).click();
  const supplyResponse = await supplySubmitted;
  expect(supplyResponse.status()).toBe(200);
  const supplyId = (await supplyResponse.json()).request.id as number;
  await expect(page.getByText('[QA TEST] Real browser station supply request.', { exact: true })).toBeVisible();
  await testInfo.attach('inventory-records', { body: JSON.stringify({ recordId, supplyId, stationId: 1 }), contentType: 'application/json' });
  await page.goto('/admin/stations/1');
  const [inventoryTabResponse] = await Promise.all([
    page.waitForResponse(result => {
      if (new URL(result.url()).pathname !== '/livewire/update' || result.request().method() !== 'POST') return false;
      const payload = result.request().postDataJSON() as { components?: { snapshot: string; updates?: Record<string, unknown> }[] };
      return payload.components?.some(component => component.updates?.activeRelationManager !== undefined
        && (JSON.parse(component.snapshot) as { memo: { path: string } }).memo.path === 'admin/stations/1') ?? false;
    }, { timeout: 60_000 }),
    page.getByRole('tab', { name: 'Inventory Submissions', exact: true }).click(),
  ]);
  expect(inventoryTabResponse.status()).toBe(200);
  expect(await inventoryTabResponse.finished()).toBeNull();
  const inventoryRow = page.getByRole('row')
    .filter({ has: page.getByRole('cell', { name: String(recordId), exact: true }) })
    .filter({ hasText: 'Personnel E2E Admin' });
  await expect(inventoryRow).toBeVisible({ timeout: 30_000 });
  await inventoryRow.getByRole('button', { name: 'View', exact: true }).click();
  await expect(page.getByText('[QA TEST] Real browser inventory snapshot.', { exact: true })).toBeVisible();
  await expect(page.getByText(/QA-BROWSER-GLOVES/)).toBeVisible();
  await page.locator('.fi-modal-window:visible').last().getByTitle('Close', { exact: true }).click();
  const pdf = inventoryRow.getByRole('link', { name: 'Download PDF', exact: true });
  const download = await page.request.get((await pdf.getAttribute('href'))!);
  expect(download.status()).toBe(200);
  expect(download.headers()['content-type']).toContain('application/pdf');
  const pdfBody = await download.body();
  expect(pdfBody.subarray(0, 5).toString()).toBe('%PDF-');
  const pdfPath = testInfo.outputPath('inventory-submission.pdf');
  await writeFile(pdfPath, pdfBody);
  await testInfo.attach('inventory-submission.pdf', { path: pdfPath, contentType: 'application/pdf' });
  await page.getByRole('tab', { name: 'Supply Requests', exact: true }).click();
  const supplyRow = page.locator('tr').filter({ hasText: '[QA TEST] Real browser station supply request.' });
  await expect(supplyRow).toBeVisible();
  await supplyRow.getByRole('button', { name: 'Edit', exact: true }).click();
  const supplyModal = page.locator('.fi-modal-window:visible').last();
  await expect(supplyModal).toBeVisible({ timeout: 30_000 });
  await supplyModal.getByRole('combobox', { name: /^Status\s*\*$/ }).selectOption('ordered');
  await page.getByLabel('Member response', { exact: true }).fill('[QA TEST] Supplies ordered for browser verification.');
  await page.getByLabel('Internal Notes', { exact: true }).fill('[QA TEST] Private supplier detail.');
  await submitAdminModal(page);
  await screenshot(page, testInfo, 'station-supply-admin', false);
  await page.goto('/daily/forms-hub/station-inventory');
  await page.getByRole('button', { name: 'Shift B', exact: true }).click();
  await page.getByLabel('Station', { exact: false }).selectOption({ label: 'Station 1' });
  await page.getByRole('button', { name: 'Open station inventory', exact: true }).click();
  await page.getByRole('button', { name: 'Special Supply Requests', exact: true }).click();
  await expect(page.getByText('[QA TEST] Supplies ordered for browser verification.', { exact: false })).toBeVisible();
  await expect(page.getByText('[QA TEST] Private supplier detail.', { exact: true })).toHaveCount(0);
});
