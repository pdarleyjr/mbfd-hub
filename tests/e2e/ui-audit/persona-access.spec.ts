import { expect, test } from '@playwright/test';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { uiAuditEnvironment } from '../../../playwright.ui-audit.config';

const origin = 'http://127.0.0.1:8127';
const artifactDir = resolve(process.env.UI_AUDIT_ARTIFACT_DIR ?? 'test-results/ui-audit-artifacts');
const personas = JSON.parse(readFileSync('test-results/protected-ui-auth/personas.json', 'utf8')) as Record<string, string>;

type Outcome = 'allow' | 'deny' | 'login' | `redirect:${string}` | `http:${number | 'none'}`;
type Expectation = { paths: Record<string, Outcome>; bid: boolean; mediaControl: boolean };

const panels = ['/', '/employee', '/admin', '/training', '/workgroups'] as const;
const memberOnly = { '/': 'allow', '/employee': 'allow', '/admin': 'deny', '/training': 'deny', '/workgroups': 'deny' } as const;

// Derived from User::canAccessPanel, RedirectTrainingUsers and the direct-entitlement methods on current main.
const expected: Record<string, Expectation> = {
  'super-admin': { paths: { '/': 'allow', '/employee': 'allow', '/admin': 'allow', '/training': 'allow', '/workgroups': 'allow' }, bid: true, mediaControl: true },
  admin: { paths: { '/': 'allow', '/employee': 'allow', '/admin': 'allow', '/training': 'deny', '/workgroups': 'allow' }, bid: true, mediaControl: true },
  'logistics-admin': { paths: { '/': 'allow', '/employee': 'allow', '/admin': 'allow', '/training': 'deny', '/workgroups': 'deny' }, bid: true, mediaControl: true },
  'training-admin': { paths: { '/': 'allow', '/employee': 'allow', '/admin': 'redirect:/training', '/training': 'allow', '/workgroups': 'deny' }, bid: true, mediaControl: true },
  'training-viewer': { paths: { '/': 'allow', '/employee': 'allow', '/admin': 'deny', '/training': 'allow', '/workgroups': 'deny' }, bid: true, mediaControl: false },
  'workgroup-member': { paths: { ...memberOnly, '/workgroups': 'allow' }, bid: false, mediaControl: false },
  'workgroup-facilitator': { paths: { ...memberOnly, '/workgroups': 'allow' }, bid: false, mediaControl: false },
  officer: { paths: { ...memberOnly }, bid: false, mediaControl: false },
  member: { paths: { ...memberOnly }, bid: false, mediaControl: false },
  'employee-only': { paths: { ...memberOnly }, bid: false, mediaControl: false },
};

test('every persona has a declared access expectation', () => {
  expect(Object.keys(personas).sort()).toEqual(Object.keys(expected).sort());
  expect(uiAuditEnvironment.BID_FEDERATION_TOKEN ?? '').toBe('');
  expect(uiAuditEnvironment.MEDIA_CONTROL_FEDERATION_TOKEN ?? '').toBe('');
});

for (const persona of Object.keys(personas)) {
  test(`access matrix: ${persona}`, async ({ browser }) => {
    const context = await browser.newContext({ storageState: `test-results/protected-ui-auth/persona-${persona}.json`, serviceWorkers: 'block' });
    // Navigation only: any write, including Livewire, is refused during access probing.
    await context.route('**/*', route => ['GET', 'HEAD', 'OPTIONS'].includes(route.request().method()) ? route.continue() : route.abort('blockedbyclient'));
    const page = await context.newPage();
    const actual: Record<string, Outcome> = {};
    let homePresentation: { bidCards: number; mediaControlCards: number; bidLaunchLinks: number; mediaControlLaunchLinks: number } | null = null;
    for (const path of panels) {
      const response = await page.goto(origin + path, { waitUntil: 'domcontentloaded' });
      const finalPath = new URL(page.url()).pathname.replace(/\/$/, '') || '/';
      actual[path] = response?.status() === 403 ? 'deny'
        : response?.status() !== 200 ? `http:${response?.status() ?? 'none'}`
        : finalPath === '/login' ? 'login'
        : finalPath === path || (path !== '/' && finalPath.startsWith(`${path}/`)) ? 'allow'
        : `redirect:${finalPath}`;
      if (path === '/') homePresentation = await page.evaluate(() => {
        const titles = [...document.querySelectorAll('[data-quick-access-card] .hub-tile__title')].map(el => el.textContent?.trim());
        const links = [...document.querySelectorAll('a[href]')].map(el => new URL((el as HTMLAnchorElement).href));
        return { bidCards: titles.filter(title => title === 'Bid').length, mediaControlCards: titles.filter(title => title === 'Media Control').length,
          bidLaunchLinks: links.filter(link => link.hostname === 'bid.mbfdhub.com' || link.hostname.endsWith('.bid.mbfdhub.com') || link.pathname === '/auth/bid/authorize').length,
          mediaControlLaunchLinks: links.filter(link => link.hostname === 'media.mbfdhub.com' || link.pathname === '/auth/media-control/authorize').length };
      });
    }
    // Application entitlements come from the production User methods (recorded read-only by the seeder);
    // /account's Available badge also requires SSO client config, which the sanitized harness clears.
    const recorded = JSON.parse(readFileSync('test-results/protected-ui-auth/persona-entitlements.json', 'utf8'))[persona] as { bid: boolean; mediaControl: boolean };
    const entitlements = { bid: recorded.bid, mediaControl: recorded.mediaControl };
    const accountResponse = await page.goto(origin + '/account', { waitUntil: 'domcontentloaded' });
    const accountRows = await page.locator('section[aria-labelledby="access-heading"] li').evaluateAll(rows => rows.map(row => ({
      label: row.querySelector('h3')?.textContent?.trim(), status: row.querySelector('p')?.textContent?.trim(),
      badge: row.lastElementChild?.textContent?.trim(), visible: (row as HTMLElement).offsetHeight > 0,
      actionControls: row.querySelectorAll('a[href],button,[role=button]').length,
    })).filter(row => ['Bid', 'Media Control'].includes(row.label ?? '')));
    const presentation = { home: homePresentation, account: { httpStatus: accountResponse?.status(), rows: accountRows },
      meaning: 'Home has no canonical Bid launcher; Media Control requires entitlement and an operational client. Account lists both applications, unavailable because sanitized federation tokens are absent. Entitlement remains the separately verified User-method result.' };
    await context.close();

    mkdirSync(artifactDir, { recursive: true });
    writeFileSync(resolve(artifactDir, `access-${persona}.json`), JSON.stringify({ persona, expected: expected[persona], actual: { paths: actual, ...entitlements }, presentation }, null, 2));
    expect.soft(actual, `${persona} panel access`).toEqual(expected[persona].paths);
    expect.soft(entitlements, `${persona} application entitlements`).toEqual({ bid: expected[persona].bid, mediaControl: expected[persona].mediaControl });
    expect.soft(homePresentation, `${persona} unavailable application launchers stay absent on Home`).toEqual({ bidCards: 0, mediaControlCards: 0, bidLaunchLinks: 0, mediaControlLaunchLinks: 0 });
    expect.soft(presentation.account.httpStatus, `${persona} Account presentation`).toBe(200);
    expect.soft(accountRows, `${persona} visible application availability, independent of entitlement`).toEqual(['Bid', 'Media Control'].map(label => ({
      label, status: 'SSO client not configured or unavailable — access unavailable', badge: 'Unavailable', visible: true, actionControls: 0,
    })));
  });
}
