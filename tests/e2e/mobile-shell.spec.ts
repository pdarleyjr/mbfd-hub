import { expect, test, type Locator, type Page } from '@playwright/test';

async function loginHome(page: Page, member = false): Promise<void> {
  const passwordName = member ? 'PERSONNEL_REQUESTS_E2E_MEMBER_PASSWORD' : 'PERSONNEL_REQUESTS_E2E_ADMIN_PASSWORD';
  const password = process.env[passwordName];
  if (!password) throw new Error(`${passwordName} must be supplied by the personnel-request Playwright configuration.`);
  await page.goto('/login');
  await page.getByLabel('Employee ID').fill(member ? '99002' : '99003');
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/$/);
  await expect(page.locator('[data-hub-shell]')).toBeVisible();
}

async function activate(target: Locator, touch: boolean): Promise<void> {
  if (touch) await target.tap();
  else await target.click();
}

for (const menuName of ['Apps', 'More']) {
  test(`Home -> ${menuName} -> Admin performs native navigation`, async ({ page, isMobile, hasTouch }) => {
    test.skip(menuName === 'More' && !isMobile, 'The bottom More menu is a phone destination.');
    const errors: string[] = [];
    const failedResponses: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => {
      if ([401, 403, 500].includes(response.status())) failedResponses.push(`${response.status()} ${new URL(response.url()).pathname}`);
    });
    await loginHome(page);
    const menu = page.locator(menuName === 'Apps' ? '.hub-app-switcher' : '.hub-member-nav__more');
    await activate(menu.locator('summary'), hasTouch);
    const admin = menu.getByRole('menuitem', { name: 'Admin', exact: true });
    await expect(admin).toBeVisible();
    await expect(admin).toHaveAttribute('href', '/admin');
    await activate(admin, hasTouch);
    await expect(page).toHaveURL(/\/admin\/?$/, { timeout: 15_000 });
    await expect(page.locator('.fi-main')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Station Operations Command Board', exact: true })).toBeVisible();
    await expect(page.locator('[data-admin-status-bar]').first()).toBeAttached();
    expect(failedResponses).toEqual([]);
    expect(errors).toEqual([]);
  });
}

test('a member sees no Admin destination and keeps all operational form access', async ({ page }) => {
  await loginHome(page, true);
  await expect(page.locator('[data-hub-menu] a[href="/admin"]')).toHaveCount(0);
  await expect(page.getByRole('link', { name: /ICS Forms/ })).toBeVisible();
  await expect(page.getByRole('link', { name: /ICS Forms/ })).toHaveAttribute('href', /\/employee\/forms$/);
});

test('phone Home has the official brand, Stations action and five comfortable navigation items', async ({ page, isMobile }, testInfo) => {
  test.skip(!isMobile, 'Phone layout is verified in the Chromium and true WebKit phone projects.');
  await loginHome(page);
  for (const width of [320, 375, 390, 430]) {
    await page.setViewportSize({ width, height: 844 });
    const brand = page.getByRole('link', { name: 'MBFD Hub home', exact: true });
    await expect(brand).toHaveText('Hub');
    const logo = brand.locator('img');
    await expect(logo).toBeVisible();
    await expect(logo).toHaveAttribute('src', '/images/mbfd-official-seal-256.png');
    await expect.poll(() => logo.evaluate(image => (image as HTMLImageElement).naturalWidth)).toBe(256);
    const stations = page.getByRole('link', { name: 'Stations', exact: true });
    await expect(stations).toBeVisible();
    await expect(stations).toHaveAttribute('href', /\/daily\/stations$/);
    const navigation = page.getByRole('navigation', { name: 'Member navigation', exact: true });
    await expect(navigation).toBeVisible();
    await expect(navigation.locator('.hub-member-nav__item > span:last-child')).toHaveText(['Home', 'Checkout', 'Employee', 'Requests', 'More']);
    await expect(navigation.getByRole('link', { name: 'Employee', exact: true })).toHaveAttribute('href', '/employee');
    const icon = navigation.getByRole('link', { name: 'Checkout', exact: true }).locator('.hub-checkout-icon');
    await expect(icon).toHaveAttribute('aria-hidden', 'true');
    expect(await icon.evaluate(element => getComputedStyle(element).maskImage)).toContain('/images/icons/checkout-apparatus.svg');
    for (const target of await navigation.locator('.hub-member-nav__item').evaluateAll(elements => elements.map(element => {
      const rect = element.getBoundingClientRect();
      return { width: rect.width, height: rect.height, fontSize: getComputedStyle(element).fontSize };
    }))) {
      expect(target.width).toBeGreaterThanOrEqual(44);
      expect(target.height).toBeGreaterThanOrEqual(64);
      expect(target.height).toBeLessThanOrEqual(66);
      expect(target.fontSize).toBe('12px');
    }
    expect(await icon.evaluate(element => element.getBoundingClientRect().width)).toBe(24);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);
    expect(await page.evaluate(() => parseFloat(getComputedStyle(document.body).paddingBottom))).toBeGreaterThanOrEqual(66);
    await page.screenshot({ path: testInfo.outputPath(`home-${width}-viewport.png`) });
    await page.screenshot({ path: testInfo.outputPath(`home-${width}.png`), fullPage: true });
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    expect(await page.locator('.home-footer').evaluate(element => element.getBoundingClientRect().bottom)).toBeLessThanOrEqual((await navigation.boundingBox())!.y);
    await page.evaluate(() => window.scrollTo(0, 0));
  }
});

test('desktop Home retains the Hub brand and Stations primary action', async ({ page, isMobile }, testInfo) => {
  test.skip(isMobile, 'Desktop layout is verified in the desktop project.');
  await loginHome(page);
  await expect(page.getByRole('link', { name: 'MBFD Hub home', exact: true })).toHaveText('Hub');
  await expect(page.locator('.hub-shell-brand img')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Stations', exact: true })).toHaveAttribute('href', /\/daily\/stations$/);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);
  await page.screenshot({ path: testInfo.outputPath('home-desktop.png'), fullPage: true });
});

test('menus still close with Escape and outside clicks', async ({ page, hasTouch }) => {
  await loginHome(page);
  const menu = page.locator('.hub-app-switcher');
  await activate(menu.locator('summary'), hasTouch);
  await page.getByRole('menuitem', { name: 'Admin', exact: true }).first().focus();
  await page.keyboard.press('Escape');
  await expect(menu).not.toHaveAttribute('open');
  await expect(menu.locator('summary')).toBeFocused();
  await activate(menu.locator('summary'), hasTouch);
  await activate(page.getByRole('heading', { name: /Good (morning|afternoon|evening)/ }), hasTouch);
  await expect(menu).not.toHaveAttribute('open');
});

test('Daily shares the icon and Employee destination while connected-only navigation explains offline state', async ({ page, context, isMobile, hasTouch }, testInfo) => {
  test.skip(!isMobile, 'Daily bottom navigation is a phone destination.');
  await loginHome(page, true);
  await page.goto('/daily/stations');
  await expect(page.getByRole('heading', { name: 'MBFD Stations', exact: true })).toBeVisible();
  const navigation = page.getByRole('navigation', { name: 'Hub member navigation', exact: true });
  await expect(navigation.locator('.hub-member-navigation-item > span:last-child, .hub-member-more > button > span')).toHaveText(['Home', 'Checkout', 'Employee', 'Requests', 'More']);
  const icon = navigation.getByRole('link', { name: 'Checkout', exact: true }).locator('.hub-checkout-icon');
  expect(await icon.evaluate(element => getComputedStyle(element).maskImage)).toContain('/images/icons/checkout-apparatus.svg');
  for (const width of [320, 375, 390, 430]) {
    await page.setViewportSize({ width, height: 844 });
    const brand = page.getByRole('link', { name: 'MBFD Hub home', exact: true });
    await expect(brand).toHaveText('Hub');
    await expect(brand.locator('img')).toBeVisible();
    for (const target of await navigation.locator('.hub-member-navigation-item, .hub-member-more > button').evaluateAll(elements => elements.map(element => {
      const rect = element.getBoundingClientRect();
      return { width: rect.width, height: rect.height, fontSize: getComputedStyle(element).fontSize };
    }))) {
      expect(target.width).toBeGreaterThanOrEqual(44);
      expect(target.height).toBeGreaterThanOrEqual(64);
      expect(target.height).toBeLessThanOrEqual(66);
      expect(target.fontSize).toBe('12px');
    }
    expect(await icon.evaluate(element => element.getBoundingClientRect().width)).toBe(24);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath(`daily-shell-${width}.png`), fullPage: true });
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    expect(await page.locator('[data-testid="daily-workspace"]').evaluate(element => element.getBoundingClientRect().bottom)).toBeLessThanOrEqual((await navigation.boundingBox())!.y + 1);
    await page.evaluate(() => window.scrollTo(0, 0));
  }
  await context.setOffline(true);
  await activate(navigation.getByRole('link', { name: 'Employee', exact: true }), hasTouch);
  await expect(page.locator('[data-hub-navigation-notice]')).toHaveText('Requires connection');
  await expect(page.locator('[data-hub-navigation-notice]')).toBeVisible();
  await expect(page).toHaveURL(/\/daily\/stations$/);
  await page.screenshot({ path: testInfo.outputPath('daily-offline-employee.png') });
  await context.setOffline(false);
  await expect(page.locator('[data-hub-navigation-notice]')).toHaveCount(0);
  await activate(navigation.getByRole('link', { name: 'Employee', exact: true }), hasTouch);
  await expect(page).toHaveURL(/\/employee\/dashboard$/);
  await expect(page.locator('.fi-main')).toBeVisible();
});

test('Home Employee and Requests links open the signed-in member destinations', async ({ page, isMobile, hasTouch }) => {
  test.skip(!isMobile, 'Home bottom navigation is a phone destination.');
  await loginHome(page, true);
  const navigation = page.getByRole('navigation', { name: 'Member navigation', exact: true });
  await activate(navigation.getByRole('link', { name: 'Employee', exact: true }), hasTouch);
  await expect(page).toHaveURL(/\/employee\/dashboard$/);
  await expect(page.locator('.fi-main')).toBeVisible();
  await expect(navigation.locator('[aria-current="page"]')).toHaveCount(1);
  await expect(navigation.getByRole('link', { name: 'Employee', exact: true })).toHaveAttribute('aria-current', 'page');
  await page.goto('/');
  await activate(navigation.getByRole('link', { name: 'Requests', exact: true }), hasTouch);
  await expect(page).toHaveURL(/\/employee\/my-requests$/);
  await expect(page.getByRole('heading', { name: 'My Requests', exact: true })).toBeVisible();
  await expect(navigation.locator('[aria-current="page"]')).toHaveCount(1);
  await expect(navigation.getByRole('link', { name: 'Requests', exact: true })).toHaveAttribute('aria-current', 'page');
});

test('Daily fallback retains Employee when the navigation bootstrap is absent', async ({ page, isMobile }) => {
  test.skip(!isMobile, 'Daily bottom navigation is a phone destination.');
  await loginHome(page, true);
  await page.route('**/daily/stations', async route => {
    const response = await route.fetch();
    const body = await response.text();
    expect(body).toContain('window.__MBFD_HUB_NAVIGATION__ = ');
    await route.fulfill({ response, body: body.replace(/window\.__MBFD_HUB_NAVIGATION__ = [^;]+;/, '') });
  });
  await page.goto('/daily/stations');
  const navigation = page.getByRole('navigation', { name: 'Hub member navigation', exact: true });
  await expect(navigation.locator('.hub-member-navigation-item > span:last-child, .hub-member-more > button > span')).toHaveText(['Home', 'Checkout', 'Employee', 'Requests', 'More']);
  await expect(navigation.getByRole('link', { name: 'Employee', exact: true })).toHaveAttribute('href', '/employee');
  await expect(navigation.getByRole('link', { name: 'Requests', exact: true })).toHaveAttribute('href', '/employee/my-requests');
});
