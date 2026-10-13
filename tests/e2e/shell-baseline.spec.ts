import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { expect, test } from '@playwright/test';

/*
 * Before/after screenshots for the UI modernization pull requests.
 *
 *   SHELL_BASELINE_DIR=<absolute output folder> npx playwright test -c playwright.shell-alignment.config.ts shell-baseline
 *
 * Captures twelve key routes at 390, 820 and 1366 px wide into <dir>/<width>/<route>.png. Without
 * SHELL_BASELINE_DIR the whole file is skipped, so the normal suite never writes images. The same
 * command after each work package produces the "after" set to compare against the baseline.
 */

const outputDirectory = process.env.SHELL_BASELINE_DIR;
const widths = [390, 820, 1366];

const routes = [
  { slug: '01-home', path: '/' },
  { slug: '02-updates', path: '/updates' },
  { slug: '03-account', path: '/account' },
  { slug: '04-admin-dashboard', path: '/admin' },
  { slug: '05-admin-apparatuses', path: '/admin/apparatuses' },
  { slug: '06-admin-trt-inventory', path: '/admin/trt-trailer-inventory' },
  { slug: '07-employee-dashboard', path: '/employee/dashboard' },
  { slug: '08-employee-my-requests', path: '/employee/my-requests' },
  { slug: '09-employee-forms', path: '/employee/forms' },
  { slug: '10-training-dashboard', path: '/training' },
  { slug: '11-workgroups-dashboard', path: '/workgroups' },
  { slug: '12-daily-stations', path: '/daily/stations' },
];

test.describe('baseline screenshots', () => {
  test.skip(outputDirectory === undefined, 'Set SHELL_BASELINE_DIR to capture screenshots.');

  for (const route of routes) {
    test(`${route.slug} (${route.path})`, async ({ page }, testInfo) => {
      const width = page.viewportSize()?.width ?? 0;
      test.skip(!widths.includes(width), `Baseline widths are ${widths.join(', ')} px.`);

      const response = await page.goto(route.path, { waitUntil: 'load' });
      const status = response?.status() ?? 0;
      // A missing build (for example Daily before its own build) is recorded, not hidden.
      await testInfo.attach('status.txt', { body: Buffer.from(`${route.path} -> HTTP ${status}`), contentType: 'text/plain' });
      expect(status, `${route.path} must respond for the seeded super admin`).toBeLessThan(400);

      await page.evaluate(() => document.fonts.ready.then(() => undefined));
      await page.waitForTimeout(400);
      const folder = join(outputDirectory!, String(width));
      mkdirSync(folder, { recursive: true });
      await page.screenshot({ path: join(folder, `${route.slug}.png`), fullPage: true, animations: 'disabled' });
    });
  }
});
