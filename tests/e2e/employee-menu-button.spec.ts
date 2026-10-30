import { expect, test, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/*
 * The Employee Portal "Menu" button: the control in the navy top bar that opens the Employee navigation drawer
 * on phones and tablets. It must read as a button (visible surface, high-contrast bars, a text label when there
 * is room), be a full touch target, and never collide with the Employee switcher, the notifications bell or the
 * bottom navigation.
 *
 *   HOME_NAV_LABEL=before|after   names the screenshot (default "after")
 *   HOME_NAV_SHOTS_DIR            folder for screenshots (default test-results/home-nav-shots)
 *   HOME_NAV_MODE=measure         record geometry and screenshots without asserting
 */

const label = process.env.HOME_NAV_LABEL ?? 'after';
const measureOnly = process.env.HOME_NAV_MODE === 'measure';
const authDir = process.env.EMPLOYEE_LAYOUT_AUTH_DIR ?? join(tmpdir(), 'mbfd-employee-layout-auth');
const shotsDir = process.env.HOME_NAV_SHOTS_DIR ?? join('test-results', 'home-nav-shots');

test.use({ storageState: join(authDir, 'member.json') });

type Box = { left: number; top: number; right: number; bottom: number; width: number; height: number };

async function box(page: Page, selector: string): Promise<Box | null> {
  return page.evaluate(sel => {
    const element = document.querySelector(sel);
    if (!(element instanceof HTMLElement)) return null;
    const r = element.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) return null;
    return { left: r.left, top: r.top, right: r.right, bottom: r.bottom, width: r.width, height: r.height };
  }, selector);
}

const overlaps = (a: Box, b: Box) => a.left < b.right - 0.5 && b.left < a.right - 0.5 && a.top < b.bottom - 0.5 && b.top < a.bottom - 0.5;

test('the Employee Menu button is recognisable and does not collide with its neighbours', async ({ page }, testInfo) => {
  const width = testInfo.project.use.viewport!.width;
  test.skip(width >= 1024, 'The Menu button is the phone and tablet control; wide screens show the sidebar.');

  await page.goto('/employee/dashboard');
  const button = page.locator('.fi-topbar-open-sidebar-btn');
  await expect(button).toBeVisible();
  // The bell is lazy-loaded by Livewire; give it a moment so the collision check has something to measure.
  await page.locator('.fi-topbar .fi-topbar-database-notifications-btn').waitFor({ state: 'visible', timeout: 8_000 }).catch(() => undefined);

  mkdirSync(shotsDir, { recursive: true });
  await page.locator('.fi-topbar').screenshot({ path: join(shotsDir, `employee-topbar-${label}-${width}.png`) });
  await page.screenshot({ path: join(shotsDir, `employee-page-${label}-${width}.png`) });

  const geometry = {
    button: await box(page, '.fi-topbar-open-sidebar-btn'),
    switcher: await box(page, '.fi-topbar .hub-app-switcher'),
    bell: await box(page, '.fi-topbar .fi-topbar-database-notifications-btn'),
    account: await box(page, '.fi-topbar .fi-user-menu-trigger, .fi-topbar .fi-dropdown-trigger'),
    bottomNav: await box(page, '.hub-member-nav, .hub-member-navigation'),
    topbar: await box(page, '.fi-topbar'),
  };
  testInfo.annotations.push({ type: 'geometry', description: JSON.stringify(geometry) });
  console.log(`[${label} ${width}px] ${JSON.stringify(geometry)}`);
  if (measureOnly) return;

  // Accessible name, state and relationship.
  await expect(button).toHaveAttribute('aria-label', /menu/i);
  await expect(button).toHaveAttribute('aria-expanded', 'false');
  const controls = await button.getAttribute('aria-controls');
  expect(controls).toBeTruthy();
  await expect(page.locator(`#${controls}`)).toHaveCount(1);

  // Touch target and layout.
  const b = geometry.button!;
  expect(b.width).toBeGreaterThanOrEqual(44);
  expect(b.height).toBeGreaterThanOrEqual(44);
  expect(b.left).toBeGreaterThanOrEqual(0);
  expect(b.right).toBeLessThanOrEqual(width);
  for (const [name, other] of Object.entries({ switcher: geometry.switcher, bell: geometry.bell, account: geometry.account, bottomNav: geometry.bottomNav })) {
    if (other) expect(overlaps(b, other), `Menu button overlaps ${name}`).toBe(false);
  }
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);

  // A visible surface and border, high-contrast bars, and a visible text label when the bar has room.
  const style = await button.evaluate(element => {
    const css = getComputedStyle(element);
    const svg = element.querySelector('svg');
    const text = element.querySelector('[data-menu-label]');
    return {
      background: css.backgroundColor,
      borderWidth: css.borderTopWidth,
      borderColor: css.borderTopColor,
      borderRadius: css.borderTopLeftRadius,
      iconColor: svg ? getComputedStyle(svg).color : '',
      iconStroke: svg ? getComputedStyle(svg).strokeWidth : '',
      labelDisplay: text ? getComputedStyle(text).display : 'none',
      labelText: text?.textContent?.trim() ?? '',
    };
  });
  expect(style.background).not.toBe('rgba(0, 0, 0, 0)');
  expect(parseFloat(style.borderWidth)).toBeGreaterThanOrEqual(1);
  expect(parseFloat(style.borderRadius)).toBeGreaterThanOrEqual(8);
  expect(parseFloat(style.iconStroke)).toBeGreaterThanOrEqual(2);
  expect(style.iconColor).toBe('rgb(255, 255, 255)');
  expect(style.labelText).toBe('Menu');
  if (width >= 360) expect(style.labelDisplay).not.toBe('none');
  else expect(style.labelDisplay).toBe('none');

  // The bars contrast with the navy bar by at least 3:1 (non-text contrast).
  const ratio = await page.evaluate(() => {
    const parse = (value: string) => (value.match(/[\d.]+/g) ?? []).slice(0, 3).map(Number);
    const luminance = ([r, g, b]: number[]) => {
      const [R, G, B] = [r, g, b].map(v => { const c = v / 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; });
      return 0.2126 * R + 0.7152 * G + 0.0722 * B;
    };
    const icon = document.querySelector('.fi-topbar-open-sidebar-btn svg')!;
    const bar = document.querySelector('.fi-topbar > nav')!;
    const hi = luminance(parse(getComputedStyle(icon).color));
    const lo = luminance(parse(getComputedStyle(bar).backgroundColor));
    return (Math.max(hi, lo) + 0.05) / (Math.min(hi, lo) + 0.05);
  });
  expect(ratio).toBeGreaterThanOrEqual(3);

  // Visible keyboard focus.
  await button.focus();
  const focus = await button.evaluate(element => ({ outline: getComputedStyle(element).outlineStyle, width: getComputedStyle(element).outlineWidth }));
  expect(focus.outline).not.toBe('none');
  expect(parseFloat(focus.width)).toBeGreaterThanOrEqual(2);

  // It opens the drawer and reports the state.
  await button.click();
  const sidebar = page.locator(`#${controls}`);
  await expect(sidebar).toBeInViewport();
  await page.locator('.fi-topbar-close-sidebar-btn, .fi-sidebar-close-overlay').first().waitFor({ state: 'attached' });
});

test('the Menu button keeps the sidebar toggle on wide screens hidden', async ({ page }, testInfo) => {
  test.skip(testInfo.project.use.viewport!.width < 1024, 'Wide screens only.');
  await page.goto('/employee/dashboard');
  await expect(page.locator('.fi-topbar-open-sidebar-btn')).toBeHidden();
  await expect(page.locator('.fi-sidebar')).toBeVisible();
});
