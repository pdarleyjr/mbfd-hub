import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { copyFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { protectedUiInventoryEnvironment } from '../../playwright.protected-ui-inventory.config';
import { guardUiRendering, waitForUiRendering } from './support/ui-readonly';

type Entry = { route: string; name: string; panel: string | null; scope: string; parent: string; route_name: string | null };
const catalog = JSON.parse(readFileSync(resolve('tests/e2e/support/protected-ui-inventory.json'), 'utf8')) as { pages: Entry[] };
const widths = [320, 390, 820, 1024, 1280, 1440, 1920];
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
  if (['/daily/vehicle-inspections/:slug', '/daily/apparatus/:slug', '/daily/forms-hub/station-inspection'].includes(entry.route)) return 'Frozen active inspection: verified separately by the dedicated disposable Daily flow suite; this read-only inventory does not enter inspection flows.';
  return null;
}

function structuralClassification(entry: Entry) {
  const route = entry.route;
  const securityMechanicsFrozen = /^\/(login|forgot-password|reset-password|member-onboarding|account\/city-email)(\/|$)|\/(login|set-password)$/.test(route);
  const base = { securityMechanicsFrozen, meaning: 'Source classification only; does not establish rendering or visual acceptance.' };
  if (exclusion(entry)) return { ...base, category: 'FROZEN_SYSTEM', reason: exclusion(entry), source: 'resources/js/daily-checkout/src/App.tsx; catalog active-inspection scope' };
  if (route === '/admin/fire-equipment-requests' || route === '/admin/fire-equipment-requests/{record}') return {
    ...base, category: 'FROZEN_SYSTEM', reason: 'Legacy resource intentionally denies both list and record viewing.',
    source: 'app/Filament/Resources/FireEquipmentRequestResource.php::canViewAny/canView',
  };
  if (/^\/(admin|employee|training|workgroups)\/login$/.test(route)) return {
    ...base, category: 'AUTH_REDIRECT', reason: 'Panel login entry redirects to the canonical /login screen.',
    source: 'app/Http/Controllers/Auth/CanonicalPanelLoginRedirectController.php',
  };
  const redirects: Record<string, { reason: string; source: string }> = {
    '/employee': { reason: 'Filament panel home redirects to its registered dashboard.', source: 'EmployeePanelProvider.php; catalog filament.employee.home route' },
    '/admin/my-profile': { reason: 'Legacy profile entry redirects to password, employee, or account profile according to the current account.', source: 'app/Filament/Pages/MyProfile.php::mount' },
    '/admin/personnel-uniforms-equipment': { reason: 'Filament cluster entry redirects to its first accessible sub-navigation item.', source: 'vendor/filament/filament/src/Clusters/Cluster.php::mount' },
    '/daily': { reason: 'Daily root navigates to /daily/stations.', source: 'resources/js/daily-checkout/src/App.tsx' },
    '/daily/forms-hub/big-ticket-request': { reason: 'Legacy entry navigates to the canonical station request route.', source: 'resources/js/daily-checkout/src/components/LegacyStationRequestRedirect.tsx' },
    '/daily/forms-hub/equipment-request': { reason: 'Legacy entry navigates to the canonical station request route.', source: 'resources/js/daily-checkout/src/components/LegacyStationRequestRedirect.tsx' },
  };
  if (redirects[route]) return { ...base, category: 'ROUTE_REDIRECT', ...redirects[route] };
  if (/[{:]/.test(route) || ['/workgroups/evaluation-form-page', '/workgroups/survey-form-page', '/workgroups/survey-results-page', '/member-onboarding/invite', '/member-onboarding', '/workgroup/saver-report'].includes(route)) return {
    ...base, category: 'DYNAMIC_FIXTURE', reason: route === '/member-onboarding' ? 'Requires the disposable pending-member onboarding session.' : 'Requires a valid authorized record, query parameter, or local token fixture.',
    source: 'database/seeders/ProtectedUiInventorySeeder.php; test-results/protected-ui-auth fixture route keys or onboarding-state.json',
  };
  return { ...base, category: 'RENDERABLE', reason: 'Source defines a distinct visual page; runtime prerequisites and acceptance remain to be checked.', source: entry.route_name ?? entry.name };
}

function dependencyState(entry: Entry) {
  if (/video-conferencing/.test(entry.route)) return {
    kind: 'CONFERENCE_ENTRY_SHELL', source: 'ConferencePageController.php; conference.enabled configuration',
    limitation: 'The configured enabled/unavailable screen is evidence only for the entry shell. Camera, microphone, calls, lineup controls, and external media are not exercised.',
  };
  if (entry.route === '/pump-simulator') return {
    kind: 'SIMULATOR_ENTRY', source: 'resources/views/pump-simulator.blade.php; resources/js/pump-simulator/App.tsx',
    limitation: 'Local entry rendering does not establish simulator operation or an external integration.',
  };
  if (entry.route === '/workgroups/session-results') return {
    kind: 'AI_DISABLED_LOCAL', source: 'playwright.protected-ui-inventory.config.ts; SessionResultsPage.php',
    limitation: 'WORKGROUP_AI_ENABLED=false and empty Worker URL/secret. The disabled state and ordinary report layout are reviewed; AI generation is not verified.',
  };
  if (entry.route === '/workgroup/saver-report') return {
    kind: 'CACHED_SYNTHETIC_REPORT', source: 'database/seeders/ProtectedUiInventorySeeder.php',
    limitation: 'A cached synthetic report exercises the report template. Operational report generation is not verified.',
  };
  if (/^\/daily\/.*success$/.test(entry.route)) return {
    kind: 'SUCCESS_LAYOUT_WITHOUT_SUBMISSION', source: 'resources/js/daily-checkout/src/components/SuccessPage.tsx',
    limitation: 'Direct navigation supplies no submission context. The displayed confirmation is not proof of a recorded inspection or successful synchronization.',
  };
  if (entry.route === '/admin/pulse' || entry.route === '/pulse') return {
    kind: 'LOCAL_MONITORING', source: 'resources/views/filament/pages/pulse-dashboard.blade.php; Laravel Pulse',
    limitation: 'Local monitoring presentation and same-origin frame facts do not establish production monitoring health.',
  };
  return null;
}

function matcher(route: string): RegExp {
  return new RegExp(`^${route.split('/').map(segment => /^\{.+\}$|^:\w+$/.test(segment) ? '([^/?#]+)' : segment.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('/')}$`);
}

test('inventory records every route and renders available protected surfaces at seven widths', async ({ browser }, info) => {
  test.setTimeout(2 * 60 * 60_000);
  expect(new URL(info.project.use.baseURL as string).origin).toBe(origin);
  expect(protectedUiInventoryEnvironment.WORKGROUP_AI_ENABLED).toBe('false');
  expect(protectedUiInventoryEnvironment.WORKGROUP_AI_WORKER_URL).toBe('');
  expect(protectedUiInventoryEnvironment.WORKGROUP_AI_WORKER_SECRET).toBe('');
  const context = await browser.newContext({ storageState: 'test-results/protected-ui-auth/state.json', serviceWorkers });
  const canonicalContext = await context.request.get(`${origin}/api/me/context`, { headers: { Accept: 'application/json', Origin: origin, Referer: origin + '/admin' } });
  expect(canonicalContext.status(), 'Inventory starts with a current canonical employee session').toBe(200);
  const fixtureFile = resolve('test-results/protected-ui-auth/fixture-routes.json');
  const fixtureRoutes: Record<string, string> = existsSync(fixtureFile) ? JSON.parse(readFileSync(fixtureFile, 'utf8')) : {};
  const redirectDestinations: Record<string, string | undefined> = {
    '/employee': '/employee/dashboard',
    '/admin/my-profile': fixtureRoutes['/admin/employees/{record}/edit'],
    '/admin/personnel-uniforms-equipment': '/admin/personnel-uniforms-equipment/overview',
    '/daily': '/daily/stations',
    '/daily/forms-hub/big-ticket-request': '/daily/forms-hub/station-request',
    '/daily/forms-hub/equipment-request': '/daily/forms-hub/station-request',
  };
  const guestContext = await browser.newContext({ storageState: { cookies: [], origins: [] }, serviceWorkers });
  const onboardingContext = await browser.newContext({ storageState: 'test-results/protected-ui-auth/onboarding-state.json', serviceWorkers });
  // Only these source-defined push settings pages exercise native registration.
  const pushSettingsContext = await browser.newContext({ storageState: 'test-results/protected-ui-auth/state.json', serviceWorkers: 'allow' });
  const authenticatedPage = await context.newPage();
  const guestPage = await guestContext.newPage();
  const onboardingPage = await onboardingContext.newPage();
  const pushSettingsPage = await pushSettingsContext.newPage();
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
  let consoleMessages: { type: string; text: string; url: string; classification: string }[] = [];
  let resourceFailures: { url: string; detail: string; classification: string }[] = [];
  let serverUnavailable = false;
  for (const currentContext of [context, guestContext, onboardingContext, pushSettingsContext]) currentContext.setDefaultTimeout(5_000);
  for (const currentPage of [authenticatedPage, guestPage, onboardingPage, pushSettingsPage]) {
    currentPage.on('pageerror', error => browserErrors.push(error.message));
    currentPage.on('console', message => {
      if (['error', 'warning'].includes(message.type())) consoleMessages.push({ type: message.type(), text: message.text(), url: message.location().url,
        classification: /Service Worker registration blocked by Playwright/.test(message.text()) ? 'HARNESS_SERVICE_WORKER_BLOCK' : 'APP_CONSOLE' });
    });
    currentPage.on('requestfailed', request => resourceFailures.push({ url: request.url(), detail: request.failure()?.errorText || 'Request failed',
      classification: !['GET', 'HEAD', 'OPTIONS'].includes(request.method()) && blockedRequests.some(value => value.startsWith(`${request.method()} ${new URL(request.url()).pathname}:`)) ? 'HARNESS_NON_READ_BLOCK' : 'RESOURCE_FAILURE' }));
    currentPage.on('response', response => {
      if (response.status() >= 400) resourceFailures.push({ url: response.url(), detail: `HTTP ${response.status()}`, classification: 'HTTP_ERROR' });
    });
  }
  for (const currentContext of [context, guestContext, onboardingContext, pushSettingsContext]) await guardUiRendering(currentContext, origin, {
    allowDisabledAiReport: true,
    onBlocked: description => blockedRequests.push(description),
  });

  const priority = (entry: Entry) => /analysis-report|final-presentation|saver-report|^\/member-onboarding$/.test(entry.route) ? -1 : Number(/[{:]/.test(entry.route));
  const entries = catalog.pages.map((entry, index) => ({ entry, index })).filter(({ entry }) => !outcomes.some(outcome => outcome.route === entry.route)).sort((a, b) => priority(a.entry) - priority(b.entry));
  const persist = () => {
    const serialized = JSON.stringify({
    fixture: 'Disposable local super_admin linked employee; no production acceptance claim',
    phase: resumeFile ? 'DISCOVERY_WITH_RESUMED_RESULTS: earlier outcomes may use an earlier candidate build' : 'FRESH_CANDIDATE_RENDER', buildAssets,
    testMeaning: 'Source classification and runtime evidence with explicit automated rendering, layout, and accessibility assertions. Passing checks do not establish human visual or operational acceptance.',
    geometryAcceptance: {
      images: 'Resource images intersecting the viewport must finish with naturalWidth > 0. Completed rendered images with naturalWidth 0 fail. Native offscreen lazy images may remain pending.',
      tables: 'Rendered table bounds, or their native auto/scroll viewport, must fit within 1px of the document viewport without a hidden/clip ancestor cutting them. This is CSS geometry evidence, not a keyboard/touch scrolling test.',
    },
    baseline: 'Candidate working tree', widths, expectedRoutes: catalog.pages.length,
    discoveredInternalLinks: [...links].sort(),
    sourceClassification: catalog.pages.map(entry => ({ route: entry.route, ...structuralClassification(entry),
      fixtureRouteMapped: Boolean(fixtureRoutes[entry.route]), sessionFixture: entry.route === '/member-onboarding' ? 'onboarding-state.json' : null, dependencyState: dependencyState(entry) })),
    outcomes: outcomes.toSorted((a, b) => Number(a.index) - Number(b.index)).map(outcome => ({ ...outcome,
      structuralClassification: structuralClassification(outcome as Entry), dependencyState: dependencyState(outcome as Entry), captureBuildAssets: outcome.captureBuildAssets || buildAssets })),
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
    if (['/admin/settings', '/training/settings'].includes(entry.route)) page = pushSettingsPage;
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
    const captures: Record<string, unknown>[] = [];
    const validationErrors: string[] = [];
    const recordRenderCheck = async (label: string, check: () => Promise<unknown>) => {
      try {
        await check();
      } catch (error) {
        validationErrors.push(`${label}: ${String(error).slice(0, 1000)}`);
      }
    };
    const canonicalSessionChecks: { stage: string; status: number }[] = [];
    const checkCanonicalSession = async (stage: string) => {
      if (!['/employee/video-conferencing/command', '/employee/my-requests/{personnelRequest}', '/daily/stations/:id'].includes(entry.route)) return;
      const response = await context.request.get(`${origin}/api/me/context`, { headers: { Accept: 'application/json', Origin: origin, Referer: origin + '/admin' } });
      canonicalSessionChecks.push({ stage, status: response.status() });
      if (response.status() !== 200) validationErrors.push(`Canonical employee session ${stage}: HTTP ${response.status()}`);
    };
    try {
      await checkCanonicalSession('before');
      await page.setViewportSize({ width: 1440, height: 1000 });
      const response = await page.goto(new URL(target, origin).href, { waitUntil: 'domcontentloaded', timeout: 15_000 });
      await page.locator('body').waitFor({ timeout: 3_000 });
      if (entry.route === '/daily/stations/:id') {
        await recordRenderCheck('Station detail heading', () => expect(page.getByRole('heading', { name: /^Station \d/ }).first()).toBeVisible({ timeout: 10_000 }));
      }
      await page.waitForLoadState('networkidle', { timeout: 2_000 }).catch(() => undefined);
      await page.evaluate(() => Promise.race([document.fonts.ready, new Promise(done => setTimeout(done, 1500))]));
      const final = new URL(page.url());
      const httpStatus = response?.status() ?? null;
      const redirected = final.pathname !== new URL(target, origin).pathname;
      const authRedirect = /\/(login|set-password)|city-email/.test(final.pathname) && final.pathname !== new URL(target, origin).pathname;
      const expectedLoginRedirect = /\/login$/.test(entry.route) && final.pathname === '/login';
      if (httpStatus !== 200 || (authRedirect && !expectedLoginRedirect)) {
        outcomes.push({ index, ...entry, requested: target, finalPath: final.pathname, httpStatus, status: 'BLOCKED',
          classification: httpStatus === 403 && entry.route.startsWith('/admin/fire-equipment-requests') ? 'BLOCKED_INTENTIONAL: FireEquipmentRequestResource::canViewAny/canView return false; disabled legacy route.' : undefined,
          reason: authRedirect ? 'Redirected to an authentication prerequisite.' : `HTTP ${httpStatus}`, captures, validationErrors, canonicalSessionChecks,
          blockedRequests: [...blockedRequests], browserErrors: [...browserErrors], consoleMessages: [...consoleMessages], resourceFailures: [...resourceFailures] });
        persist();
        continue;
      }
      await recordRenderCheck('Native content readiness before link discovery', () => waitForUiRendering(page, origin));
      const discovered = await page.locator('a[href]').evaluateAll(elements => elements.map(el => (el as HTMLAnchorElement).href));
      for (const link of discovered) if (new URL(link).origin === origin) links.add(new URL(link).pathname + new URL(link).search);
      for (const width of widths) {
        await page.setViewportSize({ width, height: width < 1024 ? 844 : 1000 });
        await page.waitForTimeout(100);
        // Normalize only the navigation drawer, never a form or record action.
        const overlay = page.locator('.fi-sidebar-close-overlay');
        if (width < 1024 && await overlay.isVisible()) {
          await overlay.click({ position: { x: width - 8, y: 100 } });
          await recordRenderCheck(`${width}px: Sidebar overlay closes`, () => expect(overlay).toBeHidden({ timeout: 5_000 }));
          const sidebar = page.locator('.fi-sidebar');
          if (await sidebar.count()) await recordRenderCheck(`${width}px: Mobile sidebar is hidden`, () => expect.poll(() => sidebar.evaluate(el => el.getBoundingClientRect().right <= 1 || getComputedStyle(el).display === 'none')).toBeTruthy());
        }
        let renderingReadiness: Awaited<ReturnType<typeof waitForUiRendering>> | null = null;
        await recordRenderCheck(`${width}px: Native content readiness`, async () => { renderingReadiness = await waitForUiRendering(page, origin); });
        // Full-page screenshots do not trigger below-fold native lazy images.
        if (/^\/workgroups\/(?:analysis-report|evaluation-report|final-recommendations|workgroup-summary|l1-inventory)$/.test(entry.route)) {
          for (const image of await page.locator('img[loading="lazy"]').all()) {
            if (!await image.isVisible()) continue;
            await image.scrollIntoViewIfNeeded({ timeout: 5_000 });
            await recordRenderCheck(`${width}px: Lazy report image loads`, () => expect.poll(() => image.evaluate(el => (el as HTMLImageElement).complete && (el as HTMLImageElement).naturalWidth > 0), { timeout: 10_000 }).toBeTruthy());
          }
          await page.evaluate(async () => { await Promise.all([...document.images].filter(img => img.complete && img.naturalWidth > 0).map(img => img.decode().catch(() => undefined))); window.scrollTo(0, 0); });
          // Bring each chart into view before checking pixels: browsers may throttle
          // offscreen canvas animations after a responsive resize.
          for (const canvas of await page.locator('canvas').all()) {
            if (!await canvas.isVisible()) continue;
            await canvas.scrollIntoViewIfNeeded({ timeout: 5_000 });
            await recordRenderCheck(`${width}px: Chart fits its container`, () => expect.poll(() => canvas.evaluate(el => el.getBoundingClientRect().width <= (el.parentElement?.clientWidth ?? 0) + 1), { timeout: 10_000 }).toBeTruthy());
            let previous = '', stable = 0;
            await recordRenderCheck(`${width}px: Chart pixels settle`, () => expect.poll(async () => {
              const pixels = await canvas.evaluate(el => (el as HTMLCanvasElement).toDataURL());
              stable = pixels === previous ? stable + 1 : 0;
              previous = pixels;
              return stable;
            }, { timeout: 10_000, intervals: [150] }).toBeGreaterThanOrEqual(3));
          }
          await page.evaluate(() => window.scrollTo(0, 0));
          await recordRenderCheck(`${width}px: Report has no document overflow`, () => expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1), { timeout: 10_000 }).toBeTruthy());
        }
        // Native lazy images below the viewport may legitimately be pending.
        // Images currently on screen must finish loading and decode as an image.
        await recordRenderCheck(`${width}px: Viewport images load`, () => expect.poll(() => page.evaluate(() => [...document.images].filter(img => {
          const style = getComputedStyle(img), box = img.getBoundingClientRect();
          return Boolean(img.currentSrc || img.getAttribute('src')) && style.display !== 'none' && style.visibility !== 'hidden'
            && box.width > 0 && box.height > 0 && box.bottom > 0 && box.top < innerHeight && box.right > 0 && box.left < innerWidth
            && (!img.complete || img.naturalWidth === 0);
        }).map(img => img.currentSrc || img.getAttribute('src'))), { timeout: 5_000 }).toEqual([]));
        const geometry = await page.evaluate(() => {
          const viewport = document.documentElement.clientWidth;
          const visible = (el: Element) => {
            const style = getComputedStyle(el);
            return style.display !== 'none' && style.visibility !== 'hidden' && el.getClientRects().length > 0 && !el.closest('[aria-hidden="true"], [inert]');
          };
          // Supplemental DOM label facts; Axe below performs semantic name checks.
          const controlName = (el: Element) => {
            const labelledBy = (el.getAttribute('aria-labelledby') || '').split(/\s+/).filter(Boolean)
              .map(id => document.getElementById(id)?.textContent || '').join(' ').trim();
            const labels = el instanceof HTMLInputElement || el instanceof HTMLSelectElement || el instanceof HTMLTextAreaElement
              ? [...(el.labels || [])].map(label => label.textContent || '').join(' ').trim() : '';
            const buttonValue = el instanceof HTMLInputElement && ['submit', 'reset', 'button'].includes(el.type)
              ? el.value || (el.type === 'submit' ? 'Submit' : el.type === 'reset' ? 'Reset' : '') : '';
            return (labelledBy || el.getAttribute('aria-label') || labels || el.getAttribute('title') || (el as HTMLElement).innerText
              || el.querySelector('img[alt]')?.getAttribute('alt') || el.querySelector('svg title')?.textContent || buttonValue || '').trim();
          };
          const h1 = [...document.querySelectorAll('h1')].filter(visible).map(el => el.textContent?.trim().replace(/\s+/g, ' ').slice(0, 160));
          const unnamedControls = [...document.querySelectorAll('a[href], button, [role="button"], input:not([type="hidden"]), select, textarea')]
            .filter(el => visible(el) && !controlName(el)).map(el => ({ tag: el.tagName.toLowerCase(), id: el.id || null, role: el.getAttribute('role'), type: el.getAttribute('type') }));
          const headings = [...document.querySelectorAll('h1, .fi-header-heading')].filter(el => (el as HTMLElement).offsetWidth > 0).map(el => {
            const box = el.getBoundingClientRect();
            return { text: el.textContent?.trim().slice(0, 100), left: box.left, right: box.right, outsideViewport: box.left < -1 || box.right > viewport + 1 };
          });
          return { viewport, documentWidth: document.documentElement.scrollWidth, documentOverflow: document.documentElement.scrollWidth > viewport + 1,
            headings, title: document.title, h1, visibleH1Count: h1.length, singleVisibleH1: h1.length === 1,
            totalH1Count: document.querySelectorAll('h1').length, unnamedControls, unnamedControlCount: unnamedControls.length,
            controlNameMethod: 'Supplemental DOM label heuristic; consult Axe findings for semantic accessibility.', bodyFont: getComputedStyle(document.body).fontFamily,
            reportFooters: [...document.querySelectorAll('.report-footer')].map(el => {
              let surface: Element | null = el;
              while (surface?.parentElement && getComputedStyle(surface).backgroundColor === 'rgba(0, 0, 0, 0)') surface = surface.parentElement;
              return { color: getComputedStyle(el).color, fontSize: getComputedStyle(el).fontSize, background: surface ? getComputedStyle(surface).backgroundColor : null };
            }),
            headerActions: [...document.querySelectorAll('.fi-header .fi-ac .fi-btn, .fi-header .fi-ac .fi-icon-btn')].filter(el => (el as HTMLElement).offsetHeight > 0).map(el => {
              const box = el.getBoundingClientRect(), header = el.closest('.fi-header')!.getBoundingClientRect();
              return { text: el.textContent?.trim() || el.getAttribute('aria-label'), left: box.left, right: box.right, top: box.top, bottom: box.bottom,
                outsideHeader: box.left < header.left - 1 || box.right > header.right + 1 || box.top < header.top - 1 || box.bottom > header.bottom + 1,
                outsideViewport: box.left < -1 || box.right > viewport + 1 };
            }),
            incompleteImages: [...document.images].filter(img => getComputedStyle(img).display !== 'none' && (!img.complete || img.naturalWidth === 0)).map(img => img.getAttribute('src')),
            brokenImages: [...document.images].filter(img => {
              const style = getComputedStyle(img), box = img.getBoundingClientRect();
              return Boolean(img.currentSrc || img.getAttribute('src')) && style.display !== 'none' && style.visibility !== 'hidden'
                && box.width > 0 && box.height > 0 && img.complete && img.naturalWidth === 0;
            }).map(img => img.currentSrc || img.getAttribute('src')),
            aiDisabledStateVisible: document.body.innerText.includes('AI service not configured'),
            reportActions: [...document.querySelectorAll('.wg-report-actions .wg-ai-btn')].filter(el => (el as HTMLElement).offsetHeight > 0).map(el => {
              const box = el.getBoundingClientRect();
              return { text: (el as HTMLElement).innerText.trim(), left: box.left, right: box.right, height: box.height, outsideViewport: box.left < -1 || box.right > viewport + 1 };
            }),
            backLinks: [...document.querySelectorAll('[data-hub-back]')].map(el => ({ label: el.getAttribute('aria-label') || el.textContent?.trim(), visibleText: (el as HTMLElement).innerText.trim(),
              usefulVisibleLabel: viewport < 640 || ((el as HTMLElement).innerText.trim().length > 4 && (el as HTMLElement).innerText.trim() !== 'Back'),
              href: el.getAttribute('href'), tag: el.tagName, disabled: el instanceof HTMLButtonElement ? el.disabled : false })),
            tables: [...document.querySelectorAll('table')].filter(visible).map(el => {
              const box = el.getBoundingClientRect();
              let reachableBounds = { left: box.left, right: box.right };
              let parent = el.parentElement;
              let scrollContainer: { width: number; contentWidth: number } | null = null;
              const clippingAncestors: string[] = [];
              // A bounded auto/scroll ancestor makes a wide table reachable.
              // Hidden/clip ancestors that cut that table or its scrollport do not.
              while (parent && parent !== document.documentElement) {
                const overflow = getComputedStyle(parent).overflowX;
                const parentBox = parent.getBoundingClientRect();
                const left = parentBox.left + parent.clientLeft, right = left + parent.clientWidth;
                if (['hidden', 'clip'].includes(overflow) && (reachableBounds.left < left - 1 || reachableBounds.right > right + 1)) {
                  clippingAncestors.push(parent.id || parent.className || parent.tagName.toLowerCase());
                }
                if (['auto', 'scroll'].includes(overflow)) {
                  scrollContainer ??= { width: parent.clientWidth, contentWidth: parent.scrollWidth };
                  reachableBounds = { left, right };
                }
                parent = parent.parentElement;
              }
              return { width: box.width, outsideViewport: box.left < -1 || box.right > viewport + 1,
                scrollContainer, clippingAncestors,
                horizontalContentReachable: clippingAncestors.length === 0 && reachableBounds.left >= -1 && reachableBounds.right <= viewport + 1 };
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
          if (geometry.reportActions.length === 0) validationErrors.push(`${width}px: No report actions rendered.`);
          for (const action of geometry.reportActions) {
            if (action.outsideViewport) validationErrors.push(`${width}px: Report action outside viewport: ${action.text}`);
            if (action.height < 44) validationErrors.push(`${width}px: Report action below 44px: ${action.text}`);
            if (action.text.includes('Generating...')) validationErrors.push(`${width}px: Report action stuck generating: ${action.text}`);
          }
        }
        if ([9, 125, 127, 140].includes(index)) {
          if (geometry.headerActions.some(action => action.outsideHeader || action.outsideViewport)) validationErrors.push(`${width}px: Header actions extend outside their header or viewport.`);
        }
        let axeSeriousCritical: { id: string; impact: string | null | undefined; description: string; help: string; helpUrl: string; targets: string[][]; failureSummaries: (string | undefined)[] }[] = [];
        let axeError: string | null = null;
        try {
          const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
          axeSeriousCritical = axe.violations.filter(violation => ['serious', 'critical'].includes(violation.impact ?? ''))
            .map(violation => ({ id: violation.id, impact: violation.impact, description: violation.description, help: violation.help, helpUrl: violation.helpUrl,
              targets: violation.nodes.map(node => node.target.map(selector => String(selector))), failureSummaries: violation.nodes.map(node => node.failureSummary) }));
        } catch (error) {
          axeError = String(error).slice(0, 1000);
          validationErrors.push(`${width}px: Axe did not complete: ${axeError}`);
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
        captures.push({ width, screenshot: filename, geometry, frames, axeSeriousCritical, axeError, renderingReadiness });
      }
      await page.waitForLoadState('networkidle', { timeout: 2_000 }).catch(() => undefined);
      await checkCanonicalSession('after');
      outcomes.push({ index, ...entry, requested: target, finalPath: final.pathname, httpStatus, status: redirected ? 'NOT_RENDERED' : 'RENDERED',
        reason: redirected ? 'Requested route redirects; screenshots prove its destination only, not a distinct page at this route.' : undefined,
        evidence: redirected ? 'REDIRECT TARGET ONLY' : /^\/daily\/.*success$/.test(entry.route) ? 'Success-page layout opened directly without submission context; no successful operational submission is claimed.' : /saver-report/.test(entry.route) ? 'Template/layout fixture only: cached synthetic report content; operational report generation is not verified.' : /video-conferencing|pump-simulator/.test(entry.route) ? 'Local entry shell only; no conference, external integration, or simulator operation verified.' : 'Authorized page shell rendered; content may be an empty fixture.', captures,
        validationErrors, canonicalSessionChecks, blockedRequests: [...blockedRequests], browserErrors: [...browserErrors], consoleMessages: [...consoleMessages], resourceFailures: [...resourceFailures] });
    } catch (error) {
      const message = String(error);
      serverUnavailable = /ERR_CONNECTION_REFUSED|ECONNREFUSED/.test(message);
      outcomes.push({ index, ...entry, requested: target, status: 'BLOCKED', reason: message.slice(0, 1000), captures, validationErrors, canonicalSessionChecks, blockedRequests: [...blockedRequests], browserErrors: [...browserErrors], consoleMessages: [...consoleMessages], resourceFailures: [...resourceFailures] });
    }
    persist();
    console.log(`Inventory ${outcomes.length}/${catalog.pages.length}: ${entry.route} ${outcomes.at(-1)?.status}`);
  }
  const finalCanonicalContext = await context.request.get(`${origin}/api/me/context`, {
    headers: { Accept: 'application/json', Origin: origin, Referer: origin + '/admin' }, maxRedirects: 0,
  });
  expect(finalCanonicalContext.status(), 'Completed inventory retains its canonical employee session').toBe(200);
  // Preserve this same session's refreshed cookie expiry for the read-only link follow-up.
  await context.storageState({ path: 'test-results/protected-ui-auth/state.json' });
  await context.close();
  await guestContext.close();
  await onboardingContext.close();
  await pushSettingsContext.close();
  await info.attach('inventory-results', { path: info.outputPath('inventory-results.json'), contentType: 'application/json' });
  expect(outcomes).toHaveLength(catalog.pages.length);
  // All route evidence is written before acceptance assertions so one failure
  // cannot discard the rest of the catalog or already completed captures.
  for (const outcome of outcomes) {
    const entry = outcome as Entry;
    const source = structuralClassification(entry);
    const frozenInspection = Boolean(exclusion(entry));
    const intentionalDenial = source.category === 'FROZEN_SYSTEM' && !frozenInspection;
    const sourceRedirect = ['AUTH_REDIRECT', 'ROUTE_REDIRECT'].includes(source.category);
    const captures = outcome.captures as {
      width: number;
      geometry: { documentOverflow: boolean; visibleH1Count: number; h1: string[]; unnamedControlCount: number; unloadedLazyWidgets: number;
        brokenImages: string[]; tables: { clippingAncestors: string[]; horizontalContentReachable: boolean }[] };
      frames: { status: string; documentWidth?: number; viewport?: number; bodyTextLength?: number; unloadedLazyWidgets?: number }[];
      axeSeriousCritical?: unknown[];
      axeError?: string | null;
    }[];
    expect.soft(outcome.status, `${outcome.route}: source-defined render outcome`).toBe(frozenInspection || sourceRedirect ? 'NOT_RENDERED' : intentionalDenial ? 'BLOCKED' : 'RENDERED');
    if (frozenInspection) {
      expect.soft(outcome.reason, `${outcome.route}: dedicated Daily flow exclusion`).toBe(exclusion(entry));
      expect.soft(captures, `${outcome.route}: active inspections stay outside this inventory`).toEqual([]);
    } else {
      expect.soft(outcome.httpStatus, `${outcome.route}: expected HTTP result`).toBe(intentionalDenial ? 403 : 200);
      if (intentionalDenial) {
        expect.soft(outcome.classification, `${outcome.route}: source-disabled legacy resource`).toBe('BLOCKED_INTENTIONAL: FireEquipmentRequestResource::canViewAny/canView return false; disabled legacy route.');
        expect.soft(outcome.finalPath, `${outcome.route}: denial belongs to the requested resource`).toBe(new URL(String(outcome.requested), origin).pathname);
        expect.soft(captures, `${outcome.route}: disabled resource is not visual acceptance`).toEqual([]);
      } else {
        expect.soft(captures.map(capture => capture.width), `${outcome.route}: complete seven-width evidence`).toEqual(widths);
        if (sourceRedirect) {
          const destination = source.category === 'AUTH_REDIRECT' ? '/login' : redirectDestinations[entry.route];
          expect.soft(destination, `${outcome.route}: source redirect has an authorized fixture destination`).toBeTruthy();
          if (destination) expect.soft(outcome.finalPath, `${outcome.route}: source redirect reaches its registered destination`).toBe(new URL(destination, origin).pathname);
        }
      }
    }
    // The disabled legacy resource's exact main-document 403 is an expected
    // source contract. All other resource failures remain failing evidence.
    const unexpectedResourceFailures = (outcome.resourceFailures as { url: string; detail: string; classification: string }[] | undefined ?? []).filter(failure => {
      if (!intentionalDenial || failure.classification !== 'HTTP_ERROR' || failure.detail !== 'HTTP 403') return true;
      const failedUrl = new URL(failure.url);
      return failedUrl.origin !== origin || failedUrl.pathname !== new URL(String(outcome.requested), origin).pathname;
    });
    const unexpectedConsoleErrors = (outcome.consoleMessages as { type: string; text: string; url?: string; classification: string }[] | undefined ?? []).filter(message => {
      if (message.type !== 'error' || message.classification !== 'APP_CONSOLE') return false;
      if (!intentionalDenial || !message.url || !/^Failed to load resource: the server responded with a status of 403\b/.test(message.text)) return true;
      const failedUrl = new URL(message.url, origin);
      return failedUrl.origin !== origin || failedUrl.pathname !== new URL(String(outcome.requested), origin).pathname;
    });
    expect.soft(outcome.blockedRequests ?? [], `${outcome.route}: no unexplained blocked requests`).toEqual([]);
    expect.soft(outcome.browserErrors ?? [], `${outcome.route}: no browser exceptions`).toEqual([]);
    expect.soft(unexpectedConsoleErrors, `${outcome.route}: no unexpected console errors`).toEqual([]);
    expect.soft(unexpectedResourceFailures, `${outcome.route}: no unexpected resource or HTTP errors`).toEqual([]);
    expect.soft(outcome.validationErrors ?? [], `${outcome.route}: existing layout and canonical-session checks`).toEqual([]);
    for (const capture of captures) {
      expect.soft(capture.geometry.documentOverflow, `${outcome.route} at ${capture.width}px: no document overflow`).toBe(false);
      expect.soft(capture.geometry.visibleH1Count, `${outcome.route} at ${capture.width}px: one visible H1`).toBe(1);
      expect.soft(capture.geometry.h1?.[0], `${outcome.route} at ${capture.width}px: H1 has text`).toBeTruthy();
      expect.soft(capture.geometry.unnamedControlCount, `${outcome.route} at ${capture.width}px: all visible controls have names`).toBe(0);
      expect.soft(capture.geometry.unloadedLazyWidgets, `${outcome.route} at ${capture.width}px: visible lazy widgets loaded`).toBe(0);
      expect.soft(capture.geometry.brokenImages, `${outcome.route} at ${capture.width}px: no rendered broken images`).toEqual([]);
      for (const [tableIndex, table] of capture.geometry.tables.entries()) {
        expect.soft(table.clippingAncestors, `${outcome.route} at ${capture.width}px: table ${tableIndex + 1} is not clipped`).toEqual([]);
        expect.soft(table.horizontalContentReachable, `${outcome.route} at ${capture.width}px: table ${tableIndex + 1} fits or scrolls within the viewport`).toBe(true);
      }
      for (const frame of capture.frames.filter(frame => frame.status === 'SAME_ORIGIN_RENDERED')) {
        expect.soft(frame.documentWidth, `${outcome.route} at ${capture.width}px: same-origin frame has no document overflow`).toBeLessThanOrEqual((frame.viewport ?? 0) + 1);
        expect.soft(frame.bodyTextLength, `${outcome.route} at ${capture.width}px: same-origin frame rendered content`).toBeGreaterThan(0);
        expect.soft(frame.unloadedLazyWidgets, `${outcome.route} at ${capture.width}px: same-origin frame visible lazy widgets loaded`).toBe(0);
      }
      expect.soft(capture.axeError ?? null, `${outcome.route} at ${capture.width}px: Axe completed`).toBeNull();
      expect.soft(capture.axeSeriousCritical, `${outcome.route} at ${capture.width}px: serious/critical accessibility findings`).toEqual([]);
    }
  }
});
