import { memo, type ReactNode } from 'react';
import { ChevronRight, Minus, Plus } from 'lucide-react';
import { amountLabel, itemChips, itemsLabel, refillLabel } from './emsLogic.js';
import type { EmsFormItem } from './emsTypes';

/** Large − / + steppers around a numeric keypad input. Blank means "not needed"; the stepper never writes 0. */
function QuantityControl({ inputId, itemKey, label, qty, onType, onBump, describedBy }: {
    inputId: string; itemKey: string; label: string; qty: number | undefined; describedBy: string;
    onType: (key: string, raw: string) => void; onBump: (key: string, delta: number) => void;
}) {
    return <div className="ems-qty" role="group" aria-label={`${label}: how many are needed`}>
        <button type="button" className="ems-step" aria-label={`One fewer ${label}`} disabled={qty === undefined} onClick={() => onBump(itemKey, -1)}><Minus size={18} aria-hidden="true" /></button>
        <input
            id={inputId} className="ems-qty-input" type="text" inputMode="numeric" pattern="[0-9]*" enterKeyHint="done" autoComplete="off"
            aria-label={`${label}, number needed`} aria-describedby={describedBy} placeholder="Need" maxLength={3}
            value={qty === undefined ? '' : String(qty)}
            onFocus={(event) => event.currentTarget.select()}
            onChange={(event) => onType(itemKey, event.target.value)}
        />
        <button type="button" className="ems-step" aria-label={`One more ${label}`} onClick={() => onBump(itemKey, 1)}><Plus size={18} aria-hidden="true" /></button>
    </div>;
}

/** A Yes / No answer. Tapping the chosen answer again clears it, so the item can go back to blank. */
function YesNoControl({ firstId, itemKey, label, answer, onAnswer }: {
    firstId: string; itemKey: string; label: string; answer: boolean | undefined; onAnswer: (key: string, value: boolean | null) => void;
}) {
    return <div className="ems-yesno" role="group" aria-label={`${label}: yes or no`}>
        <button id={firstId} type="button" className="ems-choice" aria-pressed={answer === true} onClick={() => onAnswer(itemKey, answer === true ? null : true)}>Yes</button>
        <button type="button" className="ems-choice" aria-pressed={answer === false} onClick={() => onAnswer(itemKey, answer === false ? null : false)}>No</button>
    </div>;
}

export interface ItemRowProps {
    rowId: string;
    itemKey: string;
    item: EmsFormItem;
    qty: number | undefined;
    answer: boolean | undefined;
    caption: string;
    /** Where the item lives ("Airway Bag › Top Compartment"), shown in search results where the list and section are not on screen. */
    context?: string;
    controlledNote: string;
    onType: (key: string, raw: string) => void;
    onBump: (key: string, delta: number) => void;
    onAnswer: (key: string, value: boolean | null) => void;
}

/**
 * One item as the paper form lists it: name, AMOUNT ("Carry 4") and "Fill if <" ("Refill under 2") side by side but distinct,
 * small chips for where it is kept / the printed condition / the color group / the flags, and the NEED control.
 */
export const ItemRow = memo(function ItemRow({ rowId, itemKey, item, qty, answer, caption, context, controlledNote, onType, onBump, onAnswer }: ItemRowProps) {
    const chips = itemChips(item);
    const amount = amountLabel(item);
    const refill = refillLabel(item);
    const hasValue = item.response_type === 'yes_no' ? answer !== undefined : qty !== undefined;
    const metaId = `${rowId}-meta`;
    return <li id={rowId} className={`ems-item${hasValue ? ' is-set' : ''}`}>
        {caption && <p className="ems-variant-caption">{caption}</p>}
        <div className="ems-item-row">
            <div className="ems-item-text">
                <span className="ems-item-label">{item.label}</span>
                <span className="ems-item-meta" id={metaId}>
                    {context && <span className="ems-item-context">{context}</span>}
                    {amount && <span className="ems-amount">{amount}</span>}
                    {refill && <span className="ems-refill">{refill}</span>}
                    {chips.map((chip) => <span className={`ems-chip is-${chip.kind}`} key={`${chip.kind}:${chip.text}`}>{chip.text}</span>)}
                </span>
            </div>
            {item.response_type === 'yes_no'
                ? <YesNoControl firstId={`${rowId}-yes`} itemKey={itemKey} label={item.label} answer={answer} onAnswer={onAnswer} />
                : <QuantityControl inputId={`${rowId}-input`} itemKey={itemKey} label={item.label} qty={qty} describedBy={metaId} onType={onType} onBump={onBump} />}
        </div>
        {item.flags.controlled_substance && controlledNote && <p className="ems-item-note">{controlledNote}</p>}
    </li>;
});

/** A collapsible section. The header says how many items in it have an entry; the printed note sits under the heading. */
export function CategoryPanel({ panelId, toggleId, title, requested, total, open, notes = [], onToggle, children }: {
    panelId: string; toggleId: string; title: string; requested: number; total: number; open: boolean; notes?: string[]; onToggle: () => void; children: ReactNode;
}) {
    return <section className="ems-category">
        <h3 className="ems-category-heading">
            <button id={toggleId} type="button" aria-expanded={open} aria-controls={panelId} onClick={onToggle}>
                <ChevronRight className="ems-chevron" size={18} aria-hidden="true" />
                <span className="ems-category-title">{title}</span>
                <span className="ems-category-count">
                    {requested > 0 && <span className="ems-count-chip">{requested} entered</span>}
                    <span className="ems-count-total">{itemsLabel(total)}</span>
                </span>
            </button>
        </h3>
        <div id={panelId} className="ems-category-body" hidden={!open}>
            {open && notes.map((note) => <p className="ems-section-note" key={note}>{note}</p>)}
            {open ? children : null}
        </div>
    </section>;
}
