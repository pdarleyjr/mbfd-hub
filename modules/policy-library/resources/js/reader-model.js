// Page ownership comes from the published revision, never an inferred next bookmark.
export function ownedPages(node, entry) {
    const count = node?.revision?.page_count || 0;
    const pages = entry ? entry.semantic_pages ?? [entry.physical_page] : Array.from({ length: count }, (_, index) => index + 1);
    return [...new Set(pages)].filter(page => Number.isInteger(page) && page >= 1 && page <= count).sort((a, b) => a - b);
}

export function readingContent(node, selected, entries) {
    const identity = selected?.id || node.metadata?.asset_id;
    let entry = entries.find(entry => entry.id === identity);
    let owner = selected;
    if (!entry && selected) {
        const asset = node.metadata?.asset_id;
        const catalog = node.revision.metadata?.primary_entries || [];
        owner = catalog.find(item => item.id === selected.id);
        if (!asset || node.revision.metadata?.asset_id !== asset || selected.owning_asset_id !== asset
            || !owner || owner.owning_asset_id !== asset || selected.parent !== owner.parent) return null;
        // Doctrine hierarchy can have no parent or a parent in another document.
        // The catalog's explicit PDF owner binds native artifact fallback.
        const root = catalog.find(item => item.id === asset);
        if (!root || (root.owning_asset_id && root.owning_asset_id !== asset)) return null;
        entry = entries.find(item => item.id === asset);
    }
    if (!entry) return null;
    const allowed = ownedPages(node, owner);
    return { id: identity, title: selected?.title || entry.title, blocks: entry.blocks.filter(block => allowed.includes(block.pdf_page)) };
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

// Portable source PDF actions stay byte-identical. Only an import-proved annotation
// can resolve to a visible, exact-hash current document; rejected bindings never
// fall back to a raw filesystem URL.
export function peerAnnotationTarget(revision, annotation, sourcePage, documents) {
    const declared = Array.isArray(revision?.metadata?.peer_links)
        ? revision.metadata.peer_links.filter(link => link?.annotation_id === annotation.id) : [];
    if (!declared.length) return { bound: false, target: null };
    const matches = declared.filter(link => link.source_page === sourcePage);
    const blocked = { bound: true, target: null };
    if (matches.length !== 1 || annotation.subtype !== 'Link') return blocked;
    const link = matches[0];
    const hash = /^[a-f0-9]{64}$/;
    if (!hash.test(link.source_pdf_sha256) || link.source_pdf_sha256 !== revision.metadata.canonical_sha256
        || !hash.test(link.target_pdf_sha256) || !Number.isInteger(link.target_page)
        || !Array.isArray(link.rect) || link.rect.length !== 4 || !Array.isArray(annotation.rect)
        || annotation.rect.length !== 4 || link.rect.some((value, index) => !Number.isFinite(value) || value !== annotation.rect[index])) return blocked;
    const targets = documents.filter(node => node.metadata?.asset_id === link.target_asset_id
        && node.revision?.metadata?.asset_id === link.target_asset_id
        && node.revision.metadata.canonical_sha256 === link.target_pdf_sha256
        && link.target_page >= 1 && link.target_page <= node.revision.page_count);
    if (targets.length !== 1 || targets[0].id === undefined) return blocked;
    return { bound: true, target: { node: targets[0].id, page: link.target_page } };
}
