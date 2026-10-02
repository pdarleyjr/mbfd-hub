export function flattenDocuments(nodes, parents = []) {
    return nodes.flatMap(node => [
        ...(node.revision ? [{ ...node, path: parents }] : []),
        ...flattenDocuments(node.children || [], [...parents, node.title]),
    ]);
}

export function adjacentPage(documents, nodeId, page, direction) {
    const index = documents.findIndex(node => String(node.id) === String(nodeId));
    if (index < 0) return null;
    const node = documents[index];
    const target = page + direction;
    if (target >= 1 && target <= node.revision.page_count) return { node: node.id, page: target };
    const next = documents[index + direction];
    return next ? { node: next.id, page: direction > 0 ? 1 : next.revision.page_count } : null;
}

export function canvasDimensions(width, height, ratio, maximumPixels = 16_000_000) {
    const density = Math.min(Math.max(1, ratio || 1), 3, Math.sqrt(maximumPixels / (width * height)));
    return { width: Math.max(1, Math.floor(width * density)), height: Math.max(1, Math.floor(height * density)), density };
}

export function resolveSelection(documents, nodeId, page) {
    const node = documents.find(item => item.slug === nodeId) || documents.find(item => String(item.id) === String(nodeId));
    if (!node) return null;
    const number = Number(page);
    return { node, page: Number.isInteger(number) && number >= 1 && number <= node.revision.page_count ? number : 1 };
}
