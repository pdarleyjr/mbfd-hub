import { expect, test } from '@playwright/test';
import { readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { resolveBlueprint } from '../../resources/js/daily-checkout/src/data/apparatusBlueprints';
import { mobileApparatus, mobileInspectionFixture, measureInspection } from './support/daily-checkout-mobile';
import { readDrafts } from './support/daily-checkout-drafts';

const requiredProjects = [
  'daily-responsive-phone-390', 'daily-responsive-phone-430', 'daily-responsive-tablet-768',
  'daily-responsive-tablet-landscape-1024', 'daily-responsive-wide-1440',
];

for (const model of mobileApparatus) {
  test(`mobile workflow measurements ${model.designation}`, async ({ page }, testInfo) => {
    test.skip(!requiredProjects.includes(testInfo.project.name), 'Measurements use the five requested viewport sizes.');
    await mobileInspectionFixture(page, model);
    const profile = resolveBlueprint(model.checklistType)!;
    const initial = await measureInspection(page);
    for (const fullPage of [false, true]) {
      const path = testInfo.outputPath(`initial-${fullPage ? 'full' : 'viewport'}.png`);
      await page.screenshot({ path, fullPage });
      await testInfo.attach(`initial ${fullPage ? 'full page' : 'viewport'}`, { path, contentType: 'image/png' });
    }
    const zone = profile.views[0].zones.at(-1)!;
    await page.locator(`.blueprint-touch-zones button[data-area-id="${zone.compartmentId}"]`).click();
    await expect(page.locator('.equipment-pass').first()).toBeAttached();
    const selected = await measureInspection(page);
    for (const fullPage of [false, true]) {
      const path = testInfo.outputPath(`selected-${fullPage ? 'full' : 'viewport'}.png`);
      if (fullPage) await page.evaluate(() => scrollTo(0, 0));
      await page.screenshot({ path, fullPage });
      await testInfo.attach(`selected ${fullPage ? 'full page' : 'viewport'}`, { path, contentType: 'image/png' });
    }
    const path = testInfo.outputPath('workflow-measurements.json');
    writeFileSync(path, JSON.stringify({ apparatus: model.designation, checklist: model.checklistType, selectedAreaId: zone.compartmentId, evidence: 'Local production bundle; actual source checklist and artwork; mocked authenticated identity/API; not live server authentication.', initial, selected }, null, 2));
    await testInfo.attach('workflow measurements', { path, contentType: 'application/json' });
  });
}

test('E2 mobile selection preserves artwork and geometry, exposes equipment, and restores its picker', async ({ page }, testInfo) => {
  test.skip(!requiredProjects.includes(testInfo.project.name), 'Uses the five requested viewport sizes.');
  await mobileInspectionFixture(page, mobileApparatus[0]);
  const profile = resolveBlueprint('engine2')!;
  const narrow = (page.viewportSize()?.width ?? 0) < 1024;
  for (const view of profile.views.filter(entry => ['left', 'right'].includes(entry.id))) {
    const changeArea = page.getByRole('button', { name: 'Change area', exact: true });
    if (await changeArea.isVisible()) await changeArea.click();
    await page.getByLabel('Apparatus views', { exact: true }).getByRole('button', { name: view.label, exact: true }).click();
    const imageUrl = await page.locator('.apparatus-blueprint image').getAttribute('href');
    expect(imageUrl).toBeTruthy();
    const response = await page.request.get(imageUrl!);
    expect(response.ok()).toBe(true);
    expect(createHash('sha256').update(await response.body()).digest('hex')).toBe(createHash('sha256').update(readFileSync(new URL(view.image!))).digest('hex'));
    for (const zone of view.zones) {
      const rect = page.locator(`.blueprint-zone[data-area-id="${zone.compartmentId}"] > rect`);
      for (const [attribute, value] of Object.entries({ x: view.mirror ? (view.canvas?.width ?? 600) - zone.x - zone.width : zone.x, y: zone.y, width: zone.width, height: zone.height })) {
        await expect(rect).toHaveAttribute(attribute, String(value));
      }
    }
    const selected = view.zones.at(-1)!;
    const button = page.locator(`.blueprint-touch-zones button[data-area-id="${selected.compartmentId}"]`);
    await button.click();
    await expect(button).toHaveAttribute('aria-pressed', 'true');
    await expect(button).toContainText('Selected');
    await expect(page.locator(`.blueprint-zone[data-area-id="${selected.compartmentId}"]`)).toHaveAttribute('aria-pressed', 'true');
    const firstPass = page.locator('.equipment-pass').first();
    await expect(firstPass).toBeVisible();
    if (narrow) {
      await expect(page.getByRole('complementary', { name: 'Apparatus navigation' })).toBeHidden();
      await expect(changeArea).toBeVisible();
      const metrics = await measureInspection(page);
      expect(metrics.firstAction!.top).toBeGreaterThanOrEqual(0);
      expect(metrics.firstAction!.bottom).toBeLessThan(metrics.viewport.height - metrics.bottomBar!.height);
      expect(metrics.bottomBar!.height).toBeLessThanOrEqual(76);
      await changeArea.click();
      await expect(page.getByRole('complementary', { name: 'Apparatus navigation' })).toBeVisible();
      await expect(button).toHaveAttribute('aria-pressed', 'true');
      await page.locator(`.blueprint-zone[data-area-id="${selected.compartmentId}"]`).click();
    } else {
      const picker = await page.getByRole('complementary', { name: 'Apparatus navigation' }).boundingBox();
      const equipment = await page.getByRole('region', { name: 'Compartment Inspection' }).boundingBox();
      expect(picker!.x + picker!.width).toBeLessThanOrEqual(equipment!.x);
      expect(Math.abs(picker!.y - equipment!.y)).toBeLessThanOrEqual(20);
    }
    expect((await firstPass.boundingBox())!.height).toBeGreaterThanOrEqual(44);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  }
});

test('E2 collapsed selection and explicit answers survive interruption with member ownership protection', async ({ page, context }, testInfo) => {
  test.skip(!requiredProjects.includes(testInfo.project.name), 'Uses the five requested viewport sizes.');
  const api = await mobileInspectionFixture(page, mobileApparatus[0]);
  const chooseL4 = async () => {
    const changeArea = page.getByRole('button', { name: 'Change area', exact: true });
    if (await changeArea.isVisible()) await changeArea.click();
    await page.getByLabel('Apparatus views', { exact: true }).getByRole('button', { name: 'Driver', exact: true }).click();
    await page.locator('.blueprint-touch-zones button[data-area-id="comp_l1"]').click();
  };
  await chooseL4();
  await page.getByRole('button', { name: 'Next area', exact: true }).click();
  await expect.poll(async () => (await readDrafts(page))[0]?.data.compartments.flatMap(area => area.items).filter(item => item.observed).length).toBe(0);
  await chooseL4();
  const pass = page.getByRole('button', { name: "Pass Driver's Gear", exact: true });
  await pass.click();
  await page.locator('.equipment-row').first().locator('.equipment-expand').click();
  await page.getByRole('button', { name: 'Damaged', exact: true }).click();
  await context.setOffline(true);
  await expect(page.getByText('Offline · On this device', { exact: true })).toBeVisible();
  await page.getByLabel('Notes (optional)', { exact: true }).fill('Observed during mobile inspection.');
  if ((page.viewportSize()?.width ?? 0) < 1024) {
    const notes = page.getByLabel('Notes (optional)', { exact: true });
    await notes.focus();
    await notes.scrollIntoViewIfNeeded();
    await expect(notes).toBeFocused();
    expect((await notes.boundingBox())!.y + (await notes.boundingBox())!.height).toBeLessThan((await page.locator('.inspection-action-bar').boundingBox())!.y);
  }
  await page.getByRole('button', { name: 'Mark all items in this compartment as present', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Damaged', exact: true })).toHaveAttribute('aria-pressed', 'true');
  await expect.poll(async () => (await readDrafts(page))[0]?.data.compartments.find(area => area.id === 'comp_l1')?.items[0]?.notes).toBe('Observed during mobile inspection.');
  await context.setOffline(false);
  await page.reload();
  await chooseL4();
  await page.locator('.equipment-row').first().locator('.equipment-expand').click();
  await expect(page.getByLabel('Notes (optional)', { exact: true })).toHaveValue('Observed during mobile inspection.');
  await expect(page.getByRole('button', { name: 'Damaged', exact: true })).toHaveAttribute('aria-pressed', 'true');
  api.setUserId(402);
  await page.reload();
  await chooseL4();
  await expect(pass).toHaveAttribute('aria-pressed', 'false');
  await page.locator('.equipment-row').first().locator('.equipment-expand').click();
  await expect(page.getByLabel('Notes (optional)', { exact: true })).toHaveValue('');
  api.setUserId(401);
  await page.reload();
  await chooseL4();
  await page.locator('.equipment-row').first().locator('.equipment-expand').click();
  await expect(page.getByLabel('Notes (optional)', { exact: true })).toHaveValue('Observed during mobile inspection.');
  expect(api.submissions).toEqual([]);
});

test('E2 readings are optional, entered readings validate, and shift stays required', async ({ page }, testInfo) => {
  test.skip(!requiredProjects.includes(testInfo.project.name), 'Uses the five requested viewport sizes.');
  const api = await mobileInspectionFixture(page, mobileApparatus[0]);
  const navigation = page.getByRole('navigation', { name: 'Inspection workspace', exact: true });
  const readings = navigation.getByRole('button', { name: 'Readings · Optional', exact: true });
  await readings.click();
  await expect(page.getByRole('heading', { name: 'Readings · Optional', exact: true })).toBeVisible();
  await expect(page.getByText('Enter current readings when available. You may continue without them.', { exact: true })).toBeVisible();
  await expect(page.locator('#engine_hours')).toHaveValue('');
  await expect(page.locator('#miles')).toHaveValue('');
  await page.getByRole('button', { name: 'Continue: Apparatus', exact: true }).click();
  await expect(page.getByRole('region', { name: 'Compartment Inspection', exact: true })).toBeVisible();
  await readings.click();
  await page.locator('#engine_hours').fill('12.34');
  await page.getByRole('button', { name: 'Continue: Apparatus', exact: true }).click();
  await expect(page.getByText('Enter hours with at most one decimal place', { exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Readings · Optional', exact: true })).toBeVisible();
  for (const [selector, value, error] of [
    ['#engine_hours', '-1', 'Value cannot be negative'],
    ['#miles', '-1', 'Value cannot be negative'],
    ['#miles', '1.5', 'Enter whole miles'],
    ['#miles', '1000000000', 'Reading is outside the supported range'],
  ]) {
    await page.locator('#engine_hours').fill('');
    await page.locator('#miles').fill('');
    await page.locator(selector).fill(value);
    await page.getByRole('button', { name: 'Continue: Apparatus', exact: true }).click();
    await expect(page.locator(selector)).toHaveValue(value);
    await expect(page.getByText(error, { exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Readings · Optional', exact: true })).toBeVisible();
  }
  await page.locator('#miles').fill('');
  await page.locator('#engine_hours').fill('1200');
  await expect(page.getByText('This is below the previous reading. Record what the display shows; it will be saved for review.', { exact: true })).toBeVisible();
  await page.locator('#engine_hours').fill('');
  await page.getByRole('button', { name: 'Continue: Apparatus', exact: true }).click();
  await navigation.getByRole('button', { name: 'Member / Vehicle Info', exact: true }).click();
  const shift = page.getByRole('combobox', { name: 'Shift', exact: true });
  await expect(shift).toHaveAttribute('required', '');
  await page.getByRole('button', { name: 'Continue: Apparatus', exact: true }).click();
  await expect(shift).toBeVisible();
  expect(await shift.evaluate((element: HTMLSelectElement) => element.validity.valueMissing)).toBe(true);
  await shift.selectOption('A');
  await page.getByRole('button', { name: 'Continue: Apparatus', exact: true }).click();
  await expect(page.getByRole('region', { name: 'Compartment Inspection', exact: true })).toBeVisible();
  expect(api.submissions).toEqual([]);
});

test('invalid raw readings cannot be bypassed through workspace tabs', async ({ page }, testInfo) => {
  test.skip(!requiredProjects.includes(testInfo.project.name), 'Uses the five requested viewport sizes.');
  await mobileInspectionFixture(page, mobileApparatus[0]);
  const navigation = page.getByRole('navigation', { name: 'Inspection workspace', exact: true });
  await navigation.getByRole('button', { name: 'Readings · Optional', exact: true }).click();
  const hours = page.locator('#engine_hours');
  for (const invalid of ['-1', 'abc']) {
    await hours.fill('123');
    await hours.fill(invalid);
    await navigation.getByRole('button', { name: 'Apparatus', exact: true }).click();
    await expect(hours).toBeVisible();
    await expect(hours).toHaveValue(invalid);
    expect(await hours.evaluate((input: HTMLInputElement) => input.validity.valid)).toBe(false);
  }
  await hours.fill('');
  const miles = page.locator('#miles');
  await miles.fill('1e3');
  for (const name of ['Member / Vehicle Info', 'Checklist details']) {
    await navigation.getByRole('button', { name, exact: true }).click();
    await expect(miles).toBeVisible();
    await expect(miles).toHaveValue('1e3');
    expect(await miles.evaluate((input: HTMLInputElement) => input.validity.valid)).toBe(false);
  }
  expect((await readDrafts(page))[0]?.data.meter.miles).not.toBe(1000);
  await miles.fill('');
  await hours.fill('123');
  await hours.fill('   ');
  await miles.fill('   ');
  await expect(page.getByText('This is below the previous reading. Record what the display shows; it will be saved for review.', { exact: true })).toHaveCount(0);
  await page.getByRole('button', { name: 'Continue: Apparatus', exact: true }).click();
  await expect(page.getByRole('region', { name: 'Compartment Inspection', exact: true })).toBeVisible();
  await expect.poll(async () => (await readDrafts(page))[0]?.data.meter).toEqual({ engine_hours: null, miles: null });
});
