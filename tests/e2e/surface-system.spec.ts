import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { PRIMARY_BUTTON, readButton, type ComputedButton } from './support/control-contract';

/*
 * Surface system contract (WP3): white canvas, grey glass panels, white wells inside them.
 *   npx playwright test -c playwright.shell-alignment.config.ts surface-system
 * Glass resolves from the --hub-glass* tokens, never nests, never lifts on hover, and the working
 * pages of every panel have no colour-contrast violations on top of it.
 */

const GLASS = [
  '.fi-section', '.fi-ta-ctn', '.fi-in-section', '.fi-fo-section', '.fi-wi-stats-overview-stat', '.hub-panel', '.hub-tile',
  // Employee and Workgroups portal cards. The blue primary action card is a button, not a surface.
  '.ep-action-card:not(.ep-action-primary)', '.ep-bid-assignment', '.ep-eq-table-wrap', '.pr-card', '.ast-employee-form', '.ppe-form', '.mr-legacy',
  '.wg-overview-stats', '.wg-stat-card', '.wg-section', '.wg-ai-panel', '.wg-saver-card',
].join(', ');

// Pages that must show at least this many glass surfaces, so a green run is never an empty one.
const minimumSurfaces: Record<string, number> = { '/admin/apparatuses': 1, '/training': 1, '/employee/dashboard': 1 };

const landingPages = [
  '/admin',
  '/admin/apparatuses',
  '/employee/dashboard',
  '/employee/my-requests',
  '/training',
  '/workgroups',
];

async function open(page: Page, path: string): Promise<void> {
  await page.addInitScript(() => {
    try { localStorage.removeItem('isOpen'); } catch { /* first-visit state */ }
  });
  const response = await page.goto(path, { waitUntil: 'load' });
  expect(response?.status(), `${path} must render for the seeded super admin`).toBe(200);
  await page.evaluate(() => document.fonts.ready.then(() => undefined));
  await page.evaluate(() => Promise.all(document.getAnimations().map(animation => animation.finished.catch(() => undefined))).then(() => undefined));
}

test.describe('surface system', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(!['vp-1366x768', 'vp-390x844'].includes(testInfo.project.name), 'Surfaces are checked at one desktop and one phone width.');
  });

  for (const path of landingPages) {
    test(`${path}: glass resolves from tokens, wells nest inside it, nothing is glass inside glass`, async ({ page }, testInfo) => {
      await open(page, path);
      const report = await page.evaluate(selector => {
        const problems: string[] = [];
        let glassCount = 0;
        for (const element of Array.from(document.querySelectorAll<HTMLElement>(selector))) {
          const rect = element.getBoundingClientRect();
          if (rect.width === 0 || rect.height === 0 || getComputedStyle(element).display === 'none') continue;
          const style = getComputedStyle(element);
          const label = `${element.tagName.toLowerCase()}.${String(element.className).split(/\s+/).slice(0, 3).join('.')}`;
          const nested = element.parentElement?.closest(selector) ?? null;
          if (nested) {
            if (style.backgroundColor !== 'rgb(255, 255, 255)' && style.backgroundColor !== 'rgba(0, 0, 0, 0)') problems.push(`${label}: nested surface is ${style.backgroundColor}, expected a white well`);
            continue;
          }
          glassCount += 1;
          if (style.backgroundColor !== 'rgba(238, 241, 245, 0.82)') problems.push(`${label}: background ${style.backgroundColor}`);
          if (style.borderTopColor !== 'rgb(208, 215, 224)' || style.borderTopWidth !== '1px') problems.push(`${label}: border ${style.borderTopWidth} ${style.borderTopColor}`);
          if (style.borderTopLeftRadius !== '12px') problems.push(`${label}: radius ${style.borderTopLeftRadius}`);
          if (!style.boxShadow.includes('inset') || !style.boxShadow.includes('rgba(1, 39, 102, 0.05)')) problems.push(`${label}: shadow ${style.boxShadow}`);
          if (style.backdropFilter !== 'none') problems.push(`${label}: backdrop-filter ${style.backdropFilter}`);
        }
        return { glassCount, problems };
      }, GLASS);
      await testInfo.attach('surfaces.json', { body: Buffer.from(JSON.stringify(report, null, 2)), contentType: 'application/json' });
      expect(report.glassCount, `${path} must show at least ${minimumSurfaces[path] ?? 0} glass surface(s)`).toBeGreaterThanOrEqual(minimumSurfaces[path] ?? 0);
      expect(report.problems, `${path} surfaces:\n${report.problems.join('\n')}`).toEqual([]);
    });

    test(`${path}: no colour-contrast violations on the glass surfaces`, async ({ page }, testInfo) => {
      await open(page, path);
      const result = await new AxeBuilder({ page }).withRules(['color-contrast']).analyze();
      await testInfo.attach('axe-contrast.json', { body: Buffer.from(JSON.stringify(result.violations, null, 2)), contentType: 'application/json' });
      const summary = result.violations.flatMap(violation => violation.nodes.map(node => `${node.target.join(' ')} :: ${node.any[0]?.message ?? violation.help}`));
      expect(summary, `${path} contrast:\n${summary.join('\n')}`).toEqual([]);
    });
  }

  test('primary button: Blade and Filament compute the shared contract (colour, radius, height)', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'vp-1366x768', 'The contract values are for a fine pointer.');
    // Blade: a real .hub-btn--primary rendered into the live Home page with the real stylesheets.
    await open(page, '/');
    const blade = await page.evaluate(`(${readButton.toString()})(
      (() => { const a = document.createElement('a'); a.className = 'hub-btn hub-btn--primary'; a.textContent = 'Contract check'; a.href = '#'; document.querySelector('main, body').appendChild(a); return a; })()
    )`) as ComputedButton;
    expect(blade).toMatchObject({ background: PRIMARY_BUTTON.background, color: PRIMARY_BUTTON.color, borderTopLeftRadius: PRIMARY_BUTTON.borderTopLeftRadius });
    expect(Math.abs(blade.height - PRIMARY_BUTTON.height)).toBeLessThanOrEqual(0.5);

    // Filament: the header action on the Training dashboard.
    await open(page, '/training');
    const filament = await page.locator('.fi-header .fi-btn.fi-color-primary').first().evaluate(readButton);
    expect(filament).toMatchObject({ background: PRIMARY_BUTTON.background, color: PRIMARY_BUTTON.color, borderTopLeftRadius: PRIMARY_BUTTON.borderTopLeftRadius });
    expect(Math.abs(filament.height - PRIMARY_BUTTON.height)).toBeLessThanOrEqual(0.5);
    await testInfo.attach('primary-buttons.json', { body: Buffer.from(JSON.stringify({ blade, filament, contract: PRIMARY_BUTTON }, null, 2)), contentType: 'application/json' });
  });

  test('stat tiles do not lift or gain a shadow on hover', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'vp-1366x768', 'Hover applies to a fine pointer, not to touch phones.');
    await open(page, '/training');
    const tile = page.locator('.fi-wi-stats-overview-stat').first();
    await expect(tile).toBeVisible();
    const before = await tile.evaluate(element => ({ transform: getComputedStyle(element).transform, shadow: getComputedStyle(element).boxShadow }));
    await tile.hover();
    await tile.evaluate(element => Promise.all(element.getAnimations().map(animation => animation.finished.catch(() => undefined))));
    const after = await tile.evaluate(element => ({ transform: getComputedStyle(element).transform, shadow: getComputedStyle(element).boxShadow }));
    expect(after).toEqual(before);
    expect(after.transform).toBe('none');
  });
});
