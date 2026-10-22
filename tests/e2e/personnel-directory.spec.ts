import { test, expect, Locator, Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

function readWorkbook(path: string): unknown[][] {
    const options = process.platform === 'win32' ? ['-d', 'extension=php_sodium.dll'] : [];
    return JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [...options, '-r',
        'require "vendor/autoload.php"; $book = PhpOffice\\PhpSpreadsheet\\IOFactory::load($argv[1]); echo json_encode($book->getSheetByName("Personnel")->toArray(null, true, false), JSON_THROW_ON_ERROR);', path], { encoding: 'utf8' }));
}

async function login(page: Page, role = 'admin') {
    await page.goto('/login');
    const response = await page.request.post('/login', { form: {
        _token: await page.locator('input[name="_token"]').inputValue(),
        employee_id: `personnel-${role}@example.test`, password: 'synthetic-personnel-password',
    }, maxRedirects: 0 });
    expect(response.status()).toBe(302);
    expect(response.headers().location).not.toContain('/login');
}

async function openTable(page: Page, name: string) {
    await page.goto('/admin/custom-personnel-tables');
    await page.getByRole('link', { name, exact: true }).click();
    await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
}

async function ready(control: Locator) {
    // Filament renders table controls before AsyncAlpine binds their native directives.
    await expect.poll(() => control.evaluate(element => typeof (element as unknown as { _x_marker?: number })._x_marker === 'number')).toBe(true);
}

async function action(page: Page, name: string) {
    const button = page.getByRole('button', { name, exact: true }).first();
    if (!await button.isVisible()) {
        const group = page.getByRole('button', { name: ['Save view', 'Saved views', 'Delete saved view'].includes(name) ? 'Views' : 'More', exact: true }).first();
        await ready(group);
        await group.click();
    }
    await ready(button);
    await expect.poll(() => button.evaluate(element => {
        let component: Element | null = element;
        while (component && !component.hasAttribute('wire:id')) component = component.parentElement;
        const modals = Array.from(component?.querySelectorAll('[data-fi-modal-id]') ?? []);
        return modals.length > 0 && modals.every(modal =>
            Object.hasOwn((modal as unknown as { _x_attributeCleanups?: Record<string, unknown> })._x_attributeCleanups ?? {}, 'x-on:open-modal.window'));
    })).toBe(true);
    await button.click();
    const dialog = page.locator('.fi-modal-open .fi-modal-window:visible').first();
    await expect(dialog).toBeVisible();
    return dialog;
}

test('homepage card preserves the signed-in inspection route and responsive directory remains usable', async ({ page }, info) => {
    await login(page);
    await page.goto('/');
    const card = page.locator('[data-quick-access-card]').filter({ hasText: 'Take Home Vehicle Inspection' });
    await expect(card).toBeVisible();
    await expect(card).toContainText('Complete a take-home vehicle inspection.');
    await expect(page.locator('footer')).not.toContainText('Take Home Vehicle Inspection');
    await expect(page.locator('footer')).toContainText('Security & Standards');
    await card.click();
    await expect(page).toHaveURL(/\/take-home-vehicle-inspections$/);
    await expect(page.getByRole('link', { name: 'New inspection', exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'New inspection', exact: true }).click();
    await expect(page.getByText('Inspection type: Take Home Vehicle Inspection', { exact: false })).toBeVisible();
    await page.goto('/admin/master-personnel-directory');
    await expect(page.getByRole('heading', { name: 'Master Personnel Directory', exact: true })).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: '99002' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Details', exact: true }).first()).toBeVisible();
    const details = await action(page, 'Details');
    await expect(details).toContainText('Name');
    await expect(details).toContainText('Synthetic');
    await details.getByRole('button', { name: 'Close', exact: true }).last().click();
    await expect(page.locator('.fi-modal-window:visible')).toHaveCount(0);
    await page.getByRole('columnheader').nth(1).scrollIntoViewIfNeeded();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
    await page.screenshot({ path: info.outputPath('directory-responsive.png'), fullPage: true });
});

test('administrator creates a table, edits tracking, saves a view and exports actual workbook', async ({ page }, info) => {
    await login(page);
    await page.goto('/admin/custom-personnel-tables');
    const name = `Synthetic browser ${info.project.name}`;
    const create = await action(page, 'Create Table');
    await create.getByRole('textbox', { name: /^Table name/ }).fill(name);
    await expect(create.getByText('3', { exact: true })).toBeVisible();
    await create.getByRole('button', { name: 'Add tracking column', exact: true }).click();
    await create.getByRole('textbox', { name: /^Column name/ }).fill('Acknowledged');
    await create.getByRole('button', { name: 'Create Table', exact: true }).click();
    await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
    const tableUrl = page.url();
    const alpha = page.getByRole('row').filter({ hasText: 'Synthetic Alpha' });
    const checkbox = alpha.locator('input[type="checkbox"]').last();
    await ready(checkbox);
    await checkbox.check();
    await expect(page.getByText('Saved', { exact: true }).last()).toBeVisible();
    await page.reload();
    await expect(alpha.locator('input[type="checkbox"]').last()).toBeChecked();
    const save = await action(page, 'Save view');
    await save.getByRole('textbox', { name: /^Name/ }).fill('Synthetic saved view');
    await save.getByRole('button', { name: 'Submit', exact: true }).click();
    await expect(page.getByText('View saved', { exact: true })).toBeVisible();
    const saved = await action(page, 'Saved views');
    await saved.getByRole('combobox', { name: /^View/ }).selectOption({ label: 'Synthetic saved view' });
    await saved.getByRole('button', { name: 'Submit', exact: true }).click();
    await expect(page.getByText('View applied', { exact: true })).toBeVisible();
    const columns = await action(page, 'Columns');
    await columns.getByRole('button', { name: 'Move down', exact: true }).first().click();
    await columns.getByRole('button', { name: 'Submit', exact: true }).click();
    await expect(page.getByText('Columns saved', { exact: true })).toBeVisible();
    await expect(page.getByRole('columnheader').nth(1)).toContainText('Employee ID');
    const exportDialog = await action(page, 'Export');
    const downloadPending = page.waitForEvent('download');
    await exportDialog.getByRole('button', { name: 'Submit', exact: true }).click();
    const download = await downloadPending;
    expect(download.suggestedFilename()).toMatch(/\.xlsx$/);
    const exportPath = info.outputPath('personnel-export.xlsx');
    await download.saveAs(exportPath);
    const workbook = readWorkbook(exportPath);
    expect(workbook).toHaveLength(4);
    expect(workbook[0][0]).toBe('Employee ID');
    expect(workbook.find(row => row[0] === '99002')?.[3]).toBe(true);
    for (const format of info.project.name === 'desktop' ? ['csv', 'pdf'] : ['csv']) {
        const formatDialog = await action(page, 'Export');
        await formatDialog.getByRole('combobox', { name: /^Format/ }).selectOption(format);
        const pending = page.waitForEvent('download');
        await formatDialog.getByRole('button', { name: 'Submit', exact: true }).click();
        const file = await pending;
        expect(file.suggestedFilename()).toMatch(new RegExp(`\\.${format}$`));
        const path = info.outputPath(`personnel-export.${format}`);
        await file.saveAs(path);
        if (format === 'csv') expect(readFileSync(path, 'utf8')).toContain('99002');
        else expect(readFileSync(path).subarray(0, 5).toString()).toBe('%PDF-');
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
    await page.screenshot({ path: info.outputPath('custom-table.png'), fullPage: true });
    const archive = await action(page, 'Archive');
    await archive.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/custom-personnel-tables$/);
    await page.goto(tableUrl);
    const restore = await action(page, 'Restore');
    await restore.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(page.getByText('Table restored', { exact: true })).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: 'Synthetic Alpha' }).locator('input[type="checkbox"]').last()).toBeChecked();
});

test('fixed membership remains while dynamic membership follows source changes and retains tracking', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'The shared source transition is verified once; layouts are covered independently.');
    await login(page);
    await openTable(page, 'Synthetic dynamic tracking');
    let alpha = page.getByRole('row').filter({ hasText: 'Synthetic Alpha' });
    await expect(alpha.locator('input[type="checkbox"]').last()).toBeChecked();
    await page.goto('/admin/master-personnel-directory');
    alpha = page.getByRole('row').filter({ hasText: '99002' });
    const rankInput = alpha.locator('.fi-table-cell-rank input:not([type="hidden"])');
    await ready(rankInput);
    await rankInput.fill('Captain');
    await rankInput.press('Tab');
    await expect(page.getByText('Profile saved', { exact: true }).last()).toBeVisible();
    await openTable(page, 'Synthetic dynamic tracking');
    await expect(page.getByRole('row').filter({ hasText: 'Synthetic Alpha' })).toHaveCount(0);
    await openTable(page, 'Synthetic fixed tracking');
    await expect(page.getByRole('row').filter({ hasText: 'Synthetic Alpha' })).toContainText('Captain');
    await page.goto('/admin/master-personnel-directory');
    const restoreRank = page.getByRole('row').filter({ hasText: '99002' }).locator('.fi-table-cell-rank input:not([type="hidden"])');
    await ready(restoreRank);
    await restoreRank.fill('Firefighter');
    await restoreRank.press('Tab');
    await expect(page.getByText('Profile saved', { exact: true }).last()).toBeVisible();
    await openTable(page, 'Synthetic dynamic tracking');
    await expect(page.getByRole('row').filter({ hasText: 'Synthetic Alpha' }).locator('input[type="checkbox"]').last()).toBeChecked();
});

test('ordinary member is denied direct directory and custom table URLs', async ({ page }) => {
    await login(page, 'member');
    for (const url of ['/admin/master-personnel-directory', '/admin/custom-personnel-tables', '/admin/custom-personnel-tables/2']) {
        const response = await page.goto(url);
        expect(response?.status()).not.toBe(200);
        await expect(page.getByRole('heading', { name: 'Master Personnel Directory', exact: true })).toHaveCount(0);
    }
});

test('inline checkbox date and text persist after native save notifications close without server errors', async ({ page }, info) => {
    const serverErrors: number[] = [];
    const databaseCloseStatuses: number[] = [];
    await page.addInitScript(() => {
        const state = window as unknown as { personnelClosedNotificationIds: string[] };
        state.personnelClosedNotificationIds = [];
        window.addEventListener('notificationClosed', event => {
            state.personnelClosedNotificationIds.push((event as CustomEvent<{ id: string }>).detail.id);
        });
    });
    page.on('response', response => {
        if (!response.url().endsWith('/livewire/update')) return;
        if (response.status() >= 500) serverErrors.push(response.status());
        const payload = response.request().postDataJSON() as { components?: { snapshot: string; calls?: { method: string; params: unknown[] }[] }[] };
        if (payload.components?.some(component => JSON.parse(component.snapshot).memo.name === 'filament.livewire.database-notifications'
            && component.calls?.some(call => call.method === '__dispatch' && call.params[0] === 'notificationClosed'))) {
            databaseCloseStatuses.push(response.status());
        }
    });
    const closedIds = () => page.evaluate(() => (window as unknown as { personnelClosedNotificationIds: string[] }).personnelClosedNotificationIds);
    const saveAndClose = async (save: () => Promise<unknown>) => {
        const priorEventCount = (await closedIds()).length;
        const priorResponseCount = databaseCloseStatuses.length;
        await save();
        await expect(page.getByText('Saved', { exact: true }).last()).toBeVisible();
        await expect.poll(async () => (await closedIds()).length).toBeGreaterThan(priorEventCount);
        expect((await closedIds()).at(-1)).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i);
        await expect.poll(() => databaseCloseStatuses.length).toBeGreaterThan(priorResponseCount);
        expect(databaseCloseStatuses.at(-1)).toBe(200);
        expect(serverErrors).toEqual([]);
        await expect(page.locator('iframe')).toHaveCount(0);
    };
    await login(page);
    await openTable(page, `Synthetic inline date and text tracking ${info.project.name}`);
    const row = page.getByRole('row').filter({ hasText: '99001' });
    const reviewed = row.locator('input[type="checkbox"]').last();
    await ready(reviewed);
    await saveAndClose(() => reviewed.check());
    await page.reload();
    await expect(reviewed).toBeChecked();
    const date = row.locator('input[type="date"]');
    await ready(date);
    await saveAndClose(async () => { await date.fill('2026-10-21'); await date.press('Tab'); });
    const text = row.locator('input[type="text"]');
    await ready(text);
    await saveAndClose(async () => { await text.fill('Synthetic persisted inline note'); await text.press('Tab'); });
    await page.reload();
    await expect(reviewed).toBeChecked();
    await expect(date).toHaveValue('2026-10-21');
    await expect(text).toHaveValue('Synthetic persisted inline note');
    expect(serverErrors).toEqual([]);
    await expect(page.locator('iframe')).toHaveCount(0);
    await page.screenshot({ path: info.outputPath('inline-tracking-persisted.png'), fullPage: true });
});
