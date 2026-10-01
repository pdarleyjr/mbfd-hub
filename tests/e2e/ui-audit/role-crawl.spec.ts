import AxeBuilder from '@axe-core/playwright';
import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const origin = 'http://127.0.0.1:8127';
const widths = [320, 390, 820, 1024, 1280, 1440, 1920];
const seeds = ['/', '/admin', '/employee', '/workgroups', '/training', '/daily/stations', '/updates', '/support', '/account'];
const maxPagesPerPersona = Number(process.env.UI_AUDIT_MAX_PAGES ?? 400);
const maxInstancesPerPattern = 2;
const artifactDir = resolve(process.env.UI_AUDIT_ARTIFACT_DIR ?? 'test-results/ui-audit-artifacts');
const personas = JSON.parse(readFileSync('test-results/protected-ui-auth/personas.json', 'utf8')) as Record<string, string>;

// Navigation targets the crawler must never open: state changes, downloads, other-owner active flows.
const skipPath = /\/(logout|export|download|print)(?:\/|$)|\.(pdf|csv|xlsx|zip|json|js|webmanifest)$|^\/daily\/(vehicle-inspections|apparatus)\/[^/]+$|^\/(oauth|auth|login|forgot-password|reset-password|member-onboarding|city-email)|^\/livewire|^\/api\//;

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

for (const persona of Object.keys(personas)) {
  test(`read-only crawl as ${persona}`, async ({ browser }) => {
    test.setTimeout(45 * 60_000);
    const statePath = `test-results/protected-ui-auth/persona-${persona}.json`;
    expect(existsSync(statePath), `Persona storage state for ${persona}`).toBe(true);
    const blocked: string[] = [];
    const context = await browser.newContext({ storageState: statePath, serviceWorkers: 'block', viewport: { width: 1440, height: 900 } });
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
    const pages: Record<string, unknown>[] = [];
    const linkTargets = new Map<string, Set<string>>();

    while (queue.length && pages.length < maxPagesPerPersona) {
      const target = queue.shift()!;
      const key = pattern(new URL(target, origin).pathname);
      if ((patternCounts.get(key) ?? 0) >= maxInstancesPerPattern) continue;
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
          const next = url.pathname + (url.search && !/[?&](tableSort|tableFilters|tableSearch|page|activeTab)=/.test(url.search) ? url.search : '');
          if (!linkTargets.has(next)) linkTargets.set(next, new Set());
          linkTargets.get(next)!.add(finalUrl.pathname);
          if (!queued.has(next) && !skipPath.test(url.pathname)) { queued.add(next); queue.push(next); }
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

    const brokenLinks = pages.filter(p => typeof p.httpStatus === 'number' && (p.httpStatus as number) >= 400)
      .map(p => ({ path: p.requested, status: p.httpStatus, linkedFrom: [...(linkTargets.get(p.requested as string) ?? [])].slice(0, 5) }));
    mkdirSync(artifactDir, { recursive: true });
    writeFileSync(resolve(artifactDir, `crawl-${persona}.json`), JSON.stringify({
      persona, fixture: 'Disposable local SQLite fixture; synthetic identities only', crawledAt: new Date().toISOString(),
      pagesVisited: pages.length, unvisitedQueued: queue.length, brokenLinks, pages,
    }, null, 2));
    await context.close();
    expect(pages.length, `${persona} reached at least one page`).toBeGreaterThan(0);
  });
}
