// Bottom sheet: quantity (opens at 1), quick chips, unit toggle, Add to cart. Big amounts need a second tap.
import { useEffect, useRef, useState } from 'react';
import { clampQuantity, defaultUnit, needsConfirm, unitWords } from './cart.ts';
import type { SupplyItem } from './types.ts';

const QUICK = [1, 2, 3, 5, 10];

interface Props {
    item: SupplyItem;
    onAdd: (quantity: number, unit: string) => void;
    onClose: () => void;
}

export function QuantitySheet({ item, onAdd, onClose }: Props) {
    const [quantity, setQuantityRaw] = useState(1);
    const [unit, setUnitRaw] = useState(() => defaultUnit(item));
    const [armed, setArmed] = useState(false);
    const addRef = useRef<HTMLButtonElement>(null);

    const setQuantity = (value: number) => { setQuantityRaw(clampQuantity(value)); setArmed(false); };
    const setUnit = (value: string) => { setUnitRaw(value); setArmed(false); };

    useEffect(() => {
        addRef.current?.focus({ preventScroll: true });
        const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    const big = needsConfirm(item, quantity, unit);
    const add = () => {
        if (big && !armed) { setArmed(true); return; }
        onAdd(quantity, unit);
    };
    const words = unitWords(item, unit, quantity);

    return (
        <div className="k-sheet-backdrop" onClick={event => { if (event.target === event.currentTarget) onClose(); }}>
            <div className="k-sheet" role="dialog" aria-modal="true" aria-labelledby="k-sheet-title">
                <div className="k-sheet-head">
                    <div>
                        <h2 id="k-sheet-title" className="k-sheet-title">{item.name}</h2>
                        <p className="k-sheet-place">{item.location_label || item.location_code || 'Supply room'}</p>
                    </div>
                    <button type="button" className="k-icon-button" aria-label="Close" onClick={onClose}>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" /></svg>
                    </button>
                </div>

                <div className="k-stepper" role="group" aria-label="Quantity">
                    <button type="button" className="k-step" aria-label="One less" onClick={() => setQuantity(quantity - 1)} disabled={quantity <= 1}>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14" /></svg>
                    </button>
                    <output className="k-qty" aria-live="polite" aria-label="Quantity">{quantity}</output>
                    <button type="button" className="k-step" aria-label="One more" onClick={() => setQuantity(quantity + 1)}>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14" /></svg>
                    </button>
                </div>

                <div className="k-quick" role="group" aria-label="Quick amounts">
                    {QUICK.map(value => (
                        <button key={value} type="button" className="k-chip" aria-pressed={quantity === value} onClick={() => setQuantity(value)}>{value}</button>
                    ))}
                </div>

                {item.units.length > 1 ? (
                    <div className="k-unit-toggle" role="group" aria-label="Count by">
                        {item.units.map(option => (
                            <button key={option.key} type="button" className="k-chip k-chip-wide" aria-pressed={unit === option.key} onClick={() => setUnit(option.key)}>
                                {option.label}
                            </button>
                        ))}
                    </div>
                ) : null}

                <button ref={addRef} type="button" className={`k-add${big && armed ? ' is-armed' : ''}`} onClick={add}>
                    {big && armed ? `Taking ${quantity} ${words}? Tap again to confirm` : `ADD TO CART: ${quantity} ${words}`}
                </button>
            </div>
        </div>
    );
}
