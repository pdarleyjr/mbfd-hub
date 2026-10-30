import { useCallback, useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react';
import { Maximize2, X, ZoomIn, ZoomOut } from 'lucide-react';

const LEVELS = [1, 1.5, 2, 3, 4];

/**
 * Full-screen photo viewer: Fit, zoom in and out, drag to move, double tap to zoom, Escape to close.
 * The picture is the sharp "preview" or "full" size from the authorized image route; nothing is downloaded.
 */
export function PhotoViewer({ src, alt, onClose }: { src: string; alt: string; onClose: () => void }) {
    const [level, setLevel] = useState(0);
    const stage = useRef<HTMLDivElement>(null);
    const closeButton = useRef<HTMLButtonElement>(null);
    const drag = useRef<{ x: number; y: number; left: number; top: number } | null>(null);
    const zoom = LEVELS[level];

    useEffect(() => {
        const previous = document.activeElement as HTMLElement | null;
        closeButton.current?.focus();
        const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') { event.stopPropagation(); onClose(); } };
        document.addEventListener('keydown', onKey, true);
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => {
            document.removeEventListener('keydown', onKey, true);
            document.body.style.overflow = overflow;
            previous?.focus?.();
        };
    }, [onClose]);

    const step = useCallback((delta: number) => setLevel((current) => Math.min(LEVELS.length - 1, Math.max(0, current + delta))), []);

    const onPointerDown = (event: ReactPointerEvent<HTMLDivElement>) => {
        if (event.pointerType === 'touch' || zoom === 1 || !stage.current) return;
        drag.current = { x: event.clientX, y: event.clientY, left: stage.current.scrollLeft, top: stage.current.scrollTop };
        event.currentTarget.setPointerCapture(event.pointerId);
    };
    const onPointerMove = (event: ReactPointerEvent<HTMLDivElement>) => {
        if (!drag.current || !stage.current) return;
        stage.current.scrollLeft = drag.current.left - (event.clientX - drag.current.x);
        stage.current.scrollTop = drag.current.top - (event.clientY - drag.current.y);
    };
    const onPointerUp = () => { drag.current = null; };

    return (
        <div className="nc-viewer" role="dialog" aria-modal="true" aria-label="Photo viewer">
            <div className="nc-viewer-bar">
                <button type="button" className="nc-viewer-btn" onClick={() => step(-1)} disabled={level === 0} aria-label="Zoom out"><ZoomOut size={20} aria-hidden="true" /></button>
                <span className="nc-viewer-zoom" aria-live="polite">{Math.round(zoom * 100)}%</span>
                <button type="button" className="nc-viewer-btn" onClick={() => step(1)} disabled={level === LEVELS.length - 1} aria-label="Zoom in"><ZoomIn size={20} aria-hidden="true" /></button>
                <button type="button" className="nc-viewer-btn" onClick={() => setLevel(0)} disabled={level === 0} aria-label="Fit to screen"><Maximize2 size={20} aria-hidden="true" /><span>Fit</span></button>
                <button type="button" ref={closeButton} className="nc-viewer-btn nc-viewer-close" onClick={onClose} aria-label="Close photo"><X size={20} aria-hidden="true" /><span>Close</span></button>
            </div>
            <div
                ref={stage}
                className={`nc-viewer-stage${zoom > 1 ? ' is-zoomed' : ''}`}
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={onPointerUp}
                onPointerCancel={onPointerUp}
                onDoubleClick={() => setLevel((current) => (current === 0 ? 2 : 0))}
            >
                <img src={src} alt={alt} className="nc-viewer-img" style={zoom === 1 ? undefined : { width: `${zoom * 100}%`, maxWidth: 'none', maxHeight: 'none' }} draggable={false} />
            </div>
        </div>
    );
}
