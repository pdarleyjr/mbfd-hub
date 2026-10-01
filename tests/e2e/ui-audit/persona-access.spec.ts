import { expect, test } from '@playwright/test';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const origin = 'http://127.0.0.1:8127';
const artifactDir = resolve(process.env.UI_AUDIT_ARTIFACT_DIR ?? 'test-results/ui-audit-artifacts');
const personas = JSON.parse(readFileSync('test-results/protected-ui-auth/personas.json', 'utf8')) as Record<string, string>;

type Outcome = 'allow' | 'deny' | 'login' | `redirect:${string}`;
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
});

for (const persona of Object.keys(personas)) {
  test(`access matrix: ${persona}`, async ({ browser }) => {
    const context = await browser.newContext({ storageState: `test-results/protected-ui-auth/persona-${persona}.json`, serviceWorkers: 'block' });
    // Navigation only: any write, including Livewire, is refused during access probing.
    await context.route('**/*', route => ['GET', 'HEAD', 'OPTIONS'].includes(route.request().method()) ? route.continue() : route.abort('blockedbyclient'));
    const page = await context.newPage();
    const actual: Record<string, Outcome> = {};
    for (const path of panels) {
      const response = await page.goto(origin + path, { waitUntil: 'domcontentloaded' });
      const finalPath = new URL(page.url()).pathname.replace(/\/$/, '') || '/';
      actual[path] = response?.status() === 403 ? 'deny'
        : finalPath === '/login' ? 'login'
        : finalPath === path || (path !== '/' && finalPath.startsWith(`${path}/`)) ? 'allow'
        : `redirect:${finalPath}`;
    }
    // Read-only entitlement signal: /account renders ApplicationAccessService::states(); /auth/*/authorize would issue a code.
    await page.goto(`${origin}/account`, { waitUntil: 'domcontentloaded' });
    const state = async (label: string) => (await page.locator('li', { has: page.getByRole('heading', { name: label, exact: true }) })
      .locator('span').last().innerText()).trim() === 'Available';
    const entitlements = { bid: await state('Bid'), mediaControl: await state('Media Control') };
    await context.close();

    mkdirSync(artifactDir, { recursive: true });
    writeFileSync(resolve(artifactDir, `access-${persona}.json`), JSON.stringify({ persona, expected: expected[persona], actual: { paths: actual, ...entitlements } }, null, 2));
    expect.soft(actual, `${persona} panel access`).toEqual(expected[persona].paths);
    expect.soft(entitlements, `${persona} application entitlements`).toEqual({ bid: expected[persona].bid, mediaControl: expected[persona].mediaControl });
  });
}
