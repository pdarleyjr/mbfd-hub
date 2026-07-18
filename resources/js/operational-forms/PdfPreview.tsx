import { useEffect, useRef, useState } from 'react';
import { Button, Dialog, DialogBody, DialogContent, DialogSurface, DialogTitle, DialogTrigger, Spinner } from '@fluentui/react-components';
import { ChevronLeft, ChevronRight, Printer, X, ZoomIn, ZoomOut } from 'lucide-react';
import * as pdfjs from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;

export function PdfPreview({ url, name, onClose }: { url: string; name: string; onClose: () => void }) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const [document, setDocument] = useState<any>();
    const [page, setPage] = useState(1);
    const [scale, setScale] = useState(1.1);
    const [error, setError] = useState('');

    useEffect(() => {
        let active = true;
        const task = pdfjs.getDocument({ url, withCredentials: true });
        task.promise.then((loaded) => active && setDocument(loaded)).catch(() => active && setError('The generated PDF could not be displayed.'));
        return () => { active = false; task.destroy(); };
    }, [url]);

    useEffect(() => {
        if (!document || !canvasRef.current) return;
        let cancelled = false;
        document.getPage(page).then((pdfPage: any) => {
            if (cancelled || !canvasRef.current) return;
            const viewport = pdfPage.getViewport({ scale });
            const canvas = canvasRef.current;
            const context = canvas.getContext('2d');
            if (!context) return;
            canvas.width = viewport.width;
            canvas.height = viewport.height;
            pdfPage.render({ canvasContext: context, viewport });
        });
        return () => { cancelled = true; };
    }, [document, page, scale]);

    const print = () => {
        const frame = window.open(url, '_blank', 'noopener,noreferrer');
        frame?.addEventListener('load', () => frame.print(), { once: true });
    };

    return (
        <Dialog open modalType="modal">
            <DialogSurface className="of-pdf-dialog">
                <DialogBody>
                        <DialogTitle action={<DialogTrigger disableButtonEnhancement><Button appearance="subtle" icon={<X size={18} />} aria-label="Close preview" onClick={onClose} /></DialogTrigger>}>{name}</DialogTitle>
                    <div className="of-preview-toolbar" aria-label="PDF controls">
                        <Button icon={<ChevronLeft size={17} />} disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>Previous</Button>
                        <span>Page {page} of {document?.numPages ?? '—'}</span>
                        <Button icon={<ChevronRight size={17} />} disabled={!document || page >= document.numPages} onClick={() => setPage((value) => value + 1)}>Next</Button>
                        <span className="of-toolbar-spacer" />
                        <Button appearance="subtle" icon={<ZoomOut size={17} />} aria-label="Zoom out" onClick={() => setScale((value) => Math.max(.6, value - .15))} />
                        <span>{Math.round(scale * 100)}%</span>
                        <Button appearance="subtle" icon={<ZoomIn size={17} />} aria-label="Zoom in" onClick={() => setScale((value) => Math.min(2, value + .15))} />
                        <Button icon={<Printer size={17} />} onClick={print}>Print generated PDF</Button>
                    </div>
                    <DialogContent className="of-preview-content">
                        {!document && !error && <Spinner label="Opening controlled PDF…" />}
                        {error && <p role="alert">{error}</p>}
                        <canvas ref={canvasRef} aria-label={`PDF page ${page}`} />
                    </DialogContent>
                </DialogBody>
            </DialogSurface>
        </Dialog>
    );
}
