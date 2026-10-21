import { test, expect, Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';

function readWorkbook(path: string): unknown[][] {
    const options = process.platform === 'win32' ? ['-d', 'extension=php_sodium.dll'] : [];
    return JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [...options, '-r',
        'require "vendor/autoload.php"; $book = PhpOffice\\PhpSpreadsheet\\IOFactory::load($argv[1]); echo json_encode($book->getSheetByName("Personnel")->toArray(), JSON_THROW_ON_ERROR);', path], { encoding: 'utf8' }));
}

async function login(page: Page, role = 'admin') {
    page.on('pageerror', error => console.log('Personnel browser error', error.message));
    page.on('request', request => {
        if (request.url().endsWith('/livewire/update')) {
            const payload = request.postDataJSON();
            console.log('Personnel update request', payload.components?.map((component: any) => ({
                name: JSON.parse(component.snapshot).memo.name,
                methods: component.calls?.map((call: any) => call.method),
            })));
        }
    });
    page.on('response', response => { if (response.url().endsWith('/livewire/update')) console.log('Personnel update response', response.status()); });
    await page.goto('/login');
    const response = await page.request.post('/login', { form: {
        _token: await page.locator('input[name="_token"]').inputValue(),
        employee_id: `personnel-${role}@example.test`, password: 'synthetic-personnel-password',
    }, maxRedirects: 0 });
    expect(response.status()).toBe(302);
    expect(response.headers().location).not.toContain('/login');
}

test('existing vehicle export dialog uses the same native action runtime', async ({ page }) => {
    await login(page);
    await page.goto('/admin/unit-master-vehicles');
    const dialog = await action(page, 'Export Excel');
    await expect(dialog).toContainText('Records to export');
});

async function openTable(page: Page, name: string) {
    await page.goto('/admin/custom-personnel-tables');
    await page.getByRole('link', { name, exact: true }).click();
    await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
}

async function action(page: Page, name: string) {
    const button = page.getByRole('button', { name, exact: true }).first();
    if (!await button.isVisible()) {
        await page.getByRole('button', { name: ['Save view', 'Saved views', 'Delete saved view'].includes(name) ? 'Views' : 'More', exact: true }).first().click();
    }
    await button.click();
    console.log('Personnel component state', await page.evaluate(() => ({
        roots: Array.from(document.querySelectorAll('[wire\\:id]')).map(element => ({
            tag: element.tagName,
            component: JSON.parse(element.getAttribute('wire:snapshot') || '{}').memo?.name,
        })),
        livewireCount: (window as any).Livewire?.all().length,
    })));
    const dialog = page.getByRole('dialog').filter({ visible: true }).last();
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
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
    await page.screenshot({ path: info.outputPath('directory-responsive.png'), fullPage: true });
});

test('administrator creates a table, edits tracking, saves a view and exports actual workbook', async ({ page }, info) => {
    await login(page);
    await page.goto('/admin/custom-personnel-tables');
    const name = `Synthetic browser ${info.project.name}`;
    const create = await action(page, 'Create Table');
    await create.getByLabel('Table name', { exact: true }).fill(name);
    await expect(create.getByText('3', { exact: true })).toBeVisible();
    await create.getByRole('button', { name: 'Add tracking column', exact: true }).click();
    await create.getByLabel('Column name', { exact: true }).fill('Acknowledged');
    await create.getByRole('button', { name: 'Create Table', exact: true }).click();
    await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
    const tableUrl = page.url();
    const alpha = page.getByRole('row').filter({ hasText: 'Synthetic Alpha' });
    const checkbox = alpha.locator('input[type="checkbox"]').last();
    await checkbox.check();
    await expect(page.getByText('Saved', { exact: true }).last()).toBeVisible();
    await page.reload();
    await expect(alpha.locator('input[type="checkbox"]').last()).toBeChecked();
    const save = await action(page, 'Save view');
    await save.getByLabel('Name', { exact: true }).fill('Synthetic saved view');
    await save.getByRole('button', { name: 'Submit', exact: true }).click();
    await expect(page.getByText('View saved', { exact: true })).toBeVisible();
    const saved = await action(page, 'Saved views');
    await saved.getByLabel('View', { exact: true }).selectOption({ label: 'Synthetic saved view' });
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
    await rankInput.fill('Captain');
    await rankInput.press('Tab');
    await expect(page.getByText('Profile saved', { exact: true }).last()).toBeVisible();
    await openTable(page, 'Synthetic dynamic tracking');
    await expect(page.getByRole('row').filter({ hasText: 'Synthetic Alpha' })).toHaveCount(0);
    await openTable(page, 'Synthetic fixed tracking');
    await expect(page.getByRole('row').filter({ hasText: 'Synthetic Alpha' })).toContainText('Captain');
    await page.goto('/admin/master-personnel-directory');
    const restoreRank = page.getByRole('row').filter({ hasText: '99002' }).locator('.fi-table-cell-rank input:not([type="hidden"])');
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
