import { chromium, expect } from '@playwright/test';
import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';

const evidence = path.resolve(process.env.POLICY_LIBRARY_BROWSER_FIXTURE || 'var/reader-fixture');
const config = JSON.parse(await readFile(process.env.POLICY_LIBRARY_READING_PREVIEW, 'utf8'));
const artifact = JSON.parse(await readFile(config.artifactPath, 'utf8'));
const entry = artifact.entries[0];
const slug = config.nodes[0].children[0].slug;
const base = process.env.POLICY_LIBRARY_BROWSER_URL || 'http://127.0.0.1:8879';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const checks = [], errors = [];
try {
    const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`${base}/?manual=sogs&node=${slug}&page=1`);
    await page.locator('#reading-page:not([hidden])').waitFor();
    expect(await page.locator('.reading-cover p').allTextContents()).toEqual(artifact.cover);
    checks.push('Exact native C2 cover remains visible');
    const tables = entry.blocks.filter(block => block.type === 'table');
    const actual = await page.locator('.reading-table-scroll table').evaluateAll(tables => tables.map(table => [...table.rows].map(row => [...row.cells].map(cell => ({
        text: cell.textContent, col_span: cell.colSpan, row_span: cell.rowSpan, header: cell.tagName === 'TH', pdf_page: Number(cell.dataset.pdfPage),
    })))));
    const expected = tables.map(block => block.rows.map(row => row.map(({ text, col_span, row_span, header, pdf_page }) => ({ text, col_span, row_span, header, pdf_page }))));
    expect(actual).toEqual(expected);
    checks.push('All native table cells, horizontal/vertical spans, headers and actual source pages match exactly');
    expect(await page.locator('.reading-block,.reading-pdf-reference').allTextContents()).toEqual(entry.blocks.filter(block => block.type !== 'table').map(block => block.text));
    checks.push('Every paragraph and explicit original-PDF fallback remains present');
    const geometry = await page.locator('.reading-table-scroll').evaluateAll(items => items.map(item => ({ width: item.clientWidth, scrollWidth: item.scrollWidth, fontPx: parseFloat(getComputedStyle(item.querySelector('table')).fontSize), focusable: item.tabIndex === 0 })));
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
    expect(await page.locator('#page-stage').evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);
    expect(geometry.every(item => item.fontPx >= 17 && item.width <= 390 && item.focusable)).toBe(true);
    checks.push('Tables preserve their grid in contained, keyboard-focusable phone scroll regions');
    await page.locator('.reading-table-scroll').first().scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(evidence, 'reading-C2-phone-table.png') });
    const sourcePage = entry.blocks.find(block => block.type === 'pdf_reference').pdf_page;
    await page.locator('.view-button').filter({ hasText: `View original PDF page ${sourcePage}` }).first().click();
    await page.locator('#pdf-page:not([hidden])').waitFor();
    await expect(page).toHaveURL(new RegExp(`page=${sourcePage}(?:&|$)`));
    checks.push('Complex table/paragraph fallback opens its exact original PDF page');
    await writeFile(path.join(evidence, 'reading-C2-tables-browser.json'), JSON.stringify({ capturedUtc: new Date().toISOString(), scope: config.scope,
        sourcePdfSha256: artifact.pdf_sha256, sourceDocxSha256: artifact.source_docx_sha256, artifactPublicationValidated: false,
        identity: entry.id, tables: tables.length, cells: expected.flat(2).length, geometry, checks, unexpectedPageErrors: errors }, null, 2));
    expect(errors).toEqual([]);
    console.log(JSON.stringify({ passed: checks.length, tables: tables.length, cells: expected.flat(2).length, errors }));
} finally { await browser.close(); }
