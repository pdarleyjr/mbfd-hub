import { expect, test } from '@playwright/test';
import { copyFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { protectedUiInventoryEnvironment } from '../../playwright.protected-ui-inventory.config';

type Entry = { route: string; name: string; panel: string | null; scope: string; parent: string; route_name: string | null };
const catalog = JSON.parse(readFileSync(resolve('tests/e2e/support/protected-ui-inventory.json'), 'utf8')) as { pages: Entry[] };
const widths = [390, 768, 1440];
const origin = 'http://127.0.0.1:8127';
const serviceWorkers = process.env.PROTECTED_UI_INVENTORY_ALLOW_SW === '1' ? 'allow' as const : 'block' as const;

function redactEvidence(value: unknown): unknown {
  if (typeof value !== 'string') return value;
  return value
    .replace(/(\/(?:reset-password|account\/city-email\/verify)\/)(?!\{)[^/?#\s"']+/g, '$1[redacted]')
    .replace(/(\/member-onboarding\/invite#)[^\s"']+/g, '$1[redacted]')
    .replace(/([?&](?:token|hash|signature|email|employee_id)=)[^&#\s"']+/gi, '$1[redacted]')
    .replace(/\b[a-f0-9]{64}\b/gi, '[redacted-token-or-digest]');
}

function exclusion(entry: Entry): string | null {
  if (['/daily/vehicle-inspections/:slug', '/daily/apparatus/:slug'].includes(entry.route)) return 'Active InspectionWizard belongs to the coordinating Daily agent; no inspection flow entered.';
  return null;
}

function matcher(route: string): RegExp {
  return new RegExp(`^${route.split('/').map(segment => /^\{.+\}$|^:\w+$/.test(segment) ? '([^/?#]+)' : segment.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('/')}$`);
}

test('inventory records every route and renders available protected surfaces at three widths', async ({ browser }, info) => {
  test.setTimeout(30 * 60_000);
  expect(new URL(info.project.use.baseURL as string).origin).toBe(origin);
  expect(protectedUiInventoryEnvironment.WORKGROUP_AI_ENABLED).toBe('false');
  expect(protectedUiInventoryEnvironment.WORKGROUP_AI_WORKER_URL).toBe('');
  expect(protectedUiInventoryEnvironment.WORKGROUP_AI_WORKER_SECRET).toBe('');
  const context = await browser.newContext({ storageState: 'test-results/protected-ui-auth/state.json', serviceWorkers });
  const fixtureFile = resolve('test-results/protected-ui-auth/fixture-routes.json');
  const fixtureRoutes: Record<string, string> = existsSync(fixtureFile) ? JSON.parse(readFileSync(fixtureFile, 'utf8')) : {};
  const guestContext = await browser.newContext({ storageState: { cookies: [], origins: [] }, serviceWorkers });
  const onboardingContext = await browser.newContext({ storageState: 'test-results/protected-ui-auth/onboarding-state.json', serviceWorkers });
  const authenticatedPage = await context.newPage();
  const guestPage = await guestContext.newPage();
  const onboardingPage = await onboardingContext.newPage();
  let page = authenticatedPage;
  const links = new Set<string>();
  const resumeFile = process.env.PROTECTED_UI_INVENTORY_RESUME_FILE;
  const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
  const buildAssets = Object.fromEntries(Object.entries(manifest).filter(([, asset]) => (asset as { isEntry?: boolean }).isEntry).map(([entry, asset]) => [entry, (asset as { file: string }).file]));
  const outcomes: Record<string, unknown>[] = resumeFile ? JSON.parse(readFileSync(resumeFile, 'utf8')).outcomes : [];
  const artifactDirectory = process.env.PROTECTED_UI_INVENTORY_ARTIFACT_DIR;
  const preservedScreenshots = new Set<string>();
  for (const outcome of outcomes) for (const capture of outcome.captures as { screenshot: string }[]) preservedScreenshots.add(capture.screenshot);
  if (artifactDirectory) mkdirSync(resolve(artifactDirectory, 'inventory-captures'), { recursive: true });
  let blockedRequests: string[] = [];
  let browserErrors: string[] = [];
  let consoleMessages: { type: string; text: string; classification: string }[] = [];
  let resourceFailures: { url: string; detail: string; classification: string }[] = [];
  let serverUnavailable = false;
  for (const currentContext of [context, guestContext, onboardingContext]) currentContext.setDefaultTimeout(5_000);
  for (const currentPage of [authenticatedPage, guestPage, onboardingPage]) {
    currentPage.on('pageerror', error => browserErrors.push(error.message));
    currentPage.on('console', message => {
      if (['error', 'warning'].includes(message.type())) consoleMessages.push({ type: message.type(), text: message.text(),
        classification: /Service Worker registration blocked by Playwright/.test(message.text()) ? 'HARNESS_SERVICE_WORKER_BLOCK' : 'APP_CONSOLE' });
    });
    currentPage.on('requestfailed', request => resourceFailures.push({ url: request.url(), detail: request.failure()?.errorText || 'Request failed',
      classification: !['GET', 'HEAD', 'OPTIONS'].includes(request.method()) && blockedRequests.some(value => value.startsWith(`${request.method()} ${new URL(request.url()).pathname}:`)) ? 'HARNESS_NON_READ_BLOCK' : 'RESOURCE_FAILURE' }));
    currentPage.on('response', response => {
      if (response.status() >= 400) resourceFailures.push({ url: response.url(), detail: `HTTP ${response.status()}`, classification: 'HTTP_ERROR' });
    });
  }
  for (const currentContext of [context, guestContext, onboardingContext]) await currentContext.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (request.isNavigationRequest() && url.origin !== origin) {
      blockedRequests.push(`External navigation: ${url.origin}${url.pathname}`);
      return route.abort('blockedbyclient');
    }
    if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method())) {
      const payload = url.origin === origin && url.pathname === '/livewire/update' ? request.postDataJSON() : null;
      const components = payload?.components;
      const readOnlyRenderLoad = Array.isArray(components) && components.length > 0 && components.every(component => {
        const componentName = JSON.parse(component.snapshot || '{}').memo?.name || '';
        return Object.keys(component.updates || {}).length === 0 && Array.isArray(component.calls)
          && component.calls.length > 0 && component.calls.every(call => ['loadTable', '__lazyLoad', 'getFormUploadedFiles'].includes(call.method)
            || (call.method === '$refresh' && componentName.startsWith('pulse.'))
            || (call.method === 'loadAiReport' && componentName.endsWith('session-results-page') && new URL(currentContext.pages()[0]?.url() || origin).pathname === '/workgroups/session-results'));
      });
      if (!readOnlyRenderLoad) {
        blockedRequests.push(`${request.method()} ${url.pathname}: ${components?.flatMap(component => component.calls?.map(call => call.method) || []).join(',') || 'non-read request'}`);
        return route.abort('blockedbyclient');
      }
    }
    return route.continue();
  });

  const priority = (entry: Entry) => /analysis-report|final-presentation|saver-report|^\/member-onboarding$/.test(entry.route) ? -1 : Number(/[{:]/.test(entry.route));
  const entries = catalog.pages.map((entry, index) => ({ entry, index })).filter(({ entry }) => !outcomes.some(outcome => outcome.route === entry.route)).sort((a, b) => priority(a.entry) - priority(b.entry));
  const persist = () => {
    const serialized = JSON.stringify({
    fixture: 'Disposable local super_admin linked employee; no production acceptance claim',
    phase: resumeFile ? 'DISCOVERY_WITH_RESUMED_RESULTS: earlier outcomes may use an earlier candidate build' : 'FRESH_CANDIDATE_RENDER', buildAssets,
    testMeaning: 'Enumeration completion only. RENDERED is not visual acceptance; review geometry, browser errors, blocked requests, and screenshots.',
    baseline: 'Candidate working tree', widths, expectedRoutes: catalog.pages.length,
    outcomes: outcomes.toSorted((a, b) => Number(a.index) - Number(b.index)).map(outcome => ({ ...outcome, captureBuildAssets: outcome.captureBuildAssets || buildAssets })),
    }, (_key, value) => redactEvidence(value), 2);
    writeFileSync(info.outputPath('inventory-results.json'), serialized);
    if (artifactDirectory) {
      for (const outcome of outcomes) for (const capture of outcome.captures as { screenshot: string }[]) {
        if (!preservedScreenshots.has(capture.screenshot)) {
          copyFileSync(info.outputPath(capture.screenshot), resolve(artifactDirectory, 'inventory-captures', capture.screenshot));
          preservedScreenshots.add(capture.screenshot);
        }
      }
      writeFileSync(resolve(artifactDirectory, 'inventory-render-results.json'), serialized);
    }
  };

  for (const { entry, index } of entries) {
    page = /\/(login|forgot-password|reset-password)(?:\/|$)|^\/member-onboarding/.test(entry.route) ? guestPage : authenticatedPage;
    if (entry.route === '/member-onboarding') page = onboardingPage;
    const reason = exclusion(entry);
    if (reason || serverUnavailable) {
      outcomes.push({ index, ...entry, status: 'NOT_RENDERED', reason: reason || 'Local test server became unavailable.', captures: [] });
      persist();
      continue;
    }
    let target = entry.route;
    if (fixtureRoutes[entry.route]) {
      const fixture = new URL(fixtureRoutes[entry.route], origin);
      expect(fixture.origin, 'Fixture destinations must remain on the isolated local server').toBe(origin);
      expect(matcher(entry.route).test(fixture.pathname), 'Fixture URL must match the inventoried route').toBeTruthy();
      target = fixture.pathname + fixture.search + fixture.hash;
    } else if (/[{:]/.test(target)) {
      const pattern = matcher(target);
      const found = [...links].find(link => {
        const match = pattern.exec(new URL(link, origin).pathname);
        return match && match.slice(1).every(value => !['create', 'edit', 'export', 'download'].includes(value));
      });
      if (!found) {
        outcomes.push({ index, ...entry, status: 'NOT_RENDERED', reason: 'No matching record link discovered in the available list/detail fixtures.', captures: [] });
        persist();
        continue;
      }
      target = found;
    } else if (/evaluation-form-page|survey-form-page|survey-results-page/.test(target)) {
      const found = [...links].find(link => new URL(link, origin).pathname === target && new URL(link, origin).search);
      if (!found) {
        outcomes.push({ index, ...entry, status: 'NOT_RENDERED', reason: 'No authorized product/survey query link discovered in this fixture.', captures: [] });
        persist();
        continue;
      }
      target = found;
    }

    blockedRequests = [];
    browserErrors = [];
    consoleMessages = [];
    resourceFailures = [];
    try {
      await page.setViewportSize({ width: 1440, height: 1000 });
      const response = await page.goto(new URL(target, origin).href, { waitUntil: 'domcontentloaded', timeout: 15_000 });
      await page.locator('body').waitFor({ timeout: 3_000 });
      await page.waitForLoadState('networkidle', { timeout: 2_000 }).catch(() => undefined);
      for (const frame of page.frames().filter(frame => frame.url().startsWith(origin + '/'))) {
        const lazyWidgets = await frame.locator('[wire\\:snapshot]').all();
        for (const widget of lazyWidgets) {
          const snapshot = await widget.getAttribute('wire:snapshot').catch(() => null);
          if (snapshot && JSON.parse(snapshot).memo?.lazyLoaded === false && await widget.isVisible()) {
            await widget.scrollIntoViewIfNeeded().catch(() => undefined);
            await page.waitForTimeout(200);
            await page.waitForLoadState('networkidle', { timeout: 3_000 }).catch(() => undefined);
          }
        }
        await frame.evaluate(() => window.scrollTo(0, 0));
      }
      await page.evaluate(() => window.scrollTo(0, 0));
      await page.waitForTimeout(250);
      await page.evaluate(() => Promise.race([document.fonts.ready, new Promise(done => setTimeout(done, 1500))]));
      const final = new URL(page.url());
      const httpStatus = response?.status() ?? null;
      const redirected = final.pathname !== new URL(target, origin).pathname;
      const authRedirect = /\/(login|set-password)|city-email/.test(final.pathname) && final.pathname !== new URL(target, origin).pathname;
      const expectedLoginRedirect = /\/login$/.test(entry.route) && final.pathname === '/login';
      if (httpStatus !== 200 || (authRedirect && !expectedLoginRedirect)) {
        outcomes.push({ index, ...entry, requested: target, finalPath: final.pathname, httpStatus, status: 'BLOCKED',
          classification: httpStatus === 403 && entry.route.startsWith('/admin/fire-equipment-requests') ? 'BLOCKED_INTENTIONAL: FireEquipmentRequestResource::canViewAny/canView return false; disabled legacy route.' : undefined,
          reason: authRedirect ? 'Redirected to an authentication prerequisite.' : `HTTP ${httpStatus}`, captures: [], blockedRequests: [...blockedRequests], browserErrors: [...browserErrors], consoleMessages: [...consoleMessages], resourceFailures: [...resourceFailures] });
        persist();
        continue;
      }
      const discovered = await page.locator('a[href]').evaluateAll(elements => elements.map(el => (el as HTMLAnchorElement).href));
      for (const link of discovered) if (new URL(link).origin === origin) links.add(new URL(link).pathname + new URL(link).search);
      const captures: Record<string, unknown>[] = [];
      for (const width of widths) {
        await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
        await page.waitForTimeout(100);
        // Normalize only the navigation drawer, never a form or record action.
        const overlay = page.locator('.fi-sidebar-close-overlay');
        if (width < 1024 && await overlay.isVisible()) {
          await overlay.click({ position: { x: width - 8, y: 100 } });
          await expect(overlay).toBeHidden({ timeout: 5_000 });
          const sidebar = page.locator('.fi-sidebar');
          if (await sidebar.count()) await expect.poll(() => sidebar.evaluate(el => el.getBoundingClientRect().right <= 1 || getComputedStyle(el).display === 'none')).toBeTruthy();
        }
        const geometry = await page.evaluate(() => {
          const viewport = document.documentElement.clientWidth;
          const headings = [...document.querySelectorAll('h1, .fi-header-heading')].filter(el => (el as HTMLElement).offsetWidth > 0).map(el => {
            const box = el.getBoundingClientRect();
            return { text: el.textContent?.trim().slice(0, 100), left: box.left, right: box.right, outsideViewport: box.left < -1 || box.right > viewport + 1 };
          });
          return { viewport, documentWidth: document.documentElement.scrollWidth, documentOverflow: document.documentElement.scrollWidth > viewport + 1,
            headings, bodyFont: getComputedStyle(document.body).fontFamily,
            reportFooters: [...document.querySelectorAll('.report-footer')].map(el => {
              let surface: Element | null = el;
              while (surface?.parentElement && getComputedStyle(surface).backgroundColor === 'rgba(0, 0, 0, 0)') surface = surface.parentElement;
              return { color: getComputedStyle(el).color, fontSize: getComputedStyle(el).fontSize, background: surface ? getComputedStyle(surface).backgroundColor : null };
            }),
            aiDisabledStateVisible: document.body.innerText.includes('AI service not configured'),
            reportActions: [...document.querySelectorAll('.wg-report-actions .wg-ai-btn')].filter(el => (el as HTMLElement).offsetHeight > 0).map(el => {
              const box = el.getBoundingClientRect();
              return { text: (el as HTMLElement).innerText.trim(), left: box.left, right: box.right, height: box.height, outsideViewport: box.left < -1 || box.right > viewport + 1 };
            }),
            backLinks: [...document.querySelectorAll('[data-hub-back]')].map(el => ({ label: el.getAttribute('aria-label') || el.textContent?.trim(), visibleText: (el as HTMLElement).innerText.trim(),
              usefulVisibleLabel: viewport < 640 || ((el as HTMLElement).innerText.trim().length > 4 && (el as HTMLElement).innerText.trim() !== 'Back'),
              href: el.getAttribute('href'), tag: el.tagName, disabled: el instanceof HTMLButtonElement ? el.disabled : false })),
            tables: [...document.querySelectorAll('table')].filter(el => el.offsetHeight > 0).map(el => {
              const box = el.getBoundingClientRect();
              let parent = el.parentElement;
              while (parent && parent !== document.body && !['auto', 'scroll'].includes(getComputedStyle(parent).overflowX)) parent = parent.parentElement;
              return { width: box.width, outsideViewport: box.left < -1 || box.right > viewport + 1,
                scrollContainer: parent !== document.body && parent !== null ? { width: parent.clientWidth, contentWidth: parent.scrollWidth } : null };
            }),
            supportLinks: [...document.querySelectorAll('a[href]')].filter(el => /support\/issues\/create/.test(el.getAttribute('href') || '') && (el as HTMLElement).offsetHeight > 0).map(el => {
              const box = el.getBoundingClientRect();
              return { text: el.textContent?.trim(), left: box.left, top: box.top, right: box.right, bottom: box.bottom, position: getComputedStyle(el).position };
            }),
            emptyState: [...document.querySelectorAll('.fi-ta-empty-state')].some(el => (el as HTMLElement).offsetHeight > 0),
            unloadedLazyWidgets: [...document.querySelectorAll('[wire\\:snapshot]')].filter(el => {
              try { return (el as HTMLElement).offsetHeight > 0 && JSON.parse(el.getAttribute('wire:snapshot') || '{}').memo?.lazyLoaded === false; } catch { return false; }
            }).length,
            rowCount: document.querySelectorAll('.fi-ta-row').length };
        });
        if (entry.route === '/workgroups/session-results') {
          expect(geometry.reportActions.length).toBeGreaterThan(0);
          for (const action of geometry.reportActions) {
            expect(action.outsideViewport, `Report action must remain visible: ${action.text}`).toBe(false);
            expect(action.height).toBeGreaterThanOrEqual(44);
            expect(action.text).not.toContain('Generating...');
          }
        }
        const filename = `${String(index).padStart(3, '0')}-${entry.route.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'home'}-${width}.png`;
        const frames = [];
        for (const frame of page.frames().filter(frame => frame !== page.mainFrame())) {
          frames.push(frame.url().startsWith(origin + '/') ? await frame.evaluate(() => ({ path: location.pathname, status: 'SAME_ORIGIN_RENDERED',
            bodyTextLength: document.body.innerText.trim().length, documentWidth: document.documentElement.scrollWidth, viewport: document.documentElement.clientWidth,
            unloadedLazyWidgets: [...document.querySelectorAll('[wire\\:snapshot]')].filter(el => {
              try { return (el as HTMLElement).offsetHeight > 0 && JSON.parse(el.getAttribute('wire:snapshot') || '{}').memo?.lazyLoaded === false; } catch { return false; }
            }).length })) : { status: 'INNER_CONTENT_NOT_VERIFIED', url: frame.url() });
        }
        await page.screenshot({ path: info.outputPath(filename), fullPage: true, animations: 'disabled', timeout: 15_000 });
        captures.push({ width, screenshot: filename, geometry, frames });
      }
      await page.waitForLoadState('networkidle', { timeout: 2_000 }).catch(() => undefined);
      outcomes.push({ index, ...entry, requested: target, finalPath: final.pathname, httpStatus, status: redirected ? 'NOT_RENDERED' : 'RENDERED',
        reason: redirected ? 'Requested route redirects; screenshots prove its destination only, not a distinct page at this route.' : undefined,
        evidence: redirected ? 'REDIRECT TARGET ONLY' : /^\/daily\/.*success$/.test(entry.route) ? 'Success-page layout opened directly without submission context; no successful operational submission is claimed.' : /saver-report/.test(entry.route) ? 'Template/layout fixture only: cached synthetic report content; operational report generation is not verified.' : /video-conferencing|pump-simulator/.test(entry.route) ? 'Local entry shell only; no conference, external integration, or simulator operation verified.' : 'Authorized page shell rendered; content may be an empty fixture.', captures,
        blockedRequests: [...blockedRequests], browserErrors: [...browserErrors], consoleMessages: [...consoleMessages], resourceFailures: [...resourceFailures] });
    } catch (error) {
      const message = String(error);
      serverUnavailable = /ERR_CONNECTION_REFUSED|ECONNREFUSED/.test(message);
      outcomes.push({ index, ...entry, requested: target, status: 'BLOCKED', reason: message.slice(0, 1000), captures: [], blockedRequests: [...blockedRequests], browserErrors: [...browserErrors], consoleMessages: [...consoleMessages], resourceFailures: [...resourceFailures] });
    }
    persist();
    console.log(`Inventory ${outcomes.length}/${catalog.pages.length}: ${entry.route} ${outcomes.at(-1)?.status}`);
  }
  await context.close();
  await guestContext.close();
  await onboardingContext.close();
  await info.attach('inventory-results', { path: info.outputPath('inventory-results.json'), contentType: 'application/json' });
  expect(outcomes).toHaveLength(catalog.pages.length);
});
