import { test, expect, Locator, Page } from '@playwright/test';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { execFileSync } from 'node:child_process';

async function ready(control: Locator) {
    await expect.poll(() => control.evaluate(element => typeof (element as unknown as { _x_marker?: number })._x_marker === 'number')).toBe(true);
}

async function openAction(page: Page, name: string) {
    const button = page.getByRole('button', { name, exact: true }).first();
    await ready(button);
    await button.click();
    const dialog = page.locator('.fi-modal-open .fi-modal-window:visible').first();
    await expect(dialog).toBeVisible();
    return dialog;
}

test('vehicle documents remain usable across upload, refresh, search, preview, download and packaged export', async ({ page }, info) => {
    const { directory } = JSON.parse(readFileSync(resolve('test-results/vehicle-documents-fixture.json'), 'utf8'));
    const fixture = JSON.parse(readFileSync(resolve(directory, 'fixture.json'), 'utf8'));
    const project = info.project.name;
    const id = fixture.vehicles[project];
    const title = `Synthetic uploaded report ${project}`;
    const original = readFileSync(fixture.synthetic_pdf);

    await page.goto('/login');
    const signedIn = await page.request.post('/login', { form: {
        _token: await page.locator('input[name="_token"]').inputValue(),
        employee_id: `vehicle-${project}@example.test`, password: 'synthetic-vehicle-password',
    }, maxRedirects: 0 });
    expect(signedIn.status()).toBe(302);
    expect(signedIn.headers().location).not.toContain('/login');
    await page.goto(`/admin/unit-master-vehicles/${id}`);
    await expect(page.getByRole('heading', { name: new RegExp(`QA-${project.toUpperCase()}`) })).toBeVisible();
    const documentsTab = page.getByRole('tab', { name: /^Documents/ });
    await ready(documentsTab);
    await documentsTab.click();
    await expect(page.getByText('Synthetic original record', { exact: true })).toBeVisible({ timeout: 90_000 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
    await page.screenshot({ path: info.outputPath('vehicle-profile-before-upload.png'), fullPage: true });

    const upload = await openAction(page, 'Add Documents');
    await upload.locator('input[type="file"]').setInputFiles(fixture.synthetic_pdf);
    const titleField = upload.getByRole('textbox', { name: /^Title/ });
    await expect(titleField).toBeVisible();
    await titleField.fill(title);
    await upload.getByRole('button', { name: 'Upload documents', exact: true }).click();
    await expect(page.locator('.fi-modal-window:visible')).toHaveCount(0);
    await expect(page.getByText(title, { exact: true })).toBeVisible();
    await page.reload();
    await ready(page.getByRole('tab', { name: /^Documents/ }));
    await page.getByRole('tab', { name: /^Documents/ }).click();
    await expect(page.getByText(title, { exact: true })).toBeVisible();

    const documents = page.locator('.fi-ta').filter({ has: page.getByRole('heading', { name: 'Documents', exact: true }) });
    const search = documents.getByRole('searchbox');
    await search.fill(title);
    await expect(documents.getByText('Synthetic original record', { exact: true })).toHaveCount(0);
    await expect(documents.getByText(title, { exact: true })).toBeVisible();

    const downloadLink = documents.getByRole('link', { name: 'Download', exact: true }).first();
    const originalDownloaded = page.waitForEvent('download');
    await downloadLink.click();
    const downloadedOriginal = await originalDownloaded;
    const originalPath = info.outputPath('downloaded-original.pdf');
    await downloadedOriginal.saveAs(originalPath);
    expect(createHash('sha256').update(readFileSync(originalPath)).digest('hex')).toBe(createHash('sha256').update(original).digest('hex'));
    const previewLink = documents.getByRole('link', { name: 'Preview', exact: true }).first();
    const preview = await page.request.get(await previewLink.getAttribute('href') as string);
    expect(preview.status()).toBe(200);
    expect(preview.headers()['content-type']).toContain('application/pdf');
    expect(preview.headers()['content-disposition']).toContain('inline');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
    await page.screenshot({ path: info.outputPath('documents-mobile-ready.png'), fullPage: true });

    const pdfDialog = await openAction(page, 'Export Vehicle Profile');
    const pdfDownloaded = page.waitForEvent('download');
    await pdfDialog.getByRole('button', { name: 'Download', exact: true }).click();
    const pdfDownload = await pdfDownloaded;
    expect(pdfDownload.suggestedFilename()).toMatch(/\.pdf$/);
    const pdfPath = info.outputPath('vehicle-profile.pdf');
    await pdfDownload.saveAs(pdfPath);
    expect(readFileSync(pdfPath).subarray(0, 4).toString()).toBe('%PDF');
    await expect(page.locator('.fi-modal-window:visible')).toHaveCount(0);

    const exportDialog = await openAction(page, 'Export Vehicle Profile');
    await exportDialog.getByLabel('Excel', { exact: true }).check();
    await exportDialog.getByLabel('All permitted documents', { exact: true }).check();
    const downloaded = page.waitForEvent('download');
    await exportDialog.getByRole('button', { name: 'Download', exact: true }).click();
    const download = await downloaded;
    expect(download.suggestedFilename()).toMatch(/\.zip$/);
    const packagePath = info.outputPath('vehicle-export.zip');
    await download.saveAs(packagePath);
    const phpOptions = process.platform === 'win32' ? ['-d', 'extension=php_sodium.dll'] : [];
    const packet = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [...phpOptions, '-r',
        '$zip = new ZipArchive; if ($zip->open($argv[1]) !== true) exit(2); $manifest = json_decode($zip->getFromName("manifest.json"), true, flags: JSON_THROW_ON_ERROR); foreach ($manifest["documents"] as $entry) { if (hash("sha256", $zip->getFromName($entry["path"])) !== $entry["sha256"]) exit(3); } echo json_encode(["count" => count($manifest["documents"]), "profile" => $manifest["profile"]["path"], "titles" => array_column($manifest["documents"], "title")], JSON_THROW_ON_ERROR);', packagePath], { encoding: 'utf8' }));
    expect(packet.count).toBe(2);
    expect(packet.profile).toMatch(/\.xlsx$/);
    expect(packet.titles).toContain(title);
    expect(packet.titles).toContain('Synthetic original record');
});
