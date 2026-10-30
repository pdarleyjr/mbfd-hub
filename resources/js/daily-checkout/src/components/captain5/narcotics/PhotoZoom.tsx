import { useCallback, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react';
import * as Dialog from '@radix-ui/react-dialog';
import { Maximize2, X, ZoomIn, ZoomOut } from 'lucide-react';
import { BTN_SECONDARY } from '../ui';
import { ZOOM_LEVELS } from './logic';

/**
 * Full-screen photo viewer for Captain 5: Fit, zoom in and out, drag to move, double click to zoom.
 * It is its own Radix dialog, so Escape closes only the viewer (not the check sheet under it), focus is
 * trapped and returned, and the page behind does not scroll. It shows the sharp "full" size from the
 * authorized image route; nothing is downloaded or opened in another tab.
 */
export default function PhotoZoom({ src, alt, onClose }: { src: string; alt: string; onClose: () => void }) {
  const [level, setLevel] = useState(0);
  const stage = useRef<HTMLDivElement>(null);
  const drag = useRef<{ x: number; y: number; left: number; top: number } | null>(null);
  const zoom = ZOOM_LEVELS[level];

  const step = useCallback((delta: number) => setLevel((current) => Math.min(ZOOM_LEVELS.length - 1, Math.max(0, current + delta))), []);

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
    <Dialog.Root open onOpenChange={(next) => { if (!next) onClose(); }}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 z-[90] bg-hub-n-900/95" />
        <Dialog.Content
          data-hub-ui="2"
          data-testid="narcotics-zoom"
          aria-describedby={undefined}
          className="fixed inset-0 z-[91] flex flex-col font-hub"
        >
          <Dialog.Title className="sr-only">Photo viewer</Dialog.Title>
          <div className="flex flex-wrap items-center gap-2 border-b border-hub-glass-border bg-hub-n-0 px-3 py-2">
            <button type="button" className={`${BTN_SECONDARY} !min-w-11`} onClick={() => step(-1)} disabled={level === 0} aria-label="Zoom out"><ZoomOut className="h-5 w-5" aria-hidden="true" /></button>
            <span className="min-w-12 text-center text-sm font-semibold tabular-nums text-hub-ink" aria-live="polite">{Math.round(zoom * 100)}%</span>
            <button type="button" className={`${BTN_SECONDARY} !min-w-11`} onClick={() => step(1)} disabled={level === ZOOM_LEVELS.length - 1} aria-label="Zoom in"><ZoomIn className="h-5 w-5" aria-hidden="true" /></button>
            <button type="button" className={`${BTN_SECONDARY} gap-1.5`} onClick={() => setLevel(0)} disabled={level === 0} aria-label="Fit to screen"><Maximize2 className="h-4 w-4" aria-hidden="true" /><span>Fit</span></button>
            <Dialog.Close className={`${BTN_SECONDARY} ml-auto gap-1.5`} aria-label="Close photo"><X className="h-4 w-4" aria-hidden="true" /><span>Close</span></Dialog.Close>
          </div>
          <div
            ref={stage}
            className={`min-h-0 flex-1 overflow-auto overscroll-contain ${zoom > 1 ? 'block cursor-grab' : 'grid place-items-center'}`}
            style={{ touchAction: 'pan-x pan-y pinch-zoom' }}
            onPointerDown={onPointerDown}
            onPointerMove={onPointerMove}
            onPointerUp={onPointerUp}
            onPointerCancel={onPointerUp}
            onDoubleClick={() => setLevel((current) => (current === 0 ? 2 : 0))}
          >
            <img
              src={src}
              alt={alt}
              draggable={false}
              className="select-none"
              style={zoom === 1 ? { maxWidth: '100%', maxHeight: '100%', objectFit: 'contain', margin: 'auto' } : { width: `${zoom * 100}%`, maxWidth: 'none', maxHeight: 'none' }}
            />
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
