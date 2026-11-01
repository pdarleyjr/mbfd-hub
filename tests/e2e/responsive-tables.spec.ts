import { expect, test, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Responsive tables contract (playwright.responsive-tables.config.ts runs it at 320, 390, 768, 1024, 1280, 1440).
 *
 * For every route in support/responsive-tables-routes.json (real Hub routes rendered with synthetic rows,
 * long unbroken strings and many rows) assert, with a 1px sub-pixel tolerance only:
 *   - the document does not scroll sideways (scrollWidth <= clientWidth),
 *   - no element in the page is a horizontal scroller with hidden content (no swipeable table wrappers),
 *   - every table lies inside the viewport and its own content fits (table.scrollWidth <= table.clientWidth),
 *   - on touch widths the controls this module adds are at least 44px.
 * Variants: sidebar open and closed (desktop widths), filters open, a row's details open, a dialog open.
 * A new ultra-wide table fails here instead of regressing later. Nothing is hidden with overflow-x: hidden.
 */

type Route = { path: string; label: string; table?: boolean; filament?: boolean; dialog?: string };
const routes: Route[] = JSON.parse(readFileSync(resolve(import.meta.dirname, 'support', 'responsive-tables-routes.json'), 'utf8'));
const TOLERANCE = 1;

async function login(page: Page): Promise<void> {
    await page.goto('/login');
    const response = await page.request.post('/login', { form: {
        _token: await page.locator('input[name="_token"]').inputValue(),
        employee_id: 'tables-admin@example.test', password: 'synthetic-tables-password',
    }, maxRedirects: 0 });
    expect(response.status()).toBe(302);
}

/** Wait for Livewire's deferred table load and for hub-tables.js to apply its plan. */
async function settle(page: Page): Promise<void> {
    // Pages with wire:poll never go fully idle, so the wait is bounded.
    await page.waitForLoadState('networkidle', { timeout: 5_000 }).catch(() => undefined);
    await page.waitForTimeout(1_200);
}

async function measure(page: Page) {
    return page.evaluate(() => {
        const root = document.documentElement;
        const viewport = root.clientWidth;
        const scrollers: string[] = [];
        document.querySelectorAll('body *').forEach(element => {
            const style = getComputedStyle(element);
            if (style.display === 'none' || style.visibility === 'hidden' || element.clientWidth === 0) return;
            // The shell's own menus are owned by hub-shell.css and covered by shell-alignment.spec.ts.
            if (element.closest('.hub-shell-popover, .hub-shell-bar')) return;
            if ((style.overflowX === 'auto' || style.overflowX === 'scroll') && element.scrollWidth > element.clientWidth + 1) {
                scrollers.push(`${element.tagName.toLowerCase()}.${String(element.getAttribute('class') ?? '').split(/\s+/).slice(0, 3).join('.')} +${element.scrollWidth - element.clientWidth}px`);
            }
        });
        const tables = [...document.querySelectorAll('table')].filter(table => table.getBoundingClientRect().width > 0).map(table => {
            const rect = table.getBoundingClientRect();
            return {
                name: `table.${String(table.getAttribute('class') ?? '').split(/\s+/).slice(0, 2).join('.')}`,
                mode: (table as HTMLElement).dataset.hubMode ?? 'table',
                left: rect.left, right: rect.right,
                contentOverflow: table.scrollWidth - table.clientWidth,
            };
        });
        const small = [...document.querySelectorAll('.hub-rt-toggle, .hub-rt-sort__select')].map(control => {
            const rect = control.getBoundingClientRect();
            return { name: control.className, width: rect.width, height: rect.height };
        }).filter(control => control.width > 0);
        return { viewport, documentWidth: root.scrollWidth, bodyWidth: document.body.scrollWidth, scrollers, tables, small };
    });
}

async function expectFits(page: Page, context: string): Promise<void> {
    const report = await measure(page);
    expect(report.documentWidth, `${context}: document scrollWidth vs viewport ${report.viewport}`).toBeLessThanOrEqual(report.viewport + TOLERANCE);
    expect(report.bodyWidth, `${context}: body scrollWidth`).toBeLessThanOrEqual(report.viewport + TOLERANCE);
    expect(report.scrollers, `${context}: sideways scrollers`).toEqual([]);
    for (const table of report.tables) {
        expect(table.right, `${context}: ${table.name} right edge`).toBeLessThanOrEqual(report.viewport + TOLERANCE);
        expect(table.left, `${context}: ${table.name} left edge`).toBeGreaterThanOrEqual(-TOLERANCE);
        expect(table.contentOverflow, `${context}: ${table.name} (${table.mode}) content wider than the table`).toBeLessThanOrEqual(TOLERANCE);
    }
}

test.beforeEach(async ({ page }) => {
    await login(page);
});

for (const route of routes) {
    test(`${route.label} fits the viewport`, async ({ page }, info) => {
        const width = page.viewportSize()!.width;
        const response = await page.goto(route.path);
        expect(response?.status(), `${route.path} status`).toBeLessThan(400);
        await settle(page);
        if (route.table) await expect(page.locator('table').first(), `${route.path} renders a table`).toBeVisible();
        await expectFits(page, `${route.label} @${width}`);

        // Details open: the hidden fields appear in the row without widening anything.
        const toggle = page.locator('.hub-rt-toggle').first();
        if (await toggle.count()) {
            await toggle.click();
            await expect(toggle).toHaveAttribute('aria-expanded', 'true');
            await expectFits(page, `${route.label} @${width} details open`);
            if (info.project.use.hasTouch) {
                for (const control of (await measure(page)).small) {
                    expect(Math.min(control.width, control.height), `${route.label}: touch target ${control.name}`).toBeGreaterThanOrEqual(44);
                }
            }
        }

        // Filters open (Filament's filter dropdown), where the page has one.
        const filters = page.locator('.fi-ta-filters-dropdown-trigger, button[aria-label="Filter"], button[title="Filter"]').first();
        if (route.filament && await filters.count() && await filters.isVisible()) {
            await filters.click();
            await page.waitForTimeout(400);
            await expectFits(page, `${route.label} @${width} filters open`);
            await page.keyboard.press('Escape');
        }

        // Sidebar closed (desktop widths keep it open by default).
        if (route.filament && width >= 1024) {
            await page.evaluate(() => (window as unknown as { Alpine?: { store(name: string): { close(): void } } }).Alpine?.store('sidebar').close());
            await page.waitForTimeout(600);
            await expectFits(page, `${route.label} @${width} sidebar closed`);
        }

        // A dialog opened from the row (view/detail modal) stays inside the viewport.
        if (route.dialog) {
            await page.getByRole('button', { name: route.dialog, exact: true }).first().click();
            const dialog = page.locator('.fi-modal-open .fi-modal-window:visible').first();
            await expect(dialog).toBeVisible();
            const box = await dialog.boundingBox();
            expect(box!.x, `${route.label}: dialog left`).toBeGreaterThanOrEqual(-TOLERANCE);
            expect(box!.x + box!.width, `${route.label}: dialog right`).toBeLessThanOrEqual(width + TOLERANCE);
            await expectFits(page, `${route.label} @${width} dialog open`);
        }
    });
}

test('personnel directory keeps every column reachable: sort menu, search and details', async ({ page }) => {
    await page.goto('/admin/master-personnel-directory');
    await settle(page);
    const mode = await page.locator('table.fi-ta-table').first().getAttribute('data-hub-mode');
    const width = page.viewportSize()!.width;
    if (mode) {
        // Moved columns stay sortable through the menu and visible in the open row.
        await expect(page.locator('.hub-rt-sort__select').first()).toBeVisible();
        await page.locator('.hub-rt-toggle').first().click();
        await expect(page.locator('tr[data-hub-open] td[data-hub-hidden]').first()).toBeVisible();
        const labels = await page.locator('tr[data-hub-open] td[data-hub-hidden]').evaluateAll(cells => cells.map(cell => cell.getAttribute('data-hub-label')));
        expect(labels.filter(Boolean).length, `moved columns at ${width}`).toBeGreaterThan(0);
    }
    await page.getByPlaceholder(/Search/).first().fill('99010');
    await expect(page.locator('table.fi-ta-table tbody tr').filter({ hasText: '99010' }).first()).toBeVisible();
});

test('long unbroken strings in a table cell wrap instead of widening the table', async ({ page }) => {
    await page.goto('/admin/master-personnel-directory');
    await settle(page);
    await page.getByPlaceholder(/Search/).first().fill('99003');
    await expect(page.locator('table.fi-ta-table tbody tr').filter({ hasText: '99003' }).first()).toBeVisible();
    await expectFits(page, 'long email');
});
