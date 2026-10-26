import { expect, test as base, type Page } from '@playwright/test';

/**
 * Public /install page: the one shared "Install MBFD Hub" experience.
 *
 * Self-contained and unauthenticated. It never touches a real install dialog: a synthetic
 * `beforeinstallprompt` event (preventDefault, a prompt() counter and a userChoice promise) stands in
 * for the browser's, and `(display-mode: standalone)` is stubbed through matchMedia because
 * emulateMedia cannot set display-mode. Run it against a LOCAL server only (PLAYWRIGHT_BASE_URL),
 * for example:
 *
 *   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8098 npx playwright test tests/e2e/pwa-install.spec.ts \
 *     --config=<a config whose testMatch includes this file; playwright.config.ts has no project for it yet>
 *
 * Each test pins its own user agent, so the result does not depend on the runner's operating system.
 */

const UA = {
  windowsChrome: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
  iphoneSafari: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Mobile/15E148 Safari/604.1',
};

type InstallOutcome = 'accepted' | 'dismissed';

declare global {
  interface Window {
    __promptCalls: number;
    __preventedCalls: number;
    __notificationAsks: number;
    __lastInstallEvent?: Event;
  }
}

/** Counts every attempt to ask for notification permission or subscribe to push. Install must never do either. */
async function watchPermissionRequests(page: Page): Promise<void> {
  await page.addInitScript(() => {
    window.__notificationAsks = 0;
    const count = () => { window.__notificationAsks += 1; };
    if ('Notification' in window) {
      const original = Notification.requestPermission?.bind(Notification);
      Notification.requestPermission = ((...args: unknown[]) => { count(); return original ? (original as (...a: unknown[]) => Promise<NotificationPermission>)(...args) : Promise.resolve('default' as NotificationPermission); }) as typeof Notification.requestPermission;
    }
    if (typeof PushManager !== 'undefined') {
      const original = PushManager.prototype.subscribe;
      PushManager.prototype.subscribe = function (...args: Parameters<typeof original>) { count(); return original.apply(this, args); };
    }
  });
}

/**
 * Dispatches a fake install offer. `early` fires while the document is still `interactive`, i.e. after
 * the shared head script has attached its listener but before the deferred install-ui bundle runs, so
 * it exercises the capture-then-adopt path. `late` fires after `load`, which the controller hears itself.
 */
async function offerInstall(page: Page, outcome: InstallOutcome, when: 'early' | 'late' = 'early'): Promise<void> {
  await page.addInitScript(({ outcome, when }) => {
    window.__promptCalls = 0;
    window.__preventedCalls = 0;
    const fire = () => {
      const event = new Event('beforeinstallprompt', { cancelable: true }) as Event & {
        prompt: () => Promise<void>;
        userChoice: Promise<{ outcome: string; platform: string }>;
      };
      const preventDefault = event.preventDefault.bind(event);
      event.preventDefault = () => { window.__preventedCalls += 1; preventDefault(); };
      event.prompt = () => { window.__promptCalls += 1; return Promise.resolve(); };
      event.userChoice = Promise.resolve({ outcome, platform: 'web' });
      window.__lastInstallEvent = event;
      window.dispatchEvent(event);
    };
    if (when === 'early') {
      document.addEventListener('readystatechange', () => { if (document.readyState === 'interactive') fire(); }, { once: true });
    } else {
      window.addEventListener('load', () => setTimeout(fire, 100), { once: true });
    }
  }, { outcome, when });
}

/** Makes `(display-mode: <mode>)` match, leaving every other media query to the real implementation. */
async function stubDisplayMode(page: Page, mode: string): Promise<void> {
  await page.addInitScript((mode) => {
    const real = window.matchMedia.bind(window);
    window.matchMedia = (query: string) => {
      const list = real(query);
      if (query.replace(/\s+/g, '') !== `(display-mode:${mode})`) return list;
      return {
        matches: true, media: query, onchange: null,
        addEventListener: () => {}, removeEventListener: () => {}, addListener: () => {}, removeListener: () => {},
        dispatchEvent: () => false,
      } as MediaQueryList;
    };
  }, mode);
}

/** Every test fails on an uncaught page error or a console error (failed static assets in a bare dev server are ignored). */
const test = base.extend<{ pageIssues: string[] }>({
  pageIssues: [async ({ page }, use) => {
    const issues: string[] = [];
    page.on('pageerror', (error) => issues.push(`pageerror: ${error.message}`));
    page.on('console', (message) => {
      if (message.type() === 'error' && !/Failed to load resource/i.test(message.text())) issues.push(`console.error: ${message.text()}`);
    });
    await use(issues);
    expect(issues, 'No page errors or console errors on /install').toEqual([]);
  }, { auto: true }],
});

const installPage = (page: Page) => page.locator('[data-install-page]');
const visibleGuides = (page: Page) => page.locator('[data-install-guide]:visible');
const installButton = (page: Page) => page.locator('[data-install-primary]');
const status = (page: Page) => page.locator('[data-install-status]');

async function open(page: Page): Promise<void> {
  const response = await page.goto('/install');
  expect(response?.status(), '/install is public').toBe(200);
  await expect(installPage(page)).toHaveClass(/is-enhanced/);
}

test.describe('desktop Chromium', () => {
  test.use({ userAgent: UA.windowsChrome, viewport: { width: 1280, height: 800 } });

  test('(a) without an install offer there is no Install button and exactly one guide', async ({ page }) => {
    await watchPermissionRequests(page);
    await open(page);

    await expect(installButton(page)).toBeHidden();
    await expect(page.getByRole('button', { name: 'Install MBFD Hub' })).toHaveCount(0);
    await expect(visibleGuides(page)).toHaveCount(1);
    await expect(page.locator('[data-install-guide="desktop-chromium"]')).toBeVisible();
    await expect(installPage(page)).toHaveAttribute('data-install-state', 'manual');
    await expect(status(page)).toContainText('installs from its menu');
    await expect(page.locator('[data-install-success]')).toBeHidden();
    expect(await page.evaluate(() => window.__notificationAsks)).toBe(0);
  });

  for (const when of ['early', 'late'] as const) {
    test(`(b) an accepted install offer (${when}) shows the button once, prompts once, then the success state`, async ({ page }) => {
      await watchPermissionRequests(page);
      await offerInstall(page, 'accepted', when);
      await open(page);

      await expect(installButton(page)).toBeVisible();
      await expect(installPage(page)).toHaveAttribute('data-install-state', 'ready');
      await expect(status(page)).toContainText('Ready to install');
      expect(await page.evaluate(() => window.__preventedCalls), 'the mini-infobar is suppressed').toBeGreaterThan(0);
      expect(await page.evaluate(() => window.__promptCalls), 'nothing prompts before a click').toBe(0);

      await installButton(page).click();

      await expect(page.locator('[data-install-success]')).toBeVisible();
      await expect(page.locator('[data-install-success-heading]')).toBeFocused();
      await expect(installPage(page)).toHaveAttribute('data-install-state', 'installed');
      await expect(installButton(page)).toBeHidden();
      await expect(page.locator('[data-install-setup]')).toBeHidden();
      await expect(visibleGuides(page)).toHaveCount(0);
      expect(await page.evaluate(() => window.__promptCalls)).toBe(1);

      // A second install is impossible: no button, and the controller refuses to reuse the event.
      await expect(page.getByRole('button', { name: 'Install MBFD Hub' })).toHaveCount(0);
      const again = await page.evaluate(() => (window as unknown as { MBFDInstall: { promptInstall(): Promise<string> } }).MBFDInstall.promptInstall());
      expect(again).toBe('unavailable');
      expect(await page.evaluate(() => window.__promptCalls)).toBe(1);
      expect(await page.evaluate(() => window.__notificationAsks), 'installing never asks for notification permission').toBe(0);
    });
  }

  test('(b2) a double click still prompts once', async ({ page }) => {
    await offerInstall(page, 'accepted');
    await open(page);
    await installButton(page).dblclick();
    await expect(page.locator('[data-install-success]')).toBeVisible();
    expect(await page.evaluate(() => window.__promptCalls)).toBe(1);
  });

  test('(c) a dismissed install offer reports it and consumes the prompt', async ({ page }) => {
    await offerInstall(page, 'dismissed');
    await open(page);

    await installButton(page).click();

    await expect(status(page)).toContainText(/cancelled/i);
    await expect(installButton(page)).toBeHidden();
    await expect(installPage(page)).toHaveAttribute('data-install-state', 'manual');
    await expect(page.locator('[data-install-success]')).toBeHidden();
    await expect(page.locator('[data-install-guide="desktop-chromium"]')).toBeVisible();
    expect(await page.evaluate(() => window.__promptCalls)).toBe(1);
    expect(await page.evaluate(() => (window as unknown as { MBFDInstall: { promptInstall(): Promise<string> } }).MBFDInstall.promptInstall())).toBe('unavailable');
    expect(await page.evaluate(() => window.__promptCalls)).toBe(1);
  });

  // Invariant, not today's behaviour: the page must not say "try again" while it offers no way to.
  // Expected to FAIL until the cancelled-install copy or the button is changed (see review notes).
  test('(c2) a cancelled install does not promise a retry the page cannot offer', async ({ page }) => {
    await offerInstall(page, 'dismissed');
    await open(page);
    await installButton(page).click();
    await expect(status(page)).toContainText(/cancelled/i);
    const promisesRetry = /try again/i.test((await status(page).textContent()) ?? '');
    if (promisesRetry) await expect(installButton(page)).toBeVisible();
  });

  test('(e) the device switcher is a keyboard-operable tablist', async ({ page }) => {
    await open(page);
    const tabs = page.locator('[role="tab"]'); // getByRole skips tabs inside the closed <details>
    await expect(tabs).toHaveCount(9);

    // The switcher is closed by default; opening it exposes the tabs.
    await expect(tabs.first()).toBeHidden();
    await page.locator('[data-install-switcher] summary').click();
    await expect(tabs.first()).toBeVisible();

    const selected = page.locator('[role="tab"][aria-selected="true"]');
    await expect(selected).toHaveCount(1);
    await expect(selected).toHaveAttribute('data-guide-tab', 'desktop-chromium');
    await expect(selected).toHaveAttribute('tabindex', '0');
    await expect(page.locator('[data-install-guide="desktop-chromium"]')).toBeVisible();
    // Roving tabindex: only the selected tab is in the tab order.
    expect(await page.locator('[role="tab"][tabindex="0"]').count()).toBe(1);

    await selected.focus();
    await page.keyboard.press('ArrowRight');
    await expect(selected).toHaveAttribute('data-guide-tab', 'mac-safari');
    await expect(selected).toBeFocused();
    await expect(visibleGuides(page)).toHaveCount(1);
    await expect(page.locator('[data-install-guide="mac-safari"]')).toBeVisible();
    await expect(page.locator('[data-install-guide="desktop-chromium"]')).toBeHidden();

    await page.keyboard.press('ArrowLeft');
    await expect(selected).toHaveAttribute('data-guide-tab', 'desktop-chromium');

    await page.keyboard.press('Home');
    await expect(selected).toHaveAttribute('data-guide-tab', 'ios-safari');
    await expect(page.locator('[data-install-guide="ios-safari"]')).toBeVisible();

    await page.keyboard.press('End');
    await expect(selected).toHaveAttribute('data-guide-tab', 'other');
    await page.keyboard.press('ArrowRight');
    await expect(selected).toHaveAttribute('data-guide-tab', 'ios-safari');

    // Every tab names the panel it controls, and that panel exists.
    for (const tab of await tabs.all()) {
      const controls = await tab.getAttribute('aria-controls');
      await expect(page.locator(`#${controls}`)).toHaveCount(1);
    }
    // A manual choice survives a later state change (the browser offering an install).
    await page.evaluate(() => window.dispatchEvent(new Event('beforeinstallprompt', { cancelable: true })));
    await expect(page.locator('[data-install-guide="ios-safari"]')).toBeVisible();
  });

  test('(f) running as the installed app shows the success state; other devices stay one click away', async ({ page }) => {
    await stubDisplayMode(page, 'standalone');
    await watchPermissionRequests(page);
    await open(page);

    await expect(installPage(page)).toHaveAttribute('data-install-state', 'installed');
    await expect(page.locator('[data-install-success]')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'MBFD Hub is installed' })).toBeVisible();
    await expect(installButton(page)).toBeHidden();
    await expect(page.locator('[data-install-setup]')).toBeHidden();
    await expect(visibleGuides(page)).toHaveCount(0);
    await expect(status(page)).toContainText('installed');

    await page.getByRole('button', { name: 'Install on another device' }).click();
    await expect(page.locator('[data-install-setup]')).toBeVisible();
    await expect(visibleGuides(page)).toHaveCount(1);
    await expect(page.locator('[data-install-switcher]')).toHaveJSProperty('open', true);
    await expect(page.locator('[data-install-success]')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Install on another device' })).toBeHidden();
    // Installed mode never exposes a native install button, even if the browser offers one.
    await page.evaluate(() => window.dispatchEvent(new Event('beforeinstallprompt', { cancelable: true })));
    await expect(installButton(page)).toBeHidden();
    expect(await page.evaluate(() => window.__notificationAsks)).toBe(0);
  });

  test('(f2) window-controls-overlay and minimal-ui also count as installed; browser tabs do not', async ({ page, browser }) => {
    for (const mode of ['window-controls-overlay', 'minimal-ui']) {
      const context = await browser.newContext({ userAgent: UA.windowsChrome });
      const stubbed = await context.newPage();
      await stubDisplayMode(stubbed, mode);
      await stubbed.goto('/install');
      await expect(stubbed.locator('[data-install-page]'), mode).toHaveAttribute('data-install-state', 'installed');
      await context.close();
    }
    await open(page);
    await expect(installPage(page)).toHaveAttribute('data-install-state', 'manual');
  });

  test('(h) tab order reaches the tabs and controls with visible 44px targets', async ({ page }) => {
    await offerInstall(page, 'accepted');
    await open(page);
    await page.locator('[data-install-switcher] summary').click();
    const targets = [installButton(page), page.locator('[data-install-switcher] summary'), ...(await page.getByRole('tab').all())];
    for (const target of targets) {
      const box = await target.boundingBox();
      expect(box, 'target is rendered').not.toBeNull();
      expect(box!.height, 'target height').toBeGreaterThanOrEqual(43.5);
    }
  });
});

test.describe('iPhone Safari', () => {
  test.use({ userAgent: UA.iphoneSafari, viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

  test('(d) shows the Safari steps and never an Install button', async ({ page }) => {
    await watchPermissionRequests(page);
    await open(page);

    await expect(page.locator('[data-install-guide="ios-safari"]')).toBeVisible();
    await expect(visibleGuides(page)).toHaveCount(1);
    await expect(installButton(page)).toBeHidden();
    await expect(page.getByRole('button', { name: 'Install MBFD Hub' })).toHaveCount(0);
    await expect(page.getByText('Add to Home Screen').first()).toBeVisible();
    await expect(installPage(page)).toHaveAttribute('data-install-state', 'manual');
    // No horizontal scroll at phone width.
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    expect(await page.evaluate(() => window.__notificationAsks)).toBe(0);
  });

  test('(d2) a Home Screen launch (navigator.standalone) is treated as installed', async ({ page }) => {
    await page.addInitScript(() => Object.defineProperty(navigator, 'standalone', { value: true, configurable: true }));
    await open(page);
    await expect(installPage(page)).toHaveAttribute('data-install-state', 'installed');
    await expect(page.locator('[data-install-success]')).toBeVisible();
  });
});

test.describe('iPhone Chrome and in-app browsers', () => {
  test('iOS Chrome gets the Share-sheet steps, not a button', async ({ browser }) => {
    const context = await browser.newContext({
      userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/131.0.6778.73 Mobile/15E148 Safari/604.1',
      viewport: { width: 390, height: 844 },
    });
    const page = await context.newPage();
    await open(page);
    await expect(page.locator('[data-install-guide="ios-other"]')).toBeVisible();
    await expect(installButton(page)).toBeHidden();
    await context.close();
  });

  test('an in-app browser is told to open the page in Safari or Chrome', async ({ browser }) => {
    const context = await browser.newContext({
      userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 350.0.0.0',
      viewport: { width: 390, height: 844 },
    });
    const page = await context.newPage();
    await open(page);
    await expect(page.locator('[data-install-guide="other"]')).toBeVisible();
    await expect(status(page)).toContainText('Safari or Chrome');
    await expect(installButton(page)).toBeHidden();
    await context.close();
  });
});
