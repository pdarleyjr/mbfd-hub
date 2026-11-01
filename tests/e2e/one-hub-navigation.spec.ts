import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';
import { PRIMARY_ORIGIN, installedHostEnvironment } from './installed-host.setup';
import { localPhpBinary } from './support/test-environment';

/**
 * The Hub in a real browser, against the real Laravel app: files open in place, offline taps stay put, leaving a clean
 * form never prompts, the Command Board caption appears only in an installed window, and the Home visit card counts.
 *
 * Run: npx playwright test --config=playwright.installed-host.config.ts
 */

const admin = {
  id: process.env.OPERATIONAL_FORMS_E2E_ADMIN_EMPLOYEE_ID ?? 'E215',
  password: process.env.OPERATIONAL_FORMS_E2E_ADMIN_PASSWORD ?? 'OperationalFormsAdmin!1',
};
const member = {
  id: process.env.OPERATIONAL_FORMS_E2E_EMPLOYEE_ID ?? 'E214',
  password: process.env.OPERATIONAL_FORMS_E2E_PASSWORD ?? 'OperationalForms!1',
};

const signIn = async (page: Page, who: { id: string; password: string }): Promise<void> => {
  await page.goto(`${PRIMARY_ORIGIN}/login`);
  await page.getByLabel('Employee ID').fill(who.id);
  await page.getByLabel('Password').fill(who.password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL((url) => url.pathname !== '/login', { timeout: 30_000 });
};

const home = (page: Page) => page.goto(`${PRIMARY_ORIGIN}/`);
const number = async (page: Page, selector: string): Promise<number> => Number((await page.locator(selector).innerText()).replace(/,/g, ''));
const artisan = (code: string): string => execFileSync(localPhpBinary('INSTALLED_HOST_E2E_PHP'), ['-d', 'memory_limit=1G', 'artisan', 'tinker', '--execute', code], { cwd: process.cwd(), env: installedHostEnvironment() }).toString();

test.describe('Document viewer', () => {
  const addLink = (page: Page, attributes: Record<string, string>, text: string) => page.evaluate(({ attributes, text }) => {
    const link = document.createElement('a');
    Object.entries(attributes).forEach(([name, value]) => link.setAttribute(name, value));
    link.textContent = text;
    link.style.cssText = 'position:fixed;top:0;left:0;z-index:50;background:#fff;padding:12px';
    document.body.append(link);
  }, { attributes, text });

  test('a PDF renders in a dialog on the same page, and typed work survives', async ({ page }) => {
    await signIn(page, admin);
    await page.goto(`${PRIMARY_ORIGIN}/account`);
    await page.evaluate(() => {
      const field = document.createElement('textarea');
      field.id = 'typed-reply';
      field.value = 'half a reply';
      document.body.prepend(field);
    });
    await addLink(page, { href: '/documents/MBFD_Operational_Forms_User_Guide.pdf', 'data-hub-viewer': 'pdf', target: '_blank' }, 'Open guide');
    const url = page.url();
    const popups: unknown[] = [];
    page.context().on('page', (opened) => popups.push(opened));

    await page.getByText('Open guide').click();

    const viewer = page.locator('dialog.hub-viewer');
    await expect(viewer).toBeVisible();
    await expect(viewer).toContainText('Open guide');
    await expect(viewer.locator('canvas').first()).toBeVisible({ timeout: 30_000 });
    expect(await viewer.locator('canvas').first().evaluate((canvas: HTMLCanvasElement) => canvas.width > 100)).toBe(true);
    expect(page.url(), 'nothing navigated').toBe(url);
    expect(popups, 'no new window').toHaveLength(0);

    await viewer.getByRole('button', { name: 'Close' }).click();
    await expect(viewer).toBeHidden();
    await expect(page.locator('#typed-reply')).toHaveValue('half a reply');
  });

  test('an image opens in place and a missing file explains itself with a way forward', async ({ page }) => {
    await signIn(page, admin);
    await page.goto(`${PRIMARY_ORIGIN}/account`);
    await addLink(page, { href: '/images/mbfd-logo.png', 'data-hub-viewer': 'image' }, 'Open photo');
    await page.getByText('Open photo').click();
    const viewer = page.locator('dialog.hub-viewer');
    await expect(viewer.locator('img')).toBeVisible();
    expect(await viewer.locator('img').evaluate((image: HTMLImageElement) => image.naturalWidth > 0)).toBe(true);
    await page.keyboard.press('Escape');
    await expect(viewer).toBeHidden();
    await expect(page.getByRole('link', { name: 'Open photo' })).toBeFocused();

    await addLink(page, { href: '/images/does-not-exist.png', 'data-hub-viewer': 'image' }, 'Open missing');
    await page.getByText('Open missing').click();
    await expect(viewer.getByRole('link', { name: 'Open file' })).toBeVisible();
  });
});

test('offline, a tap on a Hub link stays on the page and says why', async ({ page, context }) => {
  await signIn(page, admin);
  await page.goto(`${PRIMARY_ORIGIN}/account`);
  const url = page.url();
  await context.setOffline(true);
  await page.evaluate(() => {
    const link = document.createElement('a');
    link.href = '/updates';
    link.textContent = 'Go to updates';
    link.style.cssText = 'position:fixed;top:0;left:0;z-index:50;background:#fff;padding:12px';
    document.body.append(link);
  });
  await page.getByText('Go to updates').click();
  await expect(page.locator('[data-hub-offline-strip]').first()).toContainText('This page needs a connection');
  expect(page.url()).toBe(url);
  await context.setOffline(false);
});

test.describe('Leaving a Filament form', () => {
  const leaveByLink = async (page: Page) => {
    // beforeunload only prompts after the member has touched the page.
    await page.locator('body').click({ position: { x: 700, y: 20 } });
    await page.evaluate(() => {
      const link = document.createElement('a');
      link.href = '/admin';
      link.id = 'leave-form';
      link.textContent = 'Leave';
      link.style.cssText = 'position:fixed;bottom:0;right:0;z-index:99;background:#fff;padding:12px';
      document.body.append(link);
    });
    await page.locator('#leave-form').click();
  };

  test('a clean rich-text form never prompts; an edited one does', async ({ page }) => {
    artisan(`\\App\\Models\\DepartmentUpdate::query()->updateOrCreate(['title' => 'Unsaved check'], [
      'body' => '<p>Hello <strong>team</strong>, see <a href="https://www.mbfdhub.com/updates">updates</a>.</p><ul><li>One</li></ul>',
      'category' => 'general', 'priority' => 'normal', 'status' => 'draft', 'audience' => 'everyone',
      'author_id' => \\App\\Models\\User::query()->where('employee_id', '${admin.id}')->value('id'),
    ]);`);
    const id = artisan("echo 'ID='.\\App\\Models\\DepartmentUpdate::query()->where('title', 'Unsaved check')->value('id');").match(/ID=(\d+)/)?.[1];
    expect(id).toBeTruthy();

    await signIn(page, admin);
    // Ask the page's own handler what it decided, so "no prompt" cannot pass just because no prompt was possible.
    const wouldPrompt = () => page.evaluate(() => {
      const event = new Event('beforeunload', { cancelable: true });
      window.dispatchEvent(event);
      return event.defaultPrevented;
    });

    await page.goto(`${PRIMARY_ORIGIN}/admin/department-updates/${id}/edit`);
    await expect(page.locator('trix-editor').first()).toBeVisible({ timeout: 30_000 });
    await page.waitForFunction(() => typeof (window as unknown as { jsMd5?: unknown }).jsMd5 === 'function');
    await page.waitForTimeout(1_500);
    expect(await wouldPrompt(), 'clean rich-text form').toBe(false);

    const title = page.locator('input[id$="data.title"]');
    await title.fill('Unsaved check edited');
    await title.blur();
    await page.waitForTimeout(500);
    expect(await wouldPrompt(), 'edited form').toBe(true);

    await title.fill('Unsaved check');
    await title.blur();
    await page.waitForTimeout(500);
    expect(await wouldPrompt(), 'edit undone').toBe(false);
    await leaveByLink(page);
    await page.waitForURL(/\/admin\/?$/);
  });
});

test('the Command Board caption is hidden in a browser tab and has an installed-only rule', async ({ page }) => {
  await signIn(page, admin);
  await home(page);
  test.skip((await page.locator('[data-home-command-board]').count()) === 0, 'this fixture member is not offered Command Board');
  const caption = page.locator('.hub-installed-hint').first();
  await expect(caption).toHaveText(/Opens outside the Hub/);
  await expect(caption).toBeHidden();
  // Playwright cannot put a page in display-mode: standalone, so read the rule the browser actually parsed.
  const shown = await page.evaluate(() => [...document.styleSheets].some((sheet) => {
    try {
      return [...sheet.cssRules].some((rule) => rule instanceof CSSMediaRule
        && rule.conditionText.includes('display-mode: standalone')
        && [...rule.cssRules].some((inner) => inner instanceof CSSStyleRule && inner.selectorText.includes('.hub-installed-hint') && inner.style.display !== 'none' && inner.style.display !== ''));
    } catch {
      return false;
    }
  }));
  expect(shown).toBe(true);
});

test.describe('Home visit card', () => {
  const PAST = '[data-activity-past]';
  const TOTAL = '[data-activity-total]';

  test('both numbers go up with each visit and an open page refreshes itself', async ({ page, context }) => {
    await signIn(page, admin);
    await home(page);
    await expect(page.locator('[data-home-activity]')).toBeVisible();
    const firstPast = await number(page, PAST);
    const firstTotal = await number(page, TOTAL);
    expect(firstPast).toBeGreaterThan(0);
    expect(firstTotal).toBeGreaterThanOrEqual(firstPast);
    await expect(page.locator('[data-activity-caption]')).toContainText(/Home page visits since [A-Z][a-z]{2} \d{1,2}, \d{4}/);

    await page.waitForTimeout(300);
    await page.reload();
    expect(await number(page, PAST), 'a reload is a visit').toBe(firstPast + 1);
    expect(await number(page, TOTAL)).toBe(firstTotal + 1);

    // A visit from another page reaches this open one through its own refresh, marked as a background request.
    const other = await context.newPage();
    await other.goto(`${PRIMARY_ORIGIN}/`);
    await other.waitForTimeout(300);
    await other.close();
    await page.waitForTimeout(10_500);
    const refresh = page.waitForRequest((request) => new URL(request.url()).pathname === '/home/activity');
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    expect((await refresh).headers()['x-hub-background-refresh']).toBe('1');
    await expect(page.locator(PAST)).toHaveText(String(firstPast + 2));
    await expect(page.locator(TOTAL)).toHaveText(String(firstTotal + 2));
  });

  test('Admin and other pages do not count', async ({ page }) => {
    await signIn(page, admin);
    await home(page);
    const total = () => page.evaluate(async () => (await (await fetch('/home/activity', { cache: 'no-store' })).json()).total);
    await page.waitForTimeout(16_000);
    const settled = await total();
    expect(settled).toBeGreaterThan(0);
    await page.goto(`${PRIMARY_ORIGIN}/admin`);
    await page.goto(`${PRIMARY_ORIGIN}/account`);
    await page.waitForTimeout(16_000);
    expect(await total()).toBe(settled);
  });

  test('an administrator opens who signed in as a pop-up, not a page', async ({ page }) => {
    await signIn(page, admin);
    await home(page);
    const url = page.url();
    const link = page.locator('[data-activity-open]');
    await expect(link).toContainText(/\d+ members? signed in/);
    await link.click();
    const dialog = page.locator('[data-activity-dialog]');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('.home-activity-dialog__row').first()).toBeVisible();
    await expect(dialog).toContainText(/Today \d{1,2}:\d{2} [AP]M/);
    expect(page.url()).toBe(url);
    await dialog.getByRole('button', { name: 'Close' }).click();
    await expect(dialog).toBeHidden();
    await expect(link).toBeFocused();
  });

  test('a member sees the counters but not the list', async ({ page }) => {
    await signIn(page, member);
    await home(page);
    await expect(page.locator('[data-home-activity]')).toBeVisible();
    await expect(page.locator('[data-activity-open]')).toHaveCount(0);
    expect(await page.evaluate(async () => (await fetch('/home/activity/members', { headers: { Accept: 'application/json' } })).status)).toBe(403);
  });

  for (const [name, viewport] of [['desktop', { width: 1280, height: 800 }], ['phone', { width: 390, height: 844 }], ['small-phone', { width: 320, height: 640 }]] as const) {
    test(`fits and matches the page at ${name} width`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await signIn(page, admin);
      await home(page);
      const card = page.locator('[data-home-activity]');
      await card.scrollIntoViewIfNeeded();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'no sideways scroll').toBe(true);
      await page.screenshot({ path: `test-results/one-hub/home-${name}.png`, fullPage: true });
      await card.screenshot({ path: `test-results/one-hub/card-${name}.png` });
      await page.locator('[data-activity-open]').click();
      await expect(page.locator('.home-activity-dialog__row').first()).toBeVisible();
      await page.screenshot({ path: `test-results/one-hub/dialog-${name}.png` });
    });
  }
});
