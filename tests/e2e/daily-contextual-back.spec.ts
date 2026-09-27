import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { contextualBackLabel, contextualBackPath, safeContextualPath } from '../../resources/js/daily-checkout/src/utils/contextualBack';

const origin = 'http://127.0.0.1:8127';

test('contextual resolver preserves valid Hub destinations and Daily legacy paths', () => {
  for (const [candidate, expected] of [
    ['/stations/2/rooms/7?tab=events#history', '/daily/stations/2/rooms/7?tab=events#history'],
    ['/forms-hub', '/daily/forms-hub'],
    ['/daily/stations/2', '/daily/stations/2'],
    [`${origin}/admin/stations?page=3`, '/admin/stations?page=3'],
    ['/support/issues', '/support/issues'],
    ['/', '/'],
  ]) expect(safeContextualPath(candidate, origin)).toBe(expected);
});

test('contextual resolver rejects external, ambiguous, auth and non UI targets', () => {
  for (const candidate of [
    'https://example.test/admin', '//example.test/admin', `${origin.replace('8127', '8128')}/admin`,
    'javascript:alert(1)', 'data:text/html,test', '/admin\\evil', '/admin/%2f%2fevil', '/daily/../admin',
    '/daily/%2e%2e/admin', '/admin/%252f', '/admin\n', '/admin?x=%0a',
    '/admin/login', '/logout', '/api/public/stations', '/storage/report', 'admin',
    'http://user:password@127.0.0.1:8127/admin',
  ]) expect(safeContextualPath(candidate, origin), candidate).toBeNull();
});

test('contextual resolver chooses explicit return then safe parent and avoids current page', () => {
  expect(contextualBackPath('?return_to=%2Fadmin%2Fstations%3Fpage%3D3', '/stations', origin, '/daily/stations/1')).toBe('/admin/stations?page=3');
  expect(contextualBackPath('?return_to=https%3A%2F%2Fevil.test', '/forms-hub', origin, '/daily/forms-hub/station-request')).toBe('/daily/forms-hub');
  expect(contextualBackPath('?return_to=%2Fstations%2F1', '/stations', origin, '/daily/stations/1')).toBe('/daily/stations');
  expect(contextualBackPath('', 'https://evil.test', origin, '/daily/stations')).toBe('/');
  expect(contextualBackLabel('/daily/stations/1/rooms/7?tab=events')).toBe('Back to room');
});

test('Daily contextual Back follows canonical parents instead of unrelated browser history', async ({ page }, info) => {
  test.setTimeout(120_000);
  test.skip(!['width-390', 'width-1440'].includes(info.project.name), 'Canonical local fixture at phone and desktop widths.');
  const fixtureRoutes = JSON.parse(readFileSync('test-results/protected-ui-auth/fixture-routes.json', 'utf8'));
  const roomPath = fixtureRoutes['/daily/stations/:stationId/rooms/:roomId'];
  const stationPath = fixtureRoutes['/daily/stations/:id'];
  expect(roomPath, 'Protected UI seeder supplies a real local room').toMatch(/^\/daily\/stations\/\d+\/rooms\/\d+$/);
  expect(stationPath).toMatch(/^\/daily\/stations\/\d+$/);
  const stationId = stationPath.split('/').at(-1);
  const cases = [
    { path: roomPath, target: roomPath.replace(/\/rooms\/\d+$/, ''), label: 'Back to station' },
    { path: stationPath, target: '/daily/stations', label: 'Back to stations' },
    { path: '/daily/forms-hub', target: '/daily/stations', label: 'Back to stations' },
    { path: '/daily/vehicle-inspections', target: '/daily/stations', label: 'Back to stations' },
    { path: '/daily/forms-hub/station-inventory', target: '/daily/forms-hub', label: 'Back to Forms Hub' },
    { path: '/daily/forms-hub/trt-inventory', target: '/daily/forms-hub', label: 'Back to Forms Hub' },
    { path: '/daily/forms-hub/station-inspection', target: '/daily/forms-hub', label: 'Back to Forms Hub' },
    { path: `/daily/forms-hub/station-request?station_id=${stationId}`, target: stationPath, label: 'Back to station' },
    { path: '/daily/forms-hub/station-request?return_to=%2Fadmin%2Fstations', target: '/admin/stations', label: 'Back to Administration' },
    { path: '/daily/forms-hub/station-request?return_to=https%3A%2F%2Fexample.test', target: '/daily/forms-hub', label: 'Back to Forms Hub' },
  ];
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => {
    if (message.text() === 'Service Worker registration blocked by Playwright') info.annotations.push({ type: 'harness-warning', description: message.text() });
    else if (['error', 'warning'].includes(message.type())) errors.push(`console ${message.type()}: ${message.text()}`);
  });
  page.on('requestfailed', request => errors.push(`requestfailed: ${new URL(request.url()).pathname} ${request.failure()?.errorText}`));
  page.on('response', response => { if (response.status() >= 400) errors.push(`HTTP ${response.status()}: ${new URL(response.url()).pathname}`); });
  for (const item of cases) {
    await page.goto('/account', { waitUntil: 'networkidle' });
    await page.goto(item.path);
    const back = page.getByRole('button', { name: item.label, exact: true }).first();
    await expect(back).toBeVisible();
    expect((await back.innerText()).trim()).toBe(page.viewportSize()!.width >= 640 ? item.label : 'Back');
    const box = (await back.boundingBox())!;
    expect(box.height).toBeGreaterThanOrEqual(44);
    expect(box.width).toBeGreaterThanOrEqual(44);
    await page.keyboard.press('Tab');
    await back.focus();
    const focus = await back.evaluate(element => {
      const style = getComputedStyle(element);
      const box = element.getBoundingClientRect();
      const top = document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2);
      return { visible: style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) >= 2 || style.boxShadow !== 'none', unobscured: element === top || element.contains(top) };
    });
    expect(focus).toEqual({ visible: true, unobscured: true });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBeTruthy();
    const support = page.locator('.hub-issue-trigger');
    const placement = await page.locator('.hub-issue-widget').evaluate(element => ({
      top: element.getBoundingClientRect().top,
      mainBottom: document.querySelector('main')!.getBoundingClientRect().bottom,
      position: getComputedStyle(element.querySelector('.hub-issue-trigger')!).position,
    }));
    expect(placement.position).toBe('static');
    expect(placement.top).toBeGreaterThanOrEqual(placement.mainBottom - 1);
    await support.focus();
    expect(await support.evaluate(element => {
      const box = element.getBoundingClientRect();
      const hit = document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2);
      return hit === element || element.contains(hit);
    })).toBeTruthy();
    await back.focus();
    await page.screenshot({ path: info.outputPath(`back-${cases.indexOf(item)}.png`), fullPage: true });
    await page.waitForLoadState('networkidle');
    await page.keyboard.press('Enter');
    await expect.poll(() => new URL(page.url()).pathname).toBe(item.target);
    // A client-side route can retain the previous document's networkidle state.
    // Require the destination content to mount before settling its requests.
    const destinationHeading = item.target === '/daily/stations' ? 'MBFD Stations'
      : item.target === '/daily/forms-hub' ? 'Forms Hub'
      : item.target === '/admin/stations' ? 'Stations' : `Station ${stationId}`;
    await expect(page.getByRole('heading', { name: destinationHeading, exact: true })).toBeVisible();
    await page.waitForLoadState('networkidle');
    await expect.poll(() => page.locator('main img').evaluateAll(images => images.every(image => (image as HTMLImageElement).complete))).toBeTruthy();
  }
  expect(errors).toEqual([]);
});

test('Daily issue dialog opens and restores focus without submitting', async ({ page }, info) => {
  test.skip(!['width-390', 'width-1440'].includes(info.project.name), 'Phone and desktop Daily modal acceptance.');
  const submitted: string[] = [];
  page.on('request', request => { if (request.method() === 'POST' && new URL(request.url()).pathname === '/support/issues') submitted.push(request.url()); });
  await page.goto('/daily/forms-hub');
  const trigger = page.locator('.hub-issue-trigger');
  await trigger.click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(page.locator('#hub-daily-issue-description')).toBeFocused();
  const bounds = (await dialog.boundingBox())!;
  expect(bounds.x).toBeGreaterThanOrEqual(0);
  expect(bounds.y).toBeGreaterThanOrEqual(0);
  expect(bounds.x + bounds.width).toBeLessThanOrEqual(page.viewportSize()!.width + 1);
  expect(bounds.y + bounds.height).toBeLessThanOrEqual(page.viewportSize()!.height + 1);
  await page.screenshot({ path: info.outputPath('daily-report-issue-modal.png'), fullPage: true });
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(trigger).toBeFocused();
  expect(submitted).toEqual([]);
});

test('Station Request actions do not cover focused form fields', async ({ page }, info) => {
  test.skip(!['width-390', 'width-768', 'width-1440'].includes(info.project.name), 'Phone, tablet and desktop focus containment.');
  const fixtureRoutes = JSON.parse(readFileSync('test-results/protected-ui-auth/fixture-routes.json', 'utf8'));
  const stationId = fixtureRoutes['/daily/stations/:id'].split('/').at(-1);
  await page.goto(`/daily/forms-hub/station-request?station_id=${stationId}`, { waitUntil: 'networkidle' });
  await page.keyboard.press('Tab');
  const measurements = [];
  for (const control of await page.locator('main input, main select, main textarea, main button, main a').all()) {
    if (!await control.isVisible() || await control.isDisabled()) continue;
    await control.focus();
    measurements.push(await control.evaluate(element => {
      const bounds = element.getBoundingClientRect();
      const hit = document.elementFromPoint(bounds.x + bounds.width / 2, bounds.y + bounds.height / 2);
      return { tag: element.tagName, label: element.getAttribute('aria-label') || element.getAttribute('placeholder') || element.textContent?.trim().slice(0, 80), visible: hit === element || element.contains(hit), coveredBy: hit ? `${hit.tagName}.${hit.className}` : 'outside viewport' };
    }));
  }
  await info.attach('station-request-focus', { body: JSON.stringify(measurements, null, 2), contentType: 'application/json' });
  expect(measurements.length).toBeGreaterThan(5);
  expect(measurements.filter(item => !item.visible)).toEqual([]);
});
