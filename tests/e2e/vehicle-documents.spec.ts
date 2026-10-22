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
    const originalPathForUpload = fixture.synthetic_pdfs[project];
    const original = readFileSync(originalPathForUpload);

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
    if (await documentsTab.getAttribute('aria-selected') !== 'true') await documentsTab.click();
    await expect(page.getByText('Synthetic original record', { exact: true })).toBeVisible({ timeout: 90_000 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
    await page.screenshot({ path: info.outputPath('vehicle-profile-before-upload.png'), fullPage: true });

    const upload = await openAction(page, 'Add Documents');
    await upload.locator('input[type="file"]').setInputFiles(originalPathForUpload);
    const titleField = upload.getByRole('textbox', { name: /^Title/ });
    await expect(titleField).toBeVisible();
    await titleField.fill(title);
    await upload.getByRole('button', { name: 'Upload documents', exact: true }).click();
    await expect(page.locator('.fi-modal-window:visible')).toHaveCount(0);
    await expect(page.getByText(title, { exact: true })).toBeVisible();
    await page.reload();
    await ready(page.getByRole('tab', { name: /^Documents/ }));
    if (await page.getByRole('tab', { name: /^Documents/ }).getAttribute('aria-selected') !== 'true') {
        await page.getByRole('tab', { name: /^Documents/ }).click();
    }
    await expect(page.getByText(title, { exact: true })).toBeVisible();

    const documents = page.locator('.fi-ta').filter({ has: page.getByRole('heading', { name: 'Documents', exact: true }) });
    const search = documents.getByRole('searchbox');
    await ready(search);
    await search.fill(title);
    await expect(search).toHaveValue(title);
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
    const [pdfDownload] = await Promise.all([
        page.waitForEvent('download', { timeout: 60_000 }),
        page.waitForResponse(response => new URL(response.url()).pathname === `/fleet/vehicles/${id}/export`)
            .then(response => expect(response.status()).toBe(200)),
        pdfDialog.getByRole('button', { name: 'Download', exact: true }).click(),
    ]);
    expect(pdfDownload.suggestedFilename()).toMatch(/\.pdf$/);
    const pdfPath = info.outputPath('vehicle-profile.pdf');
    await pdfDownload.saveAs(pdfPath);
    expect(readFileSync(pdfPath).subarray(0, 4).toString()).toBe('%PDF');
    await expect(page.locator('.fi-modal-window:visible')).toHaveCount(0);

    const exportDialog = await openAction(page, 'Export Vehicle Profile');
    await exportDialog.getByLabel('Excel', { exact: true }).check();
    await exportDialog.getByLabel('All permitted documents', { exact: true }).check();
    const [download] = await Promise.all([
        page.waitForEvent('download', { timeout: 60_000 }),
        page.waitForResponse(response => new URL(response.url()).pathname === `/fleet/vehicles/${id}/export`)
            .then(response => expect(response.status()).toBe(200)),
        exportDialog.getByRole('button', { name: 'Download', exact: true }).click(),
    ]);
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

test('historical review attaches the unchanged original to the vehicle selected by Fleet', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'The persisted review decision is exercised once; document layouts run in every viewport.');
    const { directory } = JSON.parse(readFileSync(resolve('test-results/vehicle-documents-fixture.json'), 'utf8'));
    const fixture = JSON.parse(readFileSync(resolve(directory, 'fixture.json'), 'utf8'));
    const vehicleId = fixture.vehicles.desktop;
    const documentId = fixture.review.id;
    const expectedHash = createHash('sha256').update(readFileSync(fixture.review.source)).digest('hex');

    await page.goto('/login');
    const signedIn = await page.request.post('/login', { form: {
        _token: await page.locator('input[name="_token"]').inputValue(),
        employee_id: 'vehicle-desktop@example.test', password: 'synthetic-vehicle-password',
    }, maxRedirects: 0 });
    expect(signedIn.status()).toBe(302);
    expect(signedIn.headers().location).not.toContain('/login');
    expect((await page.request.get(`/fleet/vehicles/${vehicleId}/documents/${documentId}/download`)).status()).toBe(404);

    await page.goto('/admin/unit-master-vehicles');
    await page.getByRole('link', { name: 'Historical document review', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Historical document review', exact: true })).toBeVisible();
    const queue = page.locator('.fi-ta');
    await expect(queue.getByText(fixture.review.title, { exact: true })).toBeVisible();
    const reviewDownloadLink = queue.getByRole('link', { name: 'Download', exact: true });
    const reviewDownloadUrl = await reviewDownloadLink.getAttribute('href') as string;
    const originalDownload = page.waitForEvent('download');
    await reviewDownloadLink.click();
    const beforePath = info.outputPath('historical-original-before-review.pdf');
    await (await originalDownload).saveAs(beforePath);
    expect(createHash('sha256').update(readFileSync(beforePath)).digest('hex')).toBe(expectedHash);

    const review = await openAction(page, 'Review / assign vehicle');
    await expect(review.getByRole('textbox', { name: 'Observed vehicle identifiers and source references' })).toHaveValue(/Fleet number: QA-DESKTOP/);
    const selectedVehicles = review.locator('select[multiple]');
    await expect(selectedVehicles).toHaveValues([]);
    const vehicleField = review.locator('.choices').filter({ has: page.locator('select[multiple]') });
    await vehicleField.locator('input[type="search"]').fill('QA-DESKTOP', { timeout: 15_000 });
    await review.getByRole('option', { name: /QA-DESKTOP/ }).click({ timeout: 15_000 });
    await expect(selectedVehicles).toHaveValues([String(vehicleId)]);
    await review.getByRole('textbox', { name: /^Evidence supporting this decision/ }).fill('Confirmed fleet number QA-DESKTOP directly on page 1 of the synthetic original.');
    await page.screenshot({ path: info.outputPath('historical-review-selected-vehicle.png'), fullPage: true });
    await review.getByRole('button', { name: /^(Submit|Save|Confirm vehicle links)$/ }).click();
    await expect(page.locator('.fi-modal-window:visible')).toHaveCount(0);
    await expect(queue.getByText(fixture.review.title, { exact: true })).toHaveCount(0);
    await page.reload();
    await expect(queue.getByText(fixture.review.title, { exact: true })).toHaveCount(0);
    await expect(page.getByText('No documents waiting for review', { exact: true })).toBeVisible();
    expect((await page.request.get(reviewDownloadUrl)).status()).toBe(404);

    await page.goto(`/admin/unit-master-vehicles/${vehicleId}`);
    const documentsTab = page.getByRole('tab', { name: /^Documents/ });
    await ready(documentsTab);
    if (await documentsTab.getAttribute('aria-selected') !== 'true') await documentsTab.click();
    const documents = page.locator('.fi-ta').filter({ has: page.getByRole('heading', { name: 'Documents', exact: true }) });
    await expect(documents.getByText(fixture.review.title, { exact: true })).toBeVisible();
    const search = documents.getByRole('searchbox');
    await ready(search);
    await search.fill(fixture.review.title);
    await expect(documents.getByText('Synthetic original record', { exact: true })).toHaveCount(0);
    await expect(documents.locator('.fi-ta-record').getByText('Verified', { exact: true })).toBeVisible();
    const linkedDownload = documents.getByRole('link', { name: 'Download', exact: true });
    await expect(linkedDownload).toHaveAttribute('href', new RegExp(`/fleet/vehicles/${vehicleId}/documents/${documentId}/download$`));
    const afterDownload = page.waitForEvent('download');
    await linkedDownload.click();
    const afterPath = info.outputPath('historical-original-after-review.pdf');
    await (await afterDownload).saveAs(afterPath);
    expect(createHash('sha256').update(readFileSync(afterPath)).digest('hex')).toBe(expectedHash);
    expect(createHash('sha256').update(readFileSync(fixture.review.source)).digest('hex')).toBe(expectedHash);
    expect((await page.request.get(`/fleet/vehicles/${fixture.vehicles.tablet}/documents/${documentId}/download`)).status()).toBe(404);
    expect((await page.request.get(`/fleet/vehicles/${fixture.vehicles.tablet}/documents/${documentId}/preview`)).status()).toBe(404);
    await page.screenshot({ path: info.outputPath('historical-review-linked-vehicle.png'), fullPage: true });
});
