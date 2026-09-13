import { useEffect, useRef, type ReactNode } from 'react';

export function ConferenceDialog({ title, onClose, children, drawer = false }: {
    title: string; onClose: () => void; children: ReactNode; drawer?: boolean;
}) {
    const ref = useRef<HTMLDialogElement>(null);
    useEffect(() => {
        const previous = document.activeElement as HTMLElement | null;
        ref.current?.showModal();
        return () => { ref.current?.close(); previous?.focus(); };
    }, []);
    return <dialog ref={ref} className={`vc-dialog ${drawer ? 'vc-dialog--drawer' : ''}`}
        aria-label={title} onCancel={(event) => { event.preventDefault(); onClose(); }}>
        <div className="vc-dialog__heading"><h2>{title}</h2><button type="button" autoFocus onClick={onClose} aria-label={`Close ${title}`}>Close</button></div>
        {children}
    </dialog>;
}
