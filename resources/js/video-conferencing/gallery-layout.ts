/**
 * Pure layout maths for the conference gallery and presentation stage.
 *
 * Every tile keeps an exact 16:9 box. Widths are quantised to multiples of 16
 * so the matching height (width * 9 / 16) is always a whole number of pixels and
 * the ratio is exact rather than approximate.
 */

export interface Size {
    width: number;
    height: number;
}

export const TILE_RATIO_WIDTH = 16;
export const TILE_RATIO_HEIGHT = 9;
export const GALLERY_GAP = 8;
export const PHONE_BREAKPOINT = 640;
export const MIN_TILE_WIDTH = 200;
export const MIN_TILE_WIDTH_PHONE = 150;
export const PHONE_MAX_COLUMNS = 2;
/** Space reserved for a scrollbar next to a scrolling filmstrip. */
export const SCROLLBAR_ALLOWANCE = 14;

const RAIL_MIN_WIDTH = 900;
const RAIL_MIN_HEIGHT = 480;

export interface GridLayout {
    columns: number;
    rows: number;
    tileWidth: number;
    tileHeight: number;
    /** True when tiles would be smaller than the minimum, so the gallery scrolls vertically. */
    scroll: boolean;
}

export interface PresentationLayout {
    orientation: 'solo' | 'rail' | 'strip';
    focus: Size;
    thumb: Size;
    /** Rail: column width. Strip: row height. Solo: 0. */
    filmstripExtent: number;
}

export function minTileWidthFor(containerWidth: number): number {
    return containerWidth < PHONE_BREAKPOINT ? MIN_TILE_WIDTH_PHONE : MIN_TILE_WIDTH;
}

/** Largest multiple of 16 that is not greater than `value` (never negative). */
export function quantizeWidth(value: number): number {
    if (!Number.isFinite(value) || value <= 0) return 0;

    return Math.floor(value / TILE_RATIO_WIDTH) * TILE_RATIO_WIDTH;
}

export function heightForWidth(width: number): number {
    return (width / TILE_RATIO_WIDTH) * TILE_RATIO_HEIGHT;
}

/** Largest 16:9 box that fits inside `area`. */
export function fitRatioBox(area: Size): Size {
    const width = quantizeWidth(Math.min(
        area.width,
        (Math.max(0, area.height) * TILE_RATIO_WIDTH) / TILE_RATIO_HEIGHT,
    ));

    return { width, height: heightForWidth(width) };
}

interface Candidate {
    columns: number;
    rows: number;
    width: number;
}

function widthForColumns(count: number, columns: number, container: Size, gap: number): Candidate {
    const rows = Math.ceil(count / columns);
    const byWidth = (container.width - gap * (columns - 1)) / columns;
    const byHeight = ((container.height - gap * (rows - 1)) / rows) * (TILE_RATIO_WIDTH / TILE_RATIO_HEIGHT);

    return { columns, rows, width: quantizeWidth(Math.min(byWidth, byHeight)) };
}

function isBetter(candidate: Candidate, best: Candidate | null, count: number): boolean {
    if (best === null) return true;
    if (candidate.width !== best.width) return candidate.width > best.width;
    // Same tile size: prefer the arrangement that leaves fewer empty cells.
    return candidate.columns * candidate.rows - count < best.columns * best.rows - count;
}

/**
 * Best-fit grid: choose the column count that makes the biggest 16:9 tiles that
 * still fit the container. If even the best arrangement is below the minimum
 * tile width the gallery switches to scroll mode with width-driven columns.
 */
export function computeGridLayout(count: number, container: Size, gap = GALLERY_GAP): GridLayout {
    const width = Math.max(0, container.width);
    const height = Math.max(0, container.height);
    if (count <= 0 || width === 0 || height === 0) {
        return { columns: 1, rows: 0, tileWidth: 0, tileHeight: 0, scroll: false };
    }

    const phone = width < PHONE_BREAKPOINT;
    const minWidth = minTileWidthFor(width);
    const maxColumns = Math.min(count, phone ? PHONE_MAX_COLUMNS : count);

    let best: Candidate | null = null;
    for (let columns = 1; columns <= maxColumns; columns += 1) {
        const candidate = widthForColumns(count, columns, { width, height }, gap);
        if (isBetter(candidate, best, count)) best = candidate;
    }

    if (best !== null && best.width >= minWidth) {
        return {
            columns: best.columns,
            rows: best.rows,
            tileWidth: best.width,
            tileHeight: heightForWidth(best.width),
            scroll: false,
        };
    }

    // Too many tiles for the viewport: keep tiles at a usable size and scroll.
    const fitting = Math.max(1, Math.floor((width + gap) / (minWidth + gap)));
    const columns = Math.min(count, phone ? PHONE_MAX_COLUMNS : fitting, fitting);
    const tileWidth = quantizeWidth((width - gap * (columns - 1)) / columns);

    return {
        columns,
        rows: Math.ceil(count / columns),
        tileWidth,
        tileHeight: heightForWidth(tileWidth),
        scroll: true,
    };
}

function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}

/**
 * Focus tile (screen share / pinned) plus a scrolling filmstrip of thumbnails.
 * Wide, tall-enough viewports get a vertical rail; narrow or landscape-short
 * viewports get a horizontal strip underneath.
 */
export function computePresentationLayout(thumbnailCount: number, container: Size, gap = GALLERY_GAP): PresentationLayout {
    const width = Math.max(0, container.width);
    const height = Math.max(0, container.height);
    if (thumbnailCount <= 0) {
        return { orientation: 'solo', focus: fitRatioBox({ width, height }), thumb: { width: 0, height: 0 }, filmstripExtent: 0 };
    }

    if (width >= RAIL_MIN_WIDTH && height >= RAIL_MIN_HEIGHT) {
        const thumbWidth = clamp(quantizeWidth(width * 0.2), 160, 272);
        const extent = thumbWidth + SCROLLBAR_ALLOWANCE;

        return {
            orientation: 'rail',
            focus: fitRatioBox({ width: width - extent - gap, height }),
            thumb: { width: thumbWidth, height: heightForWidth(thumbWidth) },
            filmstripExtent: extent,
        };
    }

    const thumbWidth = width < PHONE_BREAKPOINT ? 112 : 160;
    const thumbHeight = heightForWidth(thumbWidth);
    const extent = thumbHeight + SCROLLBAR_ALLOWANCE;

    return {
        orientation: 'strip',
        focus: fitRatioBox({ width, height: height - extent - gap }),
        thumb: { width: thumbWidth, height: thumbHeight },
        filmstripExtent: extent,
    };
}
