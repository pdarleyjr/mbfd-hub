import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { guardUiRendering } from '../support/ui-readonly';

const origin = 'http://127.0.0.1:8127';
const widths = [390, 1024, 1440];
const artifactDir = resolve(process.env.UI_AUDIT_ARTIFACT_DIR ?? 'test-results/ui-audit-artifacts', 'modules');

type Surface = { module: string; persona: string; path: string; migrated: boolean };

// Representative surfaces per module; `migrated` surfaces must meet the v2 acceptance bar.
const surfaces: Surface[] = [
  { module: 'home', persona: 'super-admin', path: '/', migrated: true },
  { module: 'home', persona: 'member', path: '/', migrated: true },
  { module: 'home', persona: 'workgroup-member', path: '/', migrated: true },
  ...['/admin', '/admin/apparatuses', '/admin/equipment-items', '/admin/station-requests', '/admin/employees', '/admin/employees/create', '/admin/equipment-intake'].map(path => ({ module: 'admin', persona: 'super-admin', path, migrated: true })),
  ...['/employee', '/employee/my-requests', '/employee/request-equipment', '/employee/apparatus-service-request', '/employee/forms'].map(path => ({ module: 'employee', persona: 'member', path, migrated: true })),
  { module: 'employee', persona: 'officer', path: '/employee/personnel-equipment-request', migrated: true },
  ...['/workgroups', '/workgroups/evaluations', '/workgroups/surveys', '/workgroups/files', '/workgroups/profile'].map(path => ({ module: 'workgroups', persona: 'workgroup-member', path, migrated: true })),
  ...['/workgroups/analysis-report', '/workgroups/data-dashboard', '/workgroups/l1-inventory', '/workgroups/final-recommendations'].map(path => ({ module: 'workgroups', persona: 'super-admin', path, migrated: true })),
  { module: 'training', persona: 'training-viewer', path: '/training', migrated: true },
  { module: 'training', persona: 'training-admin', path: '/training/training-todos', migrated: true },
  { module: 'training', persona: 'training-admin', path: '/training/settings', migrated: true },
  ...['/account', '/support/issues', '/support/issues/create', '/updates', '/install', '/security-standards'].map(path => ({ module: 'specialized', persona: 'member', path, migrated: true })),
];

const selected = (process.env.UI_MODULES ?? '').split(',').map(value => value.trim()).filter(Boolean);

for (const surface of surfaces.filter(entry => selected.length === 0 || selected.includes(entry.module))) {
  test(`${surface.module}: ${surface.persona} ${surface.path}`, async ({ browser }) => {
    const context = await browser.newContext({ storageState: `test-results/protected-ui-auth/persona-${surface.persona}.json`, serviceWorkers: 'block' });
    // Rendering only: allow known Livewire reads and refuse mutations.
    await guardUiRendering(context, origin);
    const page = await context.newPage();
    const consoleErrors: string[] = [];
    page.on('pageerror', error => consoleErrors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') consoleErrors.push(message.text()); });

    const results: Record<string, unknown>[] = [];
    for (const width of widths) {
      await page.setViewportSize({ width, height: width < 1024 ? 844 : 900 });
      const response = await page.goto(origin + surface.path, { waitUntil: 'domcontentloaded' });
      await page.waitForLoadState('networkidle', { timeout: 5_000 }).catch(() => undefined);
      const layout = await page.evaluate(() => ({
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        title: document.title,
        h1: [...document.querySelectorAll('h1')].map(el => el.textContent?.trim()),
      }));
      const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
      const serious = axe.violations.filter(v => ['serious', 'critical'].includes(v.impact ?? ''))
        .map(v => ({ id: v.id, impact: v.impact, targets: v.nodes.slice(0, 5).map(n => n.target.join(' ')) }));
      const slug = `${surface.module}-${surface.persona}-${surface.path.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'root'}-${width}`;
      mkdirSync(artifactDir, { recursive: true });
      await page.screenshot({ path: resolve(artifactDir, `${slug}.png`), fullPage: true });
      results.push({ width, status: response?.status(), ...layout, serious });
    }
    await context.close();
    writeFileSync(resolve(artifactDir, `${surface.module}-${surface.persona}-${surface.path.replace(/[^a-z0-9]+/gi, '-') || 'root'}.json`),
      JSON.stringify({ surface, consoleErrors, results }, null, 2));

    if (surface.migrated) {
      for (const result of results) {
        expect.soft(result.status, `HTTP status at ${result.width}px`).toBe(200);
        expect.soft(result.overflow as number, `horizontal overflow at ${result.width}px`).toBeLessThanOrEqual(1);
        expect.soft((result.h1 as string[]).length, `single h1 at ${result.width}px`).toBe(1);
        expect.soft(result.serious, `serious/critical axe violations at ${result.width}px`).toEqual([]);
      }
      expect.soft(consoleErrors, 'console errors').toEqual([]);
    }
  });
}

if (selected.length === 0 || selected.includes('admin')) {
  test('admin character shortcut preference persists and modifier shortcuts remain available', async ({ browser }) => {
    const context = await browser.newContext({
      storageState: 'test-results/protected-ui-auth/persona-super-admin.json',
      serviceWorkers: 'block', viewport: { width: 1440, height: 900 },
    });
    await guardUiRendering(context, origin);
    const page = await context.newPage();
    await page.goto(origin + '/admin');
    await page.keyboard.press('Control+/');
    const dialog = page.getByRole('dialog', { name: 'Keyboard shortcuts' });
    await expect(dialog).toBeVisible();
    const singleKeys = dialog.getByRole('switch', { name: 'Single-key shortcuts' });
    await expect(singleKeys).toHaveAttribute('aria-checked', 'true');
    await singleKeys.click();
    await expect(singleKeys).toHaveAttribute('aria-checked', 'false');
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await page.reload();
    await page.locator('body').click({ position: { x: 900, y: 120 } });
    await page.keyboard.press('/');
    await page.keyboard.press('g');
    await page.keyboard.press('a');
    await expect(page).toHaveURL(origin + '/admin');
    const search = page.locator('.fi-global-search-field input');
    await expect(search).not.toBeFocused();
    await page.keyboard.press('Control+k');
    await expect(search).toBeFocused();
    await page.keyboard.press('Control+/');
    await expect(dialog).toBeVisible();
    await expect(singleKeys).toHaveAttribute('aria-checked', 'false');
    const closeHelp = dialog.getByRole('button', { name: 'Close keyboard shortcuts' });
    await expect(closeHelp).toBeFocused();
    await page.keyboard.press('Control+k');
    await expect(closeHelp).toBeFocused();
    await singleKeys.click();
    await page.keyboard.press('Escape');
    await page.locator('body').click({ position: { x: 900, y: 120 } });
    await page.keyboard.press('g');
    await page.keyboard.press('a');
    await expect(page).toHaveURL(origin + '/admin/apparatuses');
    await context.close();
  });
}
