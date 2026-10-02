import workerUrl from 'pdfjs-dist/legacy/build/pdf.worker.mjs?url';
import '../css/viewer.css';
import { adjacentPage, canvasDimensions, flattenDocuments, resolveSelection } from './navigation.js';
import { pageLink, recentlyPublished } from './library-ui.js';

let pdfEngine;
let enginePromise;
function loadPdfEngine() {
    enginePromise ||= import('pdfjs-dist/legacy/build/pdf.mjs').then(engine => {
        engine.GlobalWorkerOptions.workerSrc = workerUrl;
        pdfEngine = engine;
        return engine;
    });
    return enginePromise;
}
const byId = id => document.getElementById(id);
const ui = {
    tree: byId('manual-tree'), manuals: byId('manual-select'), search: byId('title-search'),
    stage: byId('page-stage'), paper: byId('pdf-page'), canvas: byId('pdf-canvas'),
    text: byId('pdf-text'), message: byId('viewer-message'), sidebar: byId('manual-sidebar'),
};
const state = {
    manuals: [], manual: null, tree: [], documents: [], node: null, page: 1,
    pdf: null, revisionId: null, loading: null, render: null, textLayer: null,
    selectionGeneration: 0, renderGeneration: 0, abort: null, open: new Set(),
    searchAbort: null,
    mode: matchMedia('(max-width: 900px)').matches ? 'width' : 'page', zoom: 1,
};

function element(tag, text, className) {
    const item = document.createElement(tag);
    if (text !== undefined) item.textContent = text;
    if (className) item.className = className;
    return item;
}

async function api(path, signal) {
    const response = await fetch(path, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal });
    if ([401, 419].includes(response.status) || response.redirected) {
        location.assign('/access');
        throw new Error('Session expired');
    }
    if (response.status === 403) {
        location.assign('/access');
        throw new Error('Access denied');
    }
    if (!response.ok) throw new Error('Library request failed');
    return response.json();
}

function showMessage(title, detail, retry) {
    ui.paper.hidden = true;
    ui.message.replaceChildren(element('span', '▤', 'document-symbol'), element('h3', title), element('p', detail));
    if (retry) {
        const button = element('button', 'Retry', 'view-button');
        button.addEventListener('click', retry, { once: true });
        ui.message.append(button);
        if (state.node?.revision?.asset_url) {
            const fallback = element('a', 'Open original PDF', 'quiet-link');
            fallback.href = state.node.revision.asset_url;
            fallback.target = '_blank';
            fallback.rel = 'noopener';
            ui.message.append(fallback);
        }
    }
    ui.message.hidden = false;
}

function reportFailure(error) {
    if (['AbortError', 'AbortException', 'RenderingCancelledException'].includes(error.name)) return;
    const context = { document: state.node?.id, revision: state.node?.revision?.id, page: state.page, route: location.pathname, error: error.name };
    // Error type and document IDs only; never include file paths, session/PIN data, or PDF contents.
    console.error('Policy library document failure', context);
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (token) fetch('/api/viewer-errors', {
        method: 'POST', credentials: 'same-origin', keepalive: true,
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, Accept: 'application/json' },
        body: JSON.stringify(context),
    }).catch(() => {});
    showMessage('This document could not be displayed.', 'Your place is saved. Try loading this page again.', () => selectDocument(state.node.id, state.page, false, true));
}

function setDrawer(open) {
    document.body.classList.toggle('drawer-open', open);
    byId('drawer-shade').hidden = !open;
    byId('menu-toggle').setAttribute('aria-expanded', String(matchMedia('(max-width: 900px)').matches ? open : !document.body.classList.contains('rail-collapsed')));
    if (matchMedia('(max-width: 900px)').matches) {
        ui.sidebar.inert = !open;
        if (open) { byId('drawer-close').focus(); revealSelectedDocument(); }
    }
}

function revealSelectedDocument() {
    const selected = [...ui.tree.querySelectorAll('.tree-document[aria-current="true"]')].find(button => button.getClientRects().length);
    if (!selected || !ui.tree.getClientRects().length) return;
    const container = ui.tree.getBoundingClientRect();
    const item = selected.getBoundingClientRect();
    if (item.top < container.top) ui.tree.scrollTop += item.top - container.top;
    else if (item.bottom > container.bottom) ui.tree.scrollTop += item.bottom - container.bottom;
}

function setRailView(view) {
    for (const name of ['contents', 'recent', 'results']) byId(`${name}-panel`).hidden = name !== view;
    for (const name of ['contents', 'recent']) byId(`${name}-toggle`).setAttribute('aria-pressed', String(name === view));
    if (view === 'contents') revealSelectedDocument();
}

function publicationDate(value) {
    return new Date(value).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function renderRecent() {
    const documents = recentlyPublished(state.documents);
    const items = documents.map(node => {
        const button = element('button', undefined, 'recent-document');
        button.type = 'button';
        button.append(element('span', node.short_title || node.title, 'result-title'), element('small', `Published ${publicationDate(node.revision.published_at)}`, 'result-location'));
        button.addEventListener('click', () => { selectDocument(node.id, 1); setRailView('contents'); setDrawer(false); byId('document-workspace').focus(); });
        return button;
    });
    byId('recent-documents').replaceChildren(...(items.length ? items : [element('p', 'Published documents with a recorded publication date appear here.', 'sidebar-message')]));
}

async function searchPages(event) {
    event.preventDefault();
    if (!state.manual) return;
    const query = byId('page-search').value.trim();
    if (query.length < 2) { byId('page-search').focus(); return; }
    state.searchAbort?.abort();
    const abort = new AbortController();
    state.searchAbort = abort;
    const manual = byId('search-all-manuals').checked ? null : state.manual.slug;
    const params = new URLSearchParams({ q: query });
    if (manual) params.set('manual', manual);
    setRailView('results');
    const results = byId('search-results');
    results.replaceChildren();
    results.setAttribute('aria-busy', 'true');
    byId('search-status').textContent = 'Searching published pages…';
    try {
        const data = await api(`/api/search?${params}`, abort.signal);
        if (abort.signal.aborted || state.searchAbort !== abort) return;
        byId('search-status').textContent = data.results.length ? `${data.has_more ? 'First ' : ''}${data.results.length} matching ${data.results.length === 1 ? 'page' : 'pages'}${data.has_more ? ' · Refine your search for more.' : ''}` : 'No matching pages. Try different words or search all manuals.';
        results.replaceChildren(...data.results.map(result => {
            const button = element('button', undefined, 'search-result');
            button.type = 'button';
            const page = result.printed_label ? `Page ${result.page} · Manual page ${result.printed_label}` : `Page ${result.page}`;
            button.append(element('small', [result.manual.name, ...result.path].join(' / '), 'result-location'), element('span', result.title, 'result-title'), element('span', page, 'result-page'), element('span', result.excerpt, 'result-excerpt'));
            button.addEventListener('click', async () => {
                setRailView('contents'); setDrawer(false);
                if (result.manual.slug !== state.manual?.slug) await loadManual(result.manual.slug, result.slug, result.page);
                else await selectDocument(result.node_id, result.page);
                byId('document-workspace').focus();
            });
            return button;
        }));
    } catch (error) {
        if (error.name !== 'AbortError') byId('search-status').textContent = 'Search could not be completed. Check your connection and try again.';
    } finally {
        if (state.searchAbort === abort) results.setAttribute('aria-busy', 'false');
    }
}

function manualButtons() {
    ui.manuals.replaceChildren(...state.manuals.map(manual => {
        const button = element('button', manual.type === 'sog' ? 'SOGs' : manual.name, 'manual-button');
        button.type = 'button';
        button.setAttribute('aria-pressed', String(manual.slug === state.manual?.slug));
        button.append(element('small', manual.type === 'medical' ? 'Medical protocols' : manual.type === 'sog' ? 'Fire operations' : 'Department manual'));
        button.addEventListener('click', () => loadManual(manual.slug));
        return button;
    }));
}

function renderTree(reveal = false) {
    const query = ui.search.value.trim().toLocaleLowerCase();
    const matches = node => node.title.toLocaleLowerCase().includes(query) || (node.children || []).some(matches);
    function branch(nodes, inheritedMatch = false) {
        const items = [];
        for (const node of nodes) {
            const ownMatch = inheritedMatch || (query && node.title.toLocaleLowerCase().includes(query));
            if (query && !ownMatch && !matches(node)) continue;
            if (node.revision) {
                const selected = String(node.id) === String(state.node?.id);
                const button = element('button', node.short_title || node.title, 'tree-document');
                button.type = 'button';
                button.dataset.node = node.id;
                button.setAttribute('aria-current', String(selected));
                button.addEventListener('click', () => { selectDocument(node.id, 1); setDrawer(false); byId('document-workspace').focus(); });
                items.push(button);
                if (selected) {
                    const pages = element('div', undefined, 'tree-pages');
                    pages.setAttribute('aria-label', `${node.title} pages`);
                    for (let number = 1; number <= node.revision.page_count; number++) {
                        const page = element('button', `Page ${number} of ${node.revision.page_count}`, 'tree-page');
                        page.type = 'button';
                        page.setAttribute('aria-current', number === state.page ? 'page' : 'false');
                        page.addEventListener('click', () => { selectDocument(node.id, number); setDrawer(false); byId('document-workspace').focus(); });
                        pages.append(page);
                    }
                    items.push(pages);
                }
            }
            const related = (!query || ownMatch) ? (node.metadata?.related_document_slugs || []).map(slug => state.documents.find(document => document.slug === slug)).filter(Boolean) : [];
            const childrenToShow = [...(node.children || []), ...related];
            if (childrenToShow.length) {
                const details = element('details', undefined, 'tree-group');
                details.dataset.node = node.id;
                details.open = Boolean(query || state.open.has(String(node.id)));
                details.append(element('summary', node.short_title || node.title));
                const children = element('div', undefined, 'tree-children');
                children.append(...branch(childrenToShow, ownMatch));
                details.append(children);
                details.addEventListener('toggle', () => {
                    if (!query) details.open ? state.open.add(String(node.id)) : state.open.delete(String(node.id));
                });
                items.push(details);
            }
        }
        return items;
    }
    const children = branch(state.tree);
    ui.tree.replaceChildren(...(children.length ? children : [element('p', query ? 'No matching titles. Try a different search.' : 'No published documents in this manual.', 'sidebar-message')]));
    ui.tree.setAttribute('aria-busy', 'false');
    if (reveal === true) revealSelectedDocument();
}

function expandSelected(nodes, target) {
    for (const node of nodes) {
        if (String(node.id) === String(target)) return true;
        if (expandSelected(node.children || [], target)) {
            state.open.add(String(node.id));
            return true;
        }
    }
    return false;
}

function updateControls() {
    byId('copy-link').disabled = !state.node;
    for (const button of document.querySelectorAll('[data-nav]')) {
        const direction = button.dataset.nav === 'next' ? 1 : -1;
        button.disabled = !state.node || !adjacentPage(state.documents, state.node.id, state.page, direction);
    }
    for (const label of document.querySelectorAll('[data-page-status]')) label.textContent = state.node ? `${state.page} / ${state.node.revision.page_count}` : '—';
    byId('fit-page').setAttribute('aria-pressed', String(state.mode === 'page'));
    byId('fit-width').setAttribute('aria-pressed', String(state.mode === 'width'));
    byId('zoom-value').value = `${Math.round(state.zoom * 100)}%`;
    byId('zoom-out').disabled = state.zoom <= 0.5;
    byId('zoom-in').disabled = state.zoom >= 3;
}

function writeLocation(push = true) {
    const url = new URL(location.href);
    url.searchParams.set('manual', state.manual.slug);
    if (state.node) {
        url.searchParams.set('node', state.node.slug || state.node.id);
        url.searchParams.set('page', state.page);
    } else { url.searchParams.delete('node'); url.searchParams.delete('page'); }
    if (push && url.href !== location.href) history.pushState({}, '', url);
    else if (!push) history.replaceState({}, '', url);
}

async function cancelRendering() {
    const generation = ++state.renderGeneration;
    state.textLayer?.cancel();
    state.textLayer = null;
    const render = state.render;
    state.render = null;
    if (render) { render.cancel(); await render.promise.catch(() => {}); }
    return generation;
}

async function renderPage() {
    if (!state.pdf || !state.node) return;
    const generation = await cancelRendering();
    if (generation !== state.renderGeneration || !state.pdf || !state.node) return;
    const pdf = state.pdf;
    const pageNumber = state.page;
    const page = await pdf.getPage(pageNumber);
    if (generation !== state.renderGeneration || pdf !== state.pdf) return;
    const base = page.getViewport({ scale: 1 });
    const padding = matchMedia('(max-width: 900px)').matches ? 18 : 52;
    const width = Math.max(100, ui.stage.clientWidth - padding);
    const height = Math.max(100, ui.stage.clientHeight - padding);
    const fit = state.mode === 'width' ? width / base.width : Math.min(width / base.width, height / base.height);
    const viewport = page.getViewport({ scale: fit * state.zoom });
    const dimensions = canvasDimensions(viewport.width, viewport.height, window.devicePixelRatio);
    ui.canvas.width = dimensions.width;
    ui.canvas.height = dimensions.height;
    ui.canvas.style.width = `${viewport.width}px`;
    ui.canvas.style.height = `${viewport.height}px`;
    ui.paper.style.width = `${viewport.width}px`;
    ui.paper.style.height = `${viewport.height}px`;
    ui.paper.style.setProperty('--scale-factor', viewport.scale);
    ui.paper.style.setProperty('--total-scale-factor', viewport.scale);
    ui.text.replaceChildren();
    const render = page.render({
        canvasContext: ui.canvas.getContext('2d', { alpha: false }),
        viewport, transform: [dimensions.density, 0, 0, dimensions.density, 0, 0],
        background: 'rgb(255,255,255)',
    });
    state.render = render;
    await render.promise;
    if (generation !== state.renderGeneration || pdf !== state.pdf) return;
    state.render = null;
    ui.paper.hidden = false;
    ui.message.hidden = true;
    const textLayer = new pdfEngine.TextLayer({ textContentSource: page.streamTextContent(), container: ui.text, viewport });
    state.textLayer = textLayer;
    await textLayer.render();
    if (generation !== state.renderGeneration) return;
    byId('page-announcement').textContent = `${state.node.title}, page ${pageNumber} of ${pdf.numPages}`;
    // Prime only neighboring page descriptions; this never allocates additional page canvases.
    for (const neighbor of [pageNumber - 1, pageNumber + 1]) {
        if (neighbor >= 1 && neighbor <= pdf.numPages) pdf.getPage(neighbor).catch(() => {});
    }
}

async function selectDocument(nodeId, pageNumber = 1, push = true, retry = false) {
    const selection = resolveSelection(state.documents, nodeId, pageNumber);
    if (!selection) { showMessage('Document unavailable', 'Choose another document from this manual.'); return; }
    const generation = ++state.selectionGeneration;
    state.node = selection.node;
    state.page = selection.page;
    byId('copy-status').textContent = '';
    byId('copy-link-value').hidden = true;
    expandSelected(state.tree, state.node.id);
    renderTree(true);
    writeLocation(push);
    updateControls();
    byId('document-title').textContent = state.node.title;
    byId('document-path').textContent = [state.manual.name, ...state.node.path].join(' / ');
    const revision = state.node.revision;
    const revisionParts = [];
    if (revision.version_label) revisionParts.push(revision.version_label);
    if (revision.revision_date) {
        const date = new Date(`${revision.revision_date.slice(0, 10)}T12:00:00`);
        if (!Number.isNaN(date.getTime())) revisionParts.push(`Updated ${date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`);
    }
    const pageMetadata = revision.pages?.find(item => item.page === state.page);
    if (pageMetadata?.printed_label) revisionParts.push(`Manual page ${pageMetadata.printed_label}`);
    byId('revision-info').textContent = revisionParts.join(' · ');
    ui.stage.scrollTop = 0;
    ui.stage.scrollLeft = 0;
    showMessage('Loading page…', `${state.node.title} · Page ${state.page}`);
    try {
        if (state.revisionId !== revision.id || retry) {
            showMessage('Loading document…', state.node.title);
            await cancelRendering();
            const previousLoad = state.loading;
            state.loading = null;
            state.pdf = null;
            state.revisionId = null;
            if (previousLoad) await previousLoad.destroy().catch(() => {});
            if (generation !== state.selectionGeneration) return;
            const engine = await loadPdfEngine();
            if (generation !== state.selectionGeneration) return;
            const loading = engine.getDocument({
                url: revision.asset_url, withCredentials: true,
                cMapUrl: '/vendor/policy-library/cmaps/', cMapPacked: true,
                standardFontDataUrl: '/vendor/policy-library/standard_fonts/',
                wasmUrl: '/vendor/policy-library/wasm/',
                rangeChunkSize: 256 * 1024,
                disableStream: true, disableAutoFetch: true, isEvalSupported: false, enableXfa: false,
            });
            state.loading = loading;
            const pdf = await loading.promise;
            if (generation !== state.selectionGeneration) { await loading.destroy(); return; }
            if (pdf.numPages !== revision.page_count) {
                const mismatch = new Error('Revision page count mismatch');
                mismatch.name = 'RevisionPageCountMismatch';
                throw mismatch;
            }
            state.pdf = pdf;
            state.revisionId = revision.id;
        }
        if (generation === state.selectionGeneration) await renderPage();
    } catch (error) { if (generation === state.selectionGeneration) reportFailure(error); }
}

async function loadManual(slug, selectedNode, pageNumber = 1, push = true) {
    state.searchAbort?.abort();
    setRailView('contents');
    state.abort?.abort();
    const abort = new AbortController();
    state.abort = abort;
    state.selectionGeneration++;
    await cancelRendering();
    if (abort.signal.aborted || state.abort !== abort) return;
    const manual = state.manuals.find(manual => manual.slug === slug) || state.manuals[0];
    state.manual = manual;
    state.node = null;
    state.tree = [];
    state.documents = [];
    state.open.clear();
    const previousLoad = state.loading;
    state.loading = null;
    state.pdf = null;
    state.revisionId = null;
    updateControls();
    if (!state.manual) { showMessage('No manuals are available yet.', 'The library administrator is preparing the documents.'); return; }
    manualButtons();
    ui.tree.replaceChildren(element('p', 'Loading manual…', 'sidebar-message'));
    ui.tree.setAttribute('aria-busy', 'true');
    ui.search.value = '';
    byId('document-title').textContent = manual.name;
    byId('document-path').textContent = manual.name;
    byId('revision-info').textContent = '';
    showMessage('Loading manual…', manual.name);
    try {
        if (previousLoad) await previousLoad.destroy().catch(() => {});
        if (abort.signal.aborted || state.abort !== abort) return;
        const data = await api(`/api/manuals/${encodeURIComponent(manual.slug)}/tree`, abort.signal);
        if (abort.signal.aborted || state.abort !== abort) return;
        state.tree = data.nodes;
        state.documents = flattenDocuments(data.nodes);
        renderRecent();
        state.open.clear();
        const requested = selectedNode ? resolveSelection(state.documents, selectedNode, pageNumber)?.node : state.documents[0];
        state.node = null;
        if (requested) await selectDocument(requested.id, pageNumber, push);
        else {
            renderTree(); updateControls(); writeLocation(push);
            showMessage(selectedNode ? 'Document unavailable' : 'No published documents in this manual.', 'Choose another manual or check back after publication.');
        }
    } catch (error) {
        if (error.name === 'AbortError' || abort.signal.aborted || state.abort !== abort) return;
        ui.tree.setAttribute('aria-busy', 'false');
        showMessage('The manual could not be loaded.', 'Try again when your connection is available.', () => loadManual(slug, selectedNode, pageNumber, false));
    }
}

function navigate(direction) {
    if (!state.node) return;
    const target = adjacentPage(state.documents, state.node.id, state.page, direction);
    if (target) selectDocument(target.node, target.page);
}

for (const button of document.querySelectorAll('[data-nav]')) button.addEventListener('click', () => navigate(button.dataset.nav === 'next' ? 1 : -1));
for (const [id, mode] of [['fit-page', 'page'], ['fit-width', 'width']]) byId(id).addEventListener('click', () => {
    state.mode = mode; state.zoom = 1; updateControls(); renderPage().catch(reportFailure);
});
for (const [id, delta] of [['zoom-in', 0.25], ['zoom-out', -0.25]]) byId(id).addEventListener('click', () => {
    state.zoom = Math.max(0.5, Math.min(3, state.zoom + delta)); updateControls(); renderPage().catch(reportFailure);
});
byId('focus-mode').addEventListener('click', () => {
    const focus = document.body.classList.toggle('is-focus');
    byId('focus-mode').setAttribute('aria-pressed', String(focus));
    byId('focus-mode').textContent = focus ? 'Exit focus' : 'Focus view';
});
byId('menu-toggle').addEventListener('click', () => {
    if (matchMedia('(max-width: 900px)').matches) setDrawer(!document.body.classList.contains('drawer-open'));
    else {
        const collapsed = document.body.classList.toggle('rail-collapsed');
        byId('menu-toggle').setAttribute('aria-expanded', String(!collapsed));
        if (!collapsed) revealSelectedDocument();
    }
});
for (const id of ['drawer-close', 'drawer-shade']) byId(id).addEventListener('click', () => { setDrawer(false); byId('menu-toggle').focus(); });
ui.search.addEventListener('input', () => renderTree());
byId('page-search-form').addEventListener('submit', searchPages);
byId('contents-toggle').addEventListener('click', () => setRailView('contents'));
byId('recent-toggle').addEventListener('click', () => setRailView('recent'));
byId('results-back').addEventListener('click', () => setRailView('contents'));
byId('copy-link').addEventListener('click', async () => {
    if (!state.node) return;
    const link = pageLink(location.href, state.manual.slug, state.node.slug || state.node.id, state.page);
    try {
        await navigator.clipboard.writeText(link);
        byId('copy-status').textContent = `Link copied for page ${state.page}.`;
        byId('copy-link-value').hidden = true;
    } catch {
        const input = byId('copy-link-value');
        input.value = link; input.hidden = false; input.focus(); input.select();
        byId('copy-status').textContent = 'Select and copy this page link.';
    }
});
document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && document.body.classList.contains('drawer-open')) { setDrawer(false); byId('menu-toggle').focus(); }
    if (event.key === 'Tab' && document.body.classList.contains('drawer-open')) {
        const items = [...ui.sidebar.querySelectorAll('button,input,summary,a')].filter(item => !item.disabled && item.getClientRects().length);
        const first = items[0], last = items.at(-1);
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    }
    if (event.target.closest('input,textarea,select,[contenteditable="true"],button,summary') || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
    if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') { event.preventDefault(); navigate(event.key === 'ArrowRight' ? 1 : -1); }
});
window.addEventListener('popstate', () => {
    const query = new URLSearchParams(location.search);
    if (query.get('manual') !== state.manual?.slug) loadManual(query.get('manual'), query.get('node'), query.get('page'), false);
    else selectDocument(query.get('node'), query.get('page'), false);
});
let resizeTimer;
new ResizeObserver(() => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => renderPage().catch(reportFailure), 120);
}).observe(ui.stage);
matchMedia('(max-width: 900px)').addEventListener('change', event => {
    if (event.matches) setDrawer(false);
    else { ui.sidebar.inert = false; setDrawer(false); }
});

async function start() {
    try {
        const data = await api('/api/manuals');
        state.manuals = data.manuals;
        if (data.can_manage && data.manage_url) { byId('manage-link').href = data.manage_url; byId('manage-link').hidden = false; }
        const query = new URLSearchParams(location.search);
        setDrawer(false);
        await loadManual(query.get('manual'), query.get('node'), query.get('page') || 1, false);
    } catch (error) { showMessage('The library could not be loaded.', 'Try again when your connection is available.', start); }
}
start();
