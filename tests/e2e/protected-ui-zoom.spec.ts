import { chromium, expect, test, type CDPSession, type Page, type TestInfo, type Worker } from '@playwright/test';
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Browser zoom, not CSS zoom, page-scale emulation, or deviceScaleFactor.
// https://playwright.dev/docs/chrome-extensions
// https://developer.chrome.com/docs/extensions/reference/api/tabs#method-setZoom
// https://chromedevtools.github.io/devtools-protocol/tot/Emulation/#method-setVisibleSize
type ZoomResult = { factor: number; settings: { mode: string; scope: string; defaultZoomFactor: number } };
type ZoomWorker = typeof globalThis & { setLocalZoom(url: string, factor: number): Promise<ZoomResult> };

async function tabZoom(page: Page, worker: Worker, factor: number) {
  const result = await worker.evaluate(({ url, factor }) => (globalThis as ZoomWorker).setLocalZoom(url, factor), { url: page.url(), factor });
  expect(result.factor).toBeCloseTo(factor, 5);
  expect(result.settings).toMatchObject({ mode: 'automatic', scope: 'per-tab' });
  await page.waitForFunction(expected => Math.abs(devicePixelRatio - expected) < 0.02, factor);
  return result;
}

async function sidebar(page: Page, open: boolean) {
  const aside = page.locator('.fi-sidebar');
  const currentlyOpen = await aside.evaluate(element => element.classList.contains('fi-sidebar-open'));
  if (currentlyOpen !== open) {
    const button = open
      ? page.getByRole('button', { name: 'Expand sidebar', exact: true })
      : aside.getByRole('button', { name: 'Collapse sidebar', exact: true });
    await button.filter({ visible: true }).first().click({ timeout: 5000 });
  }
  await expect.poll(() => aside.evaluate(element => element.classList.contains('fi-sidebar-open'))).toBe(open);
  // Wait for the real native sidebar animation, without replacing its styles.
  await expect.poll(() => aside.evaluate(element => element.getAnimations().filter(animation => animation.playState === 'running').length)).toBe(0);
}

async function capture(page: Page, cdp: CDPSession, info: TestInfo, name: string, width: number, factor: number, zoom: ZoomResult, state: string) {
  await page.waitForLoadState('networkidle');
  await page.evaluate(() => document.fonts.ready);
  if (state !== 'display') {
    const later = page.locator('[data-admin-pwa-install]').getByRole('button', { name: 'Not now', exact: true });
    if (await later.isVisible()) {
      await later.click();
      info.annotations.push({ type: 'install-prompt', description: `${name}: dismissed the optional prompt with its real Not now button before layout capture.` });
    }
  }
  const result = await page.evaluate(({ state }) => {
    const root = document.documentElement;
    const failures: string[] = [];
    const rect = (element: Element) => {
      const bounds = element.getBoundingClientRect();
      return { left: bounds.left, top: bounds.top, right: bounds.right, bottom: bounds.bottom, width: bounds.width, height: bounds.height };
    };
    const overlaps = (a: ReturnType<typeof rect>, b: ReturnType<typeof rect>) => Math.min(a.right, b.right) > Math.max(a.left, b.left) + 1 && Math.min(a.bottom, b.bottom) > Math.max(a.top, b.top) + 1;
    if (root.scrollWidth > root.clientWidth + 1) failures.push(`Document overflow: ${root.scrollWidth}/${root.clientWidth}`);
    const aside = document.querySelector('.fi-sidebar');
    const asideRect = aside ? rect(aside) : null;
    const topbarControls = Array.from(document.querySelectorAll('.fi-topbar button, .fi-topbar a, .fi-topbar input')).flatMap(element => {
      const bounds = rect(element);
      if (!bounds.width || !bounds.height || getComputedStyle(element).visibility === 'hidden') return [];
      const label = element.getAttribute('aria-label') || element.getAttribute('title') || element.tagName;
      if (bounds.left < -1 || bounds.right > root.clientWidth + 1) failures.push(`Topbar control outside viewport: ${label}`);
      if (element.matches('.fi-user-menu-trigger') && (bounds.width < 43.5 || bounds.height < 43.5)) failures.push('Account trigger is smaller than the 44px touch target');
      return [{ label, ...bounds }];
    });
    if (state === 'open' && asideRect && (asideRect.left < -1 || asideRect.right > root.clientWidth + 1)) failures.push('Open sidebar exceeds the viewport');
    if (state === 'display') {
      for (const selector of ['.fi-sidebar', '.fi-topbar', '.fi-header', '[data-admin-pwa-install]']) {
        const element = document.querySelector(selector);
        if (element && rect(element).width && rect(element).height) failures.push(`Display Mode leaves ${selector} visible`);
      }
    }
    for (const heading of document.querySelectorAll('h1, .fi-header-heading')) {
      const bounds = rect(heading);
      if (!bounds.width || !bounds.height) continue;
      if (bounds.left < -1 || bounds.right > root.clientWidth + 1) failures.push('Page title exceeds the viewport');
      // Mobile drawers intentionally overlay content. Desktop navigation must not.
      if (innerWidth >= 1024 && asideRect?.width && overlaps(bounds, asideRect)) failures.push('Desktop sidebar overlaps page title');
    }
    const records = Array.from(document.querySelectorAll('.mbfd-command-record, .mbfd-command-detail-list a')).map(record => {
      const bounds = rect(record);
      const label = record.querySelector(':scope > span');
      const time = record.querySelector(':scope > time');
      if (label && time && overlaps(rect(label), rect(time))) failures.push('Record label overlaps timestamp');
      if (label) {
        const walker = document.createTreeWalker(label, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) {
          const textNode = walker.currentNode;
          const range = document.createRange();
          range.selectNodeContents(textNode);
          for (const text of range.getClientRects()) {
            // Range includes unpainted lines behind line-clamp/overflow. Intersect
            // with actual clipping ancestors before reporting a visual collision.
            const painted = { left: text.left, right: text.right, top: text.top, bottom: text.bottom, width: text.width, height: text.height };
            for (let ancestor = textNode.parentElement; ancestor && ancestor !== document.body; ancestor = ancestor.parentElement) {
              const style = getComputedStyle(ancestor);
              const clip = rect(ancestor);
              if (/hidden|clip|auto|scroll/.test(style.overflowX)) {
                painted.left = Math.max(painted.left, clip.left);
                painted.right = Math.min(painted.right, clip.right);
              }
              if (/hidden|clip|auto|scroll/.test(style.overflowY)) {
                painted.top = Math.max(painted.top, clip.top);
                painted.bottom = Math.min(painted.bottom, clip.bottom);
              }
            }
            if (painted.right <= painted.left || painted.bottom <= painted.top) continue;
            if (painted.right > bounds.right + 1) failures.push('Painted record text exceeds record width');
            if (time && overlaps(painted, rect(time))) failures.push('Painted record text overlaps timestamp');
          }
        }
      }
      const next = record.nextElementSibling;
      if (next?.matches('.mbfd-command-record') && overlaps(bounds, rect(next))) failures.push('Adjacent command records overlap');
      return bounds;
    });
    const board = document.querySelector('.mbfd-station-command-board');
    const boardBounds = board ? rect(board) : null;
    // An ancestor can clip an oversized implicit grid without document overflow.
    // Check structural sections and visible controls against the board itself;
    // record text retains its intentional line clamps and inner scrolling.
    const boardParts = boardBounds ? Array.from(board!.querySelectorAll(
      '.mbfd-command-rail, .mbfd-command-master, .mbfd-command-stations, .mbfd-command-station-grid, .mbfd-command-master-card, .mbfd-command-station-card, .mbfd-command-master-card > header, .mbfd-command-station-card-header, .mbfd-command-count, .mbfd-command-card-link, .mbfd-command-actions, .mbfd-command-actions > *',
    )).flatMap(element => {
      const bounds = rect(element);
      if (!bounds.width || !bounds.height || getComputedStyle(element).visibility === 'hidden') return [];
      if (bounds.left < boardBounds.left - 1 || bounds.right > boardBounds.right + 1) failures.push(`Board part exceeds board bounds: ${element.className || element.tagName}`);
      return [{ selector: element.className || element.tagName, ...bounds }];
    }) : [];
    const grid = document.querySelector('.mbfd-command-master');
    return {
      innerWidth, innerHeight, outerWidth, outerHeight, devicePixelRatio,
      visualViewportScale: visualViewport?.scale, rootCssZoom: getComputedStyle(root).zoom,
      browserDisplayMode: matchMedia('(display-mode: browser)').matches,
      clientWidth: root.clientWidth, scrollWidth: root.scrollWidth,
      sidebarMode: innerWidth >= 1024 ? 'desktop' : 'overlay', sidebar: asideRect,
      board: boardBounds, boardParts,
      stylesheets: Array.from(document.styleSheets).map(sheet => sheet.href).filter(Boolean),
      gridColumns: grid ? getComputedStyle(grid).gridTemplateColumns : null,
      records, topbarControls, failures,
    };
  }, { state });
  const nativeLayout = await cdp.send('Page.getLayoutMetrics');
  const evidence = { requestedContentWidthAt100Percent: width, requestedZoomPercent: factor * 100, chromeTabZoom: zoom, state, nativeScreenshotBounds: nativeLayout.contentSize, ...result };
  await info.attach(`${name}-metrics`, { body: JSON.stringify(evidence, null, 2), contentType: 'application/json' });
  expect.soft(Math.abs(result.innerWidth - width / factor), `${name}: native zoom must change layout viewport`).toBeLessThanOrEqual(1);
  expect.soft(result.devicePixelRatio, `${name}: browser zoom DPR`).toBeCloseTo(factor, 2);
  expect.soft(result.visualViewportScale, `${name}: pinch zoom remains unused`).toBe(1);
  expect.soft(result.rootCssZoom, `${name}: CSS zoom remains unused`).toBe('1');
  expect.soft(result.browserDisplayMode, `${name}: normal browser tab, not PWA app mode`).toBe(true);
  expect.soft(result.failures, name).toEqual([]);
  const image = info.outputPath(`${name}.png`);
  // Playwright fullPage uses CSS bounds, which crops real browser zoom >100%.
  // CDP contentSize is the zoomed physical capture region; preserve native pixels.
  const screenshot = await cdp.send('Page.captureScreenshot', {
    format: 'png', captureBeyondViewport: true, clip: { ...nativeLayout.contentSize, scale: 1 },
  });
  const png = Buffer.from(screenshot.data, 'base64');
  expect(png.readUInt32BE(16), 'Screenshot must include the entire zoomed width').toBe(Math.ceil(nativeLayout.contentSize.width));
  writeFileSync(image, png);
  await info.attach(name, { path: image, contentType: 'image/png' });
}

test('native browser zoom preserves command board and ordinary reflow', async ({ baseURL }, info) => {
  test.setTimeout(180_000);
  expect(baseURL).toBe('http://127.0.0.1:8127');
  const width = info.project.use.viewport?.width;
  expect(width, 'Run through a protected UI width project').toBeDefined();
  const storage = info.project.use.storageState;
  expect(typeof storage, 'Use the isolated authenticated storage state').toBe('string');
  const state = JSON.parse(readFileSync(storage as string, 'utf8'));
  const extension = resolve('tests/e2e/support/protected-ui-zoom-extension');
  const context = await chromium.launchPersistentContext('', {
    channel: 'chromium', headless: true, viewport: null, serviceWorkers: 'allow',
    args: [`--disable-extensions-except=${extension}`, `--load-extension=${extension}`],
  });
  const failures: string[] = [];
  try {
    await context.addCookies(state.cookies);
    await context.addInitScript(origins => {
      const saved = origins.find((origin: { origin: string }) => origin.origin === location.origin);
      for (const entry of saved?.localStorage ?? []) localStorage.setItem(entry.name, entry.value);
    }, state.origins);
    const worker = context.serviceWorkers().find(candidate => candidate.url().startsWith('chrome-extension://'))
      ?? await context.waitForEvent('serviceworker', { predicate: candidate => candidate.url().startsWith('chrome-extension://') });
    const page = context.pages()[0];
    page.on('pageerror', error => failures.push(`pageerror: ${error.message}`));
    page.on('console', message => {
      if (['error', 'warning'].includes(message.type())) failures.push(`${message.type()}: ${message.text()}`);
    });
    page.on('requestfailed', request => failures.push(`requestfailed: ${request.url().split('?')[0]} ${request.failure()?.errorText}`));
    page.on('response', response => { if (response.status() >= 400) failures.push(`HTTP ${response.status()}: ${response.url().split('?')[0]}`); });
    const response = await page.goto(`${baseURL}/admin`);
    expect(response?.status()).toBe(200);
    expect(new URL(page.url()).pathname).toBe('/admin');
    await expect(page.locator('.mbfd-command-record').first()).toBeVisible();
    await page.waitForLoadState('networkidle');
    const cdp = await context.newCDPSession(page);
    // Resize the physical render frame, bypassing the OS window minimum at 390px.
    // This width stays constant across zoom factors. No device-metrics override.
    const frame = { width: width!, height: width! < 768 ? 844 : 1000 };
    await tabZoom(page, worker, 1);
    await cdp.send('Emulation.setVisibleSize', frame);
    await expect.poll(() => page.evaluate(() => innerWidth)).toBe(width);
    if (width === 1280) {
      // Prove Display Mode hides a real first-visit prompt before any dismissal.
      await page.goto(`${baseURL}/admin`);
      const banner = page.locator('[data-admin-pwa-install]');
      await expect(banner).toBeVisible({ timeout: 15_000 });
      await page.waitForLoadState('networkidle');
      await page.getByRole('link', { name: 'Display Mode', exact: true }).click();
      await expect(page.locator('body')).toHaveClass(/mbfd-command-display/);
      await expect(banner).toHaveCount(1, { timeout: 15_000 });
      await expect(banner).toBeHidden();
      await page.waitForLoadState('networkidle');
      await page.getByRole('link', { name: 'Exit Display Mode', exact: true }).click();
      await expect(banner).toBeVisible({ timeout: 15_000 });
      await banner.getByRole('button', { name: 'Not now', exact: true }).click();
      await expect(banner).toHaveCount(0);
      await page.waitForLoadState('networkidle');
      info.annotations.push({ type: 'install-prompt', description: 'First-visit install prompt was visible in ordinary mode and hidden in Display Mode before dismissal; exited Display Mode and clicked the real Not now button.' });
    }
    for (const factor of [0.8, 1, 1.25, 1.5, 2]) {
      await test.step(`${factor * 100}% actual tab zoom`, async () => {
        const boardResponse = await page.goto(`${baseURL}/admin`);
        expect(boardResponse?.status()).toBe(200);
        expect(new URL(page.url()).pathname).toBe('/admin');
        await expect(page.locator('.mbfd-command-record').first()).toBeVisible();
        await cdp.send('Emulation.setVisibleSize', frame);
        const zoom = await tabZoom(page, worker, factor);
        await sidebar(page, false);
        await capture(page, cdp, info, `zoom-${factor * 100}-closed`, width!, factor, zoom, 'closed');
        await sidebar(page, true);
        await capture(page, cdp, info, `zoom-${factor * 100}-open`, width!, factor, zoom, 'open');
        await sidebar(page, false);
        await page.getByRole('link', { name: 'Display Mode', exact: true }).click();
        await expect(page.locator('body')).toHaveClass(/mbfd-command-display/);
        await cdp.send('Emulation.setVisibleSize', frame);
        const displayZoom = await tabZoom(page, worker, factor);
        await capture(page, cdp, info, `zoom-${factor * 100}-display`, width!, factor, displayZoom, 'display');
      });
    }
    if (width === 1280) {
      for (const mode of [{ width: 320, factor: 1, label: '320px' }, { width: 1280, factor: 4, label: '400-percent' }]) {
        await tabZoom(page, worker, 1);
        for (const path of ['/account', '/updates', '/support/issues/create']) {
          const ordinaryResponse = await page.goto(`${baseURL}${path}`);
          expect(ordinaryResponse?.status()).toBe(200);
          expect(new URL(page.url()).pathname).toBe(path);
          await cdp.send('Emulation.setVisibleSize', { width: mode.width, height: 1000 });
          const zoom = await tabZoom(page, worker, mode.factor);
          await capture(page, cdp, info, `${mode.label}-${path.replaceAll('/', '-')}`, mode.width, mode.factor, zoom, 'ordinary');
          expect.soft(await page.evaluate(() => innerWidth)).toBe(320);
        }
      }
    }
    info.annotations.push({ type: 'zoom-method', description: 'Actual chrome.tabs.setZoom automatic/per-tab; normal headless Chromium tab, viewport:null, constant physical frame via Emulation.setVisibleSize. Every capture proves innerWidth, DPR, visualViewport.scale, CSS zoom and browser display mode.' });
  } finally {
    await info.attach('browser-failures', { body: JSON.stringify(failures, null, 2), contentType: 'application/json' });
    await context.close();
  }
  expect.soft(failures).toEqual([]);
});
