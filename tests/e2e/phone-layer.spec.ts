import { expect, test, type Page } from '@playwright/test';

/*
 * Phone layer contract (WP5), at 390x844:
 *   npx playwright test -c playwright.shell-alignment.config.ts phone-layer --project=vp-390x844
 * "More" is a bottom sheet, the drawer starts closed on every panel, and create pages keep one
 * sticky action bar above the bottom navigation.
 */

test.beforeEach(({}, testInfo) => {
  test.skip(testInfo.project.name !== 'vp-390x844', 'The phone layer is verified at 390x844.');
});

async function openMore(page: Page) {
  await page.goto('/', { waitUntil: 'load' });
  const more = page.locator('.hub-member-nav__more');
  await expect(more).toBeVisible();
  await more.locator('summary').click();
  await expect(more).toHaveAttribute('open', '');
  return more;
}

test('More opens a bottom sheet that fills the width, caps at 80% height and holds focus', async ({ page }, testInfo) => {
  const more = await openMore(page);
  const sheet = more.locator('.hub-shell-popover');
  await expect(sheet).toBeVisible();
  await page.evaluate(() => Promise.all(document.getAnimations().filter(animation => animation.effect?.getComputedTiming().iterations !== Infinity).map(animation => animation.finished.catch(() => undefined))).then(() => undefined));

  const geometry = await sheet.evaluate(element => {
    const box = element.getBoundingClientRect();
    const style = getComputedStyle(element);
    return { left: box.left, right: box.right, bottom: box.bottom, height: box.height, viewportWidth: innerWidth, viewportHeight: innerHeight, radius: style.borderTopLeftRadius, position: style.position };
  });
  await page.screenshot({ path: testInfo.outputPath('more-sheet-open.png') });
  expect(geometry.position).toBe('fixed');
  expect(geometry.left).toBeLessThanOrEqual(0.5);
  expect(geometry.right).toBeGreaterThanOrEqual(geometry.viewportWidth - 0.5);
  expect(Math.abs(geometry.bottom - geometry.viewportHeight)).toBeLessThanOrEqual(1);
  expect(geometry.height).toBeLessThanOrEqual(geometry.viewportHeight * 0.8 + 1);
  expect(geometry.radius).toBe('16px');

  // Opening moves focus to the first destination, and Tab never leaves the sheet.
  await expect(sheet.getByRole('menuitem').first()).toBeFocused();
  const items = await sheet.getByRole('menuitem').count();
  for (let step = 0; step < items + 3; step += 1) {
    await page.keyboard.press('Tab');
    expect(await page.evaluate(() => document.activeElement?.closest('.hub-member-nav__more') !== null), `Tab ${step + 1} stayed in the sheet`).toBe(true);
  }
  for (let step = 0; step < items + 3; step += 1) {
    await page.keyboard.press('Shift+Tab');
    expect(await page.evaluate(() => document.activeElement?.closest('.hub-member-nav__more') !== null), `Shift+Tab ${step + 1} stayed in the sheet`).toBe(true);
  }
  expect(await page.evaluate(() => getComputedStyle(document.body).overflow)).toBe('hidden');
});

test('Escape and the backdrop close the sheet and return focus to More', async ({ page }) => {
  const more = await openMore(page);
  await page.keyboard.press('Escape');
  await expect(more).not.toHaveAttribute('open', '');
  await expect(more.locator('summary')).toBeFocused();

  await more.locator('summary').click();
  await expect(more).toHaveAttribute('open', '');
  // The backdrop covers the page above the sheet; tap near the top of the screen.
  await page.mouse.click(195, 60);
  await expect(more).not.toHaveAttribute('open', '');
  await expect(more.locator('summary')).toBeFocused();
});

test('every panel starts a first-time phone visit with the drawer closed', async ({ page }) => {
  await page.addInitScript(() => {
    try { localStorage.clear(); } catch { /* first-visit state */ }
  });
  for (const path of ['/admin', '/employee/dashboard', '/training', '/workgroups']) {
    await page.goto(path, { waitUntil: 'load' });
    // The drawer settles through a short transition; poll the state that matters instead of waiting on animations.
    await expect.poll(async () => page.evaluate(() => {
      const sidebar = document.querySelector('.fi-sidebar');
      const right = sidebar?.getBoundingClientRect().right ?? 0;
      return { open: sidebar?.classList.contains('fi-sidebar-open') ?? false, offCanvas: right <= 1 };
    }), { message: `${path}: the drawer must start closed and off-canvas on a phone`, timeout: 8_000 }).toEqual({ open: false, offCanvas: true });
  }
});
test('create pages keep one sticky action bar above the bottom navigation', async ({ page }) => {
  await page.goto('/training/training-todos/create', { waitUntil: 'load' });
  const actions = page.locator('.fi-resource-create-record-page .fi-form-actions').first();
  await expect(actions).toBeVisible();
  const measured = await actions.evaluate(element => {
    const box = element.getBoundingClientRect();
    const nav = document.querySelector('.hub-member-nav, .hub-member-navigation')?.getBoundingClientRect();
    return { position: getComputedStyle(element).position, bottom: box.bottom, navTop: nav?.top ?? null, viewportHeight: innerHeight };
  });
  expect(measured.position).toBe('sticky');
  // Scrolled to the top of a long form it still sits in view, above the bottom navigation.
  expect(measured.bottom).toBeLessThanOrEqual((measured.navTop ?? measured.viewportHeight) + 0.5);
});
