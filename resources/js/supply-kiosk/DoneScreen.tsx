// Done: what was taken, who has a copy, Undo for 60 seconds, then back to the start on its own.
import { useState } from 'react';
import { useInterval, useNow } from './hooks.ts';
import type { Receipt } from './types.ts';

export const AUTO_RETURN_MS = 10_000;

interface Props {
    receipt: Receipt;
    /** null until Undo: then put back (200), never arrived (404), or waiting for the connection. */
    undone: 'put_back' | 'nothing_taken' | 'pending' | null;
    undoing: boolean;
    error: string | null;
    onUndo: () => void;
    onStart: () => void;
}

export function DoneScreen({ receipt, undone, undoing, error, onUndo, onStart }: Props) {
    const now = useNow(true);
    const [returnAt, setReturnAt] = useState(() => Date.now() + AUTO_RETURN_MS);
    useInterval(() => { if (!undoing && Date.now() >= returnAt) onStart(); }, 500);

    const undoLeft = Math.ceil((receipt.undoUntil - now) / 1000);
    const showUndo = undone === null && undoLeft > 0;
    const noteOnly = receipt.lines.length === 0;
    const heading = undone === 'put_back' ? 'Put back.'
        : undone === 'nothing_taken' ? 'Put back. Nothing was taken.'
        : undone === 'pending' ? 'Putting it back'
        : noteOnly ? 'Sent to Captain 5' : receipt.queued ? 'Done. Waiting to send.' : 'Done';
    const backIn = Math.max(0, Math.ceil((returnAt - now) / 1000));
    const copyLine = receipt.queued
        ? 'Saved on this screen. It goes to the Hub as soon as the connection is back.'
        : receipt.copy === 'sent' ? 'A copy went to your city email.' : 'Captain 5 has a copy.';

    return (
        <section className="k-screen k-done" aria-labelledby="k-done-title" onPointerDown={() => setReturnAt(Date.now() + AUTO_RETURN_MS)}>
            <div className={`k-done-mark${undone ? ' is-undone' : ''}`} aria-hidden="true">
                <svg viewBox="0 0 48 48" focusable="false">{undone ? <path d="M14 24h20" /> : <path d="M12 25l8 8 16-18" />}</svg>
            </div>
            <h1 id="k-done-title" className="k-title" aria-live="polite">{heading}</h1>
            <p className="k-hello">
                {receipt.employeeName}{receipt.unitLabel ? `, ${receipt.unitLabel}` : ''}{receipt.number ? ` (${receipt.number})` : ''}
            </p>
            {!undone && receipt.lines.length > 0 ? (
                <ul className="k-done-lines" aria-label="You took">
                    {receipt.lines.map((line, i) => (
                        <li key={`${line.name}-${i}`}><span className="k-done-qty">{line.quantity}</span> {line.unit_label} <strong>{line.name}</strong></li>
                    ))}
                </ul>
            ) : null}
            {!undone && receipt.note ? <p className="k-sub">Note: {receipt.note}</p> : null}
            {!undone ? <p className={receipt.queued ? 'k-queued' : 'k-copy'}>{copyLine}</p> : null}
            {undone === 'pending' ? (
                <p className="k-queued" data-testid="undo-pending">
                    No connection right now. This screen puts it back as soon as the connection is back. If the screen stays offline, tell Captain 5.
                </p>
            ) : null}
            {error ? <p className="k-error" role="alert">{error}</p> : null}
            <div className="k-done-actions">
                {showUndo ? (
                    <button type="button" className="k-undo" onClick={onUndo} disabled={undoing}>
                        {undoing ? 'Putting back' : `Undo (${undoLeft} s)`}
                    </button>
                ) : null}
                <button type="button" className="k-way" onClick={onStart}>Start now</button>
            </div>
            <p className="k-sub" aria-live="off">Back to the start in {backIn} s</p>
        </section>
    );
}
