import { useState, type ReactNode } from 'react';
import { Sheet } from '../../ui';
import { BTN, ConfirmStep, HINT, useWrite } from '../shared';
import { updateMasterItem } from './api';
import { formatQty, humanReason } from './logic';
import OnHandCell from './OnHandCell';
import { NO_TO_ORDER } from './EditRows';
import OrderActions from './OrderActions';
import { quantityText, stateSentence } from './orderLogic';
import type { MasterItem } from './types';

interface Props {
  item: MasterItem | null;
  canEdit: boolean;
  onClose: () => void;
  /** The item as the server now holds it. */
  onSaved: (item: MasterItem) => void;
  onRemoved: (item: MasterItem) => void;
  onStale: () => void;
  /** Edit mode is on: the hint points at the row instead of the Edit button. */
  editing: boolean;
  /** Where focus goes when the sheet closes (the control that opened it, or a fallback when that row is gone). */
  returnFocusTo: () => HTMLElement | null;
}

function Fact({ label, testId, children }: { label: string; testId: string; children: ReactNode }) {
  return (
    <div className="min-w-0 rounded-hub-control border border-hub-border-soft bg-hub-well px-3 py-2">
      <dt className={HINT}>{label}</dt>
      <dd className="font-semibold text-hub-ink [overflow-wrap:anywhere]" data-testid={testId}>{children}</dd>
    </div>
  );
}

function Remove({ item, onRemoved, onClose }: Pick<Props, 'onRemoved' | 'onClose'> & { item: MasterItem }) {
  const [confirm, setConfirm] = useState(false);
  const remove = useWrite();
  const doRemove = () => {
    void remove.run(`remove:${item.id}`, (key) => updateMasterItem(item.id, { active: false }, key), { onDone: () => { onRemoved(item); onClose(); } });
  };
  return (
    <details className="rounded-hub-control border border-hub-border bg-hub-n-25 px-3" data-testid="item-more">
      <summary className="flex min-h-11 cursor-pointer items-center text-sm font-semibold text-hub-ink">More</summary>
      <div className="space-y-3 pb-3">
        {!confirm && <button type="button" className={`${BTN} hub-btn--tertiary !text-hub-danger`} onClick={() => setConfirm(true)}>Remove from inventory</button>}
        {confirm && (
          <ConfirmStep title={`Remove ${item.name}?`} confirmLabel="Remove from inventory" busy={remove.busy} error={remove.error} retry={remove.retry}
            body={<p>It leaves the shelf list and the count. You can put it back later from Add item.</p>} onConfirm={doRemove} onCancel={() => setConfirm(false)} />
        )}
      </div>
    </details>
  );
}

function ItemDetails({ item, canEdit, editing, onClose, onSaved, onRemoved, onStale }: Props & { item: MasterItem }) {
  const problems = [...item.review_reasons.map(humanReason), ...(item.config_problem ? [humanReason(item.config_problem)] : [])];
  const expected = item.expected === null ? 'Not set' : quantityText(item, item.expected);
  const toOrder = item.to_order === null ? NO_TO_ORDER : item.to_order === 0 ? 'Nothing' : quantityText(item, item.to_order);
  return (
    <div className="space-y-5" data-testid="item-details">
      {problems.length > 0 && <p className="hub-callout hub-callout--warning p-3 text-sm" data-testid="item-problems">Check this item: {problems.join('. ')}.</p>}
      <dl className="grid grid-cols-1 gap-2 min-[360px]:grid-cols-2">
        <Fact label="On hand" testId="sheet-on-hand"><OnHandCell item={{ ...item, order_state: 'ok', open_order: null }} /></Fact>
        <Fact label="Expected on hand" testId="sheet-expected">{expected}</Fact>
        <Fact label="To order" testId="sheet-to-order">{toOrder}</Fact>
        <Fact label="Counted by" testId="sheet-counted">{item.counted_by ?? 'Not recorded'}</Fact>
      </dl>
      <p className="text-sm text-hub-ink" data-testid="sheet-state">{stateSentence(item)}</p>
      {item.estimated === true && <p className={HINT}>The on-hand number is an estimate until someone counts it.</p>}
      {item.units_per_package !== null && item.count_unit !== 'each' && (
        <p className={HINT}>Counted by the {item.count_unit_label}, {formatQty(item.units_per_package)} single units in one.</p>
      )}

      {canEdit && <OrderActions item={item} onSaved={onSaved} onStale={onStale} />}
      {canEdit && (
        <p className={HINT} data-testid="sheet-edit-hint">
          {editing
            ? "To change the name, shelf, unit or numbers, close this and use the boxes in this item's row."
            : 'To change the name, shelf, unit or numbers, close this and use Edit above the list.'}
        </p>
      )}
      {canEdit && <Remove item={item} onRemoved={onRemoved} onClose={onClose} />}
    </div>
  );
}

/** Bottom sheet on phones, side sheet on wider screens: the item's numbers, its order state and the order steps. */
export default function ItemSheet(props: Props) {
  const { item, onClose, returnFocusTo } = props;
  return (
    <Sheet open={item !== null} onClose={onClose} title={item ? item.name : 'Item'} testId="item-sheet" returnFocusTo={returnFocusTo}
      description={item?.location_label ? `Shelf ${item.location_label}` : item ? 'No shelf assigned' : undefined}>
      {item && <ItemDetails key={item.id} {...props} item={item} />}
    </Sheet>
  );
}
