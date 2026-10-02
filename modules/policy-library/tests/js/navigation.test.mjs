import test from 'node:test';
import assert from 'node:assert/strict';
import { adjacentPage, canvasDimensions, flattenDocuments, resolveSelection } from '../../resources/js/navigation.js';

const tree = [{ id: 1, title: 'Procedures', children: [{ id: 2, title: 'POCUS', revision: { page_count: 3 }, children: [] }, { id: 3, title: 'Assessment', revision: { page_count: 8 }, children: [] }] }];
const documents = flattenDocuments(tree);
test('reading order retains nested path and generated pages', () => {
    assert.deepEqual(documents.map(node => node.id), [2, 3]);
    assert.deepEqual(documents[0].path, ['Procedures']);
});
test('navigation crosses document boundaries in both directions', () => {
    assert.deepEqual(adjacentPage(documents, 2, 3, 1), { node: 3, page: 1 });
    assert.deepEqual(adjacentPage(documents, 3, 1, -1), { node: 2, page: 3 });
    assert.equal(adjacentPage(documents, 2, 1, -1), null);
    assert.equal(adjacentPage(documents, 3, 8, 1), null);
});
test('invalid bookmarked pages never select nonexistent pages', () => {
    for (const page of ['bogus', 0, -1, 100, 1.5]) assert.equal(resolveSelection(documents, 2, page).page, 1);
    assert.equal(resolveSelection(documents, 999, 1), null);
    assert.equal(resolveSelection(documents, '2', '3').page, 3);
});
test('logical document bookmarks survive a replacement edition with new numeric IDs', () => {
    const original = [{ id: 2, slug: 'procedures-pocus', revision: { page_count: 4 } }];
    const replacement = [{ id: 98, slug: 'procedures-pocus', revision: { page_count: 4 } }];
    assert.equal(resolveSelection(original, 'procedures-pocus', '3').node.id, 2);
    assert.equal(resolveSelection(replacement, 'procedures-pocus', '3').node.id, 98);
    assert.equal(resolveSelection(replacement, 'procedures-pocus', '3').page, 3);
});
test('a numeric logical slug takes precedence over another document numeric ID', () => {
    const replacement = [{ id: 20, slug: 'assessment', revision: { page_count: 2 } }, { id: 98, slug: '20', revision: { page_count: 4 } }];
    assert.equal(resolveSelection(replacement, '20', '3').node.id, 98);
    assert.equal(resolveSelection(replacement, 20, '1').node.id, 20);
});
test('large displays stay within the canvas memory budget', () => {
    for (const [width, height, ratio] of [[360, 520, 3], [3000, 4200, 2], [8000, 12000, 3]]) {
        const output = canvasDimensions(width, height, ratio);
        assert.ok(output.width * output.height <= 16_000_000);
        assert.ok(output.width > 0 && output.height > 0);
    }
});
