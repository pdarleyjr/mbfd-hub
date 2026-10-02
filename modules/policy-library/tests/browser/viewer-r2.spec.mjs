import { test, expect } from '@playwright/test';

// A small authored fixture tests PDF actions without changing any department source.
function actionPdf(label = 'Fixture', entryPage = 2, pageCount = 3) {
    const stream = text => `<< /Length ${text.length} >>\nstream\n${text}\nendstream`;
    const text = page => `BT /F1 18 Tf 72 700 Td (${label} PDF page ${page}) Tj ET`;
    const annotationsStart = 4 + pageCount * 2;
    const objects = [
        `<< /Type /Catalog /Pages 2 0 R /Names << /Dests << /Names [(MBFD_100_01) [${entryPage + 3} 0 R /Fit]] >> >> >>`,
        `<< /Type /Pages /Kids [${Array.from({ length: pageCount }, (_, index) => `${index + 4} 0 R`).join(' ')}] /Count ${pageCount} >>`,
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ...Array.from({ length: pageCount }, (_, index) => {
            const annotations = index === 0 ? [0, 1, 2, 3] : index === 1 ? [4] : [];
            return `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents ${4 + pageCount + index} 0 R /Annots [${annotations.map(number => `${annotationsStart + number} 0 R`).join(' ')}] >>`;
        }),
        ...Array.from({ length: pageCount }, (_, index) => stream(text(index + 1))),
        '<< /Type /Annot /Subtype /Link /Rect [72 650 300 680] /Dest (MBFD_100_01) >>',
        '<< /Type /Annot /Subtype /Link /Rect [72 580 300 610] /A << /S /URI /URI (https://example.com/reference) >> >>',
        '<< /Type /Annot /Subtype /Link /Rect [72 510 300 540] /A << /S /JavaScript /JS (unsafe) >> >>',
        '<< /Type /Annot /Subtype /Link /Rect [72 440 300 470] /A << /S /URI /URI (file:///C:/unavailable.pdf) >> >>',
        '<< /Type /Annot /Subtype /Link /Rect [72 650 300 680] /A << /S /URI /URI (https://files.mbfdhub.com/current-sog/100-COMPANION?page=3) >> >>',
    ];
    let pdf = '%PDF-1.7\n';
    const offsets = [0];
    for (const [index, object] of objects.entries()) {
        offsets.push(Buffer.byteLength(pdf));
        pdf += `${index + 1} 0 obj\n${object}\nendobj\n`;
    }
    const xref = Buffer.byteLength(pdf);
    pdf += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
    pdf += offsets.slice(1).map(offset => `${String(offset).padStart(10, '0')} 00000 n \n`).join('');
    pdf += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
    return Buffer.from(pdf);
}

const nodes = [
    { id: 12, slug: 'asset-section-100', title: 'Section 100', metadata: { asset_id: 'SECTION-100' }, children: [], revision: {
        id: 'r2-a', asset_url: '/test-assets/r2-source-a', download_url: '/test-assets/r2-source-a?download=1', page_count: 3,
        metadata: { primary_entries: [
            { id: '100.01', slug: '100-01', title: 'Policy identity', parent: null, anchor: 'MBFD_100_01', physical_page: 2, semantic_pages: [2] },
            { id: '100.01-F1', slug: '100-01-f1', title: 'Shared-page instrument', parent: '100.01', physical_page: 2, semantic_pages: [2] },
        ], subject_aliases: [
            { source_record_id: 'original-P436', legacy_id: '800.01', source_title: 'Air Tech Duties', source_pages: [436, 437], resolved_current_targets: [{ id: '100.01' }] },
            { source_record_id: 'original-P438', legacy_id: '800.01', source_title: 'Apparatus Waxing Schedule', source_pages: [438, 439], resolved_current_targets: [{ id: '100-COMPANION' }] },
        ] },
    } },
    { id: 13, slug: 'asset-100-companion', title: 'Companion PDF', metadata: { asset_id: '100-COMPANION' }, children: [], revision: {
        id: 'r2-b', asset_url: '/test-assets/r2-source-b', download_url: '/test-assets/r2-source-b?download=1', page_count: 3,
        metadata: { primary_entries: [{ id: '100-COMPANION', slug: '100-companion', title: 'Companion identity', parent: '100.01', physical_page: 3, semantic_pages: [3] }] },
    } },
];

test.beforeEach(async ({ page }) => {
    await page.route('**/api/manuals', route => route.fulfill({ json: { manuals: [{ id: 1, slug: 'sogs', name: 'SOGs', type: 'sog', active_edition_id: 1 }], can_manage: false } }));
    await page.route('**/api/manuals/sogs/tree', route => route.fulfill({ json: { manual: { active_edition_id: 1 }, nodes, documents: [12, 13] } }));
    await page.route('**/test-assets/r2-source-*', route => route.fulfill({ contentType: 'application/pdf', body: actionPdf() }));
});

test('primary bookmarks retain exact identities, semantic pages, hierarchy and shared source reading order', async ({ page }) => {
    await page.goto('/?manual=sogs&node=100-01');
    await expect(page.locator('#page-announcement')).toContainText('100.01 · Policy identity, PDF page 2 of 3');
    await expect(page.locator('#document-title')).toHaveText('100.01 · Policy identity');
    await expect(page.locator('#revision-info')).toContainText('1 content page');
    await expect(page.locator('#manual-tree .tree-primary')).toHaveCount(3);
    await expect(page.locator('#manual-tree .tree-pages .tree-page')).toHaveCount(1);
    await expect(page.locator('#manual-tree .tree-pages .tree-page')).toHaveText('PDF page 2');
    await page.getByText('Tools and companions', { exact: true }).click();
    await page.locator('[data-primary="100.01-F1"]').click();
    await expect(page).toHaveURL(/node=100-01-f1&page=2/);
    await expect(page.locator('#document-title')).toContainText('Shared-page instrument');
    await page.goBack();
    await expect(page.locator('#document-title')).toHaveText('100.01 · Policy identity');
    await page.locator('.page-controls [data-nav="next"]').click();
    await expect(page.locator('#page-announcement')).toContainText('Section 100, page 3 of 3');
    await page.locator('.page-controls [data-nav="next"]').click();
    await expect(page.locator('#document-title')).toHaveText('Companion PDF');
    await expect(page.locator('.page-controls [data-page-status]')).toHaveText('1 / 3');
    await expect(page.locator('#download-pdf')).toHaveAttribute('href', '/test-assets/r2-source-b?download=1');
    await expect(page.locator('canvas:visible')).toHaveCount(1);
});

test('PDF links preserve named destinations, current peer navigation, history and safe external links', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('/?manual=sogs&node=asset-section-100&page=1');
    await expect(page.locator('#pdf-links a')).toHaveCount(2);
    const external = page.locator('#pdf-links a[href="https://example.com/reference"]');
    await expect(external).toHaveAttribute('target', '_blank');
    await expect(external).toHaveAttribute('rel', 'noopener noreferrer');
    await page.locator('#pdf-links a[href*="node=100-01"]').press('Enter');
    await expect(page.locator('#document-title')).toHaveText('100.01 · Policy identity');
    await expect(page.locator('#page-announcement')).toContainText('PDF page 2');
    await expect(page.locator('#pdf-links a')).toHaveCount(1);
    await page.locator('#pdf-links a').click();
    await expect(page).toHaveURL(/node=asset-100-companion&page=3/);
    await expect(page.locator('#page-announcement')).toContainText('Companion PDF, page 3');
    await page.goBack();
    await expect(page).toHaveURL(/node=100-01&page=2/);
    await expect(page.locator('#pdf-links a')).toHaveCount(1);
    await page.locator('#zoom-in').click();
    await expect(page.locator('#zoom-value')).toHaveText('125%');
    await expect(page.locator('#pdf-links a')).toHaveCount(1);
    await expect(page.locator('canvas:visible')).toHaveCount(1);
    expect(errors).toEqual([]);
});

test('historical subjects with the same old number retain distinct records and exact current targets', async ({ page }) => {
    await page.goto('/?manual=sogs&node=100-01');
    await expect(page.locator('#pdf-page')).toBeVisible();
    await page.locator('#title-search').fill('800.01');
    await expect(page.locator('.subject-alias')).toHaveCount(2);
    await expect(page.locator('[data-source-record="original-P436"]')).toContainText('Air Tech Duties');
    await expect(page.locator('[data-source-record="original-P438"]')).toContainText('Apparatus Waxing Schedule');
    await page.locator('[data-source-record="original-P438"] [data-alias-target]').click();
    await expect(page).toHaveURL(/node=100-companion&page=3/);
    await expect(page.locator('#document-title')).toHaveText('100-COMPANION · Companion identity');
});

test('an open viewer destroys the previous PDF when publication changes and searches the new exact identity', async ({ page }) => {
    let edition = 1;
    const current = structuredClone(nodes);
    current[0].id = 112;
    current[0].revision.id = 'r2-current';
    current[0].revision.asset_url = '/test-assets/r2-source-new';
    for (const entry of current[0].revision.metadata.primary_entries) {
        entry.physical_page = 3;
        entry.semantic_pages = [3];
    }
    current[1].id = 113;
    await page.addInitScript(() => {
        window.terminatedPdfWorkers = 0;
        const terminate = Worker.prototype.terminate;
        Worker.prototype.terminate = function () { window.terminatedPdfWorkers++; return terminate.call(this); };
    });
    await page.route('**/api/manuals', route => route.fulfill({ json: { manuals: [{ id: 1, slug: 'sogs', name: 'SOGs', type: 'sog', active_edition_id: edition }], can_manage: false } }));
    await page.route('**/api/manuals/sogs/tree', route => route.fulfill({ json: { manual: { active_edition_id: edition }, nodes: edition === 1 ? nodes : current, documents: edition === 1 ? [12, 13] : [112, 113] } }));
    await page.route('**/test-assets/r2-source-new', route => route.fulfill({ contentType: 'application/pdf', body: actionPdf('Current edition', 3) }));
    await page.route('**/api/search?*', route => route.fulfill({ json: { results: [{
        manual: { slug: 'sogs', name: 'SOGs' }, node_id: 112, slug: 'asset-section-100', primary_id: '100.01', primary_slug: '100-01', title: 'Policy identity', page: 3, path: ['Section 100'], excerpt: 'Current edition content',
    }], has_more: false } }));
    await page.goto('/?manual=sogs&node=100-01');
    await expect(page.locator('#page-announcement')).toContainText('PDF page 2');
    const before = await page.evaluate(() => window.terminatedPdfWorkers);
    edition = 2;
    await page.locator('#page-search').fill('policy');
    await page.getByRole('button', { name: 'Search published pages' }).click();
    await expect(page.locator('#search-results .search-result')).toHaveCount(1);
    await page.locator('#search-results .search-result').click();
    await expect(page).toHaveURL(/node=100-01&page=3/);
    await expect(page.locator('#document-title')).toHaveText('100.01 · Policy identity');
    await expect(page.locator('#pdf-text')).toContainText('Current edition PDF page 3');
    expect(await page.evaluate(() => window.terminatedPdfWorkers)).toBeGreaterThan(before);
    await expect(page.locator('canvas:visible')).toHaveCount(1);
    await page.locator('.page-controls [data-nav="previous"]').click();
    await expect(page.locator('#pdf-text')).toContainText('Current edition PDF page 2');
    await expect(page).toHaveURL(/node=asset-section-100&page=2/);
});

test('a cached semantic continuation result cannot select an unrelated page in the replacement edition', async ({ page }) => {
    let edition = 1;
    const old = structuredClone(nodes);
    old[0].revision.metadata.primary_entries[0].semantic_pages = [2, 3];
    const current = structuredClone(old);
    current[0].id = 112;
    current[0].revision.id = 'r2-continuation-current';
    current[0].revision.asset_url = '/test-assets/r2-source-five-pages';
    current[0].revision.page_count = 5;
    for (const entry of current[0].revision.metadata.primary_entries) {
        entry.physical_page = 4;
        entry.semantic_pages = [4, 5];
    }
    await page.route('**/api/manuals', route => route.fulfill({ json: { manuals: [{ id: 1, slug: 'sogs', name: 'SOGs', type: 'sog', active_edition_id: edition }], can_manage: false } }));
    await page.route('**/api/manuals/sogs/tree', route => route.fulfill({ json: { manual: { active_edition_id: edition }, nodes: edition === 1 ? old : current } }));
    await page.route('**/test-assets/r2-source-five-pages', route => route.fulfill({ contentType: 'application/pdf', body: actionPdf('Current continuation edition', 4, 5) }));
    await page.route('**/api/search?*', route => route.fulfill({ json: { results: [{
        manual: { slug: 'sogs', name: 'SOGs' }, node_id: 12, slug: 'asset-section-100', primary_id: '100.01', primary_slug: '100-01', title: 'Policy identity', page: 3, path: ['Section 100'], excerpt: 'Old continuation search result',
    }], has_more: false } }));
    await page.goto('/?manual=sogs&node=100-01&page=3');
    await expect(page.locator('#page-announcement')).toContainText('PDF page 3');
    await page.locator('#page-search').fill('continuation');
    await page.getByRole('button', { name: 'Search published pages' }).click();
    await expect(page.locator('#search-results .search-result')).toHaveCount(1);
    edition = 2;
    await page.locator('#search-results .search-result').click();
    await expect(page).toHaveURL(/node=100-01&page=4/);
    await expect(page.locator('#pdf-text')).toContainText('Current continuation edition PDF page 4');
    await expect(page.locator('#revision-info')).toContainText('2 content pages');
    await page.locator('.page-controls [data-nav="previous"]').click();
    await expect(page).toHaveURL(/node=asset-section-100&page=3/);
    await expect(page.locator('#pdf-text')).toContainText('Current continuation edition PDF page 3');
});
