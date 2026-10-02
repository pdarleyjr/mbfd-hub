export function pageLink(base, manual, node, page) {
    const url = new URL(base);
    url.search = '';
    url.hash = '';
    url.searchParams.set('manual', manual);
    url.searchParams.set('node', node);
    url.searchParams.set('page', page);
    return url.href;
}

export function recentlyPublished(documents, limit = 12) {
    return documents.filter(node => node.revision?.published_at && Number.isFinite(Date.parse(node.revision.published_at)))
        .sort((a, b) => Date.parse(b.revision.published_at) - Date.parse(a.revision.published_at))
        .slice(0, limit);
}
