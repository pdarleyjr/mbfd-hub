import { expect, test, type Locator, type Page, type TestInfo } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

function fixturePassword(admin = false, officer = false): string {
  const name = admin ? 'PERSONNEL_REQUESTS_E2E_ADMIN_PASSWORD' : officer ? 'PERSONNEL_REQUESTS_E2E_OFFICER_PASSWORD' : 'PERSONNEL_REQUESTS_E2E_MEMBER_PASSWORD';
  const password = process.env[name];
  if (!password) throw new Error(`${name} must come from the isolated uniform Playwright configuration.`);
  return password;
}

async function login(page: Page, employeeId: string, admin = false): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Employee ID').fill(employeeId);
  await page.getByLabel('Password', { exact: true }).fill(fixturePassword(admin, employeeId === '99001'));
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/$/, { timeout: 20_000 });
}

async function openBuilder(page: Page, employeeId = 'BID-E2E-D07'): Promise<void> {
  await login(page, employeeId);
  await page.goto('/employee/request-equipment');
  await expect(page.getByRole('heading', { name: 'Request Uniforms', exact: true })).toBeVisible();
  await page.evaluate(() => document.fonts.ready.then(() => undefined));
  await expect(page.locator('.uo-mobile-action')).toBeHidden();
  expect(await page.locator('.uo-assignment').evaluate(element => ({
    background: getComputedStyle(element).backgroundColor,
    border: getComputedStyle(element).borderTopWidth,
  }))).toEqual({ background: 'rgb(255, 255, 255)', border: '1px' });
  expect(await product(page, 'class_a_shirt').evaluate(element => getComputedStyle(element).backgroundColor)).toBe('rgb(255, 255, 255)');
}

function product(page: Page, code: string): Locator {
  return page.locator(`[data-product="${code}"]`);
}

async function setQuantity(page: Page, code: string, quantity: number): Promise<void> {
  const input = product(page, code).getByRole('spinbutton', { name: /quantity/i });
  await input.fill(String(quantity));
  await input.blur();
  await expect(input).toHaveValue(String(quantity));
}

async function expectViewportFit(page: Page): Promise<void> {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);
}

async function capture(page: Page, testInfo: TestInfo, name: string): Promise<void> {
  await page.screenshot({ path: testInfo.outputPath(`${name}.png`), fullPage: true, animations: 'disabled' });
}

const profiles = [
  { id: 'BID-E2E-D05', assignment: 'Engine 2', profile: /Combat/, marine: false },
  { id: 'BID-E2E-D07', assignment: 'Rescue 1', profile: /Rescue/, marine: false },
  { id: 'BID-E2E-D08', assignment: 'Fire Boat 6', profile: /Day|Other/, marine: true },
  { id: '99001', assignment: 'Prevention', profile: /Day|Administrative|Other/i, marine: false },
];

for (const scenario of profiles) {
  test(`${scenario.assignment} member sees the appropriate allocation and complete catalog`, async ({ page }, testInfo) => {
    await openBuilder(page, scenario.id);
    const assignment = page.locator('[data-entitlement-profile]');
    await expect(assignment).toContainText(scenario.assignment);
    await expect(assignment).toContainText(scenario.profile);
    await expect(assignment).toContainText('2026');
    await expect(page.getByText('Product images are for demonstration purposes only.', { exact: false })).toBeVisible();
    for (const code of [
      'class_a_shirt', 'class_a_long_sleeve_shirt', 'class_a_pants', 'tie', 'work_boots',
      'polo_shirt', 'long_sleeve_polo', 'uniform_pants', 'jumpsuit', 't_shirt', 'long_sleeve_shirt',
      'belt', 'jacket', 'raincoat', 'marine_shorts', 'marine_short_sleeve_shirt', 'marine_long_sleeve_shirt', 'boating_shoes',
    ]) {
      await expect(product(page, code)).toBeVisible();
      await expect(product(page, code).getByRole('spinbutton', { name: /quantity/i })).toBeEnabled();
    }
    const categories = page.locator('.uo-category');
    if (scenario.marine) {
      await expect(categories.first()).toHaveAttribute('id', 'uo-section-marine');
      await expect(categories.first()).toContainText('Your Marine Allocation');
    } else {
      await expect(categories.last()).toHaveAttribute('id', 'uo-section-marine');
    }
    await expectViewportFit(page);
    const accessibility = await new AxeBuilder({ page }).include('.uo-builder').analyze();
    expect(accessibility.violations.map(({ id, impact, nodes }) => ({ id, impact, nodes: nodes.map(node => node.target) }))).toEqual([]);
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: testInfo.outputPath('catalog-viewport.png'), animations: 'disabled' });
    await capture(page, testInfo, `allocation-${scenario.assignment.replaceAll(' ', '-').toLowerCase()}`);
  });
}

test('catalog is touch ready and only loads larger images on expansion', async ({ page }, testInfo) => {
  const requestedImages: string[] = [];
  page.on('request', request => {
    if (request.url().includes('/images/uniforms/')) requestedImages.push(request.url());
  });
  await openBuilder(page);
  const thumbs = page.locator('[data-product] img');
  await expect(thumbs).toHaveCount(9);
  for (const thumb of await thumbs.all()) {
    await expect(thumb).toHaveAttribute('loading', 'lazy');
    await expect(thumb).toHaveAttribute('alt', /.+/);
    await expect(thumb).toHaveAttribute('src', /-thumb\.webp$/);
  }
  expect(requestedImages.filter(url => url.endsWith('-large.webp'))).toHaveLength(0);

  for (const target of await page.locator('[data-product] button, [data-product] input[type="number"], [data-product] select').evaluateAll(elements => elements.filter(element => {
    const rect = element.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
  }).map(element => {
    const rect = element.getBoundingClientRect();
    return { label: element.getAttribute('aria-label') ?? element.textContent, width: rect.width, height: rect.height };
  }))) {
    expect(target.height, String(target.label)).toBeGreaterThanOrEqual(44);
    expect(target.width, String(target.label)).toBeGreaterThanOrEqual(44);
  }

  const shirt = product(page, 'class_a_shirt');
  const add = shirt.getByRole('button', { name: 'Add one Class A Short Sleeve Shirt', exact: true });
  if (testInfo.project.use.hasTouch) await add.tap();
  else await add.click();
  await expect(shirt.getByRole('spinbutton', { name: 'Quantity', exact: true })).toHaveValue('1');
  await expect(shirt.getByLabel('Neck (inches)')).toBeVisible();
  await shirt.getByRole('button', { name: 'Remove one Class A Short Sleeve Shirt', exact: true }).click();
  await expect(shirt.getByRole('spinbutton', { name: 'Quantity', exact: true })).toHaveValue('0');
  await expect(shirt.getByLabel('Neck (inches)')).toBeHidden();

  const trigger = product(page, 'class_a_shirt').getByRole('button', { name: /(?:view|enlarge|expand|image)/i }).first();
  await trigger.scrollIntoViewIfNeeded();
  if (testInfo.project.use.hasTouch) await trigger.tap();
  else await trigger.click();
  const dialog = page.getByRole('dialog', { name: 'Class A Short Sleeve Shirt', exact: true });
  await expect(dialog).toBeVisible();
  expect(await dialog.evaluate(element => element.matches(':modal'))).toBe(true);
  await expect(dialog.getByRole('img')).toHaveAttribute('src', /class-a-short-large\.webp$/);
  await expect.poll(() => dialog.getByRole('img').evaluate(element => (element as HTMLImageElement).naturalWidth)).toBeGreaterThan(0);
  expect(await dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
  for (const key of ['Shift+Tab', 'Tab', 'Tab']) {
    await page.keyboard.press(key);
    expect(await dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
  }
  await dialog.screenshot({ path: testInfo.outputPath('product-image-expanded.png'), animations: 'disabled' });
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(trigger).toBeFocused();
  await trigger.click();
  await expect(dialog).toBeVisible();
  // A point outside the image panel exercises the actual backdrop dismissal.
  await page.mouse.click(4, 4);
  await expect(dialog).toBeHidden();
  await expect(trigger).toBeFocused();
  await trigger.click();
  await dialog.getByRole('button', { name: 'Close product image', exact: true }).click();
  await expect(dialog).toBeHidden();
  await expect(trigger).toBeFocused();
  await expectViewportFit(page);
});

test('section shortcuts avoid catalog scrolling and order rows return directly to sizing', async ({ page }, testInfo) => {
  await openBuilder(page);
  await page.screenshot({ path: testInfo.outputPath('shortcut-initial-viewport.png'), animations: 'disabled' });
  const jump = page.getByRole('combobox', { name: 'Jump to a section', exact: true });
  const work = page.locator('#uo-section-work');
  const compactNavigation = (testInfo.project.use.viewport?.width ?? 1440) < 1200;
  if (compactNavigation) {
    await expect(jump).toBeVisible();
    await jump.selectOption('uo-section-work');
    await expect(jump).toHaveValue('');
    await expect.poll(() => work.evaluate(element => element.getBoundingClientRect().top)).toBeGreaterThanOrEqual(140);
    const navigation = await page.locator('.uo-category-nav').boundingBox();
    expect(navigation!.y).toBeGreaterThanOrEqual(64);
    expect(navigation!.height).toBeGreaterThanOrEqual(44);
    const workBounds = await work.boundingBox();
    expect(workBounds!.y).toBeGreaterThanOrEqual(navigation!.y + navigation!.height);
  } else {
    await page.getByRole('navigation', { name: 'Uniform categories' }).getByRole('link', { name: 'Work Uniforms', exact: true }).click();
  }

  await setQuantity(page, 'uniform_pants', 1);
  if (compactNavigation) {
    await page.evaluate(() => (document.activeElement as HTMLElement)?.blur());
    await page.locator('.uo-assignment').evaluate(element => element.scrollIntoView({ block: 'start' }));
    await expect(page.locator('.uo-mobile-action')).toBeHidden();
    await jump.selectOption('uo-section-work');
    await page.evaluate(() => (document.activeElement as HTMLElement)?.blur());
    await expect(page.locator('.uo-mobile-action')).toBeVisible();
  }
  if (compactNavigation) await jump.selectOption('uo-summary-heading');
  await page.getByRole('link', { name: 'Edit 5.11 Tactical Pants, quantity 1', exact: true }).click();
  const pantsBounds = await product(page, 'uniform_pants').boundingBox();
  expect(pantsBounds!.y).toBeGreaterThanOrEqual(140);
  expect(pantsBounds!.y).toBeLessThan(300);
  await expect(product(page, 'uniform_pants').getByLabel('Waist (inches)')).toBeVisible();
  if (compactNavigation) {
    await jump.selectOption('uo-notes');
    await expect(page.getByRole('textbox', { name: /Notes for Support Services/ })).toBeInViewport();
    await page.getByRole('textbox', { name: /Notes for Support Services/ }).fill('Replacement sizing request.');
    await expect(page.locator('.uo-mobile-action')).toBeHidden();
    await page.getByRole('textbox', { name: /Notes for Support Services/ }).blur();
    await jump.selectOption('uo-section-work');
  }
  await expectViewportFit(page);
  await page.screenshot({ path: testInfo.outputPath('shortcut-work-viewport.png'), animations: 'disabled' });
  if (testInfo.project.name === 'uniform-375') {
    await page.setViewportSize({ width: 320, height: 844 });
    await jump.selectOption('uo-section-work');
    await expectViewportFit(page);
    expect((await jump.boundingBox())!.height).toBeGreaterThanOrEqual(44);
    await page.getByRole('link', { name: 'Edit 5.11 Tactical Pants, quantity 1', exact: true }).click();
    await expect(product(page, 'uniform_pants').getByLabel('Waist (inches)')).toBeInViewport();
    await page.screenshot({ path: testInfo.outputPath('shortcut-320-viewport.png'), animations: 'disabled' });
  }
});

test('jumpsuit exchanges and combined T-shirt overages remain advisory', async ({ page }, testInfo) => {
  await openBuilder(page);
  const summary = page.locator('[data-order-summary]');
  await setQuantity(page, 'jumpsuit', 3);
  await expect(page.locator('[data-swap-credits]')).toContainText('Jumpsuit Swap Credits: 0');
  await expect(page.locator('[data-swap-credits]')).toContainText('Work-set allowance: 2');
  await setQuantity(page, 'jumpsuit', 2);
  await expect(page.locator('[data-swap-credits]')).toContainText('Jumpsuit Swap Credits: 1');
  await expect(page.locator('[data-swap-credits]')).toContainText('Work-set allowance: 3');
  await setQuantity(page, 't_shirt', 3);
  await product(page, 't_shirt').getByLabel('Size', { exact: false }).selectOption('L');
  await setQuantity(page, 'long_sleeve_shirt', 2);
  await product(page, 'long_sleeve_shirt').getByLabel('Size', { exact: false }).selectOption('M');
  await expect(summary).toContainText(/5 selected|5.*4/);
  await expect(page.getByText(/outside your standard annual allocation/i)).toBeVisible();
  await expect(page.getByRole('button', { name: 'Submit Uniform Request', exact: true }).first()).toBeEnabled();
  await setQuantity(page, 'uniform_pants', 1);
  await product(page, 'uniform_pants').getByLabel('Waist (inches)', { exact: false }).fill('34');
  await product(page, 'uniform_pants').getByLabel('Inseam (inches)', { exact: false }).fill('32');
  await expect(summary).toContainText(/quantities differ|one polo.*one pair/i);
  await expectViewportFit(page);
  await page.evaluate(() => (document.activeElement as HTMLElement)?.blur());
  const mobileAction = page.locator('.uo-mobile-action');
  const memberNav = page.locator('.hub-member-nav');
  if (await mobileAction.isVisible() && await memberNav.isVisible()) {
    await mobileAction.scrollIntoViewIfNeeded();
    const actionBox = await mobileAction.boundingBox();
    const navBox = await memberNav.boundingBox();
    expect(actionBox).not.toBeNull();
    expect(navBox).not.toBeNull();
    expect(actionBox!.y + actionBox!.height).toBeLessThanOrEqual(navBox!.y + 1);
  }
  await capture(page, testInfo, 'selected-sizing-and-advisories');
  await product(page, 'uniform_pants').evaluate(element => window.scrollTo(0, element.getBoundingClientRect().top + window.scrollY - 96));
  await page.screenshot({ path: testInfo.outputPath('selected-work-viewport.png'), animations: 'disabled' });
});

test('invalid procurement measurements block submission and preserve the selection', async ({ page }, testInfo) => {
  await openBuilder(page);
  const priorRequests = await page.locator('.uo-recent-request').count();
  await setQuantity(page, 'uniform_pants', 1);
  const pants = product(page, 'uniform_pants');
  const waist = pants.getByLabel('Waist (inches)', { exact: false });
  await waist.fill('0');
  await pants.getByLabel('Inseam (inches)', { exact: false }).fill('32');
  await page.getByRole('button', { name: 'Submit Uniform Request', exact: true }).click();
  await expect(page.locator('.uo-input-errors')).toContainText('Check your order details');
  await expect(waist).toHaveAttribute('aria-invalid', 'true');
  await expect(pants.getByRole('spinbutton', { name: 'Quantity', exact: true })).toHaveValue('1');
  await expect(page.locator('.uo-recent-request')).toHaveCount(priorRequests);
  await expect(page.locator('[data-order-success]')).toHaveCount(0);
  await expectViewportFit(page);
  await capture(page, testInfo, 'invalid-measurement-preserved');
});

test('over-allocation order persists structured sizes, note, My Requests and the existing admin queue', async ({ page, browser, baseURL }, testInfo) => {
  test.skip(testInfo.project.name !== 'uniform-1440', 'One real request in the disposable browser database.');
  test.setTimeout(120_000);
  await openBuilder(page);
  const note = '[QA TEST] Five T-shirts and replacement trousers for Support Services review.';
  await setQuantity(page, 't_shirt', 5);
  await product(page, 't_shirt').getByLabel('Size', { exact: false }).selectOption('L');
  await setQuantity(page, 'uniform_pants', 1);
  await product(page, 'uniform_pants').getByLabel('Waist (inches)', { exact: false }).fill('34');
  await product(page, 'uniform_pants').getByLabel('Inseam (inches)', { exact: false }).fill('32');
  await product(page, 'uniform_pants').getByLabel('Requested cut', { exact: false }).selectOption('mens');
  await page.getByRole('textbox', { name: /Notes for Support Services/ }).fill(note);
  await expect(page.getByText(/outside your standard annual allocation/i)).toBeVisible();
  await page.getByRole('button', { name: 'Submit Uniform Request', exact: true }).first().click();
  const recent = page.locator('.uo-recent-request').first();
  await expect(recent).toContainText('Pending', { timeout: 20_000 });
  const memberPath = (await recent.getAttribute('href'))!;
  expect(memberPath).toMatch(/^\/employee\/my-requests\/[0-9A-Z]{26}$/);
  const publicId = memberPath.split('/').at(-1)!;
  await page.goto('/employee/my-requests');
  await expect(page.locator(`a[href="${memberPath}"]`).first()).toBeVisible();
  await page.goto(memberPath);
  await expect(page.getByText('Pending', { exact: true }).first()).toBeVisible();
  await expect(page.getByText(note, { exact: true })).toBeVisible();
  await expect(page.getByText(/Size.*34.*32/).first()).toBeVisible();
  await capture(page, testInfo, 'member-request-readback');

  const context = await browser.newContext({ baseURL, viewport: { width: 1440, height: 1000 } });
  try {
    const admin = await context.newPage();
    await login(admin, '99003', true);
    await admin.goto('/admin/personnel-uniforms-equipment/personnel-requests');
    await expect(admin.locator(`a[href$="/${publicId}"]`).first()).toBeVisible();
    await admin.goto(`/admin/personnel-uniforms-equipment/personnel-requests/${publicId}`);
    await expect(admin.getByText(note, { exact: true })).toBeVisible();
    await expect(admin.getByText('Rescue 1', { exact: true }).first()).toBeVisible();
    await expect(admin.getByText(/Waist.*34/).first()).toBeVisible();
    await expect(admin.getByText(/Inseam.*32/).first()).toBeVisible();
    await expect(admin.getByRole('button', { name: 'Acknowledge', exact: true })).toBeVisible();
    await expectViewportFit(admin);
    await capture(admin, testInfo, 'admin-request-readback');
    await testInfo.attach('uniform-request-identity', { body: JSON.stringify({ memberPath, publicId }), contentType: 'application/json' });
  } finally {
    await context.close();
  }
});
