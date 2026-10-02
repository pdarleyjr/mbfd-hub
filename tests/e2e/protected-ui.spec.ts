import { expect, test, type Page, type TestInfo } from '@playwright/test';

const pages = [
  { name: 'hub', path: '/', back: false },
  { name: 'account', path: '/account', back: true },
  { name: 'city-email', path: '/account/city-email', back: false },
  { name: 'admin', path: '/admin', back: false },
  { name: 'apparatus', path: '/admin/apparatuses', back: false },
  { name: 'apparatus-view', path: '/admin/apparatuses/1', back: true },
  { name: 'apparatus-edit', path: '/admin/apparatuses/1/edit', back: true },
  { name: 'employees', path: '/admin/employees', back: false },
  { name: 'trt-inventory', path: '/admin/trt-trailer-inventory', back: false },
  { name: 'employee', path: '/employee/dashboard', back: false },
  { name: 'uniform-request', path: '/employee/request-equipment', back: true },
  { name: 'training', path: '/training', back: false },
  { name: 'workgroups', path: '/workgroups', back: false },
  { name: 'support-detail', path: '/support/issues/1', back: true, backPath: '/support/issues' },
  { name: 'support-create', path: '/support/issues/create', back: true, backPath: '/support/issues' },
  { name: 'update-detail', path: '/updates/1', back: true, backPath: '/updates' },
];

test('Home presents updates and Quick Access without requesting an incident feed', async ({ page }, info) => {
  test.skip(!['width-390', 'width-1440'].includes(info.project.name), 'Affected phone and desktop Home acceptance.');
  const errors: string[] = [];
  const incidentRequests: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('request', request => {
    if (new URL(request.url()).pathname === '/api/incidents') incidentRequests.push(request.url());
  });
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Department Updates', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Quick Access', exact: true })).toBeVisible();
  await expect(page.locator('[data-home-column="incidents"]')).toHaveCount(0);
  await expect(page.getByRole('link', { name: /PulsePoint/ })).toHaveCount(0);
  expect(incidentRequests).toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBeTruthy();
  expect(errors).toEqual([]);
  await screenshot(page, info, 'home-without-incident-feed');
});

test('populated TRT inventory retains readable statuses inside its table scroll region', async ({ page }, info) => {
  test.skip(!['width-390', 'width-1440'].includes(info.project.name), 'Populated phone and desktop inventory acceptance.');
  await page.goto('/admin/trt-trailer-inventory');
  await closeInitialMobileSidebar(page);
  const table = page.locator('main table');
  await expect(table).toContainText('Technical rescue confined-space retrieval');
  for (const status of ['Yes', 'No', 'Excellent', 'Poor', 'Keep', 'Replace']) {
    await expect(table.getByText(status, { exact: true })).toBeVisible();
  }
  const geometry = await table.evaluate(element => {
    const region = element.parentElement!;
    const bounds = region.getBoundingClientRect();
    const oldLeft = region.scrollLeft;
    region.scrollLeft = region.scrollWidth;
    const scrollable = region.scrollLeft > oldLeft;
    region.scrollLeft = oldLeft;
    return { left: bounds.left, right: bounds.right, viewport: innerWidth, documentWidth: document.documentElement.scrollWidth, overflow: getComputedStyle(region).overflowX, scrollable };
  });
  expect(geometry.documentWidth).toBeLessThanOrEqual(geometry.viewport + 1);
  expect(geometry.left).toBeGreaterThanOrEqual(0);
  expect(geometry.right).toBeLessThanOrEqual(geometry.viewport + 1);
  expect(geometry.overflow).toBe('auto');
  if (info.project.name === 'width-390') expect(geometry.scrollable).toBeTruthy();
  const contrast = await measureContrast(page, {
    present: 'main table tbody tr:nth-child(2) td:nth-child(3) span',
    condition: 'main table tbody tr:nth-child(2) td:nth-child(5) span',
    action: 'main table tbody tr:nth-child(2) td:nth-child(6) span',
    missing: 'main table tbody tr:nth-child(3) td:nth-child(3) span',
    poor: 'main table tbody tr:nth-child(3) td:nth-child(5) span',
    replace: 'main table tbody tr:nth-child(3) td:nth-child(6) span',
  });
  for (const item of contrast) expect(item.ratio, JSON.stringify(item)).toBeGreaterThanOrEqual(4.5);
  await info.attach('trt-table-geometry-and-contrast', { body: JSON.stringify({ geometry, contrast }), contentType: 'application/json' });
  await screenshot(page, info, 'trt-populated');
  const itemButton = table.getByRole('button', { name: /Technical rescue confined-space retrieval/ });
  expect((await itemButton.boundingBox())!.height).toBeGreaterThanOrEqual(44);
  await itemButton.focus();
  await page.keyboard.press('Enter');
  const dialog = page.getByRole('dialog', { name: /Technical rescue confined-space retrieval/ });
  await expect(dialog).toBeVisible();
  const close = dialog.getByRole('button', { name: 'Close item details' });
  await expect(close).toBeFocused();
  expect((await close.boundingBox())!.height).toBeGreaterThanOrEqual(44);
  const contentBounds = (await dialog.locator(':scope > div').boundingBox())!;
  expect(contentBounds.x).toBeGreaterThanOrEqual(0);
  expect(contentBounds.x + contentBounds.width).toBeLessThanOrEqual(page.viewportSize()!.width + 1);
  expect(contentBounds.y).toBeGreaterThanOrEqual(0);
  expect(contentBounds.y + contentBounds.height).toBeLessThanOrEqual(page.viewportSize()!.height + 1);
  await page.keyboard.press('Tab');
  expect(await dialog.evaluate(element => element.contains(document.activeElement))).toBeTruthy();
  await screenshot(page, info, 'trt-item-details-keyboard');
  const logo = await page.locator('.fi-sidebar img').first().getAttribute('src');
  expect(logo).toBeTruthy();
  info.annotations.push({ type: 'synthetic-ui-event', description: 'Dispatches the existing show-full-image UI event with the actual public Hub logo; this verifies dialog behavior, not uploaded inspection photo data.' });
  await close.focus();
  await page.evaluate(src => window.dispatchEvent(new CustomEvent('show-full-image', { detail: src })), logo);
  const photo = page.getByRole('dialog', { name: 'Inventory photo' });
  await expect(photo).toBeVisible();
  await expect(photo.getByRole('button', { name: 'Close photo' })).toBeFocused();
  await expect.poll(() => photo.locator('img').evaluate(element => (element as HTMLImageElement).complete && (element as HTMLImageElement).naturalWidth > 0)).toBeTruthy();
  const photoBounds = (await photo.locator('img').boundingBox())!;
  expect(photoBounds.x).toBeGreaterThanOrEqual(0);
  expect(photoBounds.y).toBeGreaterThanOrEqual(0);
  expect(photoBounds.x + photoBounds.width).toBeLessThanOrEqual(page.viewportSize()!.width + 1);
  expect(photoBounds.y + photoBounds.height).toBeLessThanOrEqual(page.viewportSize()!.height + 1);
  await page.keyboard.press('Tab');
  expect(await photo.evaluate(element => element.contains(document.activeElement))).toBeTruthy();
  await screenshot(page, info, 'trt-photo-dialog-synthetic-logo-event');
  await page.keyboard.press('Escape');
  await expect(photo).toBeHidden();
  await expect(dialog).toBeVisible();
  await expect(close).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(itemButton).toBeFocused();
});

async function screenshot(page: Page, info: TestInfo, name: string) {
  const scroll = await page.evaluate(() => ({ x: scrollX, y: scrollY }));
  for (const widget of await page.locator('[wire\\:snapshot]').all()) {
    const snapshot = await widget.getAttribute('wire:snapshot');
    if (snapshot && JSON.parse(snapshot).memo?.lazyLoaded === false) {
      if (!await widget.isVisible()) continue;
      await widget.scrollIntoViewIfNeeded({ timeout: 5_000 });
      await expect.poll(async () => {
        const current = await widget.getAttribute('wire:snapshot');
        return current ? JSON.parse(current).memo?.lazyLoaded : undefined;
      }, { message: 'Visible lazy widget must load before visual acceptance', timeout: 15_000 }).not.toBe(false);
    }
  }
  await page.evaluate(position => window.scrollTo(position.x, position.y), scroll);
  await page.evaluate(() => document.fonts.ready);
  await page.locator('main').evaluate(async element => {
    const animations = element.getAnimations({ subtree: true });
    for (let ancestor = element.parentElement; ancestor; ancestor = ancestor.parentElement) animations.push(...ancestor.getAnimations());
    await Promise.all(animations
      .filter(animation => animation.effect?.getTiming().iterations !== Infinity)
      .map(animation => animation.finished.catch(() => {})));
  });
  await page.screenshot({ path: info.outputPath(`${name}.png`), fullPage: true });
}

async function geometry(page: Page) {
  const result = await page.evaluate(() => {
    const width = document.documentElement.clientWidth;
    const failures: string[] = [];
    const paintedTextRects = (element: Element) => {
      const rectangles: Array<{ left: number; right: number; top: number; bottom: number }> = [];
      const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
      let node: Node | null;
      while ((node = walker.nextNode())) {
        if (!node.textContent?.trim()) continue;
        const range = document.createRange();
        range.selectNodeContents(node);
        for (const raw of range.getClientRects()) {
          const rect = { left: raw.left, right: raw.right, top: raw.top, bottom: raw.bottom };
          // A range includes text hidden by line-clamp and scrolling. Compare only painted pixels.
          for (let ancestor = node.parentElement; ancestor; ancestor = ancestor.parentElement) {
            const style = getComputedStyle(ancestor);
            const box = ancestor.getBoundingClientRect();
            if (/(hidden|clip|auto|scroll)/.test(style.overflowX)) {
              rect.left = Math.max(rect.left, box.left); rect.right = Math.min(rect.right, box.right);
            }
            if (/(hidden|clip|auto|scroll)/.test(style.overflowY)) {
              rect.top = Math.max(rect.top, box.top); rect.bottom = Math.min(rect.bottom, box.bottom);
            }
          }
          if (rect.right > rect.left && rect.bottom > rect.top) rectangles.push(rect);
        }
      }
      return rectangles;
    };
    if (document.documentElement.scrollWidth > width + 1) failures.push(`document overflow ${document.documentElement.scrollWidth}/${width}`);
    for (const element of document.querySelectorAll('h1, .fi-header-heading')) {
      const rect = element.getBoundingClientRect();
      if (rect.width && (rect.left < -1 || rect.right > width + 1)) failures.push(`title outside viewport: ${element.textContent?.trim()}`);
    }
    for (const record of document.querySelectorAll('.mbfd-command-record')) {
      const next = record.nextElementSibling;
      if (next && record.getBoundingClientRect().bottom > next.getBoundingClientRect().top + 1) failures.push('command record overlaps next record');
      for (const child of record.querySelectorAll('strong, small, time')) {
        for (const rect of paintedTextRects(child)) {
          if (next && rect.bottom > next.getBoundingClientRect().top + 1) failures.push('command text overlaps next record');
        }
      }
      const label = record.querySelector('span');
      const time = record.querySelector('time');
      if (!label || !time) continue;
      const a = label.getBoundingClientRect();
      const b = time.getBoundingClientRect();
      if (Math.min(a.right, b.right) > Math.max(a.left, b.left) + 1 && Math.min(a.bottom, b.bottom) > Math.max(a.top, b.top) + 1) failures.push('command label and timestamp boxes overlap');
      for (const text of paintedTextRects(label)) {
        if (text.right > record.getBoundingClientRect().right + 1) failures.push('command text exceeds record bounds');
        if (Math.min(text.right, b.right) > Math.max(text.left, b.left) + 1 && Math.min(text.bottom, b.bottom) > Math.max(text.top, b.top) + 1) failures.push('command text and timestamp overlap');
      }
    }
    return failures;
  });
  expect.soft(result).toEqual([]);
}

async function closeInitialMobileSidebar(page: Page) {
  if (page.viewportSize()!.width >= 1024) return;
  const close = page.locator('.fi-sidebar').getByRole('button', { name: 'Collapse sidebar', exact: true }).filter({ visible: true }).first();
  if (await close.count()) {
    await close.click();
    await expect(page.locator('.fi-topbar-open-sidebar-btn')).toBeVisible();
    await expect(page.locator('.fi-sidebar')).toBeHidden();
  }
}

async function sidebar(page: Page, info: TestInfo) {
  const aside = page.locator('.fi-sidebar');
  if (!await aside.count()) return;
  const expand = page.getByRole('button', { name: 'Expand sidebar', exact: true }).filter({ visible: true }).first();
  if (await expand.count()) await expand.click();
  await expect.poll(() => aside.evaluate(el => Math.round(el.getBoundingClientRect().left))).toBeGreaterThanOrEqual(0);
  const bounds = await aside.boundingBox();
  expect.soft(bounds!.width).toBeLessThanOrEqual(page.viewportSize()!.width);
  if (page.viewportSize()!.width >= 1024) {
    for (const heading of await page.locator('h1:visible').all()) {
      expect.soft((await heading.boundingBox())!.x, 'open sidebar must not cover page title').toBeGreaterThanOrEqual(bounds!.x + bounds!.width - 1);
    }
  }
  await geometry(page);
  await screenshot(page, info, 'sidebar-open');
  if (page.viewportSize()!.width < 1024) {
    const overlay = page.locator('.fi-sidebar-close-overlay');
    await expect(overlay).toBeVisible();
    // The topbar close control is covered by the native drawer; its exposed overlay is the intended close target.
    await overlay.click({ position: { x: page.viewportSize()!.width - 8, y: 100 } });
    await expect(expand).toBeVisible();
  } else {
    const close = aside.getByRole('button', { name: 'Collapse sidebar', exact: true }).filter({ visible: true }).first();
    if (!await close.count()) {
      info.annotations.push({ type: 'sidebar', description: 'This panel uses a fixed desktop sidebar; no collapse control is supplied.' });
      return;
    }
    await close.click();
    await expect(expand).toBeVisible();
  }
  await screenshot(page, info, 'sidebar-closed');
  await geometry(page);
}

for (const route of pages) test(`authenticated ${route.name} fits and remains navigable`, async ({ page }, info) => {
  test.skip(info.project.name === 'width-1280', '1280 is the dedicated command-board case.');
  const failures: string[] = [];
  page.on('pageerror', error => failures.push(`pageerror: ${error.stack || error.message}`));
  page.on('console', message => {
    if (message.text() === 'Service Worker registration blocked by Playwright') {
      info.annotations.push({ type: 'harness-warning', description: message.text() });
    } else if (['error', 'warning'].includes(message.type())) failures.push(`console ${message.type()}: ${message.text()}`);
  });
  page.on('requestfailed', request => failures.push(`requestfailed: ${request.url().split('?')[0]} ${request.failure()?.errorText}`));
  page.on('response', response => { if (response.status() >= 400) failures.push(`HTTP ${response.status()}: ${response.url().split('?')[0]}`); });
    await test.step(route.path, async () => {
      if (route.name === 'workgroups') {
        // Reproduce cross-panel persistence independently of whatever the auth setup stored.
        await page.addInitScript(() => localStorage.setItem('collapsedGroups', JSON.stringify(['Dashboard', 'Files', 'Personal', 'Fleet Management'])));
      }
      const response = await page.goto(route.path);
      expect.soft(response?.status(), route.path).toBe(200);
      expect.soft(new URL(page.url()).pathname, `Expected destination from ${route.path}`).toBe(route.path);
      await expect(page.locator('main')).toBeVisible();
      await closeInitialMobileSidebar(page);
      await screenshot(page, info, route.name);
      await geometry(page);
      if (route.name === 'trt-inventory') {
        expect((await page.locator('main table thead th').first().boundingBox())!.width, 'TRT item label column remains readable inside its scroll region').toBeGreaterThanOrEqual(192);
      }
      if (route.name === 'employee') {
        const contrast = await measureContrast(page, { empty: '.ep-empty p', link: '.ep-panel-link' });
        await info.attach('employee-contrast', { body: JSON.stringify(contrast, null, 2), contentType: 'application/json' });
        for (const item of contrast) expect.soft(item.ratio, JSON.stringify(item)).toBeGreaterThanOrEqual(4.5);
        for (const link of await page.locator('.ep-panel-link').all()) {
          expect.soft((await link.boundingBox())!.height).toBeGreaterThanOrEqual(44);
        }
        const rendering = await page.locator('.ep-hero').evaluate(element => {
          const ancestors = [];
          for (let node: Element | null = element; node; node = node.parentElement) {
            const style = getComputedStyle(node);
            ancestors.push({ element: `${node.tagName}.${node.className}`, opacity: style.opacity, filter: style.filter, backdropFilter: style.backdropFilter, background: style.backgroundColor });
          }
          return ancestors;
        });
        await info.attach('employee-rendering', { body: JSON.stringify(rendering, null, 2), contentType: 'application/json' });
      }
      if (route.name === 'uniform-request') {
        const contrast = await measureContrast(page, {
          primary: '.pr-primary-action', choicesPlaceholder: '.choices__placeholder',
          sizePlaceholder: 'input[placeholder="Examples: L, 34x32, 10.5"]',
        }, { sizePlaceholder: '::placeholder' });
        await info.attach('uniform-primary-contrast', { body: JSON.stringify(contrast, null, 2), contentType: 'application/json' });
        for (const item of contrast) expect.soft(item.ratio, JSON.stringify(item)).toBeGreaterThanOrEqual(4.5);
        const placeholders = await page.locator('input[placeholder]:not([disabled]):not([readonly]), textarea[placeholder]:not([disabled]):not([readonly]), .choices__placeholder').evaluateAll(elements => elements.map(element => {
          const style = getComputedStyle(element);
          const placeholder = getComputedStyle(element, element.matches('input, textarea') ? '::placeholder' : null);
          return { element: element.tagName, placeholder: element.getAttribute('placeholder') || element.textContent, color: placeholder.color, opacity: placeholder.opacity, background: style.backgroundColor, visible: element.getBoundingClientRect().width > 0 };
        }));
        await info.attach('active-placeholders', { body: JSON.stringify(placeholders, null, 2), contentType: 'application/json' });
      }
      if (route.name === 'support-create') {
        const contrast = await measureContrast(page, { supportPlaceholder: '#description' }, { supportPlaceholder: '::placeholder' });
        await info.attach('support-placeholder-contrast', { body: JSON.stringify(contrast, null, 2), contentType: 'application/json' });
        for (const item of contrast) expect.soft(item.ratio, JSON.stringify(item)).toBeGreaterThanOrEqual(4.5);
      }
      if (route.back) {
        const back = 'backPath' in route ? page.locator(`a[href="${route.backPath}"]`).first() : page.getByRole('link', { name: /^Back(?:\s|$)/i }).first();
        await expect.soft(back, `${route.path}: contextual return`).toBeVisible();
        if (await back.isVisible()) {
          expect.soft((await back.boundingBox())!.height, 'Back touch target').toBeGreaterThanOrEqual(44);
          expect.soft((await back.innerText()).trim(), 'Back must paint a readable label, not only an arrow').toMatch(/[A-Za-z]{3}/);
          if (page.viewportSize()!.width >= 640 && await back.locator('.hub-back-label').count()) {
            await expect.soft(back.locator('.hub-back-label'), 'Desktop Back destination label').toBeVisible();
          }
        }
      }
      if (['admin', 'employee', 'training', 'workgroups'].includes(route.name)) await sidebar(page, info);
    });
  await info.attach('browser-failures', { body: JSON.stringify(failures, null, 2), contentType: 'application/json' });
  expect.soft(failures).toEqual([]);
});

for (const route of ['/login', '/forgot-password']) test(`guest ${route} presentation`, async ({ page, context }, info) => {
  test.skip(info.project.name === 'width-1280', '1280 is the dedicated command-board case.');
  await context.clearCookies();
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.stack || error.message));
  const response = await page.goto(route);
  expect(response?.status()).toBe(200);
  await expect(page.locator('h1')).toBeVisible();
  await geometry(page);
  await screenshot(page, info, route.slice(1));
  expect(errors).toEqual([]);
});

test('command board long records and 200 percent equivalent reflow', async ({ page }, info) => {
  await page.goto('/admin');
  await closeInitialMobileSidebar(page);
  await expect(page.locator('.mbfd-command-record').first()).toBeVisible();
  await screenshot(page, info, 'board');
  await geometry(page);
  // This is CSS-pixel viewport reflow equivalent to 200% desktop zoom, NOT actual browser zoom.
  const viewport = page.viewportSize()!;
  if (viewport.width >= 768) {
    await page.setViewportSize({ width: Math.round(viewport.width / 2), height: Math.round(viewport.height / 2) });
    await closeInitialMobileSidebar(page);
    await screenshot(page, info, 'board-200-percent-equivalent-viewport');
    await geometry(page);
  }
  if (viewport.width === 1280) {
    await page.setViewportSize({ width: 320, height: 600 });
    await closeInitialMobileSidebar(page);
    await screenshot(page, info, 'board-400-percent-equivalent-viewport');
    await geometry(page);
  }
  info.annotations.push({ type: 'zoom-method', description: 'Half CSS-pixel viewport reflow. No browser zoom or deviceScaleFactor claim.' });
});

test('keyboard focus remains visible including beside the Report Issue launcher', async ({ page }, info) => {
  await page.goto('/admin');
  await closeInitialMobileSidebar(page);
  const occluded: string[] = [];
  let checked = 0;
  const inspectFocus = async () => {
    const result = await page.evaluate(() => {
      const active = document.activeElement;
      if (!(active instanceof HTMLElement) || active === document.body) return null;
      const rect = active.getBoundingClientRect();
      if (!rect.width || !rect.height) return null;
      const x = Math.max(0, Math.min(innerWidth - 1, rect.left + rect.width / 2));
      const y = Math.max(0, Math.min(innerHeight - 1, rect.top + rect.height / 2));
      const top = document.elementFromPoint(x, y);
      return {
        label: active.getAttribute('aria-label') || active.textContent?.trim().slice(0, 90),
        visible: top === active || active.contains(top),
        bounds: { left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom },
        coveredBy: top ? `${top.tagName}.${top.className}` : 'outside document',
      };
    });
    if (result) { checked++; if (!result.visible) occluded.push(JSON.stringify(result)); }
  };
  for (let i = 0; i < 45; i++) {
    await page.keyboard.press('Tab');
    await inspectFocus();
  }
  const record = page.locator('.mbfd-command-record').first();
  await record.focus();
  await record.scrollIntoViewIfNeeded();
  const recordFocus = await record.evaluate(element => {
    const style = getComputedStyle(element);
    return { style: style.outlineStyle, width: parseFloat(style.outlineWidth), color: style.outlineColor };
  });
  expect.soft(recordFocus.style).not.toBe('none');
  expect.soft(recordFocus.width).toBeGreaterThanOrEqual(2);
  expect.soft(recordFocus.color).not.toBe('rgba(0, 0, 0, 0)');
  await inspectFocus();
  await screenshot(page, info, 'board-record-keyboard-focus');
  const launcher = page.locator('[data-hub-issue-trigger]');
  await expect(launcher).toBeVisible();
  await launcher.focus();
  await launcher.scrollIntoViewIfNeeded();
  await inspectFocus();
  await page.keyboard.press('Shift+Tab');
  await inspectFocus();
  await screenshot(page, info, 'keyboard-focus');
  expect(checked).toBeGreaterThan(0);
  expect.soft(occluded).toEqual([]);
});

function measureContrast(page: Page, selections: Record<string, string>, pseudoSelectors: Record<string, string> = {}) {
  return page.evaluate(({ selections, pseudoSelectors }) => {
    const rgba = (value: string) => {
      const values = value.match(/[\d.]+/g)?.map(Number) ?? [];
      if (values.length < 3) throw new Error(`Unsupported computed color: ${value}`);
      return [values[0], values[1], values[2], values[3] ?? 1];
    };
    const luminance = (rgb: number[]) => rgb.slice(0, 3).map(value => {
      const channel = value / 255;
      return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
    }).reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0);
    return Object.entries(selections).map(([name, selector]) => {
      const element = document.querySelector(selector);
      if (!element) return { name, error: `Missing representative ${selector}`, ratio: 0 };
      const layers: Element[] = [];
      for (let node: Element | null = element; node; node = node.parentElement) layers.unshift(node);
      let background = [255, 255, 255];
      const imageChecks: Array<{ textRight: number; imageLeft: number }> = [];
      for (const node of layers) {
        const style = getComputedStyle(node);
        if (style.backgroundImage !== 'none') {
          // Native Choices uses a fixed-size, non-repeating right-hand chevron.
          // Exclude it only when computed geometry proves it cannot paint under this text.
          const offset = style.backgroundPositionX.match(/^(?:calc\(100% - |right )([\d.]+)px\)?$/)?.[1];
          const size = style.backgroundSize.split(' ')[0].match(/^([\d.]+)px$/)?.[1];
          if (!node.matches('.choices__inner') || style.backgroundRepeat !== 'no-repeat' || style.backgroundOrigin !== 'padding-box' || offset === undefined || size === undefined) {
            return { name, error: 'A background image requires visual contrast inspection', ratio: 0 };
          }
          const text = document.createRange();
          text.selectNodeContents(element);
          const textRight = text.getBoundingClientRect().right;
          const imageLeft = node.getBoundingClientRect().right - parseFloat(style.borderRightWidth) - Number(offset) - Number(size);
          if (textRight > imageLeft) return { name, error: 'Background image overlaps text', ratio: 0 };
          imageChecks.push({ textRight, imageLeft });
        }
        const color = rgba(style.backgroundColor);
        background = background.map((channel, i) => color[i] * color[3] + channel * (1 - color[3]));
      }
      const foregroundStyle = getComputedStyle(element, pseudoSelectors[name] ?? null);
      const color = rgba(foregroundStyle.color);
      color[3] *= Number(foregroundStyle.opacity);
      const foreground = background.map((channel, i) => color[i] * color[3] + channel * (1 - color[3]));
      const a = luminance(foreground), b = luminance(background);
      return { name, foreground, background, imageChecks, ratio: (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05) };
    });
  }, { selections, pseudoSelectors });
}

test('canonical action, body, muted, and semantic text have readable contrast and visible focus', async ({ page }, info) => {
  await page.goto('/admin');
  await closeInitialMobileSidebar(page);
  const checks = await measureContrast(page, {
    displayAction: '.fi-header .fi-btn.fi-color-primary',
    body: '.mbfd-command-master-card h2',
    muted: '.fi-header-subheading',
    warning: '.mbfd-command-count[data-tone="warning"]',
    success: '.mbfd-command-live-state[data-state="live"]',
  });
  await page.goto('/admin/apparatuses/1');
  await closeInitialMobileSidebar(page);
  checks.push(...await measureContrast(page, { ordinaryPrimary: '.fi-header .fi-btn.fi-color-primary' }));
  await info.attach('computed-contrast', { body: JSON.stringify(checks, null, 2), contentType: 'application/json' });
  for (const check of checks) expect.soft(check.ratio, `${check.name}: ${'error' in check ? check.error : JSON.stringify(check)}`).toBeGreaterThanOrEqual(4.5);
  await page.keyboard.press('Tab');
  const action = page.locator('.fi-header .fi-btn.fi-color-primary').first();
  await expect(action).toBeVisible();
  await action.focus();
  const focus = await action.evaluate(element => {
    const style = getComputedStyle(element);
    return { outlineStyle: style.outlineStyle, outlineWidth: parseFloat(style.outlineWidth), boxShadow: style.boxShadow };
  });
  expect.soft((focus.outlineStyle !== 'none' && focus.outlineWidth >= 2) || focus.boxShadow !== 'none', JSON.stringify(focus)).toBeTruthy();
  await screenshot(page, info, 'primary-keyboard-focus');
});

test('phone viewport orientation changes preserve board, table, and form containment', async ({ page }, info) => {
  test.skip(info.project.name !== 'width-390', 'One portrait-landscape-portrait rotation sequence.');
  for (const path of ['/admin', '/admin/apparatuses', '/employee/request-equipment']) {
    await page.goto(path);
    for (const viewport of [{ width: 390, height: 844 }, { width: 844, height: 390 }, { width: 390, height: 844 }]) {
      await page.setViewportSize(viewport);
      await closeInitialMobileSidebar(page);
      expect(await page.evaluate(() => matchMedia('(orientation: landscape)').matches)).toBe(viewport.width > viewport.height);
      await geometry(page);
      await screenshot(page, info, `${path.replaceAll('/', '-')}-${viewport.width}x${viewport.height}`);
    }
  }
});

test('Report Issue modal contains content and restores keyboard focus without submitting', async ({ page }, info) => {
  test.skip(!['width-390', 'width-1440'].includes(info.project.name), 'Phone and desktop modal acceptance.');
  await page.goto('/admin');
  await closeInitialMobileSidebar(page);
  const submissions: string[] = [];
  page.on('request', request => {
    if (request.method() === 'POST' && new URL(request.url()).pathname === '/support/issues') submissions.push(request.url());
  });
  const trigger = page.locator('[data-hub-issue-trigger]');
  const dialog = page.locator('[data-hub-issue-dialog]');
  await trigger.click();
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('textarea')).toBeFocused();
  const contrast = await measureContrast(page, { reportPlaceholder: '#hub-issue-description' }, { reportPlaceholder: '::placeholder' });
  await info.attach('report-placeholder-contrast', { body: JSON.stringify(contrast, null, 2), contentType: 'application/json' });
  for (const item of contrast) expect.soft(item.ratio, JSON.stringify(item)).toBeGreaterThanOrEqual(4.5);
  const box = (await dialog.boundingBox())!;
  expect(box.x).toBeGreaterThanOrEqual(0);
  expect(box.y).toBeGreaterThanOrEqual(0);
  expect(box.x + box.width).toBeLessThanOrEqual(page.viewportSize()!.width + 1);
  expect(box.y + box.height).toBeLessThanOrEqual(page.viewportSize()!.height + 1);
  await screenshot(page, info, 'report-issue-modal');
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(trigger).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(dialog).toBeVisible();
  const cancel = dialog.getByRole('button', { name: 'Cancel', exact: true });
  await cancel.focus();
  await page.keyboard.press('Enter');
  await expect(dialog).toBeHidden();
  await expect(trigger).toBeFocused();
  expect(submissions).toEqual([]);
});

test('topbar Home icon remains visible on hover with a touch sized target', async ({ page }, info) => {
  test.skip(!['width-390', 'width-1440'].includes(info.project.name), 'Phone and desktop topbar hover acceptance.');
  for (const path of ['/admin', '/training', '/workgroups']) {
    await page.goto(path);
    await closeInitialMobileSidebar(page);
    const home = page.locator('.fi-topbar a[aria-label="Return to Home"]');
    await expect(home).toBeVisible();
    const box = (await home.boundingBox())!;
    expect.soft(box.width).toBeGreaterThanOrEqual(44);
    expect.soft(box.height).toBeGreaterThanOrEqual(44);
    await home.hover();
    await home.evaluate(async element => Promise.all(element.getAnimations({ subtree: true }).map(animation => animation.finished.catch(() => {}))));
    const contrast = await measureContrast(page, { homeIcon: '.fi-topbar a[aria-label="Return to Home"] svg' });
    await info.attach(`home-hover-${path.slice(1)}`, { body: JSON.stringify(contrast, null, 2), contentType: 'application/json' });
    for (const result of contrast) expect.soft(result.ratio, JSON.stringify(result)).toBeGreaterThanOrEqual(3);
    await screenshot(page, info, `home-hover-${path.slice(1)}`);
  }
});

test('deferred table remains interactive when Alpine component modules arrive after its first morph', async ({ page }, info) => {
  test.skip(info.project.name !== 'width-1440', 'One controlled lazy-module race regression.');
  const failures: string[] = [];
  let delayedModules = 0;
  page.on('pageerror', error => failures.push(error.message));
  page.on('console', message => {
    if (message.text() === 'Service Worker registration blocked by Playwright') info.annotations.push({ type: 'harness-warning', description: message.text() });
    else if (['error', 'warning'].includes(message.type())) failures.push(message.text());
  });
  await page.route('**/js/filament/**/components/*.js*', async route => {
    delayedModules++;
    await new Promise(resolve => setTimeout(resolve, 750));
    await route.continue();
  });
  await page.goto('/admin/apparatuses');
  const table = page.locator('[x-data="table"]');
  await expect.poll(() => table.evaluate(element => (element as Element & { _x_async?: string })._x_async), { timeout: 15_000 }).toBe('loaded');
  await expect(table).not.toHaveAttribute('x-ignore');
  await page.getByRole('button', { name: 'Reset', exact: true }).click();
  await expect(page.locator('.fi-ta-row')).toHaveCount(5);
  const search = page.locator('.fi-ta-search-field input');
  await search.fill('E1');
  await expect(page.locator('.fi-ta-row')).toHaveCount(1);
  await expect(page.locator('.fi-ta-row')).toContainText('E1');
  await search.fill('');
  await expect(page.locator('.fi-ta-row')).toHaveCount(5);
  const unit = page.getByRole('button', { name: 'Unit', exact: true });
  const header = page.getByRole('columnheader').filter({ has: unit });
  const direction = await header.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
  await unit.click();
  await expect(header).toHaveAttribute('aria-sort', direction);
  if (direction === 'ascending') {
    await unit.click();
    await expect(header).toHaveAttribute('aria-sort', 'descending');
  }
  await expect(page.locator('.fi-ta-row').first()).toContainText('E6');
  await page.locator('.choices__inner').click();
  const dropdown = page.locator('.choices__list--dropdown.is-active');
  await expect(dropdown).toBeVisible();
  await dropdown.locator('.choices__item--choice:not([data-value=""])').first().click();
  await expect(page.locator('.fi-ta-row')).toHaveCount(1);
  await page.getByRole('button', { name: 'Reset', exact: true }).click();
  await expect(page.locator('.fi-ta-row')).toHaveCount(5);
  await page.waitForLoadState('networkidle');
  expect(delayedModules).toBeGreaterThanOrEqual(2);
  expect(failures).toEqual([]);
  await screenshot(page, info, 'deferred-table-interactive');
});
