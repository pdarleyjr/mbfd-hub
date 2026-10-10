import { expect, test, type Locator, type Page, type TestInfo } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const categoryProducts: Record<string, string[]> = {
  work: ['polo_shirt', 'long_sleeve_polo', 'uniform_pants'],
  dress: ['class_a_shirt', 'class_a_long_sleeve_shirt', 'class_a_pants', 'tie'],
  operational: ['jumpsuit'],
  tshirts: ['t_shirt', 'long_sleeve_shirt'],
  accessories: ['belt', 'jacket', 'raincoat'],
  marine: ['marine_shorts', 'marine_short_sleeve_shirt', 'marine_long_sleeve_shirt', 'boating_shoes'],
};

function fixturePassword(admin = false, officer = false): string {
  const name = admin ? 'PERSONNEL_REQUESTS_E2E_ADMIN_PASSWORD' : officer ? 'PERSONNEL_REQUESTS_E2E_OFFICER_PASSWORD' : 'PERSONNEL_REQUESTS_E2E_MEMBER_PASSWORD';
  const password = process.env[name];
  if (!password) throw new Error(name + ' must come from the isolated uniform Playwright configuration.');
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
  const submit = page.getByRole('button', { name: 'Submit Uniform Request', exact: true, includeHidden: true });
  await expect(submit).toHaveCount(1);
  await expect(submit).toContainText('Submit order');
  await expect(page.getByRole('combobox', { name: 'Uniform category', exact: true })).toHaveCount(0);
  await expect(page.locator('[data-cart-count]')).toHaveText('0');
  await expectOrderLayout(page);
  for (const code of ['uniform_shirt', 'work_boots', 'class_a_coat']) {
    await expect(product(page, code)).toHaveCount(0);
  }
  await expect(page.locator('.uo-mobile-action')).toHaveCount(0);
  await expect(page.locator('[data-product]:visible')).toHaveCount(17);
}

function product(page: Page, code: string): Locator {
  return page.locator('[data-product="' + code + '"]');
}

function usesMobileCart(page: Page): boolean {
  return (page.viewportSize()?.width ?? 1440) < 1024;
}

async function closeCart(page: Page): Promise<void> {
  const close = page.getByRole('button', { name: 'Close cart', exact: true });
  if (await close.isVisible()) await close.click();
  await expectCatalogInteractive(page);
}

async function expectCatalogInteractive(page: Page, interactive = true): Promise<void> {
  await expect.poll(() => page.locator('.uo-catalog').evaluate(element => !element.closest('[inert], [aria-hidden="true"]'))).toBe(interactive);
}

async function scrollToCategory(page: Page, category: string): Promise<void> {
  await closeCart(page);
  await product(page, categoryProducts[category][0]).scrollIntoViewIfNeeded();
  await expect(page.locator('[data-product]:visible')).toHaveCount(17);
}

async function setQuantity(page: Page, code: string, quantity: number): Promise<void> {
  const category = Object.entries(categoryProducts).find(([, codes]) => codes.includes(code))?.[0];
  if (!category) throw new Error('Unknown uniform product: ' + code);
  await scrollToCategory(page, category);
  const input = product(page, code).getByRole('spinbutton', { name: /quantity/i });
  await input.fill(String(quantity));
  await input.blur();
  await expect(input).toHaveValue(String(quantity));
}

async function reviewOrder(page: Page): Promise<Locator> {
  if (usesMobileCart(page) && !await page.getByRole('dialog', { name: 'Your cart', exact: true }).isVisible()) {
    await page.getByRole('button', { name: 'Open cart', exact: true }).click();
  }
  const summary = page.locator('[data-order-summary]');
  await expect(summary).toBeVisible();
  await expect(page.locator('[data-product]:visible')).toHaveCount(17);
  if (usesMobileCart(page)) {
    await expect(page.getByRole('dialog', { name: 'Your cart', exact: true })).toHaveAttribute('aria-modal', 'true');
    await expectCatalogInteractive(page, false);
  }
  return summary;
}

async function expectOrderLayout(page: Page): Promise<void> {
  const summary = page.locator('[data-order-summary]');
  const cart = page.getByRole('button', { name: 'Open cart', exact: true });
  if (usesMobileCart(page)) {
    await expect(summary).toBeHidden();
    await expect(cart).toBeInViewport({ ratio: 1 });
    await expect(page.getByRole('button', { name: 'Submit Uniform Request', exact: true })).toBeHidden();
  } else {
    await expect(summary).toBeVisible();
    await expect(cart).toBeHidden();
    const catalogBounds = await page.locator('.uo-catalog').boundingBox();
    const summaryBounds = await summary.boundingBox();
    expect(catalogBounds).not.toBeNull();
    expect(summaryBounds).not.toBeNull();
    expect(summaryBounds!.x).toBeGreaterThanOrEqual(catalogBounds!.x + catalogBounds!.width);
    await expect(page.getByRole('button', { name: 'Submit Uniform Request', exact: true })).toBeVisible();
  }
}

async function expectStackedProducts(page: Page): Promise<void> {
  const bounds = await page.locator('[data-product]').evaluateAll(elements => elements.map(element => {
    const { x, y, width, height } = element.getBoundingClientRect();
    return { x, y, width, height };
  }));
  expect(bounds).toHaveLength(17);
  for (let index = 1; index < bounds.length; index++) {
    expect(bounds[index].x).toBeCloseTo(bounds[0].x, 0);
    expect(bounds[index].width).toBeCloseTo(bounds[0].width, 0);
    expect(bounds[index].y).toBeGreaterThanOrEqual(bounds[index - 1].y + bounds[index - 1].height - 1);
  }
}

async function expandDetails(page: Page, label: string): Promise<void> {
  const summary = page.locator('summary').filter({ hasText: label });
  await expect(summary).toHaveCount(1);
  const details = summary.locator('..');
  if (await details.getAttribute('open') === null) await summary.click();
  await expect(details).toHaveAttribute('open', '');
}

async function expectViewportFit(page: Page): Promise<void> {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);
}

async function expectTouchTargets(page: Page): Promise<void> {
  for (const target of await page.locator('.uo-toolbar button, .uo-summary-column button, [data-product] button, [data-product] input[type="number"], [data-product] select').evaluateAll(elements => elements.filter(element => {
    const rect = element.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
  }).map(element => {
    const rect = element.getBoundingClientRect();
    return { label: element.getAttribute('aria-label') ?? element.textContent, width: rect.width, height: rect.height };
  }))) {
    expect(target.height, String(target.label)).toBeGreaterThanOrEqual(44);
    expect(target.width, String(target.label)).toBeGreaterThanOrEqual(44);
  }
}

async function capture(page: Page, testInfo: TestInfo, name: string): Promise<void> {
  await page.screenshot({ path: testInfo.outputPath(name + '.png'), fullPage: true, animations: 'disabled' });
}

const profiles = [
  { id: 'BID-E2E-D05', assignment: 'Engine 2', profile: /Combat/, marine: false },
  { id: 'BID-E2E-D07', assignment: 'Rescue 1', profile: /Rescue/, marine: false },
  { id: 'BID-E2E-D08', assignment: 'Fire Boat 6', profile: /Day|Other/, marine: true },
  { id: '99001', assignment: 'Prevention', profile: /Day|Administrative|Other/i, marine: false },
];

for (const scenario of profiles) {
  test(scenario.assignment + ' member sees every category in one stacked catalog', async ({ page }, testInfo) => {
    await openBuilder(page, scenario.id);
    const assignment = page.locator('[data-entitlement-profile]');
    await expect(assignment).toContainText(scenario.assignment);
    await expect(assignment).toContainText(scenario.profile);
    await expect(assignment).toContainText('2026');
    await expect(page.getByText('Product images are for demonstration purposes only.', { exact: false })).toBeVisible();
    const allocation = page.locator('details').filter({ has: page.locator('summary').filter({ hasText: 'Annual allocation' }) });
    await expect(allocation).toHaveCount(1);
    await expect(allocation).not.toHaveAttribute('open', '');
    await expect(page.locator('[data-category]:visible')).toHaveCount(Object.keys(categoryProducts).length);
    await expectStackedProducts(page);
    const firstProduct = await page.locator('[data-product]').first().boundingBox();
    expect(firstProduct).not.toBeNull();
    expect(firstProduct!.y).toBeLessThan(550);
    await expectViewportFit(page);
    await expectTouchTargets(page);
    const accessibility = await new AxeBuilder({ page }).include('.uo-builder').analyze();
    expect(accessibility.violations.map(({ id, impact, nodes }) => ({ id, impact, nodes: nodes.map(node => node.target) }))).toEqual([]);
    const firstImage = page.locator('[data-product]').first().locator('img');
    if (await firstImage.count()) {
      await expect.poll(() => firstImage.evaluate(element => (element as HTMLImageElement).naturalWidth), { timeout: 20_000 }).toBeGreaterThan(0);
    }
    await page.screenshot({ path: testInfo.outputPath('catalog-viewport.png'), animations: 'disabled' });
    if (scenario.assignment === 'Rescue 1' && testInfo.project.name === 'uniform-375') {
      await page.setViewportSize({ width: 320, height: 844 });
      await expectOrderLayout(page);
      await expectStackedProducts(page);
      expect((await page.locator('[data-product]').first().boundingBox())!.y).toBeLessThan(550);
      await expectTouchTargets(page);
      await expectViewportFit(page);
      await page.screenshot({ path: testInfo.outputPath('catalog-320-viewport.png'), animations: 'disabled' });
      await page.setViewportSize(testInfo.project.use.viewport!);
    }

    for (const [category, codes] of Object.entries(categoryProducts)) {
      await scrollToCategory(page, category);
      for (const code of codes) {
        await expect(product(page, code)).toBeVisible();
        await expect(product(page, code).getByRole('spinbutton', { name: /quantity/i })).toBeEnabled();
      }
      await expectViewportFit(page);
    }
    await product(page, 'boating_shoes').scrollIntoViewIfNeeded();
    if (usesMobileCart(page)) await expect(page.getByRole('button', { name: 'Open cart', exact: true })).toBeInViewport({ ratio: 1 });
    else await expect(page.getByRole('button', { name: 'Submit Uniform Request', exact: true })).toBeInViewport({ ratio: 1 });
    await page.locator('[data-product]').first().scrollIntoViewIfNeeded();
    await capture(page, testInfo, 'allocation-' + scenario.assignment.replaceAll(' ', '-').toLowerCase());
  });
}

test('catalog is touch ready and only loads larger images on expansion', async ({ page }, testInfo) => {
  const requestedImages: string[] = [];
  page.on('request', request => {
    if (request.url().includes('/images/uniforms/')) requestedImages.push(request.url());
  });
  await openBuilder(page);
  const thumbs = page.locator('[data-product] img');
  await expect(thumbs).toHaveCount(15);
  expect(new Set(await thumbs.evaluateAll(elements => elements.map(element => element.getAttribute('src')))).size).toBe(14);
  for (const thumb of await thumbs.all()) {
    await expect(thumb).toHaveAttribute('loading', 'lazy');
    await expect(thumb).toHaveAttribute('alt', /.+/);
    await expect(thumb).toHaveAttribute('src', /-thumb\.webp$/);
  }
  expect(requestedImages.filter(url => url.endsWith('-large.webp'))).toHaveLength(0);
  await scrollToCategory(page, 'dress');
  await expectTouchTargets(page);

  const shirt = product(page, 'class_a_shirt');
  const add = shirt.getByRole('button', { name: 'Add one Class A Short Sleeve Shirt', exact: true });
  if (testInfo.project.use.hasTouch) await add.tap();
  else await add.click();
  await expect(shirt.getByRole('spinbutton', { name: 'Quantity', exact: true })).toHaveValue('1');
  await expect(shirt.getByLabel('Neck (inches)')).toBeVisible();
  await shirt.getByRole('button', { name: 'Remove one Class A Short Sleeve Shirt', exact: true }).click();
  await expect(shirt.getByRole('spinbutton', { name: 'Quantity', exact: true })).toHaveValue('0');
  await expect(shirt.getByLabel('Neck (inches)')).toBeHidden();

  const trigger = shirt.getByRole('button', { name: /(?:view|enlarge|expand|image)/i }).first();
  await trigger.scrollIntoViewIfNeeded();
  if (testInfo.project.use.hasTouch) await trigger.tap();
  else await trigger.click();
  const dialog = page.getByRole('dialog', { name: 'Class A Short Sleeve Shirt', exact: true });
  await expect(dialog).toBeVisible();
  expect(await dialog.evaluate(element => element.matches(':modal'))).toBe(true);
  await expect(dialog.getByRole('img')).toHaveAttribute('src', /class-a-short-large\.webp$/);
  await expect.poll(() => dialog.getByRole('img').evaluate(element => (element as HTMLImageElement).naturalWidth), { timeout: 20_000 }).toBeGreaterThan(0);
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
  await page.mouse.click(4, 4);
  await expect(dialog).toBeHidden();
  await expect(trigger).toBeFocused();
  await trigger.click();
  await dialog.getByRole('button', { name: 'Close product image', exact: true }).click();
  await expect(dialog).toBeHidden();
  await expect(trigger).toBeFocused();
  await expectViewportFit(page);
});

test('cart review returns directly to selected sizing and preserves the complete catalog', async ({ page }, testInfo) => {
  await openBuilder(page);
  await setQuantity(page, 'uniform_pants', 1);
  await product(page, 'uniform_pants').getByLabel('Waist (inches)').fill('34');
  await product(page, 'uniform_pants').getByLabel('Inseam (inches)').fill('32');
  await scrollToCategory(page, 'tshirts');
  await expect(product(page, 'uniform_pants')).toBeVisible();
  await scrollToCategory(page, 'work');
  await expect(product(page, 'uniform_pants').getByRole('spinbutton', { name: 'Quantity', exact: true })).toHaveValue('1');
  await expect(product(page, 'uniform_pants').getByLabel('Waist (inches)')).toHaveValue('34');
  await expect(product(page, 'uniform_pants').getByLabel('Inseam (inches)')).toHaveValue('32');

  const summary = await reviewOrder(page);
  await expect(summary).toContainText('5.11 Tactical Pants');
  const notesDetails = page.locator('details').filter({ has: page.locator('summary').filter({ hasText: 'Notes for Support Services (optional)' }) });
  await expect(notesDetails).not.toHaveAttribute('open', '');
  await expandDetails(page, 'Notes for Support Services (optional)');
  await page.getByRole('textbox', { name: /Notes for Support Services/ }).fill('Replacement sizing request.');
  await page.getByRole('textbox', { name: /Notes for Support Services/ }).blur();
  await summary.getByRole('link', { name: 'Edit 5.11 Tactical Pants, quantity 1', exact: true }).click();
  await expect(product(page, 'uniform_pants')).toBeVisible();
  await expect(product(page, 'uniform_pants').getByLabel('Waist (inches)')).toBeInViewport();
  await expect(page.getByRole('dialog', { name: 'Your cart', exact: true })).toHaveCount(0);
  await expect(product(page, 'uniform_pants').getByLabel('Waist (inches)')).toBeFocused();
  await expectCatalogInteractive(page);
  await expectTouchTargets(page);
  await expectViewportFit(page);
  await page.screenshot({ path: testInfo.outputPath('selected-work-viewport.png'), animations: 'disabled' });
  if (testInfo.project.name === 'uniform-375') {
    await page.setViewportSize({ width: 320, height: 844 });
    await reviewOrder(page);
    await page.getByRole('link', { name: 'Edit 5.11 Tactical Pants, quantity 1', exact: true }).click();
    await expectViewportFit(page);
    await expectTouchTargets(page);
    await expect(product(page, 'uniform_pants').getByLabel('Waist (inches)')).toBeInViewport();
    await page.screenshot({ path: testInfo.outputPath('selected-320-viewport.png'), animations: 'disabled' });
  }
});

test('cart counts every unit immediately and removes the complete selected line', async ({ page }) => {
  await openBuilder(page);
  await scrollToCategory(page, 'tshirts');
  const shirt = product(page, 't_shirt');
  const add = shirt.getByRole('button', { name: 'Add one Short Sleeve T-Shirt', exact: true });
  for (let count = 1; count <= 3; count++) {
    await add.click();
    await expect(page.locator('[data-cart-count]')).toHaveText(String(count));
  }
  await shirt.getByLabel('Size', { exact: true }).selectOption('L');
  await shirt.getByRole('spinbutton', { name: 'Quantity', exact: true }).press('Enter');
  await expect(page.locator('[data-order-success]')).toHaveCount(0);
  await expect(shirt.getByRole('spinbutton', { name: 'Quantity', exact: true })).toHaveValue('3');
  await setQuantity(page, 'long_sleeve_shirt', 2);
  await expect(page.locator('[data-cart-count]')).toHaveText('5');
  const summary = await reviewOrder(page);
  await expect(summary.locator('[data-cart-total]')).toHaveText('5');
  await expect(summary.getByRole('link', { name: 'Edit Short Sleeve T-Shirt, quantity 3', exact: true })).toBeVisible({ timeout: 20_000 });
  await summary.getByRole('button', { name: 'Remove Short Sleeve T-Shirt from cart', exact: true }).click();
  await expect(page.locator('[data-cart-count]')).toHaveText('2');
  await expect(summary.locator('[data-cart-total]')).toHaveText('2');
  await expect(summary.getByRole('link', { name: /Edit Short Sleeve T-Shirt/ })).toHaveCount(0);
  await closeCart(page);
  await expect(shirt.getByRole('spinbutton', { name: 'Quantity', exact: true })).toHaveValue('0');
  await setQuantity(page, 'long_sleeve_shirt', 0);
  await expect(page.locator('[data-cart-count]')).toHaveText('0');
  await reviewOrder(page);
  await expect(summary.locator('.uo-summary-empty')).toBeVisible();
});

test('mobile cart traps keyboard focus, closes with Escape and restores its trigger', async ({ page }, testInfo) => {
  test.skip((testInfo.project.use.viewport?.width ?? 1440) >= 1024, 'The desktop review panel stays visible.');
  await openBuilder(page);
  await setQuantity(page, 't_shirt', 1);
  await product(page, 't_shirt').getByLabel('Size', { exact: true }).selectOption('L');
  const cartButton = page.getByRole('button', { name: 'Open cart', exact: true });
  const summary = await reviewOrder(page);
  const selectedShirt = summary.getByRole('link', { name: 'Edit Short Sleeve T-Shirt, quantity 1', exact: true });
  await expect(selectedShirt).toBeVisible({ timeout: 20_000 });
  await expect(selectedShirt).toContainText('Size: L', { timeout: 20_000 });
  const dialog = page.getByRole('dialog', { name: 'Your cart', exact: true });
  await expect.poll(() => dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
  await dialog.getByRole('button', { name: 'Close cart', exact: true }).focus();
  for (const key of ['Shift+Tab', 'Tab', 'Tab']) {
    await page.keyboard.press(key);
    expect(await dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
  }
  const accessibility = await new AxeBuilder({ page }).include('.uo-builder').analyze();
  expect(accessibility.violations.map(({ id, impact }) => ({ id, impact }))).toEqual([]);
  await expectViewportFit(page);
  await page.screenshot({ path: testInfo.outputPath('mobile-cart-checkout.png'), animations: 'disabled' });
  await page.keyboard.press('Escape');
  await expect(dialog).toHaveCount(0);
  await expect(cartButton).toBeFocused();
  await expectCatalogInteractive(page);
  await reviewOrder(page);
  await closeCart(page);
  await expect(cartButton).toBeFocused();
  await expect(page.locator('[data-cart-count]')).toHaveText('1');
});

test('crossing the tablet breakpoint releases cart focus and keeps the selection', async ({ page }, testInfo) => {
  test.skip(!['uniform-390', 'uniform-webkit-390', 'uniform-firefox-1440'].includes(testInfo.project.name), 'One breakpoint recovery check per browser engine.');
  await openBuilder(page);
  await setQuantity(page, 't_shirt', 2);
  await product(page, 't_shirt').getByLabel('Size', { exact: true }).selectOption('M');
  await page.setViewportSize({ width: 1023, height: 900 });
  await expectOrderLayout(page);
  await reviewOrder(page);
  await page.setViewportSize({ width: 1024, height: 900 });
  await expectOrderLayout(page);
  await expect(page.getByRole('dialog', { name: 'Your cart', exact: true })).toHaveCount(0);
  await expectCatalogInteractive(page);
  await expect(page.locator('[data-cart-total]')).toHaveText('2');
  await product(page, 't_shirt').getByLabel('Size', { exact: true }).selectOption('L');
  await page.setViewportSize({ width: 390, height: 844 });
  await expectOrderLayout(page);
  await expect(product(page, 't_shirt').getByLabel('Size', { exact: true })).toHaveValue('L');
  await expect(page.locator('[data-cart-count]')).toHaveText('2');
  await expectStackedProducts(page);
  await expectViewportFit(page);
  const summary = await reviewOrder(page);
  await expect(summary.getByRole('link', { name: 'Edit Short Sleeve T-Shirt, quantity 2', exact: true })).toBeVisible();
});

test('two jacket styles share one selection, retain size, and reject a second jacket before persistence', async ({ page }, testInfo) => {
  await openBuilder(page);
  const priorRequests = await page.locator('.uo-recent-request').count();
  await scrollToCategory(page, 'accessories');
  const jacket = product(page, 'jacket');
  const quantity = jacket.getByRole('spinbutton', { name: 'Quantity', exact: true });
  await expect(quantity).toHaveAttribute('max', '1');
  const styles = [
    { label: '5.11 Quarter Zip', asset: 'jacket-quarter-zip' },
    { label: '5.11 Softshell', asset: 'jacket-softshell' },
  ];
  await expect(jacket.getByRole('radio')).toHaveCount(2);
  await expect(jacket.getByRole('radio', { name: 'MBFD Vintage jacket', exact: true })).toHaveCount(0);
  await expect(jacket.locator('.uo-thumbnail img')).toHaveAttribute('src', /jacket-quarter-zip-thumb\.webp$/);
  await expect(jacket).toContainText('Support Services will confirm any earlier off-system issue history.');
  await quantity.fill('1');
  await jacket.getByLabel('Size', { exact: true }).selectOption('L');
  for (let attempt = 0; attempt < 2; attempt++) {
    await reviewOrder(page);
    await page.getByRole('button', { name: 'Submit Uniform Request', exact: true }).click();
    await expect(page.locator('.uo-input-errors')).toContainText('Check your order details');
    await expect(page.getByRole('dialog', { name: 'Your cart', exact: true })).toHaveCount(0);
    await expect(jacket.getByRole('radio').first()).toHaveAttribute('aria-invalid', 'true');
    await expect(jacket.getByRole('radio').first()).toBeFocused();
    await expect(jacket.getByRole('radio').first()).toBeInViewport();
    await expect(jacket.getByLabel('Size', { exact: true })).toHaveValue('L');
    await expect(page.locator('.uo-recent-request')).toHaveCount(priorRequests);
  }
  for (const [index, style] of styles.entries()) {
    const radio = jacket.getByRole('radio', { name: style.label, exact: true });
    await radio.check();
    await expect(radio).toBeChecked();
    await expect(quantity).toHaveValue('1');
    await expect(jacket.getByRole('button', { name: 'Add one Jacket', exact: true })).toBeDisabled();
    const size = jacket.getByLabel('Size', { exact: true });
    if (index === 0) await size.selectOption('L');
    await expect(size).toHaveValue('L');
    await expect(jacket.locator('.uo-thumbnail img')).toHaveAttribute('src', new RegExp(style.asset + '-thumb\\.webp$'), { timeout: 20_000 });
    await expect(page.locator('[data-product] img')).toHaveCount(15);
    await jacket.getByRole('button', { name: 'Enlarge Jacket image', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Jacket', exact: true });
    await expect(dialog.getByRole('img')).toHaveAttribute('src', new RegExp(style.asset + '-large\\.webp$'));
    await expect.poll(() => dialog.getByRole('img').evaluate(element => (element as HTMLImageElement).naturalWidth), { timeout: 20_000 }).toBeGreaterThan(0);
    await dialog.getByRole('button', { name: 'Close product image', exact: true }).click();
    await expect(dialog).toBeHidden();
  }
  await expectTouchTargets(page);
  await expectViewportFit(page);
  await capture(page, testInfo, 'jacket-style-selection');
  const summary = await reviewOrder(page);
  await expect(summary.getByRole('link', { name: 'Edit 5.11 Softshell, quantity 1', exact: true })).toBeVisible({ timeout: 20_000 });
  await summary.getByRole('link', { name: 'Edit 5.11 Softshell, quantity 1', exact: true }).click();
  await expect(jacket.getByLabel('Size', { exact: true })).toHaveValue('L');
  await quantity.fill('2');
  await quantity.blur();
  await reviewOrder(page);
  await page.getByRole('button', { name: 'Submit Uniform Request', exact: true }).click();
  await expect(page.locator('.uo-input-errors')).toContainText('Check your order details');
  await expect(page.getByRole('dialog', { name: 'Your cart', exact: true })).toHaveCount(0);
  await expect(quantity).toHaveAttribute('aria-invalid', 'true');
  await expect(quantity).toBeFocused();
  await expect(page.locator('[data-order-success]')).toHaveCount(0);
  await expect(page.locator('.uo-recent-request')).toHaveCount(priorRequests);
  await quantity.fill('1');
  await quantity.blur();
  await expect(quantity).toHaveAttribute('aria-invalid', 'false', { timeout: 20_000 });
  await expect(jacket.getByRole('radio', { name: '5.11 Softshell', exact: true })).toBeChecked();
  await expect(jacket.getByLabel('Size', { exact: true })).toHaveValue('L');
  await reviewOrder(page);
  await expect(page.getByRole('button', { name: 'Submit Uniform Request', exact: true })).toBeEnabled();
});

test('jumpsuit exchanges and combined T-shirt overages remain advisory', async ({ page }, testInfo) => {
  await openBuilder(page);
  await setQuantity(page, 'jumpsuit', 3);
  await reviewOrder(page);
  await expandDetails(page, 'Allocation details');
  await expect(page.locator('[data-swap-credits]')).toContainText('Jumpsuit Swap Credits: 0', { timeout: 20_000 });
  await expect(page.locator('[data-swap-credits]')).toContainText('Work-set allowance: 2');
  await setQuantity(page, 'jumpsuit', 2);
  await reviewOrder(page);
  await expandDetails(page, 'Allocation details');
  await expect(page.locator('[data-swap-credits]')).toContainText('Jumpsuit Swap Credits: 1', { timeout: 20_000 });
  await expect(page.locator('[data-swap-credits]')).toContainText('Work-set allowance: 3');
  await setQuantity(page, 't_shirt', 3);
  await product(page, 't_shirt').getByLabel('Size', { exact: false }).selectOption('L');
  await setQuantity(page, 'long_sleeve_shirt', 2);
  await product(page, 'long_sleeve_shirt').getByLabel('Size', { exact: false }).selectOption('M');
  const summary = await reviewOrder(page);
  await expandDetails(page, 'Allocation details');
  await expect(summary).toContainText(/5 selected|5.*4/);
  await expect(page.getByText(/outside your standard annual allocation/i)).toBeVisible();
  await expect(page.getByRole('button', { name: 'Submit Uniform Request', exact: true })).toBeEnabled();
  await setQuantity(page, 'uniform_pants', 1);
  await product(page, 'uniform_pants').getByLabel('Waist (inches)', { exact: false }).fill('34');
  await product(page, 'uniform_pants').getByLabel('Inseam (inches)', { exact: false }).fill('32');
  await reviewOrder(page);
  await expandDetails(page, 'Allocation details');
  await expect(summary).toContainText(/quantities differ|one polo.*one pair/i, { timeout: 20_000 });
  await expectViewportFit(page);
  await capture(page, testInfo, 'selected-sizing-and-advisories');
});

test('invalid procurement measurements close the cart, focus the field and preserve the selection', async ({ page }, testInfo) => {
  await openBuilder(page);
  const priorRequests = await page.locator('.uo-recent-request').count();
  await reviewOrder(page);
  await page.getByRole('button', { name: 'Submit Uniform Request', exact: true }).click();
  await expect(page.locator('.uo-input-errors')).toContainText('Check your order details');
  await expect(page.locator('[data-order-success]')).toHaveCount(0);
  await setQuantity(page, 'uniform_pants', 1);
  const pants = product(page, 'uniform_pants');
  const waist = pants.getByLabel('Waist (inches)', { exact: false });
  await waist.fill('0');
  await pants.getByLabel('Inseam (inches)', { exact: false }).fill('32');
  await scrollToCategory(page, 'tshirts');
  await reviewOrder(page);
  await page.getByRole('button', { name: 'Submit Uniform Request', exact: true }).click();
  await expect(page.locator('.uo-input-errors')).toContainText('Check your order details');
  await expect(page.getByRole('dialog', { name: 'Your cart', exact: true })).toHaveCount(0);
  await expect(waist).toBeVisible();
  await expect(waist).toHaveAttribute('aria-invalid', 'true');
  await expect(waist).toBeFocused();
  await expect(waist).toBeInViewport();
  await expect(pants.getByRole('spinbutton', { name: 'Quantity', exact: true })).toHaveValue('1');
  await expect(page.locator('.uo-recent-request')).toHaveCount(priorRequests);
  await expect(page.locator('[data-order-success]')).toHaveCount(0);
  await expectViewportFit(page);
  await capture(page, testInfo, 'invalid-measurement-preserved');
});

test('over-allocation order persists structured sizes, note, My Requests and the existing admin queue', async ({ page, browser, baseURL }, testInfo) => {
  test.skip(testInfo.project.name !== 'uniform-1440', 'One real request in the disposable browser database.');
  test.setTimeout(120_000);
  await openBuilder(page, 'BID-E2E-W07');
  const note = '[QA TEST] Five T-shirts and replacement trousers for Support Services review.';
  await setQuantity(page, 't_shirt', 5);
  await product(page, 't_shirt').getByLabel('Size', { exact: false }).selectOption('L');
  await setQuantity(page, 'uniform_pants', 1);
  await product(page, 'uniform_pants').getByLabel('Waist (inches)', { exact: false }).fill('34');
  await product(page, 'uniform_pants').getByLabel('Inseam (inches)', { exact: false }).fill('32');
  await product(page, 'uniform_pants').getByLabel('Requested cut', { exact: false }).selectOption('mens');
  await scrollToCategory(page, 'accessories');
  await product(page, 'jacket').getByRole('radio', { name: '5.11 Softshell', exact: true }).check();
  await product(page, 'jacket').getByLabel('Size', { exact: true }).selectOption('L');
  await reviewOrder(page);
  await expandDetails(page, 'Notes for Support Services (optional)');
  await page.getByRole('textbox', { name: /Notes for Support Services/ }).fill(note);
  await expect(page.getByText(/outside your standard annual allocation/i)).toBeVisible();
  await page.getByRole('button', { name: 'Submit Uniform Request', exact: true }).click();
  await expect(page.locator('[data-order-success]')).toBeVisible({ timeout: 20_000 });
  await reviewOrder(page);
  await expandDetails(page, 'Recent requests');
  const recent = page.locator('.uo-recent-request').first();
  await expect(recent).toContainText('Pending', { timeout: 20_000 });
  const memberPath = (await recent.getAttribute('href'))!;
  expect(memberPath).toMatch(/^\/employee\/my-requests\/[0-9A-Z]{26}$/);
  const publicId = memberPath.split('/').at(-1)!;
  await page.goto('/employee/my-requests');
  await expect(page.locator('a[href="' + memberPath + '"]').first()).toBeVisible();
  await page.goto(memberPath);
  await expect(page.getByText('Pending', { exact: true }).first()).toBeVisible();
  await expect(page.getByText(note, { exact: true })).toBeVisible();
  await expect(page.getByText(/Size.*34.*32/).first()).toBeVisible();
  await expect(page.locator('[data-request-item]').filter({ hasText: '5.11 Softshell' })).toContainText('× 1');
  await capture(page, testInfo, 'member-request-readback');

  const context = await browser.newContext({ baseURL, viewport: { width: 1440, height: 1000 } });
  try {
    const admin = await context.newPage();
    await login(admin, '99003', true);
    await admin.goto('/admin/personnel-uniforms-equipment/personnel-requests');
    await expect(admin.locator('a[href$="/' + publicId + '"]').first()).toBeVisible();
    await admin.goto('/admin/personnel-uniforms-equipment/personnel-requests/' + publicId);
    await expect(admin.getByText(note, { exact: true })).toBeVisible();
    await expect(admin.getByText('Rescue 1', { exact: true }).first()).toBeVisible();
    await expect(admin.getByText(/Waist.*34/).first()).toBeVisible();
    await expect(admin.getByText(/Inseam.*32/).first()).toBeVisible();
    await expect(admin.getByText('5.11 Softshell', { exact: true }).first()).toBeVisible();
    await expect(admin.getByRole('button', { name: 'Acknowledge', exact: true })).toBeVisible();
    await expectViewportFit(admin);
    await capture(admin, testInfo, 'admin-request-readback');
    await testInfo.attach('uniform-request-identity', { body: JSON.stringify({ memberPath, publicId }), contentType: 'application/json' });
  } finally {
    await context.close();
  }
  await page.goto('/employee/request-equipment');
  await scrollToCategory(page, 'accessories');
  const jacket = product(page, 'jacket');
  await expect(jacket).toContainText('You already have a jacket request awaiting issue.');
  await expect(jacket.getByRole('spinbutton', { name: 'Quantity', exact: true })).toBeDisabled();
  for (const radio of await jacket.getByRole('radio').all()) await expect(radio).toBeDisabled();
});
