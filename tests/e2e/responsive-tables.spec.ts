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

type Route = { path: string; label: string; table?: boolean; filament?: boolean; deep?: boolean; dialog?: string; menus?: boolean };
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
            // Content of a closed menu (<details> without open) is never shown, so it cannot be a scroller the member
            // sees. The shell's menus are measured open, one at a time, by the `menus` variant below.
            if (element.parentElement?.closest('details:not([open])') && !element.closest('summary')) return;
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

        // Every shell menu (module switcher, account, More sheet) opened on its own: the menu and the document hold.
        if (route.menus) {
            const summaries = page.locator('details.hub-shell-menu > summary:visible');
            const count = await summaries.count();
            expect(count, `${route.label}: shell menus found`).toBeGreaterThan(0);
            for (let index = 0; index < count; index += 1) {
                await summaries.nth(index).click();
                await page.waitForTimeout(350);
                const menu = page.locator('details.hub-shell-menu[open] > .hub-shell-popover').first();
                const box = await menu.boundingBox();
                expect(box, `${route.label}: menu ${index} is on screen`).not.toBeNull();
                expect(box!.x, `${route.label}: menu ${index} left edge`).toBeGreaterThanOrEqual(-TOLERANCE);
                expect(box!.x + box!.width, `${route.label}: menu ${index} right edge`).toBeLessThanOrEqual(width + TOLERANCE);
                await expectFits(page, `${route.label} @${width} menu ${index} open`);
                // An open More sheet covers the bar, so close the menu through the page, as Escape or the backdrop would.
                await page.evaluate(() => document.querySelectorAll('details.hub-shell-menu[open]').forEach(menu => menu.removeAttribute('open')));
                await expect(page.locator('details.hub-shell-menu[open]')).toHaveCount(0);
            }
        }

        // Filters open (Filament's filter dropdown), where the page has one.
        const filters = page.locator('.fi-ta-filters-dropdown-trigger, button[aria-label="Filter"], button[title="Filter"]').first();
        if (route.deep && await filters.count() && await filters.isVisible()) {
            await filters.click();
            await page.waitForTimeout(400);
            await expectFits(page, `${route.label} @${width} filters open`);
            await page.keyboard.press('Escape');
        }

        // Sidebar closed (desktop widths keep it open by default).
        if (route.deep && width >= 1024) {
            await page.evaluate(() => (window as unknown as { Alpine?: { store(name: string): { close(): void } } }).Alpine?.store('sidebar').close());
            await page.waitForTimeout(600);
            await expectFits(page, `${route.label} @${width} sidebar closed`);
        }

        // A dialog opened from the row (view/detail modal) stays inside the viewport.
        if (route.deep && route.dialog) {
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

test('a re-render with unchanged width does not re-plan or rewrite settled rows, and open details and focus survive', async ({ page }) => {
    await page.goto('/admin/master-personnel-directory');
    await settle(page);
    const stats = () => page.evaluate(() => ({ ...(window as unknown as { __hubTablesStats: Record<string, number> }).__hubTablesStats }));
    const row = page.locator('table.fi-ta-table tbody tr').filter({ hasText: '99001' }).first();
    await expect(row).toBeVisible();
    const toggle = row.locator('.hub-rt-toggle');
    await toggle.click();
    await expect(row).toHaveAttribute('data-hub-open', '');
    const rowCount = await page.locator('table.fi-ta-table tbody tr').count();

    // 1. Nothing changed: a pass writes nothing to the table (no attribute or child reset on any row).
    const quiet = await page.evaluate(async () => {
        const table = document.querySelector('table.fi-ta-table')!;
        const records: number[] = [];
        const watcher = new MutationObserver(list => records.push(list.length));
        watcher.observe(table, { attributes: true, childList: true, subtree: true });
        window.dispatchEvent(new Event('resize'));
        await new Promise(resolve => setTimeout(resolve, 700));
        watcher.disconnect();
        return records.reduce((sum, n) => sum + n, 0);
    });
    expect(quiet, 'DOM writes on a pass with unchanged width and rows').toBe(0);

    // 2. A Livewire re-render at the same width: no re-plan, rows are restored once, details stay open, focus returns.
    await toggle.focus();
    const before = await stats();
    await page.evaluate(async () => {
        const id = document.querySelector('table.fi-ta-table')!.closest('[wire\\:id]')!.getAttribute('wire:id')!;
        await (window as unknown as { Livewire: { find(id: string): { $refresh(): Promise<void> } } }).Livewire.find(id).$refresh();
    });
    await page.waitForTimeout(900);
    const after = await stats();
    expect(after.full - before.full, 'full re-plans caused by a same-width morph').toBe(0);
    expect(after.rows - before.rows, 'rows rewritten by one morph').toBeLessThanOrEqual(rowCount);
    await expect(row).toHaveAttribute('data-hub-open', '');
    await expect(row.locator('.hub-rt-toggle')).toHaveAttribute('aria-expanded', 'true');
    await expect(row.locator('.hub-rt-toggle')).toBeFocused();
    await expectFits(page, 'after a same-width morph');
});

test('a row checkbox keeps its box and has a 44px touch target that still selects the row', async ({ page }) => {
    await page.goto('/admin/master-personnel-directory');
    await settle(page);
    const checkbox = page.locator('table.fi-ta-table tbody td.fi-ta-selection-cell input[type="checkbox"]').first();
    await expect(checkbox).toBeVisible();
    await checkbox.scrollIntoViewIfNeeded();
    const box = (await checkbox.boundingBox())!;
    expect(Math.max(box.width, box.height), 'the visible checkbox is not enlarged').toBeLessThanOrEqual(28);

    const coarse = await page.evaluate(() => matchMedia('(pointer: coarse)').matches);
    if (coarse) {
        const area = await page.evaluate(() => {
            const cell = document.querySelector('table.fi-ta-table tbody td.fi-ta-selection-cell')!;
            const inner = cell.firstElementChild!.getBoundingClientRect();
            const outer = cell.getBoundingClientRect();
            return { width: Math.max(inner.width, outer.width), height: Math.max(inner.height, outer.height) };
        });
        expect(area.width, 'selection target width').toBeGreaterThanOrEqual(44);
        expect(area.height, 'selection target height').toBeGreaterThanOrEqual(44);
    }

    // On touch, a tap beside the box (inside the 44px square) selects the row, and a second one clears it.
    // Selecting a row can shift the page (the bulk action bar appears), so each tap measures the box again.
    const tapBeside = async (offset: number) => {
        await checkbox.scrollIntoViewIfNeeded();
        const now = (await checkbox.boundingBox())!;
        await page.mouse.click(now.x + now.width / 2 + offset, now.y + now.height / 2);
    };
    if (coarse) {
        await tapBeside(-19);
        await expect(checkbox).toBeChecked();
        await tapBeside(19);
        await expect(checkbox).not.toBeChecked();
    } else {
        // With a mouse the box is its own target: a click beside it must not select the row.
        await tapBeside(-19);
        await expect(checkbox).not.toBeChecked();
    }
    // Tapping the box itself still toggles exactly once.
    await checkbox.click();
    await expect(checkbox).toBeChecked();
});
