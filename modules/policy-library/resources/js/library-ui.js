import { resolveSelection } from './navigation.js';

export function pageLink(base, manual, node, page) {
    const url = new URL(base);
    url.search = '';
    url.hash = '';
    url.searchParams.set('manual', manual);
    url.searchParams.set('node', node);
    url.searchParams.set('page', page);
    return url.href;
}

export function primaryEntries(documents) {
    const entries = documents.flatMap(node => (node.revision.metadata?.primary_entries || []).filter(entry =>
        entry.id && entry.slug && Number.isInteger(entry.physical_page) && entry.physical_page >= 1 && entry.physical_page <= node.revision.page_count,
    ).map(entry => ({ ...entry, node })));
    const preferred = new Map();
    for (const entry of entries) {
        const prior = preferred.get(entry.id);
        const owner = entry.owning_asset_id || entry.id;
        if (!prior || (entry.node.metadata?.asset_id === owner && prior.node.metadata?.asset_id !== (prior.owning_asset_id || prior.id))) preferred.set(entry.id, entry);
    }
    return [...preferred.values()];
}

export function primaryHierarchy(entries) {
    const nodes = new Map(entries.map(entry => [entry.id, { ...entry, children: [] }]));
    const roots = [];
    for (const entry of nodes.values()) {
        const parent = nodes.get(entry.parent);
        if (parent) parent.children.push(entry);
        else roots.push(entry);
    }
    return roots;
}

export function subjectAliases(documents) {
    const aliases = new Map();
    for (const node of documents) {
        for (const alias of node.revision.metadata?.subject_aliases || []) aliases.set(alias.source_record_id, alias);
    }
    return [...aliases.values()];
}

export function resolveLibrarySelection(documents, nodeId, page) {
    const entry = primaryEntries(documents).find(item => item.slug === nodeId || item.id === nodeId);
    if (!entry) return resolveSelection(documents, nodeId, page);
    const number = Number(page);
    return {
        node: entry.node, entry,
        page: page !== null && page !== undefined && page !== '' && Number.isInteger(number) && number >= 1 && number <= entry.node.revision.page_count && (!entry.semantic_pages || entry.semantic_pages.includes(number)) ? number : entry.physical_page,
    };
}

export function currentEditionChanged(manuals, slug, loadedEdition) {
    if (loadedEdition === null || loadedEdition === undefined) return false;
    const manual = manuals.find(item => item.slug === slug);
    return !manual || (manual.active_edition_id !== undefined && String(manual.active_edition_id) !== String(loadedEdition));
}

export function pdfUrlTarget(value, base) {
    try {
        const url = new URL(value, base);
        if (!['https:', 'http:'].includes(url.protocol)) return null;
        const current = /^\/current-sog\/([^/]+)$/.exec(url.pathname);
        const page = Number(url.searchParams.get('page'));
        if (current && (url.origin === new URL(base).origin || url.origin === 'https://files.mbfdhub.com')) {
            if (!Number.isInteger(page) || page < 1) return null;
            return { href: url.href, assetId: decodeURIComponent(current[1]), page };
        }
        if (!/^https?:\/\//i.test(value)) return null;
        return { href: url.href };
    } catch { return null; }
}

export async function pdfDestinationPage(pdf, destination) {
    const resolved = typeof destination === 'string' ? await pdf.getDestination(destination) : destination;
    if (!Array.isArray(resolved)) return null;
    const first = resolved[0];
    let index;
    try {
        index = Number.isInteger(first) ? first : first && typeof first === 'object' ? await pdf.getPageIndex(first) : -1;
    } catch (error) {
        if (error.name === 'UnknownErrorException' && error.message === 'The reference does not point to a /Page dictionary.') return null;
        throw error;
    }
    return Number.isInteger(index) && index >= 0 && index < pdf.numPages ? index + 1 : null;
}

export function linkRectangle(viewport, rectangle) {
    if (!Array.isArray(rectangle) || rectangle.length !== 4 || !rectangle.every(Number.isFinite)) return null;
    const first = viewport.convertToViewportPoint(rectangle[0], rectangle[1]);
    const last = viewport.convertToViewportPoint(rectangle[2], rectangle[3]);
    return { left: Math.min(first[0], last[0]), top: Math.min(first[1], last[1]), width: Math.abs(last[0] - first[0]), height: Math.abs(last[1] - first[1]) };
}

export function recentlyPublished(documents, limit = 12) {
    return documents.filter(node => node.revision?.published_at && Number.isFinite(Date.parse(node.revision.published_at)))
        .sort((a, b) => Date.parse(b.revision.published_at) - Date.parse(a.revision.published_at))
        .slice(0, limit);
}
