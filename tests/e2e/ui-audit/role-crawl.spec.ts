import AxeBuilder from '@axe-core/playwright';
import { expect, test, type APIRequestContext, type BrowserContext, type Page } from '@playwright/test';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { isAbsolute, relative, resolve } from 'node:path';
import { fixtureSessionRenewal } from '../support/fixture-session-renewal';

const origin = 'http://127.0.0.1:8127';
const widths = [320, 390, 820, 1024, 1280, 1440, 1920];
const seeds = ['/', '/admin', '/employee', '/workgroups', '/training', '/daily/stations', '/updates', '/support/issues', '/account'];
const maxPagesPerPersona = process.env.UI_AUDIT_MAX_PAGES ? Number(process.env.UI_AUDIT_MAX_PAGES) : Infinity;
const maxInstancesPerPattern = 2;
const artifactDir = resolve(process.env.UI_AUDIT_ARTIFACT_DIR ?? 'test-results/ui-audit-artifacts');
const inventoryResultsPath = process.env.UI_AUDIT_INVENTORY_RESULTS;
const personas = JSON.parse(readFileSync('test-results/protected-ui-auth/personas.json', 'utf8')) as Record<string, string>;
const fixtureRoutes = JSON.parse(readFileSync('test-results/protected-ui-auth/fixture-routes.json', 'utf8')) as Record<string, string>;
const profileDestinations = new Map<string, string>();

const catalog = JSON.parse(readFileSync('tests/e2e/support/protected-ui-inventory.json', 'utf8')).pages as { route: string }[];
const visualRoutes = catalog.map(entry => new RegExp(`^${entry.route.split('/').map(segment => /^\{.+\}$|^:\w+$/.test(segment) ? '[^/]+' : segment.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('/')}/?$`));

// Specific source-defined boundaries; an arbitrary /download or /auth path
// is never silently accepted merely because its name resembles an exclusion.
const excludedRoutes = [
  { pattern: /^\/(?:login|forgot-password|reset-password\/[^/]+|member-onboarding(?:\/invite)?|account\/city-email(?:\/verify\/[^/]+)?)\/?$/, source: 'routes/web.php canonical identity routes', reason: 'Frozen identity flow; dedicated auth and inventory acceptance.' },
  { pattern: /^\/(?:admin|employee|training|workgroups)\/(?:login|logout|set-password)\/?$/, source: 'Filament panel routes and SetPasswordPage', reason: 'Frozen panel identity/session flow.' },
  { pattern: /^\/auth\/(?:identity(?:\/callback)?|bid\/authorize|media-control\/authorize)\/?$/, source: 'routes/web.php federation routes', reason: 'Federation activation is outside local link probing.' },
  { pattern: /^\/daily\/(?:vehicle-inspections|apparatus)\/(?!success\/?$)[^/]+\/?$|^\/daily\/forms-hub\/station-inspection\/?$/, source: 'Daily App.tsx inspection routes', reason: 'Active inspection; dedicated disposable Daily flow acceptance.' },
  { pattern: /^\/(?:support\/issues\/attachments|admin\/hub-support-attachments|employee\/personnel-request-attachments|admin\/personnel-request-attachments)\/\d+$/, source: 'routes/web.php attachment controllers', reason: 'Operational attachment download; route shape only, no download performed.' },
  { pattern: /^\/(?:employee\/forms\/api|admin\/operational-forms)\/documents\/[0-7][0-9a-hjkmnp-tv-z]{25}\/(?:preview|download)$/i, source: 'routes/web.php form document controllers; OperationalFormDocument::HasUlids', reason: 'Operational document preview/download; no document operation performed.' },
  { pattern: /^\/updates\/[^/]+\/(?:image|attachment)$|^\/inventory-pdf\/\d+$|^\/workgroup\/file\/\d+\/(?:preview|download)$|^\/workgroup\/shared-upload\/\d+\/download$|^\/reports\/(?:executive-report|saver-report)\/pdf$/, source: 'routes/web.php update, inventory and Workgroup export routes', reason: 'Nonvisual attachment/export endpoint; no operational export performed.' },
];

function excludedNavigation(pathname: string) {
  const defined = excludedRoutes.find(entry => entry.pattern.test(pathname));
  if (defined) return { source: defined.source, reason: defined.reason };
  const exportKey = /^\/workgroup-export\/([^/]+)$/.exec(pathname)?.[1];
  if (exportKey) {
    const key = decodeURIComponent(exportKey);
    const category = key.startsWith('category_') ? key.slice('category_'.length).trim() : '';
    if (['competitor_groups', 'finalists', 't1_standalone', 'brand_overall', 'cutoff_saws', 'spreaders', 'cutters', 'rams'].includes(key)
      || (category.length > 0 && [...category].length <= 120)) {
      return { source: 'routes/web.php Workgroup CSV tableKey validation', reason: 'Source-valid operational CSV export; no export performed.' };
    }
  }
  if (!/\.(?:pdf|csv|xlsx|zip|png|jpe?g|webp|svg|mp4|webm)$/i.test(pathname)) return null;
  const file = resolve('public', '.' + decodeURIComponent(pathname));
  const withinPublic = relative(resolve('public'), file);
  return withinPublic !== '' && !withinPublic.startsWith('..') && !isAbsolute(withinPublic) && existsSync(file)
    ? { source: `public/${withinPublic.replaceAll('\\', '/')}`, reason: 'Existing bundled nonvisual asset; no download performed.' } : null;
}

function expectedSeedDenial(persona: string, path: string): boolean {
  // User::canAccessPanel and the canonical ten-persona panel matrix. A denied
  // seed is expected; a UI link pointing to the same denied page still fails.
  if (path === '/admin') return !['super-admin', 'admin', 'logistics-admin', 'training-admin'].includes(persona);
  if (path === '/training') return !['super-admin', 'training-admin', 'training-viewer'].includes(persona);
  if (path === '/workgroups') return !['super-admin', 'admin', 'workgroup-member', 'workgroup-facilitator'].includes(persona);
  return false;
}

function sourceRedirect(persona: string, from: string, to: string): boolean {
  // Exact destinations from panel controllers, MyProfile, the first cluster
  // navigation item, RedirectTrainingUsers and the frozen React redirects.
  if (persona === 'training-admin' && /^\/admin(?:\/|$)/.test(from) && to === '/training') return true;
  if (from === '/admin/my-profile') return profileDestinations.get(persona) === to;
  if (from === fixtureRoutes['/admin/users/{record}/edit']) return to === fixtureRoutes['/admin/employees/{record}/edit'];
  const destinations: Record<string, string> = {
    '/admin/users': '/admin/employees', '/admin/users/create': '/admin/employees/create',
    '/employee': '/employee/dashboard', '/admin/personnel-uniforms-equipment': '/admin/personnel-uniforms-equipment/overview',
    '/daily': '/daily/stations', '/daily/forms-hub/big-ticket-request': '/daily/forms-hub/station-request',
    '/daily/forms-hub/equipment-request': '/daily/forms-hub/station-request',
  };
  return destinations[from] === to;
}

function recordProfileDestination(persona: string, employeeProfileId: unknown) {
  expect(typeof employeeProfileId, 'Canonical context supplies the current employee profile ID').toBe('number');
  expect(Number.isSafeInteger(employeeProfileId) && Number(employeeProfileId) > 0).toBe(true);
  profileDestinations.set(persona, `/admin/employees/${employeeProfileId}/edit`);
}

async function probeLink(request: APIRequestContext, persona: string, target: string, linkedFrom: string[]) {
  const chain: { path: string; status: number }[] = [];
  try {
    const initial = new URL(target, origin);
    if (initial.origin !== origin || initial.username || initial.password) {
      return { path: target, linkedFrom, classification: 'INVALID_INTERNAL_TARGET', error: 'Discovered target must use the isolated local origin without URL credentials.' };
    }
    const exclusion = excludedNavigation(initial.pathname);
    if (exclusion) return { path: target, linkedFrom, classification: 'EXCLUDED_SOURCE_DEFINED', ...exclusion };
    if (!visualRoutes.some(route => route.test(initial.pathname))) return { path: target, linkedFrom, classification: 'UNCLASSIFIED_INTERNAL_LINK', error: 'Target is absent from the canonical visual route catalog and explicit source exclusions.' };
    if (/\[redacted/.test(target)) return { path: target, linkedFrom, classification: 'REDACTED_VISUAL_TARGET', error: 'Redacted evidence cannot establish an eligible visual link response.' };
    let current = initial;
    for (let hop = 0; hop < 6; hop++) {
      // Only HTTP GET, without scripts, form actions, automatic redirect
      // following or downloads. No POST is made by link acceptance.
      const response = await request.get(current.href, { maxRedirects: 0, timeout: 15_000 });
      const status = response.status(), headers = response.headers();
      chain.push({ path: current.pathname + current.search, status });
      if (status >= 300 && status < 400) {
        const next = headers.location ? new URL(headers.location, current) : null;
        if (!next || next.origin !== origin || next.username || next.password || !sourceRedirect(persona, current.pathname, next.pathname)) {
          return { path: target, linkedFrom, chain, classification: 'UNEXPECTED_REDIRECT', destination: next?.pathname, error: 'Redirect is not a source-defined local destination.' };
        }
        current = next;
        continue;
      }
      if (status === 403 && chain.length === 1 && linkedFrom.length === 0 && expectedSeedDenial(persona, initial.pathname)) {
        return { path: target, linkedFrom, chain, classification: 'INTENTIONAL_SEED_DENIAL', source: 'User::canAccessPanel; canonical persona access matrix' };
      }
      if (status !== 200) return { path: target, linkedFrom, chain, classification: 'BROKEN_INTERNAL_LINK', error: `HTTP ${status}` };
      if (headers['content-disposition']?.toLowerCase().includes('attachment') || !headers['content-type']?.toLowerCase().includes('text/html')) {
        return { path: target, linkedFrom, chain, classification: 'UNEXPECTED_NONVISUAL_RESPONSE', error: 'Visual route did not return ordinary HTML.' };
      }
      return { path: target, linkedFrom, chain, classification: 'ACCEPTED_HTTP_GET', meaning: 'Registered local HTML target; layout/interaction acceptance is recorded separately.' };
    }
    return { path: target, linkedFrom, chain, classification: 'REDIRECT_LIMIT', error: 'Source redirect chain did not terminate.' };
  } catch (error) {
    return { path: target, linkedFrom, chain, classification: 'LINK_PROBE_FAILED', error: String(error).slice(0, 500) };
  }
}

function redactEvidence(_key: string, value: unknown): unknown {
  if (typeof value !== 'string') return value;
  return value.replace(/(\/(?:reset-password|account\/city-email\/verify)\/)(?!\{)[^/?#\s"']+/g, '$1[redacted]')
    .replace(/(\/member-onboarding\/invite#)[^\s"']+/g, '$1[redacted]')
    .replace(/([?&](?:token|hash|signature|email|employee_id)=)[^&#\s"']+/gi, '$1[redacted]')
    .replace(/\b[a-f0-9]{64}\b/gi, '[redacted-token-or-digest]');
}

function currentBuildAssets() {
  const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
  return Object.fromEntries(Object.entries(manifest).filter(([, asset]) => (asset as { isEntry?: boolean }).isEntry)
    .map(([entry, asset]) => [entry, (asset as { file: string }).file]));
}

function pattern(pathname: string): string {
  return pathname.split('/').map(segment => /^\d+$|^[0-9a-f-]{32,36}$/i.test(segment) ? '{id}' : segment).join('/');
}

// Explicit render-only Livewire allowlist, mirroring protected-ui-inventory.spec.ts. Anything else is blocked and recorded.
const renderOnlyLivewireCalls: Record<string, (component: string, pagePath: string) => boolean> = {
  loadTable: () => true,
  __lazyLoad: () => true,
  getFormUploadedFiles: () => true,
  $refresh: component => component.startsWith('pulse.'),
};

async function readOnlyGuard(context: BrowserContext, blocked: string[]): Promise<void> {
  await context.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (request.isNavigationRequest() && url.origin !== origin) {
      blocked.push(`external navigation ${url.origin}`);
      return route.abort('blockedbyclient');
    }
    if (['GET', 'HEAD', 'OPTIONS'].includes(request.method())) return route.continue();
    if (url.origin !== origin || url.pathname !== '/livewire/update') {
      blocked.push(`${request.method()} ${url.pathname}`);
      return route.abort('blockedbyclient');
    }
    const pagePath = new URL(context.pages()[0]?.url() || origin).pathname;
    let components: { snapshot?: string; updates?: object; calls?: { method: string }[] }[] = [];
    try { components = request.postDataJSON()?.components ?? []; } catch { components = []; }
    const calls = components.flatMap(component => {
      let name = '';
      try { name = JSON.parse(component.snapshot || '{}').memo?.name || ''; } catch { name = ''; }
      const hasUpdates = Object.keys(component.updates || {}).length > 0;
      return (component.calls?.length ? component.calls : [{ method: '(none)' }])
        .map(call => ({ name, method: call.method, allowed: !hasUpdates && (renderOnlyLivewireCalls[call.method]?.(name, pagePath) ?? false) }));
    });
    if (calls.length > 0 && calls.every(call => call.allowed)) return route.continue();
    blocked.push(`livewire ${pagePath}: ${calls.map(call => `${call.name || '?'}.${call.method}${call.allowed ? '' : '[blocked]'}`).join(', ') || 'unparseable payload'}`);
    return route.abort('blockedbyclient');
  });
}

async function inspect(page: Page) {
  return page.evaluate(() => {
    const name = (el: Element) => (el.getAttribute('aria-label') || el.getAttribute('title') || (el as HTMLElement).innerText
      || el.querySelector('img[alt]')?.getAttribute('alt') || (el.getAttribute('aria-labelledby') ? 'labelledby' : '')).trim();
    const visible = (el: Element) => (el as HTMLElement).offsetParent !== null || getComputedStyle(el).position === 'fixed';
    const links = [...document.querySelectorAll('a[href]')] as HTMLAnchorElement[];
    return {
      title: document.title,
      h1: [...document.querySelectorAll('h1')].filter(visible).map(el => el.textContent?.trim().replace(/\s+/g, ' ').slice(0, 120)),
      outline: [...document.querySelectorAll('h1,h2,h3,h4,h5,h6')].filter(visible).map(el => Number(el.tagName[1])),
      landmarks: {
        main: document.querySelectorAll('main,[role=main]').length,
        nav: document.querySelectorAll('nav,[role=navigation]').length,
        header: document.querySelectorAll('header,[role=banner]').length,
        skipLink: links.some(a => /^#/.test(a.getAttribute('href') || '') && /skip/i.test(a.textContent || '')),
      },
      unnamedControls: [...document.querySelectorAll('a[href],button,[role=button]')].filter(el => visible(el) && !name(el)).length,
      externalWithoutNoopener: links.filter(a => a.target === '_blank' && !/noopener/.test(a.rel)).map(a => a.href.replace(/[?#].*$/, '')).slice(0, 10),
      ambiguousLinks: links.filter(a => visible(a) && /^(click here|here|view|more|view all|details|link)$/i.test(a.innerText.trim())).length,
      viewportMeta: document.querySelector('meta[name=viewport]')?.getAttribute('content') ?? null,
      links: links.map(a => a.href),
    };
  });
}

test.describe.configure({ mode: 'serial' });

if (inventoryResultsPath) {
  test('strict read-only linked GET acceptance from fresh inventory', async ({ browser }, info) => {
    test.setTimeout(90 * 60_000);
    expect(info.config.globalSetup, 'Fresh link follow-up must preserve the existing fixture/session without globalSetup').toBeNull();
    const inventory = JSON.parse(readFileSync(resolve(inventoryResultsPath), 'utf8')) as {
      phase: string; expectedRoutes: number; buildAssets: Record<string, string>; discoveredInternalLinks: string[];
      outcomes: { route: string; captureBuildAssets: Record<string, string> }[];
    };
    const buildAssets = currentBuildAssets();
    expect(inventory.phase, 'Link acceptance requires a complete fresh candidate inventory').toBe('FRESH_CANDIDATE_RENDER');
    expect(inventory.expectedRoutes).toBe(catalog.length);
    expect(inventory.outcomes).toHaveLength(catalog.length);
    expect(inventory.outcomes.map(outcome => outcome.route).sort()).toEqual(catalog.map(entry => entry.route).sort());
    expect(inventory.buildAssets, 'Inventory and link acceptance use the same build').toEqual(buildAssets);
    for (const outcome of inventory.outcomes) expect(outcome.captureBuildAssets, `${outcome.route} candidate build`).toEqual(buildAssets);
    expect(Array.isArray(inventory.discoveredInternalLinks), 'Inventory must export actually discovered internal links').toBe(true);
    expect(inventory.discoveredInternalLinks.length).toBeGreaterThan(0);

    // Reuse the fresh inventory's fixture and canonical actor. The follow-up
    // config must disable globalSetup; only supported local sign-in renews it.
    const context = await browser.newContext({ storageState: 'test-results/protected-ui-auth/state.json', serviceWorkers: 'block' });
    try {
      const canonical = await context.request.get(`${origin}/api/me/context`, {
        headers: { Accept: 'application/json', Origin: origin, Referer: origin + '/admin' }, maxRedirects: 0,
      });
      expect(canonical.status(), 'Current canonical employee session from fresh inventory').toBe(200);
      recordProfileDestination('super-admin', (await canonical.json()).personnel?.employee_profile_id);
      const canonicalRenewal = await fixtureSessionRenewal([context]);
      await canonicalRenewal.beforeBoundary('linked GET start');
      const targets = [...new Set(inventory.discoveredInternalLinks)].sort();
      const links = [];
      for (const target of targets) {
        await canonicalRenewal.beforeBoundary('linked GET target');
        links.push(await probeLink(context.request, 'super-admin', target, ['fresh inventory rendered document']));
      }
      const brokenLinks = links.filter(link => 'error' in link);
      const evidence = { persona: 'super-admin', fixture: 'Exact disposable fresh inventory fixture and canonical actor; supported local sign-in renewal only',
        phase: 'FRESH_INVENTORY_LINKED_GET_ACCEPTANCE', buildAssets, inventoryResultsPath: resolve(inventoryResultsPath),
        probedAt: new Date().toISOString(), discoveredTargets: targets.length, classifiedTargets: links.length, brokenLinks, links,
        canonicalSessionRenewals: canonicalRenewal.events,
        meaning: 'Every exported internal target is either explicitly source-excluded or checked by GET with manual source-defined redirects. Target probes use no POST, scripts, export/download operation, page-count cap or route-pattern sample. Separate fixture-only authentication uses canonical POST /login. Linked 403 fails; unlinked denied-panel seeds are classified only in persona discovery.' };
      mkdirSync(artifactDir, { recursive: true });
      const evidencePath = resolve(artifactDir, 'linked-get-inventory-super-admin.json');
      writeFileSync(evidencePath, JSON.stringify(evidence, redactEvidence, 2));
      await info.attach('linked-get-acceptance', { path: evidencePath, contentType: 'application/json' });
      expect(links).toHaveLength(targets.length);
      expect(brokenLinks, 'Every eligible actually linked internal GET must be accepted').toEqual([]);
    } finally {
      await context.close();
    }
  });
}

for (const persona of inventoryResultsPath ? [] : Object.keys(personas)) {
  test(`read-only crawl as ${persona}`, async ({ browser }) => {
    test.setTimeout(45 * 60_000);
    const statePath = `test-results/protected-ui-auth/persona-${persona}.json`;
    expect(existsSync(statePath), `Persona storage state for ${persona}`).toBe(true);
    const blocked: string[] = [];
    const context = await browser.newContext({ storageState: statePath, serviceWorkers: 'block', viewport: { width: 1440, height: 900 } });
    const canonical = await context.request.get(`${origin}/api/me/context`, { headers: { Accept: 'application/json', Origin: origin, Referer: origin + '/' }, maxRedirects: 0 });
    expect(canonical.status(), `${persona} current canonical employee session`).toBe(200);
    recordProfileDestination(persona, (await canonical.json()).personnel?.employee_profile_id);
    await readOnlyGuard(context, blocked);
    const page = await context.newPage();
    let consoleErrors: string[] = [];
    let failedResources: string[] = [];
    page.on('pageerror', error => consoleErrors.push(`pageerror: ${error.message.slice(0, 300)}`));
    page.on('console', message => { if (message.type() === 'error') consoleErrors.push(message.text().slice(0, 300)); });
    page.on('response', response => { if (response.status() >= 400) failedResources.push(`${response.status()} ${new URL(response.url()).pathname}`); });

    const queue = [...seeds];
    const queued = new Set(queue);
    const patternCounts = new Map<string, number>();
    const browserPatternSamples: string[] = [];
    const pages: Record<string, unknown>[] = [];
    const linkTargets = new Map<string, Set<string>>();

    while (queue.length && pages.length < maxPagesPerPersona) {
      const target = queue.shift()!;
      const key = pattern(new URL(target, origin).pathname);
      if ((patternCounts.get(key) ?? 0) >= maxInstancesPerPattern) { browserPatternSamples.push(target); continue; }
      patternCounts.set(key, (patternCounts.get(key) ?? 0) + 1);
      consoleErrors = [];
      failedResources = [];
      const blockedBefore = blocked.length;
      let record: Record<string, unknown> = { requested: target, pattern: key };
      try {
        await page.setViewportSize({ width: 1440, height: 900 });
        const response = await page.goto(origin + target, { waitUntil: 'domcontentloaded', timeout: 20_000 });
        await page.waitForLoadState('networkidle', { timeout: 3_000 }).catch(() => undefined);
        const finalUrl = new URL(page.url());
        const facts = await inspect(page);
        for (const href of facts.links) {
          const url = new URL(href);
          if (url.origin !== origin) continue;
          const linkedTarget = url.pathname + url.search;
          if (!linkTargets.has(linkedTarget)) linkTargets.set(linkedTarget, new Set());
          linkTargets.get(linkedTarget)!.add(finalUrl.pathname);
          // Remove only display-state pagination/search keys. Preserve record,
          // workgroup and session queries for browser discovery; the GET audit
          // above preserves every actual linked target, including display keys.
          for (const parameter of [...url.searchParams.keys()]) if (/^(?:tableSort|tableFilters|tableSearch|page|activeTab)(?:\[|$)/.test(parameter)) url.searchParams.delete(parameter);
          const next = url.pathname + url.search;
          if (!queued.has(next) && !excludedNavigation(url.pathname)) { queued.add(next); queue.push(next); }
        }
        const overflow: Record<number, number> = {};
        for (const width of widths) {
          await page.setViewportSize({ width, height: width < 820 ? 844 : 900 });
          await page.waitForTimeout(120);
          const excess = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
          if (excess > 1) overflow[width] = excess;
        }
        await page.setViewportSize({ width: 1440, height: 900 });
        const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
        const { links: _links, ...summary } = facts;
        record = { ...record, finalPath: finalUrl.pathname, redirected: finalUrl.pathname !== new URL(target, origin).pathname,
          httpStatus: response?.status() ?? null, ...summary, overflow,
          axe: axe.violations.map(v => ({ id: v.id, impact: v.impact, nodes: v.nodes.length })),
          consoleErrors: [...consoleErrors], failedResources: [...new Set(failedResources)], blockedWrites: blocked.slice(blockedBefore) };
      } catch (error) {
        record = { ...record, error: String(error).slice(0, 500) };
      }
      pages.push(record);
    }

    const links = [];
    for (const target of [...new Set([...seeds, ...linkTargets.keys()])].sort()) {
      links.push(await probeLink(context.request, persona, target, [...(linkTargets.get(target) ?? [])].sort()));
    }
    const brokenLinks = links.filter(link => 'error' in link);
    const discoveryErrors = pages.filter(page => page.error);
    mkdirSync(artifactDir, { recursive: true });
    writeFileSync(resolve(artifactDir, `crawl-${persona}.json`), JSON.stringify({
      persona, fixture: 'Disposable local SQLite fixture; synthetic identities only', crawledAt: new Date().toISOString(),
      pagesVisited: pages.length, unvisitedQueued: queue, browserPatternSamples,
      meaning: 'Browser geometry is sampled at two instances per route pattern. Every discovered internal GET is classified or probed without that sampling limit. Intentional denied unlinked panel seeds are separate from broken links; any linked 403 fails.',
      brokenLinks, discoveryErrors, links, pages,
    }, redactEvidence, 2));
    await context.close();
    expect(pages.length, `${persona} reached at least one page`).toBeGreaterThan(0);
    expect.soft(queue, `${persona} browser discovery must finish, including when an explicit runtime page limit is selected`).toEqual([]);
    expect.soft(discoveryErrors, `${persona} browser discovery errors`).toEqual([]);
    expect.soft(brokenLinks, `${persona} unexpected internal linked responses`).toEqual([]);
  });
}
