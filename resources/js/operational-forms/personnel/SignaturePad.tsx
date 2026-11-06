import { forwardRef, useCallback, useEffect, useImperativeHandle, useRef, useState } from 'react';
import { Button } from '@fluentui/react-components';
import { Eraser } from 'lucide-react';
import { signatureExportSize } from './personnelLogic.js';

export interface SignaturePadHandle {
    /** A PNG data URL of the drawn signature, or null when nothing is drawn. */
    toDataUrl: () => string | null;
    clear: () => void;
}

/**
 * A drawn signature: finger, stylus or mouse (Pointer Events), crisp on high-density screens, cleared with one
 * button. The exported image fits the server's limit and has a white background.
 */
export const SignaturePad = forwardRef<SignaturePadHandle, { label: string; disabled?: boolean; onChange: (signed: boolean) => void; describedBy?: string }>(
    function SignaturePad({ label, disabled, onChange, describedBy }, ref) {
        const canvasRef = useRef<HTMLCanvasElement>(null);
        const drawing = useRef(false);
        const last = useRef<{ x: number; y: number } | null>(null);
        const [signed, setSigned] = useState(false);
        const signedRef = useRef(false);

        const setup = useCallback(() => {
            const canvas = canvasRef.current;
            if (!canvas) return;
            const ratio = Math.min(window.devicePixelRatio || 1, 3);
            const { width, height } = canvas.getBoundingClientRect();
            if (width === 0 || height === 0) return;
            const previous = signedRef.current ? canvas.toDataURL('image/png') : null;
            canvas.width = Math.round(width * ratio);
            canvas.height = Math.round(height * ratio);
            const context = canvas.getContext('2d');
            if (!context) return;
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            context.lineCap = 'round';
            context.lineJoin = 'round';
            context.lineWidth = 2.4;
            context.strokeStyle = '#0b1f3a';
            if (previous) {
                const image = new Image();
                image.onload = () => context.drawImage(image, 0, 0, width, height);
                image.src = previous;
            }
        }, []);

        useEffect(() => {
            setup();
            const observer = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(() => setup()) : null;
            if (observer && canvasRef.current) observer.observe(canvasRef.current);
            return () => observer?.disconnect();
        }, [setup]);

        const point = (event: React.PointerEvent<HTMLCanvasElement>) => {
            const rect = event.currentTarget.getBoundingClientRect();
            return { x: event.clientX - rect.left, y: event.clientY - rect.top };
        };

        const markSigned = () => {
            if (signedRef.current) return;
            signedRef.current = true;
            setSigned(true);
            onChange(true);
        };

        const start = (event: React.PointerEvent<HTMLCanvasElement>) => {
            if (disabled) return;
            event.preventDefault();
            event.currentTarget.setPointerCapture(event.pointerId);
            drawing.current = true;
            last.current = point(event);
            const context = event.currentTarget.getContext('2d');
            if (context && last.current) {
                context.beginPath();
                context.arc(last.current.x, last.current.y, 1.2, 0, Math.PI * 2);
                context.fillStyle = '#0b1f3a';
                context.fill();
            }
        };

        const move = (event: React.PointerEvent<HTMLCanvasElement>) => {
            if (!drawing.current || disabled) return;
            event.preventDefault();
            const context = event.currentTarget.getContext('2d');
            const next = point(event);
            if (context && last.current) {
                context.beginPath();
                context.moveTo(last.current.x, last.current.y);
                context.lineTo(next.x, next.y);
                context.stroke();
                markSigned();
            }
            last.current = next;
        };

        const end = () => { drawing.current = false; last.current = null; };

        const clear = useCallback(() => {
            const canvas = canvasRef.current;
            const context = canvas?.getContext('2d');
            if (canvas && context) {
                context.save();
                context.setTransform(1, 0, 0, 1, 0, 0);
                context.clearRect(0, 0, canvas.width, canvas.height);
                context.restore();
            }
            signedRef.current = false;
            setSigned(false);
            onChange(false);
        }, [onChange]);

        useImperativeHandle(ref, () => ({
            clear,
            toDataUrl: () => {
                const canvas = canvasRef.current;
                if (!canvas || !signedRef.current) return null;
                const size = signatureExportSize(canvas.width, canvas.height);
                const out = document.createElement('canvas');
                out.width = size.width;
                out.height = size.height;
                const context = out.getContext('2d');
                if (!context) return null;
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, size.width, size.height);
                context.drawImage(canvas, 0, 0, size.width, size.height);
                return out.toDataURL('image/png');
            },
        }), [clear]);

        return <div className="pf-signature">
            <canvas
                ref={canvasRef} className="pf-signature-canvas" role="img" aria-label={`${label}. Draw your signature with a finger, stylus or mouse.`} aria-describedby={describedBy}
                onPointerDown={start} onPointerMove={move} onPointerUp={end} onPointerCancel={end} onPointerLeave={end}
            />
            <div className="pf-signature-foot">
                <span className="pf-signature-line">{signed ? 'Signed' : 'Sign above the line'}</span>
                <Button appearance="secondary" icon={<Eraser size={17} />} onClick={clear} disabled={disabled || !signed}>Clear signature</Button>
            </div>
        </div>;
    },
);
