import { test, expect } from '@playwright/test';
import { readFile, mkdir, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const corpus = path.resolve(process.env.POLICY_LIBRARY_TEST_CORPUS || fileURLToPath(new URL('../../var/preview/', import.meta.url)));
const sample = JSON.parse(await readFile(path.join(corpus, 'sample.json'), 'utf8'));
const isSog = entry => /^\d{3}\s*[-–]/.test(entry.section);
const first = sample.documents.find(entry => entry.page_count > 1 && !isSog(entry));
const next = sample.documents.filter(entry => !isSog(entry))[1];

for (const [name, viewport] of [['phone', { width: 390, height: 844 }], ['tablet', { width: 768, height: 1024 }], ['desktop', { width: 1440, height: 900 }], ['4k', { width: 3840, height: 2160 }]]) {
    test(`${name}: original PDF renders with navigation, fit, zoom and no overflow`, async ({ page }) => {
        await page.setViewportSize(viewport);
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
        await page.goto(`/?manual=medical-protocols&node=${first.id}&page=1`);
        await expect(page.locator('#pdf-page')).toBeVisible();
        await expect(page.locator('#pdf-text span').first()).toBeAttached();
        await expect(page.locator('#document-title')).toHaveText(first.title);
        await expect(page.locator('#manage-link')).toBeHidden();
        const dimensions = await page.locator('#pdf-canvas').evaluate(canvas => ({ width: canvas.width, height: canvas.height }));
        expect(dimensions.width * dimensions.height).toBeLessThanOrEqual(16_000_000);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth);
        expect(overflow).toBe(false);
        await page.locator('.bottom-toolbar [data-nav="next"]').click();
        await expect(page.locator('.bottom-toolbar [data-page-status]')).toHaveText(`2 / ${first.page_count}`);
        await expect(page).toHaveURL(/page=2/);
        await page.locator('.page-controls [data-nav="previous"]').click();
        await expect(page).toHaveURL(/page=1/);
        await page.goBack();
        await expect(page).toHaveURL(/page=2/);
        await expect(page.locator('.bottom-toolbar [data-page-status]')).toHaveText(`2 / ${first.page_count}`);
        await page.locator('#fit-width').click();
        await expect(page.locator('#fit-width')).toHaveAttribute('aria-pressed', 'true');
        await page.locator('#zoom-in').click();
        await expect(page.locator('#zoom-value')).toHaveText('125%');
        await page.locator('#zoom-out').click();
        await page.locator('#fit-page').click();
        await page.locator('#focus-mode').click();
        await expect(page.locator('#focus-mode')).toHaveAttribute('aria-pressed', 'true');
        await page.locator('#focus-mode').click();
        if (viewport.width <= 900) {
            await page.locator('#menu-toggle').click();
            await expect(page.locator('#manual-sidebar')).toBeInViewport();
            await page.locator('#title-search').fill('POCUS');
            await expect(page.locator('.tree-document')).toHaveCount(1);
            await page.locator('.tree-document').click();
            await expect(page.locator('#document-title')).toContainText('POCUS');
            await expect(page.locator('#menu-toggle')).toHaveAttribute('aria-expanded', 'false');
        }
        await expect(page.locator('#pdf-page')).toBeVisible();
        await mkdir('var/evidence/screenshots', { recursive: true });
        await page.screenshot({ path: `var/evidence/screenshots/${name}.png` });
        expect(errors).toEqual([]);
    });
}

test('last/first pages cross document boundaries and keyboard ignores search', async ({ page }) => {
    await page.goto(`/?manual=medical-protocols&node=${first.id}&page=${first.page_count}`);
    await expect(page.locator('#pdf-page')).toBeVisible();
    await page.locator('.page-controls [data-nav="next"]').click();
    await expect(page.locator('#document-title')).toHaveText(next.title);
    await expect(page.locator('.bottom-toolbar [data-page-status]')).toHaveText(`1 / ${next.page_count}`);
    await page.locator('.bottom-toolbar [data-nav="previous"]').click();
    await expect(page.locator('#document-title')).toHaveText(first.title);
    await expect(page.locator('.bottom-toolbar [data-page-status]')).toHaveText(`${first.page_count} / ${first.page_count}`);
    await page.locator('#title-search').focus();
    await page.keyboard.press('ArrowRight');
    await expect(page.locator('#document-title')).toHaveText(first.title);
    await page.locator('#document-workspace').focus();
    await page.keyboard.press('ArrowRight');
    await expect(page.locator('#document-title')).toHaveText(next.title);
});

test('manual change selects real SOG PDF without loading full MOMS source', async ({ page }) => {
    const requests = [];
    page.on('request', request => requests.push(request.url()));
    await page.goto('/');
    await expect(page.locator('#pdf-page')).toBeVisible();
    await page.getByRole('button', { name: /^SOGs/ }).click();
    const sog = sample.documents.find(isSog);
    await expect(page.locator('#document-title')).toHaveText(sog.title);
    await expect(page.locator('#pdf-page')).toBeVisible();
    expect(requests.some(url => url.includes('MOMS-compressed.pdf'))).toBe(false);
    await expect(page.locator('#pdf-page canvas')).toHaveCount(1);
    await expect(page.locator('canvas:visible')).toHaveCount(1);
});

test('a cold first page uses partial PDF requests and loads only the selected document', async ({ page }) => {
    const network = await page.context().newCDPSession(page);
    const transfers = new Map();
    await network.send('Network.enable');
    network.on('Network.requestWillBeSent', event => {
        if (event.request.url.includes('/test-assets/')) transfers.set(event.requestId, { url: event.request.url, bytes: 0, status: null });
    });
    network.on('Network.responseReceived', event => {
        const transfer = transfers.get(event.requestId);
        if (transfer) transfer.status = event.response.status;
    });
    network.on('Network.dataReceived', event => {
        const transfer = transfers.get(event.requestId);
        if (transfer) transfer.bytes += event.dataLength;
    });
    const start = Date.now();
    await page.goto(`/?manual=medical-protocols&node=${first.id}&page=1`);
    await expect(page.locator('#pdf-text span').first()).toBeAttached();
    const firstPageMs = Date.now() - start;
    await page.waitForLoadState('networkidle');
    const requests = [...transfers.values()];
    const documentBytes = (await stat(path.join(corpus, first.asset_path))).size;
    const transferredBytes = requests.reduce((total, request) => total + request.bytes, 0);
    await mkdir('var/evidence', { recursive: true });
    await writeFile('var/evidence/viewer-transfer.json', JSON.stringify({ documentBytes, transferredBytes, firstPageMs, requests }, null, 2));
    expect(requests.some(request => request.status === 206)).toBe(true);
    expect(requests.every(request => request.url.endsWith(`/test-assets/${first.id}`))).toBe(true);
    expect(transferredBytes).toBeGreaterThan(0);
    expect(transferredBytes).toBeLessThan(2.5 * 1024 * 1024);
    expect(firstPageMs).toBeLessThan(5000);
});

test('failed PDF keeps page location and offers retry', async ({ page }) => {
    await page.route(`**/test-assets/${first.id}`, route => route.fulfill({ status: 500, body: 'unavailable' }));
    await page.goto(`/?manual=medical-protocols&node=${first.id}&page=1`);
    await expect(page.getByRole('heading', { name: 'This document could not be displayed.' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Retry', exact: true })).toBeVisible();
    await expect(page).toHaveURL(new RegExp(`node=${first.id}`));
    await page.unroute(`**/test-assets/${first.id}`);
    await page.getByRole('button', { name: 'Retry', exact: true }).click();
    await expect(page.locator('#pdf-page')).toBeVisible();
});

test('rapid page changes and fit controls keep the final page usable', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    await page.goto(`/?manual=medical-protocols&node=${first.id}&page=1`);
    await expect(page.locator('#pdf-text span').first()).toBeAttached();
    await page.evaluate(() => {
        for (let index = 0; index < 5; index++) document.querySelector('.page-controls [data-nav="next"]').click();
        document.querySelector('#fit-width').click();
        document.querySelector('#fit-page').click();
    });
    await expect(page.locator('.bottom-toolbar [data-page-status]')).toHaveText(`6 / ${first.page_count}`);
    await expect(page.locator('#page-announcement')).toContainText('page 6');
    await expect(page.locator('#pdf-page')).toBeVisible();
    expect(errors).toEqual([]);
});

test('switching manuals during an outstanding PDF request preserves the selected manual', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route(`**/test-assets/${first.id}`, async route => {
        await new Promise(resolve => setTimeout(resolve, 400));
        await route.continue();
    });
    await page.goto('/');
    await page.getByRole('button', { name: /^SOGs/ }).click();
    const sog = sample.documents.find(isSog);
    await expect(page.locator('#document-title')).toHaveText(sog.title);
    await expect(page.locator('#pdf-text span').first()).toBeAttached();
    await expect(page.locator('#page-announcement')).toContainText(sog.title);
    await expect(page).toHaveURL(/manual=sogs/);
    expect(errors).toEqual([]);
});

test('a pending manual switch disables previous document controls and keeps the latest selection', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`/?manual=medical-protocols&node=${first.id}&page=1`);
    await expect(page.locator('#pdf-text span').first()).toBeAttached();
    let release;
    const blocked = new Promise(resolve => { release = resolve; });
    await page.route('**/api/manuals/sogs/tree', async route => {
        await blocked;
        await route.continue().catch(() => {});
    });
    await page.locator('#zoom-in').click();
    await page.getByRole('button', { name: /^SOGs/ }).click();
    await expect(page.locator('#manual-tree')).toHaveAttribute('aria-busy', 'true');
    await expect(page.locator('.page-controls [data-nav="next"]')).toBeDisabled();
    await expect(page.locator('.tree-document')).toHaveCount(0);
    await page.getByRole('button', { name: /^Medical Protocols/ }).click();
    release();
    await expect(page.locator('#document-title')).toHaveText(first.title);
    await expect(page.locator('#page-announcement')).toContainText(first.title);
    await expect(page).toHaveURL(/manual=medical-protocols/);
    await expect(page.locator('#pdf-page')).toBeVisible();
    expect(errors).toEqual([]);
});

test('insert shortcuts open canonical protocols without duplicating reading order', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('#pdf-page')).toBeVisible();
    await page.getByText('Updates / Inserts', { exact: true }).click();
    const updates = page.locator('details').filter({ has: page.locator('summary').filter({ hasText: /^Updates \/ Inserts$/ }) });
    await expect(updates.locator('.tree-document')).toHaveCount(2);
    await updates.getByRole('button', { name: /Point of Care Ultrasound/i }).click();
    await expect(page.locator('#document-title')).toContainText('POCUS');
    await expect(page.locator('#document-path')).toContainText('Procedures');
    await expect(page.locator('#pdf-page')).toBeVisible();
    const pocus = sample.documents.find(entry => /pocus/i.test(entry.id));
    await expect(page).toHaveURL(new RegExp(`node=${pocus.id}`));
    await updates.getByRole('button', { name: `Page ${pocus.page_count} of ${pocus.page_count}`, exact: true }).click();
    await expect(page.locator('#page-announcement')).toContainText(`page ${pocus.page_count}`);
    await expect(page.locator('.bottom-toolbar [data-nav="next"]')).toBeDisabled();
});
