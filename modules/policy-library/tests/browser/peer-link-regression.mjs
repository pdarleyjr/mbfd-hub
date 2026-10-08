import { chromium, expect } from '@playwright/test';
import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';

const evidence = path.resolve(process.env.POLICY_LIBRARY_BROWSER_FIXTURE || 'var/reader-fixture');
const config = JSON.parse(await readFile(process.env.POLICY_LIBRARY_READING_PREVIEW, 'utf8'));
const base = process.env.POLICY_LIBRARY_BROWSER_URL || 'http://127.0.0.1:8882';
const documents = config.nodes.flatMap(section => section.children);
const target = documents.find(node => node.metadata.asset_id === '100.02');
const source = documents.find(node => node.metadata.asset_id === '100.01');
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const checks = [], errors = [], unexpectedRequests = [];
try {
    async function openPage(mutate) {
        const page = await browser.newPage({ viewport: { width: 1366, height: 900 } });
        page.on('pageerror', error => errors.push(error.message));
        page.on('request', request => {
            if (!request.url().startsWith(base + '/') && !request.url().startsWith('data:')) unexpectedRequests.push(request.url());
        });
        if (mutate) await page.route('**/api/manuals/sogs/tree', async route => {
            const response = await route.fetch();
            const tree = await response.json();
            mutate(tree.nodes);
            await route.fulfill({ response, json: tree });
        });
        await page.goto(`${base}/?manual=sogs&node=${source.id}&page=3`);
        await page.locator('#pdf-page:not([hidden])').waitFor();
        await page.locator('#pdf-text span').first().waitFor();
        return page;
    }
    const page = await openPage();
    await expect(page.locator('[data-annotation="16R"]')).toBeVisible();
    await expect(page.locator('[data-annotation="15R"]')).toBeVisible();
    const request = page.waitForRequest(item => item.url().includes(`/assets/${target.revision.metadata.canonical_sha256}`));
    await page.locator('[data-annotation="16R"]').click();
    await request;
    await expect(page).toHaveURL(new RegExp(`node=${target.id}&page=2`));
    await expect(page.locator('#document-title')).toHaveText(`100.02 · ${target.title}`);
    await page.locator('#pdf-text span').first().waitFor();
    await page.screenshot({ path: path.join(evidence, 'C2-peer-target-r8.png') });
    checks.push('Actual100.01 page3 annotation16R opens exact-hash100.02 PDF at its native named-destination page2');
    await page.close();

    const missing = await openPage(nodes => {
        for (const section of nodes) section.children = section.children.filter(node => node.metadata.asset_id !== '100.02');
    });
    await expect(missing.locator('[data-annotation="15R"]')).toBeVisible();
    expect(await missing.locator('[data-annotation="16R"]').count()).toBe(0);
    checks.push('A target absent from the current visible tree cannot be reintroduced by the source PDF annotation');
    await missing.close();

    const wrongTarget = await openPage(nodes => {
        nodes.flatMap(section => section.children).find(node => node.metadata.asset_id === '100.02').revision.metadata.canonical_sha256 = '0'.repeat(64);
    });
    await expect(wrongTarget.locator('[data-annotation="15R"]')).toBeVisible();
    expect(await wrongTarget.locator('[data-annotation="16R"]').count()).toBe(0);
    checks.push('A wrong current target PDF hash blocks the declared peer link');
    await wrongTarget.close();

    const wrongSource = await openPage(nodes => {
        nodes.flatMap(section => section.children).find(node => node.metadata.asset_id === '100.01').revision.metadata.canonical_sha256 = '0'.repeat(64);
    });
    await wrongSource.waitForFunction(() => document.querySelectorAll('#pdf-text span').length > 20);
    expect(await wrongSource.locator('[data-annotation="16R"], [data-annotation="15R"]').count()).toBe(0);
    checks.push('A wrong source PDF hash rejects both peer bindings without a raw filesystem URL fallback');
    await wrongSource.close();
    expect(errors).toEqual([]);
    expect(unexpectedRequests).toEqual([]);
    await writeFile(path.join(evidence, 'C2-peer-browser-r8.json'), JSON.stringify({ capturedUtc: new Date().toISOString(), scope: config.scope,
        peerMapSha256: config.peerMapSha256, sourcePdfSha256: source.revision.metadata.canonical_sha256,
        targetPdfSha256: target.revision.metadata.canonical_sha256, checks, unexpectedPageErrors: errors, unexpectedRequests }, null, 2) + '\n');
    console.log(JSON.stringify({ passed: checks.length, errors, unexpectedRequests }));
} finally { await browser.close(); }
