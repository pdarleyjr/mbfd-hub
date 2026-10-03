import test from 'node:test';
import assert from 'node:assert/strict';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';
import { currentEditionChanged, linkRectangle, pageLink, pdfDestinationPage, pdfUrlTarget, primaryEntries, primaryHierarchy, recentlyPublished, resolveLibrarySelection, subjectAliases } from '../../resources/js/library-ui.js';

test('copy link retains exact logical document and page without temporary search state', () => {
    assert.equal(pageLink('https://files.mbfdhub.com/?q=private&manual=old#search', 'medical', 'procedures-pocus', 3),
        'https://files.mbfdhub.com/?manual=medical&node=procedures-pocus&page=3');
});

test('recent documents use recorded publication dates and do not mutate reading order', () => {
    const documents = [
        { id: 1, revision: { published_at: '2026-09-01T00:00:00Z' } },
        { id: 2, revision: { published_at: null } },
        { id: 3, revision: { published_at: '2026-10-01T00:00:00Z' } },
        { id: 4, revision: { published_at: 'invalid' } },
    ];
    assert.deepEqual(recentlyPublished(documents).map(node => node.id), [3, 1]);
    assert.deepEqual(recentlyPublished(documents, 1).map(node => node.id), [3]);
    assert.deepEqual(documents.map(node => node.id), [1, 2, 3, 4]);
});

const sources = [
    { id: 12, slug: 'asset-section-800', metadata: { asset_id: 'SECTION-800' }, revision: { page_count: 64, metadata: { primary_entries: [
        { id: '800.P03', slug: '800-p03', title: 'Air Tech Duties', parent: null, physical_page: 22, semantic_pages: [22, 23] },
        { id: '800.P02', slug: '800-p02', title: 'Apparatus Waxing', parent: null, physical_page: 10, semantic_pages: [10, 11] },
        { id: '800.P03-M1', slug: '800-p03-m1', title: 'Shared-page instrument', parent: '800.P03', physical_page: 23, semantic_pages: [23] },
    ], subject_aliases: [
        { source_record_id: 'original-P436', legacy_id: '800.01', source_title: 'Air Tech Duties', resolved_current_targets: [{ id: '800.P03' }] },
        { source_record_id: 'original-P438', legacy_id: '800.01', source_title: 'Apparatus Waxing Schedule', resolved_current_targets: [{ id: '800.P02' }] },
    ] } } },
    { id: 13, slug: 'asset-companion', metadata: { asset_id: '800-COMPANION' }, revision: { page_count: 3, metadata: { primary_entries: [
        { id: '800-COMPANION', slug: '800-companion', title: 'Companion', parent: '800.P03', physical_page: 1, semantic_pages: [1, 2, 3] },
    ] } } },
];

test('virtual identities preserve shared pages and cross-source companion parents without duplicating source PDFs', () => {
    const entries = primaryEntries(sources);
    assert.equal(entries.length, 4);
    assert.equal(sources.length, 2);
    const hierarchy = primaryHierarchy(entries);
    assert.deepEqual(hierarchy.map(entry => entry.id), ['800.P03', '800.P02']);
    assert.deepEqual(hierarchy[0].children.map(entry => entry.id), ['800.P03-M1', '800-COMPANION']);
    assert.equal(hierarchy[0].children[1].node.id, 13);
    assert.deepEqual(hierarchy[0].children[0].semantic_pages, [23]);
});

test('identity bookmarks default to the verified physical entrypoint and retain explicit exact pages', () => {
    assert.equal(resolveLibrarySelection(sources, '800-p03').page, 22);
    assert.equal(resolveLibrarySelection(sources, '800.P03', null).page, 22);
    assert.equal(resolveLibrarySelection(sources, '800-p03', 23).page, 23);
    assert.equal(resolveLibrarySelection(sources, '800-p03', 65).page, 22);
    assert.equal(resolveLibrarySelection(sources, '800-companion').node.id, 13);
    assert.equal(resolveLibrarySelection(sources, 'asset-section-800', 4).page, 4);
    assert.equal(resolveLibrarySelection(sources, 'retired-policy'), null);
    assert.equal(pageLink('https://files.mbfdhub.com/', 'sogs', '800-p03-m1', 23), 'https://files.mbfdhub.com/?manual=sogs&node=800-p03-m1&page=23');
});

test('identical historical numbers remain distinct subjects with distinct current targets', () => {
    const aliases = subjectAliases(sources);
    assert.equal(aliases.length, 2);
    assert.deepEqual(aliases.map(alias => alias.source_record_id), ['original-P436', 'original-P438']);
    assert.deepEqual(aliases.map(alias => alias.resolved_current_targets[0].id), ['800.P03', '800.P02']);
});

test('PDF URI links allow current peer routes and explicit web URLs while rejecting executable and filesystem targets', () => {
    const base = 'https://files.mbfdhub.com/?manual=sogs';
    assert.deepEqual(pdfUrlTarget('https://files.mbfdhub.com/current-sog/SECTION-300?page=110', base), { href: 'https://files.mbfdhub.com/current-sog/SECTION-300?page=110', assetId: 'SECTION-300', page: 110 });
    assert.equal(pdfUrlTarget('/current-sog/900-DE?page=2', base).assetId, '900-DE');
    assert.equal(pdfUrlTarget('https://example.com/reference', base).href, 'https://example.com/reference');
    for (const uri of ['javascript:alert(1)', 'file:///C:/policy.pdf', 'data:text/html,unsafe', '../../../old.pdf', 'old.pdf#page=1', '/current-sog/SECTION-300?page=0', '/current-sog/SECTION-300?page=1.5']) assert.equal(pdfUrlTarget(uri, base), null);
    assert.equal(pdfUrlTarget('https://example.com/current-sog/SECTION-300?page=1', base).assetId, undefined);
});

test('PDF named and explicit destinations resolve exact one-based pages through PDF.js', async () => {
    const pdf = { numPages: 32, getDestination: async name => name === 'MBFD_900_03' ? [{ num: 20, gen: 0 }, { name: 'Fit' }] : null, getPageIndex: async reference => reference.num === 20 ? 9 : 50 };
    assert.equal(await pdfDestinationPage(pdf, 'MBFD_900_03'), 10);
    assert.equal(await pdfDestinationPage(pdf, [0, { name: 'Fit' }]), 1);
    assert.equal(await pdfDestinationPage(pdf, [31, { name: 'Fit' }]), 32);
    assert.equal(await pdfDestinationPage(pdf, [32, { name: 'Fit' }]), null);
    assert.equal(await pdfDestinationPage(pdf, 'unknown'), null);
});

test('a real PDF.js non-page destination is omitted while valid links and unrelated errors are preserved', async () => {
    const objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << >> >>',
        '<< /Type /InvalidDestination >>',
    ];
    let source = '%PDF-1.7\n';
    const offsets = [0];
    for (const [index, object] of objects.entries()) {
        offsets.push(Buffer.byteLength(source));
        source += `${index + 1} 0 obj\n${object}\nendobj\n`;
    }
    const xref = Buffer.byteLength(source);
    source += `xref\n0 ${offsets.length}\n0000000000 65535 f \n`;
    source += offsets.slice(1).map(offset => `${String(offset).padStart(10, '0')} 00000 n \n`).join('');
    source += `trailer\n<< /Size ${offsets.length} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
    const loading = getDocument({ data: Uint8Array.from(Buffer.from(source)) });
    try {
        const pdf = await loading.promise;
        const invalid = [{ num: 4, gen: 0 }, { name: 'Fit' }];
        await assert.rejects(pdf.getPageIndex(invalid[0]), error => error.name === 'UnknownErrorException'
            && error.message === 'The reference does not point to a /Page dictionary.');
        assert.equal(await pdfDestinationPage(pdf, invalid), null);
        assert.equal(await pdfDestinationPage(pdf, [{ num: 3, gen: 0 }, { name: 'Fit' }]), 1);
    } finally { await loading.destroy(); }
    const failure = Object.assign(new Error('Worker connection failed'), { name: 'UnknownErrorException' });
    await assert.rejects(pdfDestinationPage({ getPageIndex: async () => { throw failure; } }, [{ num: 3, gen: 0 }]), error => error === failure);
});

test('link rectangles follow viewport rotation and ignore invalid source rectangles', () => {
    const viewport = { convertToViewportPoint: (x, y) => [100 - y, x] };
    assert.deepEqual(linkRectangle(viewport, [20, 20, 90, 90]), { left: 10, top: 20, width: 70, height: 70 });
    assert.equal(linkRectangle(viewport, [1, 2, NaN, 4]), null);
});

test('publication changes invalidate an open catalog and stable identities resolve the new entrypoints', () => {
    const manuals = [{ slug: 'sogs', active_edition_id: 10 }, { slug: 'medical', active_edition_id: 2 }];
    assert.equal(currentEditionChanged(manuals, 'sogs', 9), true);
    assert.equal(currentEditionChanged(manuals, 'sogs', 10), false);
    assert.equal(currentEditionChanged(manuals, 'medical', 2), false);
    assert.equal(currentEditionChanged([], 'sogs', 9), true);
    const replacement = structuredClone(sources);
    replacement[0].id = 112;
    replacement[0].revision.metadata.primary_entries[0].physical_page = 24;
    replacement[0].revision.metadata.primary_entries[0].semantic_pages = [24, 25];
    assert.equal(resolveLibrarySelection(sources, '800-p03').page, 22);
    assert.equal(resolveLibrarySelection(replacement, '800-p03').page, 24);
    assert.equal(resolveLibrarySelection(replacement, '800-p03').node.id, 112);
    assert.equal(resolveLibrarySelection(replacement, '800-p03', 23).page, 24);
    assert.equal(resolveLibrarySelection(replacement, 'asset-section-800', 23).page, 23);
    assert.equal(resolveLibrarySelection(replacement, 12), null);
});
