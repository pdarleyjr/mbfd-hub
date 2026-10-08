import test from 'node:test';
import assert from 'node:assert/strict';
import { bodyTextSize, ownedPages, peerAnnotationTarget, readingScale, readingSection } from '../../resources/js/reader-model.js';

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
