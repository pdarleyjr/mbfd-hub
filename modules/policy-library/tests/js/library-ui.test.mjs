import test from 'node:test';
import assert from 'node:assert/strict';
import { pageLink, recentlyPublished } from '../../resources/js/library-ui.js';

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
