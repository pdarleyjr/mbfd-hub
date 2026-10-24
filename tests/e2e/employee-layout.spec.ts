import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/*
 * Employee Portal page frame: no dead space above the first content block.
 *
 * For each Employee page and viewport this measures how far down the first content block starts, checks
 * that the page header, Back and Hub Home share one row, that nothing overflows horizontally and that
 * the touch targets stay at least 44px. A second group emulates an installed (standalone) window by
 * giving the safe-area tokens a notch inset and by applying the stylesheet's own display-mode rules.
 *
 *   EMPLOYEE_LAYOUT_LABEL=before|after   names the screenshot folder (default "after")
 *   EMPLOYEE_LAYOUT_MODE=measure         record offsets and screenshots without asserting the budgets
 *   EMPLOYEE_LAYOUT_SHOTS_DIR            root folder for screenshots and offsets-<label>.json
 */

const label = process.env.EMPLOYEE_LAYOUT_LABEL ?? 'after';
const measureOnly = process.env.EMPLOYEE_LAYOUT_MODE === 'measure';
// Sign-in state lives outside test-results/ so another Playwright run that cleans that folder cannot delete it.
const authDir = process.env.EMPLOYEE_LAYOUT_AUTH_DIR ?? join(tmpdir(), 'mbfd-employee-layout-auth');
const shotsRoot = process.env.EMPLOYEE_LAYOUT_SHOTS_DIR ?? join('test-results', 'employee-layout-shots');

/** First content block budget in px: header band (bar + title row + gaps). Phones are tight; from 640px up (tablet, desktop) the page tokens and 24px titles grow, and a two-line title still has to fit. */
const BUDGET_NARROW_PX = 150;
const BUDGET_WIDE_PX = 170;
const NOTCH_INSET_PX = 47;
const TOUCH_TARGET_PX = 44;

/** bleed: the page is a full-bleed React workspace with its own bars (no Filament top bar, sidebar or H1). */
type EmployeePage = { name: string; path: string; user: 'member' | 'officer'; bleed?: true };

const pages: EmployeePage[] = [
  { name: 'dashboard', path: '/employee/dashboard', user: 'member' },
  { name: 'my-equipment', path: '/employee/my-equipment-page', user: 'member' },
  { name: 'my-requests', path: '/employee/my-requests', user: 'member' },
  { name: 'request-uniforms', path: '/employee/request-equipment', user: 'member' },
  { name: 'apparatus-service', path: '/employee/apparatus-service-request', user: 'member' },
  { name: 'vehicle-inspections', path: '/employee/my-vehicle-inspections', user: 'member' },
  { name: 'training-history', path: '/employee/training-history', user: 'member' },
  { name: 'forms', path: '/employee/forms', user: 'member', bleed: true },
  { name: 'officer-ppe', path: '/employee/personnel-equipment-request', user: 'officer' },
];

type Frame = {
  barBottom: number;
  topbarHeight: number;
  sidebarHeaderHeight: number | null;
  firstContentTop: number | null;
  firstContentDescriptor: string;
  headingCount: number;
  headingTop: number | null;
  headingBottom: number | null;
  navTop: number | null;
  navBottom: number | null;
  backCount: number;
  homeCount: number;
  backHeight: number | null;
  homeHeight: number | null;
  homeWidth: number | null;
  backCenter: number | null;
  headingCenter: number | null;
  overflowX: boolean;
  mainPaddingLeft: number;
  mainPaddingRight: number;
  topbarPaddingTop: number;
  topbarNavTop: number;
};

async function settle(page: Page): Promise<void> {
  await page.evaluate(() => document.fonts.ready.then(() => undefined));
  // A first-time phone visitor sees Filament's drawer open; close it the way a member does, then wait for it to rest.
  const overlay = page.locator('.fi-sidebar-close-overlay');
  if (await overlay.isVisible().catch(() => false)) {
    const size = page.viewportSize()!;
    await page.mouse.click(size.width - 6, size.height - 6);
  }
  for (let round = 0; round < 3; round += 1) {
    await page.evaluate(() => new Promise<void>(resolve => requestAnimationFrame(() => resolve())));
    await page.evaluate(() => Promise.all(document.getAnimations()
      .filter(animation => animation.effect?.getComputedTiming().iterations !== Infinity)
      .map(animation => animation.finished.catch(() => undefined))).then(() => undefined));
  }
}

async function open(page: Page, path: string, bleed = false): Promise<void> {
  const response = await page.goto(path, { waitUntil: 'load' });
  expect(response?.status(), `${path} must render for the seeded member`).toBe(200);
  if (!bleed) await expect(page.locator('.fi-topbar > nav')).toBeVisible();
  await expect(page.locator('main .fi-page')).toBeVisible();
  await settle(page);
}

/** Reads the frame geometry from the live layout. */
async function measure(page: Page): Promise<Frame> {
  return page.evaluate(() => {
    const rect = (element: Element | null) => (element ? element.getBoundingClientRect() : null);
    const visible = (element: Element): boolean => {
      const box = element.getBoundingClientRect();
      const style = getComputedStyle(element);
      return box.width > 0 && box.height > 0 && style.display !== 'none' && style.visibility !== 'hidden' && style.position !== 'fixed';
    };
    // Descend through display: contents wrappers (widget and Livewire shells) to the first painted block.
    const firstBlock = (parent: Element): Element | null => {
      for (const child of Array.from(parent.children)) {
        if (child.tagName === 'SCRIPT' || child.tagName === 'STYLE' || child.tagName === 'TEMPLATE') continue;
        if (getComputedStyle(child).display === 'contents') {
          const inner = firstBlock(child);
          if (inner) return inner;
          continue;
        }
        if (visible(child)) return child;
      }
      return null;
    };
    // Filament wraps page content in section > div > div.grid.auto-cols-fr (header widgets, then the page view).
    const body = document.querySelector('main .fi-page .auto-cols-fr');
    const first = body ? firstBlock(body) : null;
    const topbar = document.querySelector('.fi-topbar');
    const nav = document.querySelector('.fi-page > .hub-page-nav');
    const heading = document.querySelector('main h1.fi-header-heading');
    const back = document.querySelector('[data-hub-back]');
    const home = document.querySelector('[data-hub-home]');
    const sidebarHeader = document.querySelector('.fi-sidebar-header');
    const main = document.querySelector('main.fi-main')!;
    const mainStyle = getComputedStyle(main);
    const topbarStyle = getComputedStyle(topbar!);
    const topbarNav = topbar!.querySelector(':scope > nav');
    const sidebarBox = rect(sidebarHeader);
    const sidebarOnScreen = sidebarBox !== null && sidebarBox.right > 0 && sidebarBox.left < innerWidth;
    const center = (box: DOMRect | null) => (box ? box.top + box.height / 2 : null);
    return {
      barBottom: rect(topbar)!.bottom,
      topbarHeight: rect(topbar)!.height,
      sidebarHeaderHeight: sidebarOnScreen ? sidebarBox!.height : null,
      firstContentTop: first ? Math.round(first.getBoundingClientRect().top + scrollY) : null,
      firstContentDescriptor: first ? `${first.tagName.toLowerCase()}.${String(first.className).trim().split(/\s+/).slice(0, 3).join('.')}` : 'none',
      headingCount: document.querySelectorAll('main h1').length,
      headingTop: rect(heading)?.top ?? null,
      headingBottom: rect(heading)?.bottom ?? null,
      navTop: rect(nav)?.top ?? null,
      navBottom: rect(nav)?.bottom ?? null,
      backCount: document.querySelectorAll('[data-hub-back]').length,
      homeCount: document.querySelectorAll('[data-hub-home]').length,
      backHeight: rect(back)?.height ?? null,
      homeHeight: rect(home)?.height ?? null,
      homeWidth: rect(home)?.width ?? null,
      backCenter: center(rect(back)),
      headingCenter: center(rect(heading)),
      overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
      mainPaddingLeft: parseFloat(mainStyle.paddingLeft),
      mainPaddingRight: parseFloat(mainStyle.paddingRight),
      topbarPaddingTop: parseFloat(topbarStyle.paddingTop),
      topbarNavTop: topbarNav ? topbarNav.getBoundingClientRect().top : 0,
    };
  });
}

async function shoot(page: Page, group: string, viewportName: string, pageName: string): Promise<void> {
  const folder = join(shotsRoot, label, group);
  mkdirSync(folder, { recursive: true });
  await page.screenshot({ path: join(folder, `${pageName}-${viewportName}.png`) });
}

function record(info: TestInfo, entry: Record<string, unknown>): void {
  const folder = join(shotsRoot, label);
  mkdirSync(folder, { recursive: true });
  writeFileSync(join(folder, `offset-${info.project.name}-${entry.page}-${entry.group}.json`), JSON.stringify(entry, null, 2));
}

function budgetFor(width: number): number {
  return width >= 640 ? BUDGET_WIDE_PX : BUDGET_NARROW_PX;
}

for (const user of ['member', 'officer'] as const) {
  test.describe(`${user} browser mode`, () => {
    test.use({ storageState: join(authDir, `${user}.json`) });

    for (const target of pages.filter(item => item.user === user)) {
      test(`${target.name}: first content block starts within budget, one row header, no overflow`, async ({ page }, info) => {
        const width = page.viewportSize()!.width;
        const failures: string[] = [];
        page.on('pageerror', error => failures.push(`pageerror: ${error.message}`));
        await open(page, target.path, target.bleed === true);
        const frame = await measure(page);
        await shoot(page, 'browser', info.project.name, target.name);
        record(info, { group: 'browser', page: target.name, viewport: info.project.name, ...frame });

        expect.soft(failures, 'no page errors').toEqual([]);
        expect.soft(frame.overflowX, 'no horizontal overflow').toBe(false);
        if (measureOnly) return;

        expect.soft(frame.firstContentTop, `${target.name} has a first content block`).not.toBeNull();
        expect.soft(frame.firstContentTop ?? Infinity, `first content block top (${frame.firstContentDescriptor}) at ${width}px wide`).toBeLessThanOrEqual(budgetFor(width));
        if (!target.bleed) expect.soft(frame.topbarHeight, 'top bar keeps its token height').toBeGreaterThanOrEqual(48);
        if (!target.bleed && frame.sidebarHeaderHeight !== null) {
          expect.soft(Math.abs(frame.sidebarHeaderHeight - frame.topbarHeight), 'sidebar header and top bar share one height').toBeLessThanOrEqual(0.5);
        }
        // One title per page: a single H1, except the Forms workspace which draws its own header.
        if (!target.bleed) expect.soft(frame.headingCount, 'exactly one H1').toBe(1);
        expect.soft(frame.backCount, 'one Back control').toBe(1);
        expect.soft(frame.homeCount, 'one Hub Home control').toBe(1);
        expect.soft(frame.backHeight ?? 0, 'Back touch target').toBeGreaterThanOrEqual(TOUCH_TARGET_PX);
        expect.soft(frame.homeHeight ?? 0, 'Hub Home touch target').toBeGreaterThanOrEqual(TOUCH_TARGET_PX);
        expect.soft(frame.homeWidth ?? 0, 'Hub Home touch width').toBeGreaterThanOrEqual(TOUCH_TARGET_PX);
        if (!target.bleed && frame.headingCenter !== null && frame.backCenter !== null) {
          expect.soft(Math.abs(frame.headingCenter - frame.backCenter), 'Back and the page title share one row').toBeLessThanOrEqual(24);
        }
        await expect.soft(page.getByRole('navigation', { name: 'Page navigation' }), 'page navigation landmark keeps its name').toBeVisible();
        await expect.soft(page.getByRole('link', { name: 'Hub Home' }), 'Hub Home keeps its accessible name').toBeVisible();
      });
    }
  });
}

test.describe('installed standalone window (emulated)', () => {
  test.use({ storageState: join(authDir, 'member.json') });

  for (const target of pages.filter(item => item.user === 'member' && ['dashboard', 'my-requests', 'request-uniforms', 'vehicle-inspections'].includes(item.name))) {
    test(`${target.name}: notch inset moves the bars, not the content spacing`, async ({ page }, info) => {
      const width = page.viewportSize()!.width;
      test.skip(width >= 1024, 'A notch inset applies to phones and tablets.');
      await open(page, target.path);
      const browserFrame = await measure(page);

      // Chromium cannot switch display-mode or fake env(safe-area-inset-*), so give the safe-area tokens a notch inset
      // and re-apply the stylesheet's own standalone / window-controls-overlay rules without their media conditions.
      const appliedRules = await page.evaluate(({ inset }) => {
        const css: string[] = [`:root:root { --hub-safe-top: ${inset}px; --hub-safe-left: 0px; --hub-safe-right: 0px; }`];
        let count = 0;
        const unprefixed: string[] = [];
        for (const sheet of Array.from(document.styleSheets)) {
          let rules: CSSRuleList;
          try { rules = sheet.cssRules; } catch { continue; }
          for (const rule of Array.from(rules)) {
            if (!(rule instanceof CSSMediaRule)) continue;
            // A phone's installed window is display-mode: standalone; the window-controls-overlay rules are desktop-only,
            // so they are only inspected (not applied): they must not hide behind the Admin-only body.mbfd-pwa class.
            if (/display-mode:\s*standalone/.test(rule.conditionText)) {
              for (const inner of Array.from(rule.cssRules)) { css.push(inner.cssText); count += 1; }
            }
            if (/display-mode:\s*window-controls-overlay/.test(rule.conditionText)) {
              for (const inner of Array.from(rule.cssRules)) {
                if (inner.cssText.includes('.fi-topbar') && !inner.cssText.includes('mbfd-pwa')) unprefixed.push(inner.cssText.slice(0, 80));
              }
            }
          }
        }
        const style = document.createElement('style');
        style.dataset.emulatedStandalone = 'true';
        style.textContent = css.join('\n');
        document.head.append(style);
        return { count, unprefixed };
      }, { inset: NOTCH_INSET_PX });
      expect.soft(appliedRules.count, 'stylesheet carries standalone display-mode rules').toBeGreaterThan(0);
      expect.soft(appliedRules.unprefixed.length, 'window-controls-overlay topbar rules do not depend on body.mbfd-pwa').toBeGreaterThan(0);

      await settle(page);
      const standalone = await measure(page);
      await shoot(page, 'standalone', info.project.name, target.name);
      record(info, { group: 'standalone', page: target.name, viewport: info.project.name, browser: browserFrame, ...standalone });
      if (measureOnly) return;

      expect.soft(standalone.topbarHeight - browserFrame.topbarHeight, 'top bar grows by exactly the inset').toBeCloseTo(NOTCH_INSET_PX, 0);
      expect.soft(standalone.topbarPaddingTop, 'the bar itself owns the inset').toBeCloseTo(NOTCH_INSET_PX, 0);
      expect.soft(standalone.topbarNavTop, 'bar controls start below the status bar').toBeGreaterThanOrEqual(NOTCH_INSET_PX - 0.5);
      if (standalone.sidebarHeaderHeight !== null) {
        expect.soft(Math.abs(standalone.sidebarHeaderHeight - standalone.topbarHeight), 'sidebar header and top bar still share one height').toBeLessThanOrEqual(0.5);
      }
      // Content spacing below the bar is unchanged: the first block moves down by the inset only.
      expect.soft((standalone.firstContentTop ?? Infinity) - (browserFrame.firstContentTop ?? 0), 'first block moves by the inset only').toBeCloseTo(NOTCH_INSET_PX, 0);
      expect.soft(standalone.firstContentTop ?? Infinity, 'first block stays within budget plus inset').toBeLessThanOrEqual(budgetFor(width) + NOTCH_INSET_PX);
      expect.soft(standalone.overflowX, 'no horizontal overflow').toBe(false);
    });
  }

  test('landscape notch: side insets keep content and bar controls clear of the sensor housing', async ({ page }, info) => {
    const width = page.viewportSize()!.width;
    test.skip(width >= 1024 || info.project.name !== 'iphone-390x844', 'One phone viewport is enough for the side-inset contract.');
    await open(page, '/employee/dashboard');
    await page.addStyleTag({ content: `:root:root { --hub-safe-left: ${NOTCH_INSET_PX}px; --hub-safe-right: ${NOTCH_INSET_PX}px; }` });
    await settle(page);
    const frame = await measure(page);
    const barPadding = await page.evaluate(() => {
      // The bar's outer element carries the inset; the nav inside keeps Filament's own padding.
      const style = getComputedStyle(document.querySelector('.fi-topbar')!);
      return { left: parseFloat(style.paddingLeft), right: parseFloat(style.paddingRight) };
    });
    if (measureOnly) return;
    expect.soft(frame.mainPaddingLeft, 'page content clears the left inset').toBeGreaterThanOrEqual(NOTCH_INSET_PX);
    expect.soft(frame.mainPaddingRight, 'page content clears the right inset').toBeGreaterThanOrEqual(NOTCH_INSET_PX);
    expect.soft(barPadding.left, 'bar controls clear the left inset').toBeGreaterThanOrEqual(NOTCH_INSET_PX);
    expect.soft(barPadding.right, 'bar controls clear the right inset').toBeGreaterThanOrEqual(NOTCH_INSET_PX);
  });
});
