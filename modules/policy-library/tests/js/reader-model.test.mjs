import test from 'node:test';
import assert from 'node:assert/strict';
import { bodyTextSize, ownedPages, peerAnnotationTarget, readingContent, readingScale, readingSection } from '../../resources/js/reader-model.js';

test('thumbnails use exact semantic page ownership, including discontinuous shared pages', () => {
    const node = { revision: { page_count: 8 } };
    assert.deepEqual(ownedPages(node, { physical_page: 2, semantic_pages: [7, 2, 2, 9, 0, '3'] }), [2, 7]);
    assert.deepEqual(ownedPages(node, { physical_page: 2 }), [2]);
    assert.deepEqual(ownedPages(node, { physical_page: 2, semantic_pages: [] }), []);
    assert.deepEqual(ownedPages(node, null), [1, 2, 3, 4, 5, 6, 7, 8]);
});

test('read size follows actual PDF body glyph size rather than large sparse titles', () => {
    const item = (str, size) => ({ str, transform: [size, 0, 0, size, 20, 50] });
    assert.equal(bodyTextSize([item('TITLE', 24), item('A longer paragraph with operational instructions.', 10)]), 10);
    assert.equal(readingScale(0.55, 10) * 10, 18);
    assert.equal(readingScale(2, 10), 2);
    assert.equal(bodyTextSize([{ ...item('vertical', 12), dir: 'ttb' }, item(' ', 10)]), 12);
});

test('sections retain canonical IDs, including companions and controlled references', () => {
    assert.equal(readingSection({ id: '800.P03-M1' }), '800');
    assert.equal(readingSection({ id: '100-COMPANION' }), '100');
    assert.equal(readingSection({ id: 'procedure-pocus' }), null);
});

// The published C2 catalog and live reading API have this exact identity/page shape:
// 300-RIC-F02 owns page 4, while its native artifact contains only the 300-RIC root.
const ricRoot = { id: '300-RIC', title: 'Firefighter Emergency / MAYDAY / Rapid Intervention', parent: null, owning_asset_id: '300-RIC', physical_page: 2, semantic_pages: [2, 3, 4, 5, 6] };
const ricFigure = { id: '300-RIC-F02', slug: '300-ric-f02', title: 'MAYDAY parallel actions', document_class: 'embedded_figure', parent: '300-RIC', owning_asset_id: '300-RIC', physical_page: 4, semantic_pages: [4] };
const ricNode = { id: 895, metadata: { asset_id: '300-RIC' }, revision: { id: '041be519-391c-482a-874d-90f5cb31b5eb', page_count: 6, metadata: { asset_id: '300-RIC', primary_entries: [ricRoot, ricFigure] } } };
const ricBlocks = [
    { type: 'heading', text: 'Root test content', pdf_page: 2 },
    { type: 'paragraph', text: 'Preceding test content', pdf_page: 3 },
    ...['paragraph', 'paragraph', 'table', 'paragraph', 'paragraph', 'paragraph'].map(type => ({ type, text: 'Selected page test content', pdf_page: 4 })),
    { type: 'paragraph', text: 'Following test content', pdf_page: 5 },
    { type: 'pdf_reference', text: 'Final page test reference', pdf_page: 6 },
];
const ricReading = [{ id: '300-RIC', title: ricRoot.title, blocks: ricBlocks }];

test('C2 semantic subsection reads its owning native root with only its actual PDF page and title', () => {
    const content = readingContent(ricNode, ricFigure, ricReading);
    assert.ok(content, 'Published subsection must resolve to its own native artifact');
    assert.equal(content.id, '300-RIC-F02');
    assert.equal(content.title, 'MAYDAY parallel actions');
    assert.deepEqual(content.blocks, ricBlocks.filter(block => block.pdf_page === 4));
    assert.equal(content.blocks.length, 6);
});

test('native root and explicit subsection artifacts retain their original selection behavior', () => {
    assert.deepEqual(readingContent(ricNode, ricRoot, ricReading).blocks, ricBlocks);
    assert.deepEqual(readingContent(ricNode, null, ricReading).blocks, ricBlocks);
    const explicit = { id: ricFigure.id, title: ricFigure.title, blocks: [ricBlocks[2]] };
    assert.deepEqual(readingContent(ricNode, ricFigure, [...ricReading, explicit]).blocks, explicit.blocks);
});

test('native root fallback rejects unknown, foreign, orphaned and cyclic subsection identities', () => {
    const nodeWith = primary_entries => ({ ...ricNode, revision: { ...ricNode.revision, metadata: { ...ricNode.revision.metadata, primary_entries } } });
    assert.equal(readingContent(ricNode, { ...ricFigure, id: '300-RIC-UNKNOWN' }, ricReading), null);
    assert.equal(readingContent(ricNode, { ...ricFigure, owning_asset_id: '300.01' }, ricReading), null);
    assert.equal(readingContent(ricNode, { ...ricFigure, parent: 'missing' }, ricReading), null);
    assert.equal(readingContent(ricNode, ricFigure, []), null);
    assert.equal(readingContent(ricNode, ricFigure, [{ ...ricReading[0], id: '300.01' }]), null);
    assert.equal(readingContent(nodeWith([ricFigure]), ricFigure, ricReading), null);
    const orphan = { ...ricFigure, parent: 'missing' };
    assert.equal(readingContent(nodeWith([ricRoot, orphan]), orphan, ricReading), null);
    const foreign = { ...ricFigure, owning_asset_id: '300.01' };
    assert.equal(readingContent(nodeWith([ricRoot, foreign]), ricFigure, ricReading), null);
    const cycle = { ...ricFigure, parent: ricFigure.id };
    assert.equal(readingContent(nodeWith([ricRoot, cycle]), cycle, ricReading), null);
    assert.equal(readingContent({ ...ricNode, revision: { ...ricNode.revision, metadata: { ...ricNode.revision.metadata, asset_id: '300.01' } } }, ricFigure, ricReading), null);
});

test('nested semantic content keeps only catalog-owned pages, including PDF references', () => {
    const nested = { ...ricFigure, id: '300-RIC-NESTED', parent: ricFigure.id, physical_page: 6, semantic_pages: [6] };
    const node = { ...ricNode, revision: { ...ricNode.revision, metadata: { ...ricNode.revision.metadata, primary_entries: [ricRoot, ricFigure, nested] } } };
    assert.deepEqual(readingContent(node, nested, ricReading).blocks, [ricBlocks.at(-1)]);
    assert.deepEqual(readingContent(node, { ...nested, semantic_pages: [4, 5, 6] }, ricReading).blocks, [ricBlocks.at(-1)]);
});

const sourceHash = 'a'.repeat(64), targetHash = 'b'.repeat(64);
const peer = { annotation_id: '15R', source_page: 3, rect: [10, 20, 80, 35], source_pdf_sha256: sourceHash, target_asset_id: '100.02', target_pdf_sha256: targetHash, target_page: 2 };
const revision = { metadata: { canonical_sha256: sourceHash, peer_links: [peer] } };
const annotation = { id: '15R', subtype: 'Link', rect: peer.rect, unsafeUrl: 'file:///untrusted.pdf' };
const target = { id: 8, metadata: { asset_id: '100.02' }, revision: { page_count: 4, metadata: { asset_id: '100.02', canonical_sha256: targetHash } } };

test('proved portable peer resolves only to the exact current visible PDF and page', () => {
    assert.deepEqual(peerAnnotationTarget(revision, annotation, 3, [target]), { bound: true, target: { node: 8, page: 2 } });
    assert.deepEqual(peerAnnotationTarget({}, annotation, 3, [target]), { bound: false, target: null });
});

test('hidden, foreign-hash, wrong-identity and duplicate peer targets remain blocked without raw URL fallback', () => {
    const blocked = { bound: true, target: null };
    for (const documents of [[], [target, target], [{ ...target, metadata: { asset_id: '100.03' } }],
        [{ ...target, revision: { ...target.revision, metadata: { ...target.revision.metadata, canonical_sha256: sourceHash } } }],
        [{ ...target, revision: { ...target.revision, metadata: { ...target.revision.metadata, asset_id: '100.03' } } }]]) {
        assert.deepEqual(peerAnnotationTarget(revision, annotation, 3, documents), blocked);
    }
});

test('source hash, annotation geometry, assigned page and duplicate binding must agree', () => {
    const blocked = { bound: true, target: null };
    assert.deepEqual(peerAnnotationTarget(revision, annotation, 2, [target]), blocked);
    assert.deepEqual(peerAnnotationTarget(revision, { ...annotation, rect: [10, 20, 80, 36] }, 3, [target]), blocked);
    for (const binding of [{ ...peer, source_pdf_sha256: targetHash }, { ...peer, target_page: 5 }, { ...peer, target_page: '2' }]) {
        assert.deepEqual(peerAnnotationTarget({ metadata: { ...revision.metadata, peer_links: [binding] } }, annotation, 3, [target]), blocked);
    }
    assert.deepEqual(peerAnnotationTarget({ metadata: { ...revision.metadata, peer_links: [peer, peer] } }, annotation, 3, [target]), blocked);
});

test('an annotation reused on another page requires its own exact page binding', () => {
    const next = { ...peer, source_page: 4, target_page: 3 };
    assert.deepEqual(peerAnnotationTarget({ metadata: { ...revision.metadata, peer_links: [peer, next] } }, annotation, 4, [target]), { bound: true, target: { node: 8, page: 3 } });
});
