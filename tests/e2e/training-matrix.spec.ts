import { test, expect, Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';

async function login(page: Page, role = 'training_viewer') {
    await page.goto('/login');
    await page.getByLabel('Employee ID or email').fill(`${role}@example.test`);
    await page.locator('input[type="password"]').fill('synthetic-matrix-password');
    const response = await page.request.post('/login', { form: {
        _token: await page.locator('input[name="_token"]').inputValue(),
        employee_id: `${role}@example.test`, password: 'synthetic-matrix-password',
    }, maxRedirects: 0 });
    expect(response.status()).toBe(302);
    expect(response.headers().location).not.toContain('/login');
}

function readWorkbook(path: string): Record<string, unknown[][]> {
    return JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', ['-r',
        'require "vendor/autoload.php"; $book = PhpOffice\\PhpSpreadsheet\\IOFactory::load($argv[1]); $sheets = []; foreach ($book->getAllSheets() as $sheet) $sheets[$sheet->getTitle()] = $sheet->toArray(); echo json_encode($sheets, JSON_THROW_ON_ERROR);', path], { encoding: 'utf8' }));
}

async function matrix(page: Page) {
    await login(page);
    await page.goto('/training/training-courses/1/matrix');
    await expect(page.getByRole('heading', { name: 'Synthetic matrix course — Attendance matrix', exact: true })).toBeVisible();
    return page.getByRole('region', { name: 'Training attendance matrix', exact: true });
}

test('matrix renders and filters personnel with shareable module and session views', async ({ page }, info) => {
    const region = await matrix(page);
    await expect(region.getByRole('rowheader')).toHaveCount(3);
    await expect(region.getByRole('columnheader', { name: /Synthetic ladders/ })).toBeVisible();
    const alpha = region.getByRole('row').filter({ hasText: 'Synthetic Alpha' });
    await expect(alpha).toContainText('1 / 2');
    await expect(alpha).toContainText('50%');
    await expect(alpha).toContainText('Synthetic pumping');
    await expect(alpha).toContainText('Assignment unverified');
    await page.getByLabel('Personnel', { exact: true }).selectOption('officers');
    await expect(region.getByRole('rowheader')).toHaveCount(2);
    await page.getByLabel('Personnel', { exact: true }).selectOption('firefighters');
    await expect(region.getByRole('rowheader')).toHaveCount(1);
    await page.getByLabel('Personnel', { exact: true }).selectOption('');
    await page.getByLabel('Missing module', { exact: true }).selectOption('1');
    await expect(region.getByRole('rowheader')).toHaveCount(2);
    await expect(region).not.toContainText('Synthetic Alpha');
    await page.getByLabel('Missing module', { exact: true }).selectOption('');
    await page.getByLabel('Name or employee ID', { exact: true }).fill('SYNTH-ALPHA');
    await expect(region.getByRole('rowheader')).toHaveCount(1);
    await expect(page).toHaveURL(/filters/);
    await page.reload();
    await expect(region.getByRole('rowheader')).toHaveCount(1);
    await expect(page.getByLabel('Name or employee ID', { exact: true })).toHaveValue('SYNTH-ALPHA');
    await page.getByRole('button', { name: 'Session detail', exact: true }).click();
    await expect(region.getByRole('columnheader', { name: /Session #1/ })).toContainText('Oct 20, 2026');
    await expect(region.getByRole('columnheader', { name: /Session #1/ })).toContainText('10:00 AM EDT');
    await expect(region.getByRole('columnheader', { name: /Session #1/ })).toContainText('Synthetic classroom');
    await expect(page).toHaveURL(/display=sessions/);
    await page.screenshot({ path: info.outputPath('matrix-session-filtered.png'), fullPage: true });
    await page.getByRole('button', { name: 'Module summary', exact: true }).click();
    await expect(region.getByText('Session #1', { exact: true })).toHaveCount(0);
    await page.getByLabel('Name or employee ID', { exact: true }).fill('No synthetic member matches');
    await expect(region).toContainText('No personnel match these filters');
});

test('actual Excel downloads distinguish current filters from complete course workbook', async ({ page }, info) => {
    const region = await matrix(page);
    await page.getByLabel('Personnel', { exact: true }).selectOption('officers');
    await expect(region.getByRole('rowheader')).toHaveCount(2);
    await page.getByLabel('Personnel', { exact: true }).selectOption('firefighters');
    await expect(region.getByRole('rowheader')).toHaveCount(1);
    await page.getByLabel('Personnel', { exact: true }).selectOption('');
    await page.getByLabel('Missing module', { exact: true }).selectOption('1');
    await expect(region.getByRole('rowheader')).toHaveCount(2);
    await expect(region).not.toContainText('Synthetic Alpha');
    await page.getByLabel('Missing module', { exact: true }).selectOption('');
    await page.getByLabel('Name or employee ID', { exact: true }).fill('SYNTH-ALPHA');
    await expect(region.getByRole('rowheader')).toHaveCount(1);
    await page.getByRole('button', { name: 'Session detail', exact: true }).click();
    await expect(region.getByText('Session #1', { exact: true })).toBeVisible();
    const currentPending = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Export current view', exact: true }).click();
    const current = await currentPending;
    expect(current.suggestedFilename()).toBe('training-course-1-current-view.xlsx');
    const currentPath = info.outputPath('current-view.xlsx');
    await current.saveAs(currentPath);
    const currentBook = readWorkbook(currentPath);
    expect(currentBook['Current View']).toHaveLength(2);
    expect(currentBook['Current View'][1][0]).toBe('SYNTH-ALPHA');
    expect(currentBook['Current View'][0].join('|')).toContain('Session 1');
    expect(JSON.stringify(currentBook.Overview)).toContain('Current filtered view');
    const completePending = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Complete workbook', exact: true }).click();
    const complete = await completePending;
    expect(complete.suggestedFilename()).toBe('training-course-1-complete.xlsx');
    const completePath = info.outputPath('complete.xlsx');
    await complete.saveAs(completePath);
    const completeBook = readWorkbook(completePath);
    expect(completeBook['All Personnel']).toHaveLength(4);
    expect(completeBook['Officers']).toHaveLength(3);
    expect(completeBook['All Firefighters']).toHaveLength(2);
    expect(completeBook['Session Detail']).toHaveLength(4);
    expect(completeBook['Attendance Evidence']).toHaveLength(2);
    expect(completeBook['All Personnel'][0]).toContain('Synthetic ladders');
    expect(JSON.stringify(completeBook)).not.toContain('synthetic/not-read.png');
});

test('viewer cannot manage modules and unrelated admin cannot open a matrix', async ({ page, browser }) => {
    await matrix(page);
    await page.getByRole('link', { name: 'Modules', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Synthetic matrix course — Modules', exact: true })).toBeVisible();
    await expect(page.getByRole('cell', { name: 'Synthetic ladders', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: /Add module|Create module|^Edit$/ })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Course settings', exact: true })).toHaveCount(0);
    // Livewire responses on the viewer page may refresh its session cookies.
    // A separate context keeps the two authenticated identities independent.
    const adminContext = await browser.newContext({ baseURL: new URL(page.url()).origin });
    try {
        const adminPage = await adminContext.newPage();
        await login(adminPage, 'admin');
        const response = await adminPage.goto('/training/training-courses/1/matrix');
        expect([403, 404]).toContain(response?.status());
        await expect(adminPage.getByRole('region', { name: 'Training attendance matrix', exact: true })).toHaveCount(0);
        await expect(adminPage.getByRole('button', { name: 'Complete workbook', exact: true })).toHaveCount(0);
    } finally {
        await adminContext.close();
    }
});
