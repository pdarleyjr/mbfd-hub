import { memo, type ReactNode } from 'react';
import { ChevronRight, Minus, Plus } from 'lucide-react';
import { flagTags, itemsLabel, minLabel } from './emsLogic.js';
import type { EmsFormItem } from './emsTypes';

/** Large − / + steppers around a numeric keypad input. Blank means "not needed"; the stepper never writes 0. */
function QuantityControl({ itemKey, label, qty, onType, onBump, describedBy }: {
    itemKey: string; label: string; qty: number | undefined; describedBy: string;
    onType: (key: string, raw: string) => void; onBump: (key: string, delta: number) => void;
}) {
    return <div className="ems-qty" role="group" aria-label={`${label}: how many are needed`}>
        <button type="button" className="ems-step" aria-label={`One fewer ${label}`} disabled={qty === undefined} onClick={() => onBump(itemKey, -1)}><Minus size={18} aria-hidden="true" /></button>
        <input
            className="ems-qty-input" type="text" inputMode="numeric" pattern="[0-9]*" enterKeyHint="done" autoComplete="off"
            aria-label={`${label}, number needed`} aria-describedby={describedBy} placeholder="Need" maxLength={3}
            value={qty === undefined ? '' : String(qty)}
            onFocus={(event) => event.currentTarget.select()}
            onChange={(event) => onType(itemKey, event.target.value)}
        />
        <button type="button" className="ems-step" aria-label={`One more ${label}`} onClick={() => onBump(itemKey, 1)}><Plus size={18} aria-hidden="true" /></button>
    </div>;
}

/** A Yes / No answer. Tapping the chosen answer again clears it, so the item can go back to blank. */
function YesNoControl({ itemKey, label, answer, onAnswer }: {
    itemKey: string; label: string; answer: boolean | undefined; onAnswer: (key: string, value: boolean | null) => void;
}) {
    return <div className="ems-yesno" role="group" aria-label={`${label}: yes or no`}>
        <button type="button" className="ems-choice" aria-pressed={answer === true} onClick={() => onAnswer(itemKey, answer === true ? null : true)}>Yes</button>
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
    controlledNote: string;
    onType: (key: string, raw: string) => void;
    onBump: (key: string, delta: number) => void;
    onAnswer: (key: string, value: boolean | null) => void;
}

/** One item exactly as the paper form lists it: name, source minimum, flags, and the NEED control. */
export const ItemRow = memo(function ItemRow({ rowId, itemKey, item, qty, answer, caption, controlledNote, onType, onBump, onAnswer }: ItemRowProps) {
    const tags = flagTags(item.flags);
    const min = minLabel(item);
    const hasValue = item.response_type === 'yes_no' ? answer !== undefined : qty !== undefined;
    const metaId = `${rowId}-meta`;
    return <li className={`ems-item${hasValue ? ' is-set' : ''}`}>
        {caption && <p className="ems-variant-caption">{caption}</p>}
        <div className="ems-item-row">
            <div className="ems-item-text">
                <span className="ems-item-label">{item.label}</span>
                <span className="ems-item-meta" id={metaId}>
                    {min && <span className="ems-min">{min}</span>}
                    {tags.map((tag) => <span className="ems-tag" key={tag}>{tag}</span>)}
                </span>
            </div>
            {item.response_type === 'yes_no'
                ? <YesNoControl itemKey={itemKey} label={item.label} answer={answer} onAnswer={onAnswer} />
                : <QuantityControl itemKey={itemKey} label={item.label} qty={qty} describedBy={metaId} onType={onType} onBump={onBump} />}
        </div>
        {item.flags.controlled_substance && controlledNote && <p className="ems-item-note">{controlledNote}</p>}
    </li>;
});

/** A collapsible category. The header says how many items in it have a quantity. */
export function CategoryPanel({ panelId, title, requested, total, open, onToggle, children }: {
    panelId: string; title: string; requested: number; total: number; open: boolean; onToggle: () => void; children: ReactNode;
}) {
    return <section className="ems-category">
        <h3 className="ems-category-heading">
            <button type="button" aria-expanded={open} aria-controls={panelId} onClick={onToggle}>
                <ChevronRight className="ems-chevron" size={18} aria-hidden="true" />
                <span className="ems-category-title">{title}</span>
                <span className="ems-category-count">
                    {requested > 0 && <span className="ems-count-chip">{requested} needed</span>}
                    <span className="ems-count-total">{itemsLabel(total)}</span>
                </span>
            </button>
        </h3>
        <div id={panelId} className="ems-category-body" hidden={!open}>{open ? children : null}</div>
    </section>;
}
