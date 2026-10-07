// Page ownership comes from the published revision, never an inferred next bookmark.
export function ownedPages(node, entry) {
    const count = node?.revision?.page_count || 0;
    const pages = entry ? entry.semantic_pages ?? [entry.physical_page] : Array.from({ length: count }, (_, index) => index + 1);
    return [...new Set(pages)].filter(page => Number.isInteger(page) && page >= 1 && page <= count).sort((a, b) => a - b);
}

// Character-weighted dominant horizontal text size avoids a large title deciding
// the reading scale. This retains PDF geometry; it does not reinterpret tables.
export function bodyTextSize(items) {
    const sizes = new Map();
    for (const item of items) {
        if (!item.str?.trim() || !Array.isArray(item.transform) || item.dir === 'ttb') continue;
        const size = Math.hypot(item.transform[2], item.transform[3]);
        if (!Number.isFinite(size) || size < 4 || size > 30) continue;
        const rounded = Math.round(size * 2) / 2;
        sizes.set(rounded, (sizes.get(rounded) || 0) + item.str.trim().length);
    }
    return [...sizes].sort((a, b) => b[1] - a[1] || a[0] - b[0])[0]?.[0] || 12;
}

export function readingScale(widthScale, textSize, target = 18) {
    return Math.max(widthScale, target / textSize);
}

export function readingSection(entry) {
    return /^(\d{3})(?:\.|-|$)/.exec(entry.id)?.[1] || null;
}
