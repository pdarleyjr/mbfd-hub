import workerUrl from 'pdfjs-dist/legacy/build/pdf.worker.mjs?url';
import '../css/viewer.css';
import { adjacentPage, canvasDimensions, flattenDocuments } from './navigation.js';
import { currentEditionChanged, linkRectangle, pageLink, pdfDestinationPage, pdfUrlTarget, primaryEntries, primaryHierarchy, recentlyPublished, resolveLibrarySelection, subjectAliases } from './library-ui.js';
import { bodyTextSize, ownedPages, readingScale, readingSection } from './reader-model.js';

let pdfEngine;
let enginePromise;
function loadPdfEngine() {
    enginePromise ||= import('pdfjs-dist/legacy/build/pdf.mjs').then(engine => {
        engine.GlobalWorkerOptions.workerSrc = workerUrl;
        pdfEngine = engine;
        return engine;
    }).catch(() => {
        enginePromise = null;
        const error = new Error('PDF reader could not be loaded');
        error.name = 'PdfEngineLoadError';
        throw error;
    });
    return enginePromise;
}
const byId = id => document.getElementById(id);
const ui = {
    tree: byId('manual-tree'), manuals: byId('manual-select'), search: byId('title-search'),
    stage: byId('page-stage'), paper: byId('pdf-page'), canvas: byId('pdf-canvas'),
    text: byId('pdf-text'), links: byId('pdf-links'), message: byId('viewer-message'), sidebar: byId('manual-sidebar'),
    thumbnails: byId('page-thumbnails'), pageSidebar: byId('page-sidebar'),
    reading: byId('reading-page'),
};
const state = {
    manuals: [], manual: null, tree: [], documents: [], entries: [], entry: null, node: null, page: 1,
    pdf: null, revisionId: null, loading: null, render: null, textLayer: null,
    selectionGeneration: 0, renderGeneration: 0, abort: null, open: new Set(),
    searchAbort: null,
    editionId: null, editionCheck: null,
    mode: matchMedia('(max-width: 600px)').matches ? 'read' : 'width', zoom: 1,
    textSizes: new Map(),
    representation: 'pdf', readingPreference: null, readingData: null, readingZoom: 1,
};
try {
    const preference = JSON.parse(localStorage.getItem('policy-library.reader-view'));
    if (preference && ['width', 'page', 'actual', 'read'].includes(preference.mode) && Number.isFinite(preference.zoom)) {
        state.mode = preference.mode;
        state.zoom = Math.max(0.5, Math.min(3, preference.zoom));
    }
} catch { /* Private browsing can disable preference storage. */ }

function saveViewPreference() {
    try { localStorage.setItem('policy-library.reader-view', JSON.stringify({ mode: state.mode, zoom: state.zoom })); }
    catch { /* Reading remains available without local storage. */ }
}

function element(tag, text, className) {
    const item = document.createElement(tag);
    if (text !== undefined) item.textContent = text;
    if (className) item.className = className;
    return item;
}

async function api(path, signal) {
    const response = await fetch(path, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store', signal });
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
    ui.reading.hidden = true;
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
    showMessage('This document could not be displayed.', 'Your place is saved. Try loading this page again.',
        error.name === 'PdfEngineLoadError' ? () => location.reload() : () => selectDocument(state.entry?.slug || state.node.id, state.page, false, true));
}

function setDrawer(open) {
    if (open) setPagesDrawer(false);
    document.body.classList.toggle('drawer-open', open);
    byId('drawer-shade').hidden = !open && !document.body.classList.contains('pages-open');
    byId('focus-navigation').setAttribute('aria-expanded', String(open));
    byId('menu-toggle').setAttribute('aria-expanded', String(matchMedia('(max-width: 900px)').matches ? open : !document.body.classList.contains('rail-collapsed')));
    if (matchMedia('(max-width: 900px)').matches || document.body.classList.contains('is-focus')) {
        ui.sidebar.inert = !open;
        if (open) { byId('drawer-close').focus(); revealSelectedDocument(); }
    }
}

function pagesAreDrawer() {
    return matchMedia('(max-width: 1100px)').matches || document.body.classList.contains('is-focus');
}

function updatePanels() {
    const pagesOpen = pagesAreDrawer() ? document.body.classList.contains('pages-open') : !document.body.classList.contains('pages-collapsed');
    byId('pages-toggle').setAttribute('aria-expanded', String(pagesOpen));
    byId('focus-pages').setAttribute('aria-expanded', String(pagesOpen));
    ui.pageSidebar.inert = !pagesOpen;
    byId('drawer-shade').hidden = !document.body.classList.contains('drawer-open') && !document.body.classList.contains('pages-open');
    if (pagesOpen) {
        for (const button of thumbs.visible) if (!button.querySelector('canvas') && !thumbs.queue.includes(button)) thumbs.queue.push(button);
        renderThumbnailQueue(thumbs.generation);
    } else thumbs.render?.cancel();
}

function setPagesDrawer(open) {
    document.body.classList.toggle('pages-open', open);
    updatePanels();
    if (open) {
        byId('pages-close').focus();
        ui.thumbnails.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest' });
    }
}

function collapseContents() {
    if (matchMedia('(max-width: 900px)').matches || document.body.classList.contains('is-focus')) setDrawer(false);
    else {
        document.body.classList.add('rail-collapsed');
        ui.sidebar.inert = true;
        byId('menu-toggle').setAttribute('aria-expanded', 'false');
    }
    returnNavigationFocus();
}

const thumbs = { key: null, generation: 0, observer: null, queue: [], busy: false, render: null, visible: new Set() };
function resetThumbnails() {
    thumbs.generation++;
    thumbs.observer?.disconnect();
    thumbs.render?.cancel();
    thumbs.render = null;
    thumbs.key = null;
    thumbs.queue = [];
    thumbs.visible.clear();
    ui.thumbnails.replaceChildren();
}

function updateThumbnails() {
    if (!state.node || !state.pdf) return;
    const key = `${state.revisionId}:${state.entry?.slug || state.node.slug}`;
    if (thumbs.key !== key) {
        resetThumbnails();
        thumbs.key = key;
        const generation = thumbs.generation;
        byId('thumbnail-title').textContent = state.entry ? `${state.entry.id} · ${state.entry.title}` : state.node.title;
        const pages = ownedPages(state.node, state.entry);
        thumbs.observer = new IntersectionObserver(records => {
            for (const record of records) {
                const button = record.target;
                if (record.isIntersecting) {
                    thumbs.visible.add(button);
                    if (!button.querySelector('canvas') && !thumbs.queue.includes(button)) thumbs.queue.push(button);
                } else thumbs.visible.delete(button);
            }
            // Keep only the visible thumbnails plus a small nearby buffer in memory.
            const cached = [...ui.thumbnails.querySelectorAll('.thumbnail:has(canvas)')];
            for (const button of cached.filter(button => !thumbs.visible.has(button)).slice(0, Math.max(0, cached.length - 6))) button.querySelector('canvas')?.remove();
            renderThumbnailQueue(generation);
        }, { root: ui.thumbnails, rootMargin: '40px' });
        for (const page of pages) {
            const button = element('button', undefined, 'thumbnail');
            button.type = 'button';
            button.dataset.page = page;
            button.setAttribute('aria-label', `Open PDF page ${page}`);
            button.append(element('span', '▤', 'thumbnail-placeholder'), element('span', `Page ${page}`, 'thumbnail-label'));
            button.addEventListener('click', () => {
                const identity = state.entry?.slug || state.node.id;
                state.readingPreference = 'pdf'; state.representation = 'pdf';
                selectDocument(identity, page);
                if (pagesAreDrawer()) { setPagesDrawer(false); byId('document-workspace').focus(); }
            });
            ui.thumbnails.append(button);
            thumbs.observer.observe(button);
        }
    }
    for (const button of ui.thumbnails.children) button.setAttribute('aria-current', Number(button.dataset.page) === state.page ? 'page' : 'false');
}

async function renderThumbnailQueue(generation) {
    if (thumbs.busy || generation !== thumbs.generation || ui.pageSidebar.inert) return;
    thumbs.busy = true;
    const pdf = state.pdf;
    try {
        while (thumbs.queue.length && generation === thumbs.generation && pdf === state.pdf && !ui.pageSidebar.inert) {
            const button = thumbs.queue.shift();
            if (!button.isConnected || !thumbs.visible.has(button) || button.querySelector('canvas')) continue;
            // Main page always owns the high-resolution render; thumbnails are sequential and small.
            if (state.render) await state.render.promise.catch(() => {});
            if (generation !== thumbs.generation || pdf !== state.pdf) break;
            const page = await pdf.getPage(Number(button.dataset.page));
            if (generation !== thumbs.generation || pdf !== state.pdf) break;
            const viewport = page.getViewport({ scale: 108 / page.getViewport({ scale: 1 }).width });
            const canvas = element('canvas');
            canvas.width = Math.ceil(viewport.width);
            canvas.height = Math.ceil(viewport.height);
            canvas.setAttribute('aria-hidden', 'true');
            const render = page.render({ canvasContext: canvas.getContext('2d', { alpha: false }), viewport });
            thumbs.render = render;
            await render.promise;
            if (generation !== thumbs.generation || pdf !== state.pdf || !button.isConnected) break;
            button.prepend(canvas);
            thumbs.render = null;
            await new Promise(resolve => setTimeout(resolve, 0));
        }
    } catch (error) {
        // A thumbnail failure does not block the source page or remove its page button.
        if (!['RenderingCancelledException', 'AbortException'].includes(error.name)) ui.thumbnails.dataset.previewStatus = 'Some previews unavailable';
    } finally {
        thumbs.busy = false;
        if (thumbs.queue.length && !ui.pageSidebar.inert) renderThumbnailQueue(thumbs.generation);
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
    try { await refreshEdition(); }
    catch { byId('search-status').textContent = 'Search could not be completed. Check your connection and try again.'; return; }
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
            button.append(element('small', [result.manual.name, ...result.path].join(' / '), 'result-location'), element('span', result.primary_id ? `${result.primary_id} · ${result.title}` : result.title, 'result-title'), element('span', page, 'result-page'), element('span', result.excerpt, 'result-excerpt'));
            if (result.subject_alias) {
                button.dataset.sourceRecord = result.subject_alias.source_record_id;
                button.append(element('small', `${result.subject_alias.legacy_id} · ${result.subject_alias.title} · Original manual pages ${result.subject_alias.source_pages.join(', ')}`, 'result-location'));
            }
            button.addEventListener('click', async () => {
                setRailView('contents'); setDrawer(false);
                if (result.manual.slug !== state.manual?.slug) await loadManual(result.manual.slug, result.primary_slug || result.slug, result.page);
                else await selectDocument(result.primary_slug || result.slug || result.node_id, result.page);
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
    if (state.entries.length) { renderPolicyTree(reveal); return; }
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

function openPolicy(node, page) {
    selectDocument(node, page);
    setDrawer(false);
    byId('document-workspace').focus();
}

function renderPolicyTree(reveal) {
    const query = ui.search.value.trim().toLocaleLowerCase();
    const roots = primaryHierarchy(state.entries);
    const textMatches = entry => `${entry.id} ${entry.title}`.toLocaleLowerCase().includes(query);
    const matches = entry => textMatches(entry) || entry.children.some(matches);
    const branch = (entries, inheritedMatch = false) => entries.flatMap(entry => {
        const ownMatch = inheritedMatch || (query && textMatches(entry));
        if (query && !ownMatch && !matches(entry)) return [];
        const selected = state.entry?.id === entry.id || (!state.entry && String(state.node?.id) === String(entry.node.id) && entry.id === entry.node.metadata?.asset_id);
        const button = element('button', `${entry.id} · ${entry.title}`, 'tree-document tree-primary');
        button.type = 'button';
        button.dataset.primary = entry.id;
        button.dataset.node = entry.node.id;
        button.setAttribute('aria-current', String(selected));
        button.addEventListener('click', () => openPolicy(entry.slug));
        const items = [button];
        if (entry.children.length) {
            const details = element('details', undefined, 'tree-group');
            details.open = Boolean(query || state.open.has(`entry-${entry.id}`));
            details.append(element('summary', 'Tools and companions'));
            const children = element('div', undefined, 'tree-children');
            children.append(...branch(entry.children, ownMatch));
            details.append(children);
            details.addEventListener('toggle', () => {
                if (!query) details.open ? state.open.add(`entry-${entry.id}`) : state.open.delete(`entry-${entry.id}`);
            });
            items.push(details);
        }
        return items;
    });
    const items = [];
    const sectionDocuments = state.documents.filter(item => /^SECTION-\d{3}$/.test(item.metadata?.asset_id || ''));
    const sections = [...new Set([...sectionDocuments.map(node => node.metadata.asset_id.slice(-3)), ...roots.map(readingSection).filter(Boolean)])].sort();
    for (const section of sections) {
        const node = sectionDocuments.find(item => item.metadata.asset_id === `SECTION-${section}`);
        const sectionNode = state.tree.find(item => item.slug === `section-${section}` || item.metadata?.section === section);
        const title = sectionNode?.title || node?.title || `Section ${section}`;
        const ownMatch = query && title.toLocaleLowerCase().includes(query);
        const entries = roots.filter(entry => readingSection(entry) === section);
        if (query && !ownMatch && !entries.some(matches)) continue;
        const details = element('details', undefined, 'tree-group');
        const key = `asset-${section}`;
        details.open = Boolean(query || state.open.has(key));
        details.append(element('summary', title));
        const children = element('div', undefined, 'tree-children');
        if (node) {
            const fullPdf = element('button', 'Full section PDF', 'tree-document');
            fullPdf.type = 'button'; fullPdf.dataset.node = node.id;
            fullPdf.setAttribute('aria-current', String(!state.entry && String(state.node?.id) === String(node.id)));
            fullPdf.addEventListener('click', () => openPolicy(node.id, 1));
            children.append(fullPdf);
        }
        children.append(...branch(entries, ownMatch));
        details.append(children);
        details.addEventListener('toggle', () => {
            if (!query) details.open ? state.open.add(key) : state.open.delete(key);
        });
        items.push(details);
    }
    items.push(...branch(roots.filter(entry => !readingSection(entry))));
    const aliases = subjectAliases(state.documents).filter(alias => !query ||
        `${alias.legacy_id} ${alias.source_title} ${alias.source_record_id}`.toLocaleLowerCase().includes(query) ||
        alias.resolved_current_targets.some(target => `${target.id} ${target.title}`.toLocaleLowerCase().includes(query)),
    );
    if (aliases.length) {
        const details = element('details', undefined, 'tree-group');
        details.open = Boolean(query || state.open.has('aliases'));
        details.append(element('summary', 'Historical subjects'));
        const children = element('div', undefined, 'tree-children');
        for (const alias of aliases) {
            const group = element('div', undefined, 'subject-alias');
            group.dataset.sourceRecord = alias.source_record_id;
            group.append(element('p', `${alias.legacy_id} · ${alias.source_title}`, 'alias-title'));
            group.append(element('small', `Original manual pages ${alias.source_pages.join(', ')}`, 'result-location'));
            for (const target of alias.resolved_current_targets) {
                const entry = state.entries.find(item => item.id === target.id);
                if (!entry) continue;
                const button = element('button', `${entry.id} · ${entry.title}`, 'tree-document');
                button.type = 'button'; button.dataset.aliasTarget = entry.id;
                button.addEventListener('click', () => openPolicy(entry.slug));
                group.append(button);
            }
            children.append(group);
        }
        details.append(children);
        details.addEventListener('toggle', () => { if (!query) details.open ? state.open.add('aliases') : state.open.delete('aliases'); });
        items.push(details);
    }
    ui.tree.replaceChildren(...(items.length ? items : [element('p', 'No matching titles. Try a different search.', 'sidebar-message')]));
    ui.tree.setAttribute('aria-busy', 'false');
    if (reveal === true) revealSelectedDocument();
}

function expandPolicySelection() {
    if (!state.entries.length || !state.node) return;
    const section = /^(?:SECTION-)?(\d{3})/.exec(state.node.metadata?.asset_id || '');
    if (section) state.open.add(`asset-${section[1]}`);
    let entry = state.entry || state.entries.find(item => item.id === state.node.metadata?.asset_id);
    while (entry?.parent) {
        state.open.add(`entry-${entry.parent}`);
        entry = state.entries.find(item => item.id === entry.parent);
    }
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
    byId('print-pdf').disabled = !state.node;
    byId('page-number').disabled = !state.node;
    byId('page-number').max = state.node?.revision.page_count || 1;
    byId('page-number').value = state.page;
    const download = byId('download-pdf');
    download.hidden = !state.node?.revision.download_url;
    if (!download.hidden) download.href = state.node.revision.download_url;
    for (const button of document.querySelectorAll('[data-nav]')) {
        const direction = button.dataset.nav === 'next' ? 1 : -1;
        button.disabled = !state.node || !adjacentPage(state.documents, state.node.id, state.page, direction);
    }
    for (const label of document.querySelectorAll('[data-page-status]')) label.textContent = state.node ? `${state.entry ? 'PDF ' : ''}${state.page} / ${state.node.revision.page_count}` : '—';
    byId('fit-page').setAttribute('aria-pressed', String(state.mode === 'page'));
    byId('fit-width').setAttribute('aria-pressed', String(state.mode === 'width'));
    byId('actual-size').setAttribute('aria-pressed', String(state.mode === 'actual'));
    byId('read-size').setAttribute('aria-pressed', String(state.mode === 'read'));
    byId('read-size-option').setAttribute('aria-pressed', String(state.mode === 'read'));
    document.body.classList.toggle('is-reading-size', state.mode === 'read');
    document.body.classList.toggle('is-text-reading', state.representation === 'reading');
    byId('reading-format').hidden = !state.node?.revision.reading_url;
    byId('reading-format').textContent = state.representation === 'reading' ? 'PDF view' : 'Reading text';
    byId('reading-format').setAttribute('aria-pressed', String(state.representation === 'reading'));
    byId('zoom-value').value = `${Math.round(state.zoom * 100)}%`;
    byId('zoom-out').disabled = state.zoom <= 0.5;
    byId('zoom-in').disabled = state.zoom >= 3;
    byId('read-zoom-out').disabled = !state.node || (state.representation === 'reading' ? state.readingZoom <= 1 : state.zoom <= 0.5);
    byId('read-zoom-in').disabled = !state.node || (state.representation === 'reading' ? state.readingZoom >= 1.5 : state.zoom >= 3);
    byId('read-size').disabled = !state.node;
}

function writeLocation(push = true) {
    const url = new URL(location.href);
    url.searchParams.set('manual', state.manual.slug);
    if (state.node) {
        url.searchParams.set('node', state.entry?.slug || state.node.slug || state.node.id);
        url.searchParams.set('page', state.page);
    } else { url.searchParams.delete('node'); url.searchParams.delete('page'); }
    if (push && url.href !== location.href) history.pushState({}, '', url);
    else if (!push) history.replaceState({}, '', url);
}

async function cancelRendering() {
    const generation = ++state.renderGeneration;
    state.textLayer?.cancel();
    state.textLayer = null;
    ui.links.replaceChildren();
    const render = state.render;
    state.render = null;
    if (render) { render.cancel(); await render.promise.catch(() => {}); }
    return generation;
}

async function renderLinks(page, viewport, pdf, generation) {
    const annotations = await page.getAnnotations({ intent: 'display' });
    if (generation !== state.renderGeneration || pdf !== state.pdf) return;
    const links = [];
    for (const annotation of annotations) {
        if (annotation.subtype !== 'Link') continue;
        const rectangle = linkRectangle(viewport, annotation.rect);
        if (!rectangle || !rectangle.width || !rectangle.height) continue;
        let target;
        if (annotation.dest) {
            const number = await pdfDestinationPage(pdf, annotation.dest);
            if (!number) continue;
            const entry = typeof annotation.dest === 'string' ? state.entries.find(item => item.node.id === state.node.id && item.anchor === annotation.dest) : null;
            target = { node: entry?.slug || state.node.id, page: number, href: pageLink(location.href, state.manual.slug, entry?.slug || state.node.slug || state.node.id, number) };
        } else if (annotation.url) {
            target = pdfUrlTarget(annotation.unsafeUrl || annotation.url, location.href);
            if (!target) continue;
            if (target.assetId) {
                const node = state.documents.find(item => item.metadata?.asset_id === target.assetId);
                if (node && target.page <= node.revision.page_count) target = { ...target, node: node.id, href: pageLink(location.href, state.manual.slug, node.slug || node.id, target.page) };
            }
        } else continue;
        if (generation !== state.renderGeneration || pdf !== state.pdf) return;
        const link = element('a', undefined, 'pdf-link');
        link.href = target.href;
        link.dataset.annotation = annotation.id;
        link.setAttribute('aria-label', annotation.overlaidText || annotation.contentsObj?.str || (target.node ? `Go to page ${target.page}` : `Open ${new URL(target.href).hostname}`));
        for (const [name, value] of Object.entries(rectangle)) link.style[name] = `${value}px`;
        if (target.node) link.addEventListener('click', event => {
            if (event.button !== 0 || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
            event.preventDefault();
            if (generation === state.renderGeneration) openPolicy(target.node, target.page);
        });
        else { link.target = '_blank'; link.rel = 'noopener noreferrer'; }
        links.push(link);
    }
    if (generation === state.renderGeneration && pdf === state.pdf) ui.links.replaceChildren(...links);
}

async function renderReading(generation, pdf) {
    const revision = state.node.revision;
    if (state.readingData?.revision_id !== revision.id) state.readingData = await api(revision.reading_url);
    if (generation !== state.renderGeneration || pdf !== state.pdf) return;
    if (state.readingData.revision_id !== revision.id) throw new Error('Reading revision mismatch');
    const identity = state.entry?.id || state.node.metadata?.asset_id;
    const entry = state.readingData.entries.find(entry => entry.id === identity);
    if (!entry) throw new Error('Reading identity unavailable');
    const allowed = ownedPages(state.node, state.entry);
    const blocks = entry.blocks.filter(block => allowed.includes(block.pdf_page));
    if (!blocks.length) throw new Error('Reading text unavailable');
    const items = [element('h2', entry.title), element('p', 'Original PDF available for tables, figures and forms.', 'reading-note')];
    if (state.readingData.cover?.length) {
        const cover = element('div', undefined, 'reading-cover');
        for (const line of state.readingData.cover) cover.append(element('p', line));
        const original = element('button', 'View original cover', 'source-page-link');
        original.type = 'button';
        original.addEventListener('click', () => {
            state.readingPreference = 'pdf'; state.representation = 'pdf';
            selectDocument(state.node.id, state.readingData.cover_pdf_page);
        });
        cover.append(original); items.push(cover);
    }
    let group, page;
    for (const block of blocks) {
        if (page !== block.pdf_page) {
            page = block.pdf_page;
            group = element('section', undefined, 'reading-source-page');
            group.dataset.pdfPage = page;
            const original = element('button', `Original PDF · page ${page}`, 'source-page-link');
            original.type = 'button';
            original.addEventListener('click', () => {
                state.readingPreference = 'pdf'; state.representation = 'pdf';
                selectDocument(state.entry?.slug || state.node.id, block.pdf_page);
            });
            group.append(original); items.push(group);
        }
        let item;
        if (block.type === 'table') {
            item = element('div', undefined, 'reading-table-scroll');
            item.tabIndex = 0;
            item.setAttribute('role', 'region');
            item.setAttribute('aria-label', `Original table text, PDF page ${block.pdf_page}. Scroll horizontally if needed.`);
            const table = element('table');
            for (const row of block.rows) {
                const tr = element('tr');
                for (const cell of row) {
                    const td = element(cell.header ? 'th' : 'td', cell.text);
                    td.colSpan = cell.col_span; td.rowSpan = cell.row_span;
                    if (cell.header) td.scope = 'col';
                    td.dataset.pdfPage = cell.pdf_page;
                    tr.append(td);
                }
                table.append(tr);
            }
            item.append(table);
        } else item = element(block.type === 'heading' ? 'h3' : 'p', block.text, block.type === 'pdf_reference' ? 'reading-pdf-reference' : 'reading-block');
        item.dataset.pdfPage = block.pdf_page;
        group.append(item);
        if (block.type === 'pdf_reference') {
            const original = element('button', `View original PDF page ${block.pdf_page}`, 'view-button');
            original.type = 'button';
            original.addEventListener('click', () => {
                state.readingPreference = 'pdf'; state.representation = 'pdf';
                selectDocument(state.entry?.slug || state.node.id, block.pdf_page);
            });
            group.append(original);
        }
    }
    ui.reading.replaceChildren(...items);
    ui.reading.style.fontSize = `${18 * state.readingZoom}px`;
    ui.reading.hidden = false; ui.paper.hidden = true; ui.message.hidden = true;
    byId('pan-left').hidden = true; byId('pan-right').hidden = true;
    byId('pan-hint').textContent = 'Text size';
    updateThumbnails();
    const selected = [...ui.reading.querySelectorAll('.reading-source-page')].find(group => Number(group.dataset.pdfPage) === state.page);
    if (selected) ui.stage.scrollTop += selected.getBoundingClientRect().top - ui.stage.getBoundingClientRect().top - 8;
    else ui.stage.scrollTop = 0;
    ui.stage.scrollLeft = 0;
    byId('page-announcement').textContent = `${entry.id} · ${entry.title}, reading text. Original PDF page links are available.`;
}

async function renderPage() {
    if (!state.pdf || !state.node) return;
    const generation = await cancelRendering();
    if (generation !== state.renderGeneration || !state.pdf || !state.node) return;
    const pdf = state.pdf;
    if (state.representation === 'reading' && state.node.revision.reading_url) {
        await renderReading(generation, pdf);
        return;
    }
    ui.reading.hidden = true;
    const pageNumber = state.page;
    const page = await pdf.getPage(pageNumber);
    if (generation !== state.renderGeneration || pdf !== state.pdf) return;
    const base = page.getViewport({ scale: 1 });
    const stageStyle = getComputedStyle(ui.stage);
    const width = Math.max(100, ui.stage.clientWidth - parseFloat(stageStyle.paddingLeft) - parseFloat(stageStyle.paddingRight) - 2);
    const height = Math.max(100, ui.stage.clientHeight - parseFloat(stageStyle.paddingTop) - parseFloat(stageStyle.paddingBottom) - 2);
    let textSize = state.textSizes.get(pageNumber);
    if (state.mode === 'read' && !textSize) {
        const content = await page.getTextContent();
        if (generation !== state.renderGeneration || pdf !== state.pdf) return;
        textSize = bodyTextSize(content.items);
        state.textSizes.set(pageNumber, textSize);
    }
    const fit = state.mode === 'read' ? readingScale(width / base.width, textSize) : state.mode === 'actual' ? 96 / 72 : state.mode === 'width' ? width / base.width : Math.min(width / base.width, height / base.height);
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
    ui.paper.dataset.bodyTextPx = textSize ? String(textSize * viewport.scale) : '';
    updateThumbnails();
    const canPan = viewport.width > width + 2;
    byId('pan-hint').textContent = canPan ? 'Swipe or pan to read' : 'Scroll to read';
    byId('pan-left').hidden = !canPan;
    byId('pan-right').hidden = !canPan;
    try { await renderLinks(page, viewport, pdf, generation); }
    catch (error) { if (generation === state.renderGeneration && pdf === state.pdf) throw error; }
    if (generation !== state.renderGeneration) return;
    byId('page-announcement').textContent = `${state.entry ? `${state.entry.id} · ${state.entry.title}, PDF` : `${state.node.title},`} page ${pageNumber} of ${pdf.numPages}`;
    // Prime only neighboring page descriptions; this never allocates additional page canvases.
    for (const neighbor of [pageNumber - 1, pageNumber + 1]) {
        if (neighbor >= 1 && neighbor <= pdf.numPages) pdf.getPage(neighbor).catch(() => {});
    }
}

async function selectDocument(nodeId, pageNumber, push = true, retry = false) {
    const requestedManual = state.manual?.slug;
    const previous = resolveLibrarySelection(state.documents, nodeId, pageNumber);
    const requestedNode = previous?.entry?.slug || previous?.node.slug || nodeId;
    let changed;
    try { changed = await refreshEdition(); }
    catch {
        showMessage('The manual could not be loaded.', 'Try again when your connection is available.', () => selectDocument(requestedNode, pageNumber, push, retry));
        return;
    }
    if (state.manual?.slug !== requestedManual) return;
    if (changed && previous?.entry) pageNumber = undefined;
    return selectCurrentDocument(requestedNode, pageNumber, push, retry);
}

async function selectCurrentDocument(nodeId, pageNumber, push = true, retry = false) {
    const selection = resolveLibrarySelection(state.documents, nodeId, pageNumber);
    if (!selection) { showMessage('Document unavailable', 'Choose another document from this manual.'); return; }
    const generation = ++state.selectionGeneration;
    state.node = selection.node;
    state.entry = selection.entry || null;
    state.page = selection.page;
    state.representation = state.node.revision.reading_url && (state.readingPreference === 'reading' || (state.readingPreference === null && matchMedia('(max-width: 600px)').matches)) ? 'reading' : 'pdf';
    byId('copy-status').textContent = '';
    byId('copy-link-value').hidden = true;
    expandSelected(state.tree, state.node.id);
    expandPolicySelection();
    renderTree(true);
    writeLocation(push);
    updateControls();
    byId('document-title').textContent = state.entry ? `${state.entry.id} · ${state.entry.title}` : state.node.title;
    byId('document-path').textContent = [state.manual.name, ...state.node.path].join(' / ');
    const revision = state.node.revision;
    const revisionParts = [];
    if (revision.version_label) revisionParts.push(revision.version_label);
    if (state.entry) {
        revisionParts.push(`Entry at PDF page ${state.entry.physical_page}`);
        if (state.entry.semantic_pages?.length) revisionParts.push(`${state.entry.semantic_pages.length} content ${state.entry.semantic_pages.length === 1 ? 'page' : 'pages'}`);
    }
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
            // A newer selection can resume while render cancellation is awaited.
            // Do not clear or destroy the newer selection's document instance.
            if (generation !== state.selectionGeneration) return;
            const previousLoad = state.loading;
            state.loading = null;
            state.pdf = null;
            state.revisionId = null;
            state.textSizes.clear();
            state.readingData = null;
            resetThumbnails();
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

async function loadManual(slug, selectedNode, pageNumber, push = true) {
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
    state.entry = null;
    state.tree = [];
    state.documents = [];
    state.entries = [];
    state.editionId = null;
    state.open.clear();
    const previousLoad = state.loading;
    state.loading = null;
    state.pdf = null;
    state.revisionId = null;
    state.textSizes.clear();
    state.readingData = null;
    resetThumbnails();
    byId('thumbnail-title').textContent = 'Select a document';
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
        state.editionId = data.manual?.active_edition_id ?? manual.active_edition_id ?? null;
        state.documents = flattenDocuments(data.nodes);
        state.entries = primaryEntries(state.documents);
        renderRecent();
        state.open.clear();
        const requested = selectedNode ? resolveLibrarySelection(state.documents, selectedNode, pageNumber) : state.documents[0] ? { node: state.documents[0] } : null;
        state.node = null;
        if (requested) await selectCurrentDocument(requested.entry?.slug || requested.node.id, pageNumber, push);
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

async function refreshEdition() {
    const slug = state.manual?.slug;
    if (!slug || state.editionId === null) return false;
    if (state.editionCheck?.slug === slug) return state.editionCheck.promise;
    const promise = (async () => {
        const data = await api('/api/manuals');
        if (state.manual?.slug !== slug) return false;
        state.manuals = data.manuals;
        if (!currentEditionChanged(data.manuals, slug, state.editionId)) return false;
        const selected = state.entry?.slug || state.node?.slug;
        const page = state.entry ? undefined : state.page;
        await loadManual(slug, selected, page, false);
        // A retired identity must not leave an old PDF selected or blank the new catalog.
        if (!state.node && state.documents.length) await selectCurrentDocument(state.documents[0].id, 1, false);
        return true;
    })();
    const check = { slug, promise };
    state.editionCheck = check;
    try { return await promise; }
    finally { if (state.editionCheck === check) state.editionCheck = null; }
}

function refreshWhenVisible() {
    if (document.visibilityState !== 'visible') return;
    refreshEdition().catch(() => showMessage('The manual could not be loaded.', 'Try again when your connection is available.', refreshWhenVisible));
}

async function navigate(direction) {
    if (!state.node) return;
    const requestedManual = state.manual?.slug;
    try { await refreshEdition(); }
    catch { showMessage('The manual could not be loaded.', 'Try again when your connection is available.', () => navigate(direction)); return; }
    if (!state.node || state.manual?.slug !== requestedManual) return;
    if (state.entry) {
        const pages = ownedPages(state.node, state.entry);
        const next = pages[pages.indexOf(state.page) + direction];
        if (next !== undefined) { selectCurrentDocument(state.entry.slug, next); return; }
    }
    const target = adjacentPage(state.documents, state.node.id, state.page, direction);
    if (target) selectCurrentDocument(target.node, target.page);
}

for (const button of document.querySelectorAll('[data-nav]')) button.addEventListener('click', () => navigate(button.dataset.nav === 'next' ? 1 : -1));
for (const [id, mode] of [['fit-page', 'page'], ['fit-width', 'width'], ['actual-size', 'actual'], ['read-size', 'read'], ['read-size-option', 'read']]) byId(id).addEventListener('click', () => {
    state.mode = mode; state.zoom = 1; saveViewPreference(); updateControls(); renderPage().catch(reportFailure);
});
for (const [id, delta] of [['zoom-in', 0.25], ['zoom-out', -0.25], ['read-zoom-in', 0.25], ['read-zoom-out', -0.25]]) byId(id).addEventListener('click', () => {
    if (state.representation === 'reading' && id.startsWith('read-zoom-')) {
        state.readingZoom = Math.max(1, Math.min(1.5, state.readingZoom + delta));
        ui.reading.style.fontSize = `${18 * state.readingZoom}px`;
        updateControls(); return;
    }
    state.zoom = Math.max(0.5, Math.min(3, state.zoom + delta)); saveViewPreference(); updateControls(); renderPage().catch(reportFailure);
});
byId('reading-format').addEventListener('click', () => {
    state.representation = state.representation === 'reading' ? 'pdf' : 'reading';
    state.readingPreference = state.representation;
    updateControls(); renderPage().catch(reportFailure);
});
byId('focus-mode').addEventListener('click', () => {
    const focus = document.body.classList.toggle('is-focus');
    byId('focus-mode').setAttribute('aria-pressed', String(focus));
    byId('focus-mode').textContent = focus ? 'Exit focus' : 'Focus view';
    setDrawer(false);
    setPagesDrawer(false);
    updatePanels();
});
byId('focus-navigation').addEventListener('click', () => setDrawer(!document.body.classList.contains('drawer-open')));
byId('page-jump').addEventListener('submit', event => {
    event.preventDefault();
    if (!state.node) return;
    const page = Number(byId('page-number').value);
    if (!Number.isInteger(page) || page < 1 || page > state.node.revision.page_count) return;
    byId('reader-options').open = false;
    selectDocument(state.entry && ownedPages(state.node, state.entry).includes(page) ? state.entry.slug : state.node.id, page);
});
byId('print-pdf').addEventListener('click', async () => {
    if (!state.node) return;
    // Reserve a browser-owned PDF tab during the user gesture; its native viewer owns printing.
    const popup = window.open('about:blank', '_blank');
    if (!popup) { byId('copy-status').textContent = 'Allow a new PDF tab to print this document.'; return; }
    popup.opener = null;
    try {
        await refreshEdition();
        if (!state.node) { popup.close(); return; }
        popup.location.replace(`${state.node.revision.asset_url}#page=${state.page}`);
    } catch { popup.close(); byId('copy-status').textContent = 'Check your connection and try again.'; }
});
byId('fullscreen-document').hidden = !document.fullscreenEnabled;
byId('fullscreen-document').addEventListener('click', async () => {
    try {
        if (document.fullscreenElement) await document.exitFullscreen();
        else await document.documentElement.requestFullscreen();
    } catch { byId('copy-status').textContent = 'Full screen is unavailable. Use Focus view.'; }
});
document.addEventListener('fullscreenchange', () => {
    byId('fullscreen-document').textContent = document.fullscreenElement ? 'Exit full screen' : 'Full screen';
});
byId('menu-toggle').addEventListener('click', () => {
    if (matchMedia('(max-width: 900px)').matches) setDrawer(!document.body.classList.contains('drawer-open'));
    else {
        const collapsed = document.body.classList.toggle('rail-collapsed');
        ui.sidebar.inert = collapsed;
        byId('menu-toggle').setAttribute('aria-expanded', String(!collapsed));
        if (!collapsed) revealSelectedDocument();
    }
});
function returnNavigationFocus() {
    byId(document.body.classList.contains('is-focus') ? 'focus-navigation' : 'menu-toggle').focus();
}
function returnPagesFocus() {
    byId(document.body.classList.contains('is-focus') ? 'focus-pages' : 'pages-toggle').focus();
}
byId('drawer-close').addEventListener('click', collapseContents);
byId('drawer-shade').addEventListener('click', () => {
    const pages = document.body.classList.contains('pages-open');
    setDrawer(false); setPagesDrawer(false);
    if (pages) returnPagesFocus(); else returnNavigationFocus();
});
function togglePages() {
    if (pagesAreDrawer()) { const open = !document.body.classList.contains('pages-open'); setDrawer(false); setPagesDrawer(open); }
    else { document.body.classList.toggle('pages-collapsed'); updatePanels(); }
}
byId('pages-toggle').addEventListener('click', togglePages);
byId('focus-pages').addEventListener('click', togglePages);
byId('pages-close').addEventListener('click', () => {
    if (pagesAreDrawer()) setPagesDrawer(false);
    else { document.body.classList.add('pages-collapsed'); updatePanels(); }
    returnPagesFocus();
});
for (const [id, direction] of [['pan-left', -1], ['pan-right', 1]]) byId(id).addEventListener('click', () => {
    ui.stage.scrollBy({ left: direction * ui.stage.clientWidth * 0.75, behavior: 'auto' });
});
ui.search.addEventListener('input', () => renderTree());
byId('page-search-form').addEventListener('submit', searchPages);
byId('contents-toggle').addEventListener('click', () => setRailView('contents'));
byId('recent-toggle').addEventListener('click', () => setRailView('recent'));
byId('results-back').addEventListener('click', () => setRailView('contents'));
byId('copy-link').addEventListener('click', async () => {
    if (!state.node) return;
    try { await refreshEdition(); }
    catch { byId('copy-status').textContent = 'Check your connection and try again.'; return; }
    if (!state.node) return;
    const link = pageLink(location.href, state.manual.slug, state.entry?.slug || state.node.slug || state.node.id, state.page);
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
byId('download-pdf').addEventListener('click', async event => {
    if (event.button !== 0 || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
    event.preventDefault();
    try {
        await refreshEdition();
        if (state.node?.revision.download_url) location.assign(state.node.revision.download_url);
    } catch { showMessage('The manual could not be loaded.', 'Try again when your connection is available.', refreshWhenVisible); }
});
document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        for (const id of ['reader-options', 'document-actions']) {
            const menu = byId(id);
            if (menu.open) { menu.open = false; menu.querySelector('summary').focus(); }
        }
    }
    if (event.key === 'Escape' && document.body.classList.contains('drawer-open')) { setDrawer(false); returnNavigationFocus(); }
    if (event.key === 'Escape' && document.body.classList.contains('pages-open')) { setPagesDrawer(false); returnPagesFocus(); }
    if (event.key === 'Tab' && (document.body.classList.contains('drawer-open') || document.body.classList.contains('pages-open'))) {
        const panel = document.body.classList.contains('pages-open') ? ui.pageSidebar : ui.sidebar;
        const items = [...panel.querySelectorAll('button,input,summary,a')].filter(item => !item.disabled && item.getClientRects().length);
        const first = items[0], last = items.at(-1);
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    }
    if (event.target.closest('input,textarea,select,[contenteditable="true"],button,summary,a') || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
    if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') { event.preventDefault(); navigate(event.key === 'ArrowRight' ? 1 : -1); }
});
window.addEventListener('popstate', () => {
    const query = new URLSearchParams(location.search);
    if (query.get('manual') !== state.manual?.slug) loadManual(query.get('manual'), query.get('node'), query.get('page'), false);
    else selectDocument(query.get('node'), query.get('page'), false);
});
window.addEventListener('focus', refreshWhenVisible);
document.addEventListener('visibilitychange', refreshWhenVisible);
let resizeTimer;
new ResizeObserver(() => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => renderPage().catch(reportFailure), 120);
}).observe(ui.stage);
matchMedia('(max-width: 900px)').addEventListener('change', event => {
    if (event.matches) setDrawer(false);
    else { ui.sidebar.inert = false; setDrawer(false); }
});
matchMedia('(max-width: 1100px)').addEventListener('change', () => { setPagesDrawer(false); updatePanels(); });

async function start() {
    try {
        const data = await api('/api/manuals');
        state.manuals = data.manuals;
        if (data.can_manage && data.manage_url) { byId('manage-link').href = data.manage_url; byId('manage-link').hidden = false; }
        const query = new URLSearchParams(location.search);
        setDrawer(false);
        updatePanels();
        await loadManual(query.get('manual'), query.get('node'), query.get('page'), false);
    } catch (error) { showMessage('The library could not be loaded.', 'Try again when your connection is available.', start); }
}
start();
