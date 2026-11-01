/**
 * One in-page viewer for files that open inline (PDFs and images).
 *
 * Why it exists: in an installed app a link with target="_blank" or window.open escapes into
 * Safari/Chrome, and a raw PDF or image opened in the same window has no Back button on iOS.
 * Leaving the page would also lose a half-typed reply. So a link that carries
 * `data-hub-viewer` opens this dialog on the same page instead; nothing navigates.
 *
 *   <a href="/employee/files/12/pdf" data-hub-viewer="pdf" data-hub-viewer-title="Taskbook">...</a>
 *
 * data-hub-viewer           image | pdf | auto (auto: file extension, else the response content type)
 * data-hub-viewer-title     dialog title (default: the link text)
 * data-hub-viewer-download  optional URL for a Download link in the header
 *
 * Only same-origin http(s) links are handled, fetched with the member's own session, so
 * authorization is exactly what it was. Anything else (modifier click, other origin, mailto:)
 * falls through to the normal link. A native modal <dialog> gives Escape and the Android back
 * gesture (CloseWatcher) for free, so history is never touched.
 *
 * PDFs render with pdf.js through the lazy `loadPdfRenderer` module, so a page that never opens
 * a PDF never loads pdf.js.
 */
import { clearBusy, markBusy } from './busy.ts';

export type ViewerKind = 'image' | 'pdf';

export interface PdfRenderRequest {
    data: ArrayBuffer;
    container: HTMLElement;
    signal: AbortSignal;
    /** 1-based page to scroll to first (from a #page=N fragment). */
    page?: number;
}

export interface PdfRenderHandle {
    destroy(): void;
}

export interface PdfRendererModule {
    renderPdf(request: PdfRenderRequest): Promise<PdfRenderHandle>;
}

export interface DocumentViewerOptions {
    loadPdfRenderer?: () => Promise<PdfRendererModule>;
}

const BUSY_REASON = 'document-viewer';
const STARTED_FLAG = '__mbfdDocumentViewer';
const IMAGE_EXTENSIONS = new Set(['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'bmp', 'svg', 'heic']);

type ViewerWindow = Window & { __mbfdDocumentViewer?: () => void };

class ViewerError extends Error {}

export function pageFromHash(hash: string): number | undefined {
    const match = /(?:^#|&)page=(\d{1,5})(?:&|$)/.exec(hash);
    const page = match ? Number(match[1]) : 0;

    return page >= 1 ? page : undefined;
}

export function kindFromExtension(pathname: string): ViewerKind | null {
    const extension = /\.([a-z0-9]+)$/i.exec(pathname)?.[1]?.toLowerCase();
    if (!extension) return null;
    if (extension === 'pdf') return 'pdf';

    return IMAGE_EXTENSIONS.has(extension) ? 'image' : null;
}

export function kindFromContentType(contentType: string | null): ViewerKind | null {
    const type = (contentType ?? '').split(';')[0].trim().toLowerCase();
    if (type === 'application/pdf') return 'pdf';

    return type.startsWith('image/') ? 'image' : null;
}

function sameOriginUrl(href: string): URL | null {
    let url: URL;
    try {
        url = new URL(href, location.href);
    } catch {
        return null;
    }

    return (url.protocol === 'http:' || url.protocol === 'https:') && url.origin === location.origin ? url : null;
}

function failureMessage(status: number): string {
    if (status === 401 || status === 403) return 'You do not have access to this file, or your sign-in has expired.';
    if (status === 404 || status === 410) return 'This file is no longer available.';

    return 'The file could not be loaded. Check your connection and try again.';
}

function element<K extends keyof HTMLElementTagNameMap>(tag: K, className: string, text?: string): HTMLElementTagNameMap[K] {
    const node = document.createElement(tag);
    node.className = className;
    if (text !== undefined) node.textContent = text;

    return node;
}

/** What the page cannot show falls back to a plain same-window link to the file itself. */
function openFileLink(href: string): HTMLAnchorElement {
    const link = element('a', 'hub-viewer__open', 'Open file');
    link.href = href;

    return link;
}

interface Session {
    dialog: HTMLDialogElement;
    close(): void;
}

let sessionCounter = 0;
let openViewers = 0;

function openSession(
    opener: HTMLAnchorElement,
    url: URL,
    declaredKind: ViewerKind | 'auto',
    options: DocumentViewerOptions,
): Session {
    const title = (opener.dataset.hubViewerTitle || opener.textContent || '').replace(/\s+/g, ' ').trim() || 'Document';
    const downloadHref = opener.dataset.hubViewerDownload ? sameOriginUrl(opener.dataset.hubViewerDownload)?.href : undefined;
    const page = pageFromHash(url.hash);
    const fetchUrl = new URL(url.href);
    fetchUrl.hash = '';

    const dialog = element('dialog', 'hub-viewer');
    const heading = element('h2', 'hub-viewer__title', title);
    heading.id = `hub-viewer-title-${++sessionCounter}`;
    dialog.setAttribute('aria-labelledby', heading.id);

    const bar = element('header', 'hub-viewer__bar');
    const actions = element('div', 'hub-viewer__actions');
    if (downloadHref) {
        const download = element('a', 'hub-viewer__action', 'Download');
        download.href = downloadHref;
        download.setAttribute('download', '');
        actions.append(download);
    }
    const closeButton = element('button', 'hub-viewer__close', 'Close');
    closeButton.type = 'button';
    closeButton.setAttribute('aria-label', 'Close');
    actions.append(closeButton);
    bar.append(heading, actions);

    const body = element('div', 'hub-viewer__body');
    body.tabIndex = 0;
    body.setAttribute('aria-busy', 'true');
    const loading = element('p', 'hub-viewer__status', 'Loading file');
    loading.setAttribute('role', 'status');
    body.append(loading);

    dialog.append(bar, body);
    document.body.append(dialog);

    const abort = new AbortController();
    let objectUrl: string | null = null;
    let pdfHandle: PdfRenderHandle | null = null;
    let closed = false;

    const showFailure = (message: string) => {
        if (closed) return;
        body.replaceChildren();
        body.setAttribute('aria-busy', 'false');
        const notice = element('p', 'hub-viewer__status hub-viewer__status--error', message);
        notice.setAttribute('role', 'alert');
        body.append(notice, openFileLink(url.href));
    };

    const showImage = (blob: Blob) => {
        objectUrl = URL.createObjectURL(blob);
        const image = element('img', 'hub-viewer__image');
        image.alt = title;
        image.src = objectUrl;
        image.addEventListener('error', () => showFailure('This image could not be displayed.'), { once: true });
        body.replaceChildren(image);
        body.setAttribute('aria-busy', 'false');
    };

    const showPdf = async (response: Response) => {
        if (!options.loadPdfRenderer) throw new ViewerError('PDFs open as a separate file here.');
        let renderer: PdfRendererModule;
        try {
            renderer = await options.loadPdfRenderer();
        } catch {
            throw new ViewerError('The PDF viewer could not be loaded. Check your connection and try again.');
        }
        const data = await response.arrayBuffer();
        if (closed) return;
        const pages = element('div', 'hub-viewer__pages');
        body.replaceChildren(pages);
        try {
            const handle = await renderer.renderPdf({ data, container: pages, signal: abort.signal, page });
            if (closed) handle.destroy();
            else pdfHandle = handle;
        } catch {
            if (closed || abort.signal.aborted) return;
            throw new ViewerError('This PDF could not be displayed.');
        }
        body.setAttribute('aria-busy', 'false');
    };

    const load = async () => {
        let response: Response;
        try {
            response = await fetch(fetchUrl.href, { credentials: 'same-origin', signal: abort.signal });
        } catch {
            if (!abort.signal.aborted) showFailure(failureMessage(0));
            return;
        }
        if (closed) return;
        if (!response.ok) {
            showFailure(failureMessage(response.status));
            return;
        }

        const kind = declaredKind !== 'auto'
            ? declaredKind
            : kindFromExtension(fetchUrl.pathname) ?? kindFromContentType(response.headers.get('content-type'));
        try {
            if (kind === 'image') showImage(await response.blob());
            else if (kind === 'pdf') await showPdf(response);
            else showFailure('This kind of file cannot be shown here.');
        } catch (error) {
            if (closed || abort.signal.aborted) return;
            showFailure(error instanceof ViewerError ? error.message : failureMessage(0));
        }
    };

    const cleanup = () => {
        if (closed) return;
        closed = true;
        abort.abort();
        pdfHandle?.destroy();
        pdfHandle = null;
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = null;
        dialog.remove();
        // close fires after the next open: a viewer that replaced this one keeps the page marked busy.
        openViewers -= 1;
        if (openViewers === 0) clearBusy(BUSY_REASON);
        if (opener.isConnected) opener.focus({ preventScroll: true });
    };

    const close = () => {
        if (dialog.open) dialog.close();
        else cleanup();
    };

    closeButton.addEventListener('click', close);
    // The dialog fills the viewport on phones; a click that lands outside the panel's own box is the backdrop.
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const box = dialog.getBoundingClientRect();
        const outside = event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom;
        if (outside) close();
    });
    dialog.addEventListener('close', cleanup, { once: true });

    openViewers += 1;
    markBusy(BUSY_REASON);
    dialog.showModal();
    closeButton.focus({ preventScroll: true });
    void load();

    return { dialog, close };
}

/**
 * Opens a file in the viewer from script (for example a React button that has no link of its own).
 * Returns false when the viewer is not running on this page, so the caller can fall back to a
 * download rather than navigating away.
 */
export function openInDocumentViewer(
    href: string,
    { kind = 'auto', title, downloadUrl }: { kind?: ViewerKind | 'auto'; title?: string; downloadUrl?: string } = {},
): boolean {
    if (!(window as ViewerWindow)[STARTED_FLAG] || !sameOriginUrl(href)) return false;

    const link = element('a', 'hub-viewer__trigger');
    link.href = href;
    link.hidden = true;
    link.dataset.hubViewer = kind;
    if (title) link.dataset.hubViewerTitle = title;
    if (downloadUrl) link.dataset.hubViewerDownload = downloadUrl;
    document.body.append(link);
    link.click();
    link.remove();

    return true;
}

export function startDocumentViewer(options: DocumentViewerOptions = {}): () => void {
    const host = window as ViewerWindow;
    const running = host[STARTED_FLAG];
    if (running) return running;

    let current: Session | null = null;

    const onClick = (event: MouseEvent) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const target = event.target as Element | null;
        const link = target?.closest?.('a[data-hub-viewer]') as HTMLAnchorElement | null;
        if (!link || link.hasAttribute('download')) return;

        const url = sameOriginUrl(link.getAttribute('href') ?? '');
        if (!url) return;
        const declared = link.dataset.hubViewer;
        const kind: ViewerKind | 'auto' = declared === 'image' || declared === 'pdf' ? declared : 'auto';

        event.preventDefault();
        current?.close();
        const session = openSession(link, url, kind, options);
        current = session;
        session.dialog.addEventListener('close', () => {
            if (current === session) current = null;
        }, { once: true });
    };

    document.addEventListener('click', onClick);
    const stop = () => {
        document.removeEventListener('click', onClick);
        current?.close();
        current = null;
        delete host[STARTED_FLAG];
    };
    host[STARTED_FLAG] = stop;

    return stop;
}
