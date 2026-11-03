// One supply item: tap the row or the big + to open the quantity sheet.
import { memo } from 'react';
import type { SupplyItem } from './types.ts';

interface Props {
    item: SupplyItem;
    inCart: number;
    anchorId?: string;
    onPick: (item: SupplyItem) => void;
}

export const ItemRow = memo(function ItemRow({ item, inCart, anchorId, onPick }: Props) {
    const place = item.location_label || item.location_code || 'Supply room';
    return (
        <li className="k-row" id={anchorId}>
            <button type="button" className="k-row-main" onClick={() => onPick(item)}>
                <span className="k-row-name">{item.name}</span>
                <span className="k-row-place">
                    {place}
                    {inCart > 0 ? <span className="k-row-incart">{inCart} in cart</span> : null}
                </span>
            </button>
            <button type="button" className="k-row-add" aria-label={`Add ${item.name}`} onClick={() => onPick(item)}>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 4v16M4 12h16" /></svg>
            </button>
        </li>
    );
});
