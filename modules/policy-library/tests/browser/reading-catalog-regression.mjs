import { chromium, expect } from '@playwright/test';
import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';

const evidence = path.resolve(process.env.POLICY_LIBRARY_BROWSER_FIXTURE || 'var/reader-fixture');
const config = JSON.parse(await readFile(process.env.POLICY_LIBRARY_READING_PREVIEW, 'utf8'));
const documents = config.nodes.flatMap(section => section.children);
const expectedSections = ['100', '200', '300', '400', '500', '600', '800', '900'];
expect(config.nodes.map(section => section.metadata.section).sort()).toEqual(expectedSections);
for (const section of config.nodes) {
    for (const node of section.children) {
        expect(node.metadata.asset_id.slice(0, 3)).toBe(section.metadata.section);
    }
}
expect(config.nodes.find(section => section.metadata.section === '600').title).toBe('PREVENTION');
const base = process.env.POLICY_LIBRARY_BROWSER_URL || 'http://127.0.0.1:8880';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const checks = [], errors = [];
try {
    const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`${base}/?manual=sogs&node=100-01&page=1`);
    await page.locator('#reading-page:not([hidden])').waitFor();
    await page.locator('#menu-toggle').click();
    const actual = await page.locator('.tree-primary').evaluateAll(items => Object.fromEntries(items.map(item => [item.dataset.primary, item.textContent])));
    expect(actual).toEqual(Object.fromEntries(documents.map(node => [node.metadata.asset_id, `${node.metadata.asset_id} · ${node.title}`])));
    expect(Object.keys(actual)).toHaveLength(151);
    expect(await page.locator('#manual-tree > .tree-group > summary').allTextContents()).toEqual([...config.nodes].sort((a, b) => a.metadata.section.localeCompare(b.metadata.section)).map(section => section.title));
    expect(await page.locator('.tree-pages').count()).toBe(0);
    checks.push('All exact126 SOG and25 companion titles appear under8 actual source sections');
    await page.locator('#title-search').fill('100.03');
    await page.locator('.tree-primary[data-primary="100.03"]').click();
    await page.locator('#pdf-page:not([hidden])').waitFor();
    await page.locator('#pdf-text span').first().waitFor();
    const selected = documents.find(node => node.metadata.asset_id === '100.03');
    await expect(page.locator('#document-title')).toHaveText(`100.03 · ${selected.title}`);
    await page.locator('#pages-toggle').click();
    expect(await page.locator('.thumbnail').evaluateAll(items => items.map(item => Number(item.dataset.page)))).toEqual(selected.revision.metadata.primary_entries[0].semantic_pages);
    checks.push('Source-title navigation loads the exact C2 individual PDF and only its measured owned pages');
    await page.locator(`.thumbnail[data-page="${selected.revision.page_count}"]`).click();
    await page.locator('#pdf-page:not([hidden])').waitFor();
    await expect(page).toHaveURL(new RegExp(`node=100-03&page=${selected.revision.page_count}`));
    checks.push('Last owned thumbnail retains exact individual SOG identity');
    await page.setViewportSize({ width: 1366, height: 768 });
    await page.goto(`${base}/?manual=sogs&node=100-01&page=1`);
    await page.locator('#pdf-text span').first().waitFor();
    await page.screenshot({ path: path.join(evidence, 'full-C2-desktop-reader-r3.png') });
    expect(errors).toEqual([]);
    await writeFile(path.join(evidence, 'full-C2-catalog-browser-r3.json'), JSON.stringify({ capturedUtc: new Date().toISOString(), scope: config.scope,
        publicationPlanSha256: config.publicationPlanSha256, inventorySha256: config.inventorySha256, counts: config.counts,
        publicationValidated: false, checks, selectedPdfSha256: selected.revision.id, selectedPdfPageCount: selected.revision.page_count, unexpectedPageErrors: errors }, null, 2));
    console.log(JSON.stringify({ passed: checks.length, counts: config.counts, errors }));
} finally { await browser.close(); }
