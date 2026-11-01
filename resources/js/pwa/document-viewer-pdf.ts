/**
 * PDF renderer for the document viewer. Reached only through a dynamic import, so pages that
 * never open a PDF do not download pdf.js. Same pdfjs-dist build and worker setup as
 * resources/js/operational-forms/PdfPreview.tsx.
 *
 * Every page becomes a canvas. Placeholders for all pages are laid out first (so a #page=N
 * link can scroll straight to its page), then pages render from the requested page onward and
 * wrap back to the start.
 */
import * as pdfjs from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import type { PdfRenderHandle, PdfRenderRequest } from './document-viewer.ts';

pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;

// Sharp enough on phones without holding a huge bitmap for every page of a long document.
const MAX_PIXEL_RATIO = 2;

export async function renderPdf({ data, container, signal, page }: PdfRenderRequest): Promise<PdfRenderHandle> {
    const task = pdfjs.getDocument({ data: new Uint8Array(data) });
    const pdf = await task.promise;
    let destroyed = false;
    const renders: Array<{ cancel(): void }> = [];

    const handle: PdfRenderHandle = {
        destroy() {
            if (destroyed) return;
            destroyed = true;
            renders.forEach((render) => render.cancel());
            void task.destroy();
        },
    };
    signal.addEventListener('abort', () => handle.destroy(), { once: true });

    try {
        const width = Math.max(container.clientWidth, 280);
        const slots: Array<{ canvas: HTMLCanvasElement; pdfPage: pdfjs.PDFPageProxy; scale: number }> = [];

        for (let number = 1; number <= pdf.numPages; number += 1) {
            const pdfPage = await pdf.getPage(number);
            if (destroyed) return handle;
            const natural = pdfPage.getViewport({ scale: 1 });
            const scale = (width / natural.width) * Math.min(window.devicePixelRatio || 1, MAX_PIXEL_RATIO);
            const viewport = pdfPage.getViewport({ scale });

            const slot = document.createElement('div');
            slot.className = 'hub-viewer__page';
            slot.style.aspectRatio = `${natural.width} / ${natural.height}`;
            const canvas = document.createElement('canvas');
            canvas.width = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);
            canvas.setAttribute('role', 'img');
            canvas.setAttribute('aria-label', `Page ${number} of ${pdf.numPages}`);
            slot.append(canvas);
            container.append(slot);
            slots.push({ canvas, pdfPage, scale });
        }

        const first = page && page <= slots.length ? page - 1 : 0;
        slots[first]?.canvas.parentElement?.scrollIntoView({ block: 'start' });

        const order = [...slots.slice(first), ...slots.slice(0, first)];
        for (const { canvas, pdfPage, scale } of order) {
            if (destroyed) break;
            const context = canvas.getContext('2d');
            if (!context) throw new Error('Canvas is not available.');
            const render = pdfPage.render({ canvasContext: context, viewport: pdfPage.getViewport({ scale }) });
            renders.push(render);
            await render.promise;
        }
    } catch (error) {
        handle.destroy();
        throw error;
    }

    return handle;
}
