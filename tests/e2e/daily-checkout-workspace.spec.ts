import { expect, test, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import type { InspectionSubmission, InspectionRevision, ChecklistData } from '../../resources/js/daily-checkout/src/types';

const sourceChecklist = JSON.parse(readFileSync('storage/checklists/engine2_checklist.json', 'utf8'));
const version = 'e'.repeat(64);
const inspectionDate = '2026-09-21';
const apparatus = { id: 102, name: 'Engine 2', designation: 'E2', type: 'engine', vehicle_number: 'TEST-102', slug: 'engine-2', status: 'In Service', current_engine_hours: 1250, current_miles: 42518, pm_health: { status: 'yellow', hours_since_pm: 270, miles_since_pm: 700, interval_hours: 300, overdue: false, last_pm_date: '2026-09-01' } };

async function fixture(page: Page, options: { checklist?: typeof sourceChecklist; vehicle?: typeof apparatus; findings?: ChecklistData['open_findings']; revisions?: InspectionRevision[] } = {}) {
  const vehicle = options.vehicle ?? apparatus;
  const checklist = options.checklist ?? sourceChecklist;
  const submissions: InspectionSubmission[] = [];
  const revisions: Array<{ reason: string; value?: number }> = [];
  let userId = 401;
  await page.route('**/images/mbfd_logo_new.png', route => route.fulfill({ path: 'public/images/mbfd_logo_new.png' }));
  await page.route('**/api/**', route => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/api/me/context') return route.fulfill({ json: { identity: { user_id: userId, has_personnel_profile: true }, personnel: { employee_profile_id: userId + 100, employee_number: `TEST-${userId + 100}`, name: userId === 401 ? 'Browser Member' : 'Other Browser Member', rank: 'Captain' }, offline: { security_version: 1 } } });
    if (path === '/api/public/apparatuses') return route.fulfill({ json: [vehicle] });
    if (path.endsWith('/checklist')) return route.fulfill({ json: { inspection_date: inspectionDate, checklist_version: version, checklist, open_findings: options.findings ?? [] } });
    if (path === `/api/public/apparatuses/${vehicle.id}/inspections` && route.request().method() === 'POST') {
      submissions.push(route.request().postDataJSON());
      return route.fulfill({ status: 201, json: { success: true, review_status: 'approved' } });
    }
    if (path === '/api/public/inspection-revisions') return route.fulfill({ json: { data: userId === 401 ? options.revisions ?? [] : [] } });
    if (path === '/api/public/inspection-exceptions/701/revision') {
      revisions.push(route.request().postDataJSON());
      return route.fulfill({ json: { success: true } });
    }
    if (path.includes('service')) return route.fulfill({ json: [] });
    return route.fulfill({ status: 404, json: { message: `Unmocked API: ${path}` } });
  });
  await page.goto(`/daily/vehicle-inspections/${vehicle.slug}`);
  await expect(page.getByRole('heading', { name: vehicle.name, exact: true })).toBeVisible();
  return { submissions, revisions, setUserId: (id: number) => { userId = id; } };
}

test('apparatus workspace fits the viewport and keeps actual equipment visible', async ({ page }, testInfo) => {
  await fixture(page);
  await expect(page.getByRole('button', { name: 'Review & Submit' })).toBeDisabled();
  await expect(page.getByRole('button', { name: "Pass Driver's Gear", exact: true })).toBeVisible();
  await expect(page.getByText('PM due soon', { exact: true })).toBeVisible();
  await expect(page.getByText('Browser Member · Shift not selected')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  const home = page.getByRole('link', { name: 'Return to MBFD Hub home page', exact: true });
  const support = page.getByRole('link', { name: 'Report an Issue', exact: true });
  const homeBox = await home.boundingBox();
  const supportBox = await support.boundingBox();
  expect(homeBox).not.toBeNull();
  expect(supportBox).not.toBeNull();
  if (!homeBox || !supportBox) throw new Error('Header controls must be visible');
  expect(homeBox.x >= supportBox.x + supportBox.width || supportBox.x >= homeBox.x + homeBox.width
    || homeBox.y >= supportBox.y + supportBox.height || supportBox.y >= homeBox.y + homeBox.height).toBe(true);
  for (const control of [home, support]) {
    const geometry = await control.evaluate(element => {
      const box = element.getBoundingClientRect();
      const hit = document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2);
      return { width: box.width, height: box.height, inViewport: box.left >= 0 && box.top >= 0 && box.right <= innerWidth && box.bottom <= innerHeight, receivesClick: hit !== null && element.contains(hit) };
    });
    expect(geometry.width).toBeGreaterThanOrEqual(44);
    expect(geometry.height).toBeGreaterThanOrEqual(44);
    expect(geometry.inViewport).toBe(true);
    expect(geometry.receivesClick).toBe(true);
  }
  await support.click();
  const supportDialog = page.getByRole('dialog', { name: 'Report an Issue', exact: true });
  await expect(supportDialog).toBeVisible();
  await supportDialog.getByRole('button', { name: 'Cancel', exact: true }).click();
  await expect(supportDialog).not.toBeVisible();
  await expect(support).toBeFocused();
  await page.screenshot({ path: testInfo.outputPath('apparatus-workspace.png') });
  const homeUrl = new URL('/', page.url()).href;
  await page.route(homeUrl, route => route.fulfill({ contentType: 'text/html', body: '<h1>Test Hub home destination</h1>' }));
  await home.click();
  await expect(page).toHaveURL(homeUrl);
  await expect(page.getByRole('heading', { name: 'Test Hub home destination' })).toBeVisible();
});

test('answers autosave before leaving a compartment and restore on reload', async ({ page }) => {
  await fixture(page);
  await page.getByRole('button', { name: "Pass Driver's Gear", exact: true }).click();
  await expect(page.getByRole('button', { name: "Pass Driver's Gear", exact: true })).toHaveAttribute('aria-pressed', 'true');
  await expect.poll(() => page.evaluate(version => {
    const data = JSON.parse(localStorage.getItem(`mbfd_autosave_inspection_engine-2_${version}_actor_401_1`) ?? '{}');
    return data.compartments?.find((c: { id: string }) => c.id === 'comp_l1')?.items[0]?.observed;
  }, version)).toBe(true);
  await page.reload();
  await expect(page.getByRole('button', { name: "Pass Driver's Gear", exact: true })).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByText(/Restored from autosave/)).toBeVisible();
});

test('Quick Checklist focuses the right item and batch confirmation preserves a reported issue', async ({ page }) => {
  await fixture(page);
  await page.getByRole('button', { name: /Checklist.*remaining/ }).click();
  const quick = page.getByRole('complementary', { name: 'Quick Checklist' });
  await quick.getByRole('button', { name: /Compartment R-2.*Sledgehammer/ }).click();
  await expect(quick).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Compartment R-2', exact: true })).toBeVisible();
  await expect(page.locator('[data-item-id][aria-expanded=true]')).toBeFocused();
  await page.getByRole('button', { name: 'Missing', exact: true }).click();
  await page.getByRole('button', { name: 'Mark all items in this compartment as present' }).click();
  await expect(page.getByRole('button', { name: 'Missing', exact: true })).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByRole('button', { name: 'Pass Sledgehammer', exact: true })).toHaveAttribute('aria-pressed', 'false');
  await page.getByRole('button', { name: /Checklist.*remaining/ }).click();
  await expect(quick.getByRole('button', { name: /Sledgehammer.*Missing/ })).toBeVisible();
});

async function fillRequiredPaperFields(page: Page, checklist: typeof sourceChecklist) {
  await page.getByRole('navigation', { name: 'Inspection workspace' }).getByRole('button', { name: 'Checklist details' }).click();
  for (const field of checklist.officerChecklist) {
    if (!field.required || field.id === 'vehicle_num' || field.id === 'inspection_date') continue;
    await page.locator(`#${field.id}`).fill(field.id === 'mileage' ? '42520' : field.inputType === 'number' || field.inputType === 'percentage' ? '75' : 'Normal');
  }
  await expect(page.locator('#vehicle_num')).toHaveValue('TEST-102');
  await expect(page.locator('#vehicle_num')).toHaveAttribute('readonly', '');
  await expect(page.locator('#inspection_date')).toHaveValue(inspectionDate);
  await expect(page.locator('#inspection_date')).toHaveAttribute('readonly', '');
}

async function inspectActualEquipment(page: Page, checklist: typeof sourceChecklist) {
  await page.getByRole('navigation', { name: 'Inspection workspace' }).getByRole('button', { name: 'Apparatus', exact: true }).click();
  for (const compartment of checklist.compartments) {
    await page.getByLabel('Compartment', { exact: true }).selectOption(compartment.id);
    await page.getByRole('button', { name: 'Mark all items in this compartment as present' }).click();
    for (const [index, item] of compartment.items.entries()) {
      if (!item.inputType || item.inputType === 'checkbox') continue;
      await page.getByRole('button', { name: `Pass ${item.name}`, exact: true }).click();
      await page.locator(`[id="value-${item.id ?? `${compartment.id}-item-${index + 1}`}"]`).fill('0007-RADIO');
      await page.getByRole('button', { name: `Pass ${item.name}`, exact: true }).click();
    }
  }
  await page.getByRole('button', { name: 'Review & Submit', exact: true }).click();
  await page.getByRole('button', { name: 'Continue to Compartment Inspection' }).click();
  await expect(page.getByRole('heading', { name: 'Review & Submit Inspection' })).toBeVisible();
}

async function sign(page: Page) {
  const shift = page.getByRole('combobox', { name: 'Shift', exact: true });
  if (await shift.inputValue() === '') await shift.selectOption('A');
  const canvas = page.locator('canvas');
  await canvas.scrollIntoViewIfNeeded();
  const box = await canvas.boundingBox();
  if (!box) throw new Error('Signature canvas is not visible');
  await page.mouse.move(box.x + 24, box.y + box.height / 2);
  await page.mouse.down();
  for (let step = 1; step <= 8; step += 1) await page.mouse.move(box.x + 24 + (box.width - 48) * step / 8, box.y + box.height / 2 + step);
  await page.mouse.up();
  const hasInk = () => canvas.evaluate(element => {
    const surface = element as HTMLCanvasElement;
    const pixels = surface.getContext('2d')!.getImageData(0, 0, surface.width, surface.height).data;
    return pixels.some((value, index) => index % 4 === 3 && value > 0);
  });
  await expect.poll(hasInk).toBe(true);
  const viewport = page.viewportSize();
  if (viewport) {
    await page.setViewportSize({ width: viewport.width + 16, height: viewport.height + 1 });
    await expect.poll(hasInk).toBe(true);
    await page.setViewportSize(viewport);
    await expect.poll(hasInk).toBe(true);
  }
}

async function queued(page: Page) {
  return page.evaluate(async () => {
    const db = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('mbfd-daily-checkout');
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    const rows = await new Promise<Array<{ ownerUserId: number; ownerSecurityVersion: number; data: InspectionSubmission }>>((resolve, reject) => {
      const request = db.transaction('dailyCheckoutSubmissions', 'readonly').objectStore('dailyCheckoutSubmissions').getAll();
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    db.close();
    return rows;
  });
}

test('paper text autosaves, survives refresh and offline editing, and queues the actual Engine 2 submission', async ({ page, context }, testInfo) => {
  test.setTimeout(90_000);
  const api = await fixture(page);
  const damageDescription = 'Scratch below rear step.\nPaint chipped.';
  const offlineDamageDescription = `${damageDescription}\nChecked while offline.`;
  await fillRequiredPaperFields(page, sourceChecklist);
  await page.getByLabel('SCBA #5', { exact: true }).fill('0005-SCBA');
  await page.getByLabel('New Damages - description', { exact: true }).fill(damageDescription);
  await page.getByLabel('New Damages - location on apparatus', { exact: true }).fill('Rear, below step');
  await expect.poll(() => page.evaluate(version => JSON.parse(localStorage.getItem(`mbfd_autosave_inspection_engine-2_${version}_actor_401_1`) ?? '{}').fieldValues?.find((f: { id: string }) => f.id === 'scba_5')?.value, version)).toBe('0005-SCBA');
  await page.reload();
  await page.getByRole('navigation', { name: 'Inspection workspace' }).getByRole('button', { name: 'Checklist details' }).click();
  await expect(page.getByLabel('SCBA #5', { exact: true })).toHaveValue('0005-SCBA');
  await expect(page.getByLabel('New Damages - description', { exact: true })).toHaveValue(damageDescription);
  await context.setOffline(true);
  await expect(page.getByText('Offline · On this device', { exact: true })).toBeVisible();
  await page.getByLabel('New Damages - description', { exact: true }).fill(offlineDamageDescription);
  await expect.poll(() => page.evaluate(version => JSON.parse(localStorage.getItem(`mbfd_autosave_inspection_engine-2_${version}_actor_401_1`) ?? '{}').fieldValues?.find((f: { id: string }) => f.id === 'new_damage_description')?.value, version)).toContain('Checked while offline.');
  await page.getByRole('navigation', { name: 'Inspection workspace' }).getByRole('button', { name: 'Meters', exact: true }).click();
  await expect(page.locator('#miles')).toHaveValue('42520');
  await page.locator('#engine_hours').fill('1251.5');
  await page.locator('#miles').fill('42521');
  await page.getByRole('navigation', { name: 'Inspection workspace' }).getByRole('button', { name: 'Checklist details' }).click();
  await expect(page.locator('#mileage')).toHaveValue('42521');
  await inspectActualEquipment(page, sourceChecklist);
  const review = page.getByRole('region', { name: 'Recorded readings and paper fields' });
  await expect(review).toContainText('0005-SCBA');
  await expect(review).toContainText('Checked while offline.');
  await expect(review).toContainText('1251.5');
  await expect(review).toContainText('42521');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await sign(page);
  await page.screenshot({ path: testInfo.outputPath('paper-fields-review.png'), fullPage: true });
  const closeNotification = page.getByRole('button', { name: 'Close notification' });
  if (await closeNotification.isVisible()) await closeNotification.click();
  await page.getByRole('button', { name: 'Submit Inspection', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Inspection Queued!' })).toBeVisible();
  const rows = await queued(page);
  expect(rows).toHaveLength(1);
  expect(rows[0]).toMatchObject({ ownerUserId: 401, ownerSecurityVersion: 1, data: { processing_version: 1, operator_name: 'Browser Member', rank: 'Captain', shift: 'A', employee_id: 501, unit_number: 'TEST-102', miles: 42521, engine_hours: 1251.5 } });
  expect(rows[0].data.field_values).toEqual(expect.arrayContaining([{ id: 'scba_5', value: '0005-SCBA' }, { id: 'new_damage_description', value: offlineDamageDescription }, { id: 'vehicle_num', value: 'TEST-102' }]));
  expect(rows[0].data.compartments.flatMap(c => c.items).every(item => item.observed === true)).toBe(true);
  expect(rows[0].data.officer_signature).toMatch(/^data:image\/png;base64,/);
  expect(api.submissions).toHaveLength(0);
  await context.setOffline(false);
  await expect.poll(() => api.submissions.length).toBe(1);
  expect(api.submissions[0]).toEqual(rows[0].data);
  await expect.poll(async () => (await queued(page)).length).toBe(0);
});

test('actual Ladder 1 typed identifiers are entered explicitly and included in review and mocked POST', async ({ page }, testInfo) => {
  test.setTimeout(90_000);
  const checklist = JSON.parse(readFileSync('storage/checklists/ladder1_checklist.json', 'utf8'));
  const api = await fixture(page, { checklist, vehicle: { ...apparatus, name: 'Ladder 1', designation: 'L1', type: 'ladder', slug: 'ladder-1' } });
  await fillRequiredPaperFields(page, checklist);
  await inspectActualEquipment(page, checklist);
  await expect(page.getByText('0007-RADIO', { exact: false }).first()).toBeVisible();
  await sign(page);
  await page.getByRole('button', { name: 'Clear Signature', exact: true }).click();
  await page.getByRole('button', { name: 'Submit Inspection', exact: true }).click();
  await expect(page.getByText('Signature is required before submitting.', { exact: true })).toBeVisible();
  expect(api.submissions).toHaveLength(0);
  await sign(page);
  await page.screenshot({ path: testInfo.outputPath('typed-identifiers-review.png'), fullPage: true });
  await page.getByRole('button', { name: 'Submit Inspection', exact: true }).click();
  await expect.poll(() => api.submissions.length).toBe(1);
  expect(api.submissions[0].processing_version).toBe(1);
  const items = api.submissions[0].compartments.flatMap(c => c.items);
  expect(items.find(item => item.name === 'SCBA #5')).toMatchObject({ value: '0007-RADIO', observed: true, status: 'Present' });
  expect(items.find(item => item.name === 'CO ID#')).toMatchObject({ id: 'comp_4-item-1', value: '0007-RADIO', observed: true, status: 'Present' });
});

test('historical finding without a unique current location remains visible after current equipment passes', async ({ page }, testInfo) => {
  await fixture(page, { findings: [{ id: 902, compartment: 'Old Combined Compartment', item: 'Legacy Rescue Bag', issue_type: 'missing', operational_impact: 'unclassified', last_observation: 'confirmed_again', last_observed_at: '2026-09-21T10:00:00Z', service_status: 'open' }] });
  const finding = page.locator('details').filter({ hasText: 'Other recorded findings (1)' });
  await finding.locator('summary').click();
  await expect(finding).toContainText('Legacy Rescue Bag');
  await expect(finding).toContainText('Verify the recorded location or duty before acting.');
  await page.getByRole('button', { name: 'Mark all items in this compartment as present' }).click();
  await expect(finding).toContainText('Legacy Rescue Bag');
  await page.getByLabel('Compartment', { exact: true }).selectOption('comp_r2');
  await expect(finding).toContainText('Old Combined Compartment');
  await page.screenshot({ path: testInfo.outputPath('historical-unlocated-finding.png'), fullPage: true });
});

test('known finding remains visible when reported corrected and keyboard actions work with reduced motion', async ({ page }, testInfo) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await fixture(page, { findings: [{ id: 901, compartment: 'Compartment L-1', item: "Driver's Gear", issue_type: 'damaged', operational_impact: 'unclassified', last_observation: 'confirmed_again', last_observed_at: '2026-09-21T10:00:00Z', service_status: 'open' }] });
  await expect(page.getByText('Known damaged · Awaiting classification · Service: open', { exact: true })).toBeVisible();
  const pass = page.getByRole('button', { name: "Pass Driver's Gear", exact: true });
  await pass.focus();
  await page.keyboard.press('Enter');
  await expect(pass).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByText(/Known damaged/)).toBeVisible();
  expect((await pass.boundingBox())?.height).toBeGreaterThanOrEqual(44);
  expect(await pass.evaluate(element => Number.parseFloat(getComputedStyle(element).transitionDuration))).toBeLessThanOrEqual(0.00001);
  const checklist = page.getByRole('button', { name: /Checklist.*remaining/ });
  await checklist.focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('complementary', { name: 'Quick Checklist' })).toBeVisible();
  await page.getByRole('button', { name: 'Close Quick Checklist' }).focus();
  await page.keyboard.press('Escape');
  await expect(checklist).toBeFocused();
  if (testInfo.project.use.hasTouch) await pass.tap();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.screenshot({ path: testInfo.outputPath('known-finding-keyboard.png') });
});

test('member clarification draft restores for its owner and is not shown to another mocked member', async ({ page }) => {
  const api = await fixture(page, { revisions: [{ id: 701, status: 'revision_requested', field: 'miles', reason: 'rollback', submitted_value: 42000, current_value: 42518, reviewer_note: 'Confirm the displayed odometer reading.', apparatus: { id: 102, name: 'Engine 2', vehicle_number: 'TEST-102', unit_id: 'E2' } }] });
  await page.goto('/daily/vehicle-inspections');
  await page.getByText(/Engine 2 · Vehicle TEST-102 · Clarification requested/).click();
  await page.getByLabel('Corrected reading').fill('42521');
  await page.getByRole('textbox', { name: 'Explanation', exact: true }).fill('Transposed the last digits; verified on the display.');
  await page.reload();
  await page.getByText(/Engine 2 · Vehicle TEST-102 · Clarification requested/).click();
  await expect(page.getByLabel('Corrected reading')).toHaveValue('42521');
  await expect(page.getByRole('textbox', { name: 'Explanation', exact: true })).toHaveValue('Transposed the last digits; verified on the display.');
  api.setUserId(402);
  await page.getByRole('button', { name: 'Send clarification' }).click();
  await expect(page.getByRole('alert')).toContainText('Your sign-in changed.');
  expect(api.revisions).toHaveLength(0);
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Vehicle Inspections' })).toBeVisible();
  await expect(page.getByRole('region', { name: 'Inspection clarifications' })).toHaveCount(0);
  api.setUserId(401);
  await page.reload();
  await page.getByText(/Engine 2 · Vehicle TEST-102 · Clarification requested/).click();
  await expect(page.getByRole('textbox', { name: 'Explanation', exact: true })).toHaveValue('Transposed the last digits; verified on the display.');
  await page.getByRole('button', { name: 'Send clarification' }).click();
  await expect(page.getByText('Your clarification is recorded and awaiting review.', { exact: true })).toBeVisible();
  expect(api.revisions).toEqual([{ reason: 'Transposed the last digits; verified on the display.', value: 42521 }]);
});
