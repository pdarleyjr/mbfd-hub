import assert from 'node:assert/strict';
import test from 'node:test';
import {
    computeGridLayout,
    computePresentationLayout,
    fitRatioBox,
    GALLERY_GAP,
    minTileWidthFor,
    quantizeWidth,
} from '../../resources/js/video-conferencing/gallery-layout.ts';

const viewports = [
    [1920, 1080],
    [1366, 768],
    [1024, 768],
    [768, 1024],
    [390, 844],
];
const counts = [1, 2, 3, 6, 8, 12];

/** Approximates the gallery element: viewport minus shell padding, header, focus bar and controls. */
function galleryContainer(viewportWidth, viewportHeight) {
    const chrome = viewportWidth < 640 ? 240 : 190;

    return { width: viewportWidth - 21, height: viewportHeight - chrome };
}

test('every tile is an exact 16:9 box and is either large enough or the gallery scrolls', () => {
    for (const [viewportWidth, viewportHeight] of viewports) {
        const container = galleryContainer(viewportWidth, viewportHeight);
        for (const count of counts) {
            const layout = computeGridLayout(count, container);
            const label = `${viewportWidth}x${viewportHeight} with ${count} tiles`;

            assert.equal(layout.tileWidth * 9, layout.tileHeight * 16, `${label}: aspect must be exactly 16:9`);
            assert.ok(Number.isInteger(layout.tileHeight), `${label}: height is a whole pixel`);
            assert.ok(
                layout.scroll || layout.tileWidth >= minTileWidthFor(container.width),
                `${label}: tile ${layout.tileWidth}px is below the minimum without scrolling`,
            );
            assert.ok(layout.tileWidth <= container.width, `${label}: tile wider than container`);
            assert.ok(layout.columns * layout.rows >= count, `${label}: grid too small`);
            if (!layout.scroll) {
                const gridWidth = layout.columns * layout.tileWidth + (layout.columns - 1) * GALLERY_GAP;
                const gridHeight = layout.rows * layout.tileHeight + (layout.rows - 1) * GALLERY_GAP;
                assert.ok(gridWidth <= container.width, `${label}: grid overflows horizontally`);
                assert.ok(gridHeight <= container.height, `${label}: grid overflows vertically`);
            }
        }
    }
});

test('phones use one or two columns', () => {
    for (const count of counts) {
        const layout = computeGridLayout(count, galleryContainer(390, 844));
        assert.ok(layout.columns <= 2, `${count} tiles used ${layout.columns} columns`);
    }
});

test('a single participant gets the biggest 16:9 tile that fits, centred rather than stretched', () => {
    const layout = computeGridLayout(1, { width: 1899, height: 890 });
    assert.equal(layout.columns, 1);
    assert.equal(layout.scroll, false);
    assert.ok(layout.tileHeight <= 890);
    assert.equal(layout.tileWidth, quantizeWidth((890 * 16) / 9));
});

test('best fit picks the column count that maximises tile size', () => {
    // 6 tiles in a wide container fit as 3x2, not as a single row of 6.
    const layout = computeGridLayout(6, { width: 1600, height: 800 });
    assert.deepEqual([layout.columns, layout.rows], [3, 2]);
});

test('too many tiles for a small container scroll at a usable size instead of shrinking', () => {
    const layout = computeGridLayout(12, { width: 700, height: 260 });
    assert.equal(layout.scroll, true);
    assert.ok(layout.tileWidth >= minTileWidthFor(700));
    assert.equal(layout.tileWidth * 9, layout.tileHeight * 16);
});

test('empty and unmeasured galleries produce no tile size', () => {
    assert.equal(computeGridLayout(0, { width: 1000, height: 600 }).tileWidth, 0);
    assert.equal(computeGridLayout(4, { width: 0, height: 0 }).tileWidth, 0);
});

test('fitRatioBox never exceeds its area and stays 16:9', () => {
    for (const [width, height] of [[1280, 500], [500, 1280], [333, 333], [16, 9]]) {
        const box = fitRatioBox({ width, height });
        assert.ok(box.width <= width && box.height <= height);
        assert.equal(box.width * 9, box.height * 16);
    }
});

test('presentation: focus tile is 16:9 and the filmstrip handles any thumbnail count', () => {
    for (const [viewportWidth, viewportHeight] of viewports) {
        const container = galleryContainer(viewportWidth, viewportHeight);
        for (const thumbnails of [0, 1, 2, 5, 11]) {
            const layout = computePresentationLayout(thumbnails, container);
            const label = `${viewportWidth}x${viewportHeight} with ${thumbnails} thumbnails (${layout.orientation})`;

            assert.equal(layout.focus.width * 9, layout.focus.height * 16, `${label}: focus aspect`);
            assert.ok(layout.focus.width <= container.width && layout.focus.height <= container.height, `${label}: focus overflows`);
            if (thumbnails === 0) {
                assert.equal(layout.orientation, 'solo');
                continue;
            }
            assert.equal(layout.thumb.width * 9, layout.thumb.height * 16, `${label}: thumbnail aspect`);
            if (layout.orientation === 'rail') {
                assert.ok(layout.focus.width + GALLERY_GAP + layout.filmstripExtent <= container.width, `${label}: rail overflows`);
            } else {
                assert.ok(layout.focus.height + GALLERY_GAP + layout.filmstripExtent <= container.height, `${label}: strip overflows`);
            }
        }
    }
});

test('presentation uses a rail on wide screens and a strip on narrow or landscape-short screens', () => {
    assert.equal(computePresentationLayout(4, galleryContainer(1920, 1080)).orientation, 'rail');
    assert.equal(computePresentationLayout(4, galleryContainer(768, 1024)).orientation, 'strip');
    assert.equal(computePresentationLayout(4, galleryContainer(390, 844)).orientation, 'strip');
    assert.equal(computePresentationLayout(4, { width: 1000, height: 330 }).orientation, 'strip');
});
