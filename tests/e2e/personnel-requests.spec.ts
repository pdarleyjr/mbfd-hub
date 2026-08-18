import { expect, test, type Page, type TestInfo } from '@playwright/test';

function requiredPassword(name: string): string {
  const password = process.env[name];
  if (!password) {
    throw new Error(`${name} must be supplied by the personnel-request Playwright configuration.`);
  }

  return password;
}

async function loginEmployee(page: Page, employeeId: string, password: string): Promise<void> {
  await page.goto('/employee/login');
  await page.getByLabel('Employee ID').fill(employeeId);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/employee\/dashboard$/, { timeout: 20_000 });
}

async function expectViewportFit(page: Page): Promise<void> {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);
}

async function screenshot(page: Page, testInfo: TestInfo, name: string): Promise<void> {
  await page.screenshot({ path: testInfo.outputPath(`${name}.png`), fullPage: true });
}

test('homepage exposes the exact station and uniform-specific destinations', async ({ page }, testInfo) => {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Station / Vehicles / Equipment' })).toBeVisible();
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
  const back = page.locator('.employee-global-back a');
  await expect(back).toHaveAttribute('href', '/daily/stations/1');
  await expect(back).toContainText('Back to Station');

  const sidebarOverlay = page.locator('.fi-sidebar-close-overlay');
  if (await sidebarOverlay.isVisible()) {
    const viewport = page.viewportSize();
    expect(viewport).not.toBeNull();
    if (viewport) {
      await page.touchscreen.tap(viewport.width - 8, 100);
      await expect(sidebarOverlay).toBeHidden();
    }
  }

  const beneficiary = page.getByRole('combobox').nth(1);
  await beneficiary.click();
  await page.keyboard.type('Morgan');
  await page.getByRole('option', { name: 'Firefighter — Morgan Member — 99002' }).click();
  await page.getByRole('button', { name: 'Next' }).click();
  await expect(page.getByText('A police report may be required')).toBeVisible();
  await page.getByLabel('Equipment*', { exact: true }).selectOption('structural_firefighting_helmet');
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
  await page.goto('/admin/login');
  await page.getByLabel('Email address').fill('personnel-admin@example.test');
  await page.getByLabel('Password').fill(requiredPassword('PERSONNEL_REQUESTS_E2E_ADMIN_PASSWORD'));
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL(/\/admin(?!\/login)/, { timeout: 20_000 });
  await page.goto('/admin/personnel-uniforms-equipment/overview', { waitUntil: 'networkidle' });

  await expect(page.getByRole('heading', { name: 'Personnel Uniforms / Equipment' }).first()).toBeVisible();
  await expect(page.getByText('Uniform Requests')).toBeVisible();
  await expect(page.getByText('Equipment Requests')).toBeVisible();
  await expect(page.getByText('Expiring Soon')).toBeVisible();
  await expectViewportFit(page);
  await screenshot(page, testInfo, 'admin-overview');
});
