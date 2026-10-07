import test from 'node:test';
import assert from 'node:assert/strict';
import { bodyTextSize, ownedPages, readingScale, readingSection } from '../../resources/js/reader-model.js';

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
