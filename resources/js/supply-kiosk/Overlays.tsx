// Things that sit on top of a screen: the cart bar, the toast, "Still there?" and the note to Captain 5.
import { useEffect, useRef, useState } from 'react';

export function CartBar({ count, onReview }: { count: number; onReview: () => void }) {
    if (count === 0) return null;
    return (
        <div className="k-cartbar" role="region" aria-label="Cart">
            <span className="k-cartbar-count" data-testid="cart-count">{count} {count === 1 ? 'item' : 'items'} in cart</span>
            <button type="button" className="k-cartbar-review" onClick={onReview}>Review</button>
        </div>
    );
}

export function Toast({ message }: { message: string | null }) {
    return <div className={`k-toast${message ? ' is-shown' : ''}`} role="status" aria-live="polite">{message ?? ''}</div>;
}

export function IdleDialog({ secondsLeft, onStay }: { secondsLeft: number; onStay: () => void }) {
    const stayRef = useRef<HTMLButtonElement>(null);
    useEffect(() => { stayRef.current?.focus({ preventScroll: true }); }, []);
    return (
        <div className="k-sheet-backdrop k-center">
            <div className="k-dialog" role="alertdialog" aria-modal="true" aria-labelledby="k-idle-title" aria-describedby="k-idle-text">
                <h2 id="k-idle-title" className="k-title">Still there?</h2>
                <p id="k-idle-text" className="k-sub">This order clears in {secondsLeft} s.</p>
                <button ref={stayRef} type="button" className="k-way" onClick={onStay}>Yes, keep going</button>
            </div>
        </div>
    );
}

interface NoteProps {
    initial: string;
    cartEmpty: boolean;
    onSave: (note: string) => void;
    onClose: () => void;
}

export const NOTE_MAX = 500;

export function NoteDialog({ initial, cartEmpty, onSave, onClose }: NoteProps) {
    const [text, setText] = useState(initial);
    const areaRef = useRef<HTMLTextAreaElement>(null);
    useEffect(() => {
        areaRef.current?.focus();
        const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);
    const ready = text.trim() !== '';
    return (
        <div className="k-sheet-backdrop" onClick={event => { if (event.target === event.currentTarget) onClose(); }}>
            <form className="k-sheet" role="dialog" aria-modal="true" aria-labelledby="k-note-title"
                onSubmit={event => { event.preventDefault(); if (ready) onSave(text.trim()); }}>
                <h2 id="k-note-title" className="k-sheet-title">Tell Captain 5 what you needed</h2>
                <p className="k-sub">{cartEmpty ? 'Captain 5 gets this note. Nothing is taken from the shelves.' : 'This note goes with your items.'}</p>
                <label className="k-field">
                    <span className="sr-only">What you needed</span>
                    <textarea ref={areaRef} rows={4} maxLength={NOTE_MAX} value={text} onChange={event => setText(event.target.value)}
                        placeholder="For example: 20g catheters, the shelf is empty" />
                </label>
                <div className="k-sheet-actions">
                    <button type="button" className="k-secondary" onClick={onClose}>Cancel</button>
                    <button type="submit" className="k-add" disabled={!ready}>{cartEmpty ? 'Send to Captain 5' : 'Add note'}</button>
                </div>
            </form>
        </div>
    );
}
