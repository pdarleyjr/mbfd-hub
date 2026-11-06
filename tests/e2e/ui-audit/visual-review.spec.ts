import { protectedUiOrigin } from '../support/test-environment';
import { test, type Page } from '@playwright/test';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Visual review capture: the same key screens, per persona, at phone and desktop widths, written
 * to test-results/visual-review/<label>/ so a design change can be compared before and after.
 * Read-only: it only opens pages. Run: VISUAL_LABEL=before npx playwright test -c playwright.visual-review.config.ts
 */
const origin = protectedUiOrigin;
const label = (process.env.VISUAL_LABEL ?? 'current').replace(/[^a-z0-9-]/gi, '');
const outDir = resolve('test-results/visual-review', label);
const viewports = [{ width: 390, height: 844 }, { width: 1440, height: 900 }];
const personas = JSON.parse(readFileSync('test-results/protected-ui-auth/personas.json', 'utf8')) as Record<string, string>;

const screens: Record<string, string[]> = {
  'super-admin': ['/', '/admin', '/admin/apparatuses', '/admin/employees', '/admin/inventory-items', '/updates', '/support/issues', '/account', '/no-such-page'],
  member: ['/', '/employee/dashboard', '/daily/stations', '/updates', '/account'],
  'training-admin': ['/training'],
  'workgroup-member': ['/workgroups'],
};

const slug = (path: string) => (path === '/' ? 'home' : path.replace(/^\//, '').replace(/[^a-z0-9]+/gi, '-'));

async function settle(page: Page) {
  await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => undefined);
  await page.evaluate(() => document.fonts?.ready).catch(() => undefined);
  await page.waitForTimeout(300);
}

mkdirSync(outDir, { recursive: true });
const report: Record<string, unknown>[] = [];

test.afterAll(() => writeFileSync(resolve(outDir, 'report.json'), JSON.stringify(report, null, 2)));

test('signed-out sign-in screen', async ({ browser }) => {
  for (const viewport of viewports) {
    const context = await browser.newContext({ viewport, storageState: { cookies: [], origins: [] }, serviceWorkers: 'block' });
    const page = await context.newPage();
    await page.goto(`${origin}/login`);
    await settle(page);
    await page.screenshot({ path: resolve(outDir, `signed-out__login__${viewport.width}.png`), fullPage: true });
    await context.close();
  }
});

for (const [requested, paths] of Object.entries(screens)) {
  test(`persona ${requested}`, async ({ browser }) => {
    const persona = personas[requested] ? requested : Object.keys(personas).find(name => !/admin|training|workgroup/.test(name));
    test.skip(!persona, `no seeded persona for ${requested}`);
    for (const viewport of viewports) {
      const context = await browser.newContext({
        viewport, hasTouch: viewport.width < 1024, serviceWorkers: 'block',
        storageState: `test-results/protected-ui-auth/persona-${persona}.json`,
      });
      const page = await context.newPage();
      const errors: string[] = [];
      page.on('console', message => { if (message.type() === 'error') errors.push(message.text().slice(0, 300)); });
      page.on('pageerror', error => errors.push(String(error).slice(0, 300)));
      for (const path of paths) {
        errors.length = 0;
        const response = await page.goto(`${origin}${path}`).catch(() => null);
        await settle(page);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        await page.screenshot({ path: resolve(outDir, `${persona}__${slug(path)}__${viewport.width}.png`), fullPage: true });
        report.push({ persona, path, width: viewport.width, status: response?.status() ?? null, landed: new URL(page.url()).pathname, overflow, errors: [...errors] });
      }
      await context.close();
    }
  });
}
