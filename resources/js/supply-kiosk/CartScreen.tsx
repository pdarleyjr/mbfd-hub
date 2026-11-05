// Review: change amounts, units and the unit picked, remove lines, then one green Take button.
import { useState } from 'react';
import { unitWords } from './cart.ts';
import { UnitPicker } from './UnitScreen.tsx';
import type { Apparatus, CartLine, UnitChoice } from './types.ts';

interface Props {
    lines: CartLine[];
    unit: UnitChoice;
    units: Apparatus[];
    note: string;
    sending: boolean;
    error: string | null;
    onQuantity: (key: string, quantity: number) => void;
    onLineUnit: (key: string, unit: string) => void;
    onRemove: (key: string) => void;
    onUnit: (unit: UnitChoice) => void;
    onMore: () => void;
    onNote: () => void;
    onTake: () => void;
}

export function takeLabel(count: number): string {
    return count === 1 ? 'TAKE THIS 1 ITEM' : `TAKE THESE ${count} ITEMS`;
}

export function CartScreen(props: Props) {
    const { lines, unit, units, note, sending, error, onQuantity, onLineUnit, onRemove, onUnit, onMore, onNote, onTake } = props;
    const [pickingUnit, setPickingUnit] = useState(false);
    const empty = lines.length === 0;

    return (
        <section className="k-screen k-cart" aria-labelledby="k-cart-title">
            <h1 id="k-cart-title" className="k-title">{empty ? 'Your cart is empty' : 'Check your items'}</h1>

            {lines.length > 0 ? (
                <ul className="k-cart-lines" aria-label="Items in your cart">
                    {lines.map(line => (
                        <li key={line.key} className="k-cart-line">
                            <div className="k-cart-line-head">
                                <span className="k-row-name">{line.item.name}</span>
                                <span className="k-row-place">{line.item.location_label || line.item.location_code || 'Supply room'}</span>
                            </div>
                            <div className="k-cart-line-controls">
                                <div className="k-stepper k-stepper-small" role="group" aria-label={`Quantity of ${line.item.name}`}>
                                    <button type="button" className="k-step" aria-label={`One less ${line.item.name}`} disabled={line.quantity <= 1}
                                        onClick={() => onQuantity(line.key, line.quantity - 1)}>
                                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14" /></svg>
                                    </button>
                                    <output className="k-qty" aria-label={`Quantity of ${line.item.name}`}>{line.quantity}</output>
                                    <button type="button" className="k-step" aria-label={`One more ${line.item.name}`} onClick={() => onQuantity(line.key, line.quantity + 1)}>
                                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14" /></svg>
                                    </button>
                                </div>
                                <span className="k-cart-unit-words">{unitWords(line.item, line.unit, line.quantity)}</span>
                                <button type="button" className="k-remove" onClick={() => onRemove(line.key)} aria-label={`Remove ${line.item.name}`}>Remove</button>
                            </div>
                            {line.item.units.length > 1 ? (
                                <div className="k-unit-toggle" role="group" aria-label={`Count ${line.item.name} by`}>
                                    {line.item.units.map(option => (
                                        <button key={option.key} type="button" className="k-chip k-chip-wide" aria-pressed={line.unit === option.key}
                                            onClick={() => onLineUnit(line.key, option.key)}>{option.label}</button>
                                    ))}
                                </div>
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="k-sub">Add items, or tell Captain 5 what you needed.</p>
            )}

            <div className="k-cart-meta">
                <div className="k-cart-meta-row">
                    <span>Unit: <strong>{unit ? unit.display : 'No unit'}</strong></span>
                    <span className="k-cart-meta-actions">
                        <button type="button" className="k-secondary" onClick={() => setPickingUnit(open => !open)} aria-expanded={pickingUnit}>
                            {pickingUnit ? 'Done' : 'Change'}
                        </button>
                        {unit ? <button type="button" className="k-secondary" onClick={() => onUnit(null)}>Clear</button> : null}
                    </span>
                </div>
                {pickingUnit ? <UnitPicker units={units} value={unit} onChange={onUnit} /> : null}
                {note.trim() !== '' ? (
                    <div className="k-cart-meta-row">
                        <span>Note to Captain 5: <strong className="k-note-text">{note}</strong></span>
                        <button type="button" className="k-secondary" onClick={onNote}>Edit</button>
                    </div>
                ) : null}
            </div>

            {error ? <p className="k-error" role="alert">{error}</p> : null}

            <div className="k-cart-actions">
                {!empty ? (
                    <button type="button" className="k-take" onClick={onTake} disabled={sending}>{sending ? 'Sending' : takeLabel(lines.length)}</button>
                ) : note.trim() !== '' ? (
                    <button type="button" className="k-take" onClick={onTake} disabled={sending}>{sending ? 'Sending' : 'SEND NOTE TO CAPTAIN 5'}</button>
                ) : null}
                <button type="button" className="k-way k-way-quiet" onClick={onMore}>Add more items</button>
                {note.trim() === '' ? <button type="button" className="k-note-link" onClick={onNote}>Can&rsquo;t find it? Tell Captain 5 what you needed</button> : null}
            </div>
        </section>
    );
}
