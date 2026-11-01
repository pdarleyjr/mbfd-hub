import { expect, test, type Page } from '@playwright/test';
import { resolveBlueprint } from '../../resources/js/daily-checkout/src/data/apparatusBlueprints';
import type { InspectionSubmission } from '../../resources/js/daily-checkout/src/types';
import { mobileApparatus, mobileInspectionFixture } from './support/daily-checkout-mobile';

const fleetVehicles = [
  { vehicle_number: 'TEST-102', designation: 'E2', role: 'frontline' },
  { vehicle_number: 'TEST-205', designation: 'E5', role: 'frontline' },
  { vehicle_number: 'RES-901', designation: 'R9', role: 'reserve' },
];
const engine2 = mobileApparatus[0];

const navigation = (page: Page) => page.getByRole('navigation', { name: 'Inspection workspace' });
const vehicleSelect = (page: Page) => page.getByRole('combobox', { name: 'Physical vehicle', exact: true });
const swapPrompt = (page: Page) => page.getByRole('group', { name: 'Was a truck swap completed?' });

async function openMemberStep(page: Page) {
  await navigation(page).getByRole('button', { name: 'Member / Vehicle Info', exact: true }).click();
  await expect(vehicleSelect(page)).toBeVisible();
}

test.describe('confirm the physical vehicle', () => {
  test('the dropdown is preset to the assigned vehicle and lists the whole fleet plus Not listed', async ({ page }) => {
    await mobileInspectionFixture(page, engine2, { fleetVehicles });
    await openMemberStep(page);

    await expect(vehicleSelect(page)).toHaveValue('TEST-102');
    await expect(vehicleSelect(page)).toBeEnabled();
    await expect(vehicleSelect(page)).not.toHaveAttribute('readonly', '');
    await expect(vehicleSelect(page)).toHaveAttribute('required', '');
    expect(await vehicleSelect(page).locator('option').allTextContents()).toEqual([
      'Choose the vehicle ID',
      'TEST-102 · E2 (Frontline)',
      'TEST-205 · E5 (Frontline)',
      'RES-901 · R9 (Reserve)',
      'Not listed',
    ]);
    await expect(swapPrompt(page)).toHaveCount(0);
    await expect(page.getByLabel('Member', { exact: true })).toHaveAttribute('readonly', '');
  });

  test('without a cached fleet list the assigned vehicle and Not listed still work', async ({ page }) => {
    await mobileInspectionFixture(page, engine2);
    await openMemberStep(page);

    await expect(vehicleSelect(page)).toHaveValue('TEST-102');
    expect(await vehicleSelect(page).locator('option').allTextContents()).toEqual([
      'Choose the vehicle ID',
      'TEST-102 · E2 (Frontline)',
      'Not listed',
    ]);
  });

  test('another vehicle asks whether a swap was completed, and I picked wrong goes back', async ({ page }) => {
    await mobileInspectionFixture(page, engine2, { fleetVehicles });
    await openMemberStep(page);

    await vehicleSelect(page).selectOption('TEST-205');
    await expect(swapPrompt(page)).toBeVisible();
    await expect(swapPrompt(page).getByRole('radio', { name: 'Yes, swap completed' })).toBeVisible();
    await expect(swapPrompt(page).getByRole('radio', { name: 'Unknown, not sure why' })).toBeVisible();
    await expect(swapPrompt(page)).toContainText('You can still submit this checkout');
    await swapPrompt(page).getByLabel('Note (optional)').fill('Engine 5 was in the shop.');

    await page.getByRole('button', { name: 'I picked wrong', exact: true }).click();
    await expect(vehicleSelect(page)).toHaveValue('TEST-102');
    await expect(swapPrompt(page)).toHaveCount(0);

    await vehicleSelect(page).selectOption('TEST-205');
    await expect(swapPrompt(page).getByLabel('Note (optional)')).toHaveValue('');
  });

  test('a different vehicle offers Record this vehicle change, with the report to Fleet still below it', async ({ page }) => {
    await mobileInspectionFixture(page, engine2, { fleetVehicles });
    await openMemberStep(page);

    const record = page.getByRole('link', { name: 'Record this vehicle change' });
    await expect(record).toHaveCount(0);
    await vehicleSelect(page).selectOption('TEST-205');
    await expect(record).toBeVisible();
    await expect(record).toHaveClass(/hub-btn--primary/);
    const href = new URL((await record.getAttribute('href')) ?? '', 'http://localhost');
    expect(href.pathname).toMatch(/^\/daily\/changeouts\/new\/\d+$/);
    expect(href.searchParams.get('truck')).toBe('TEST-205');
    expect(href.searchParams.get('return_to')).toBe(`/vehicle-inspections/${engine2.slug}`);

    const recordBox = await record.boundingBox();
    const reportBox = await swapPrompt(page).boundingBox();
    expect(recordBox && reportBox && recordBox.y < reportBox.y).toBe(true);
    await expect(swapPrompt(page).getByRole('radio', { name: 'Yes, swap completed' })).toBeVisible();

    await page.getByRole('button', { name: 'I picked wrong', exact: true }).click();
    await expect(record).toHaveCount(0);
  });

  test('Not listed accepts a typed vehicle ID and rejects characters a vehicle ID cannot have', async ({ page }) => {
    await mobileInspectionFixture(page, engine2, { fleetVehicles });
    await openMemberStep(page);

    await vehicleSelect(page).selectOption('__not_listed__');
    const typed = page.getByLabel('Vehicle ID on the truck');
    await typed.fill('<b>9');
    expect(await typed.evaluate((input: HTMLInputElement) => input.validity.patternMismatch)).toBe(true);
    await typed.fill('X-77');
    expect(await typed.evaluate((input: HTMLInputElement) => input.validity.valid)).toBe(true);
    await expect(swapPrompt(page)).toBeVisible();
  });

  test('a different vehicle cannot continue until the swap question is answered', async ({ page }) => {
    const api = await mobileInspectionFixture(page, engine2, { fleetVehicles });
    await openMemberStep(page);
    await vehicleSelect(page).selectOption('TEST-205');
    await page.getByRole('combobox', { name: 'Shift', exact: true }).selectOption('A');
    await page.locator('.inspection-review-button').click();

    expect(await swapPrompt(page).getByRole('radio', { name: 'Yes, swap completed' }).evaluate((radio: HTMLInputElement) => radio.validity.valueMissing)).toBe(true);
    await expect(vehicleSelect(page)).toBeVisible();
    expect(api.submissions).toEqual([]);
  });

  test('confirming the assigned vehicle is one tap and is sent with the checkout', async ({ page }) => {
    test.setTimeout(120_000);
    const api = await mobileInspectionFixture(page, engine2, { fleetVehicles });
    await completeChecklist(page, api.checklist);
    await openMemberStep(page);
    await page.getByRole('combobox', { name: 'Shift', exact: true }).selectOption('A');
    await page.getByRole('button', { name: 'Review & Sign', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Review & Sign Inspection' })).toBeVisible();
    await expect(page.locator('[data-vehicle-swap-summary]')).toHaveCount(0);
    await sign(page);
    await page.getByRole('button', { name: 'Submit Inspection', exact: true }).click();

    await expect.poll(() => api.submissions.length).toBe(1);
    expect(api.submissions[0].physical_vehicle).toEqual({ vehicle_number: 'TEST-102' });
    expect(api.submissions[0].field_values).toContainEqual({ id: 'vehicle_num', value: 'TEST-102' });
  });

  test('a different vehicle is still submittable and the report is queued offline then replayed', async ({ page, context }) => {
    test.setTimeout(120_000);
    const api = await mobileInspectionFixture(page, engine2, { fleetVehicles });
    await completeChecklist(page, api.checklist);
    await openMemberStep(page);
    await vehicleSelect(page).selectOption('TEST-205');
    await swapPrompt(page).getByRole('radio', { name: 'Yes, swap completed' }).check();
    await swapPrompt(page).getByLabel('Note (optional)').fill('Engine 5 was in the shop.');
    await page.getByRole('combobox', { name: 'Shift', exact: true }).selectOption('A');
    await page.getByRole('button', { name: 'Review & Sign', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Review & Sign Inspection' })).toBeVisible();
    await expect(page.locator('[data-vehicle-swap-summary]')).toContainText('Fleet reviews this possible swap');
    await expect(page.getByText('TEST-205', { exact: true }).first()).toBeVisible();
    await sign(page);

    await context.setOffline(true);
    const close = page.getByRole('button', { name: 'Close notification' });
    if (await close.isVisible()) await close.click();
    await page.getByRole('button', { name: 'Submit Inspection', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Inspection saved on this device' })).toBeVisible();
    const rows = await queued(page);
    expect(rows).toHaveLength(1);
    const queuedReport = rows[0].data.physical_vehicle;
    expect(queuedReport).toEqual({ vehicle_number: 'TEST-205', swap_answer: 'swap_completed', note: 'Engine 5 was in the shop.' });
    expect(rows[0].data.field_values).toContainEqual({ id: 'vehicle_num', value: 'TEST-205' });
    expect(api.submissions).toHaveLength(0);

    await context.setOffline(false);
    await expect.poll(() => api.submissions.length).toBe(1);
    expect(api.submissions[0]).toEqual(rows[0].data);
    await expect.poll(async () => (await queued(page)).length).toBe(0);
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
