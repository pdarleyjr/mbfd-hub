import { useEffect, useRef, useState } from 'react';
import { etDateTime } from '../logic';
import { BTN, BTN_PRIMARY, BTN_SECONDARY, ConfirmStep, HINT, LiveNote, useWrite } from '../shared';
import { markArrived, placeOrder, updateOrder } from './api';
import OrderForm, { type OrderSubmit } from './OrderForm';
import { STATUS_WORD, dayText, quantityText, suggestedQuantity } from './orderLogic';
import type { MasterItem, OpenOrder, OpenOrderStatus, OrderUpdate } from './types';

interface Props {
  item: MasterItem;
  onSaved: (item: MasterItem) => void;
  /** The item already had an open order (409 order_open): the list is reloaded so the sheet shows it. */
  onStale: () => void;
}

type Step = { kind: 'choose' } | { kind: 'new'; status: OpenOrderStatus } | { kind: 'arrived' } | { kind: 'details' } | { kind: 'cancel' };

const STATUSES: readonly OpenOrderStatus[] = ['ordered', 'pending', 'backordered'];

const markLabel = (status: OpenOrderStatus): string => `Mark ${STATUS_WORD[status].toLowerCase()}`;

function OpenOrderFacts({ item, order }: { item: MasterItem; order: OpenOrder }) {
  const changed = order.updated_at ?? order.placed_at;
  const who = order.updated_by ?? order.placed_by;
  return (
    <div className="space-y-1 rounded-hub-control border border-hub-border bg-hub-well p-3 text-sm text-hub-ink" data-testid="open-order">
      <p className="font-semibold" data-testid="open-order-status">{STATUS_WORD[order.status]}: {quantityText(item, order.quantity)}</p>
      {order.expected_on && <p>Expected {dayText(order.expected_on)}</p>}
      {order.note && <p className="[overflow-wrap:anywhere]">Note: {order.note}</p>}
      {changed && <p className={HINT}>Last changed {etDateTime(changed)}{who ? ` by ${who}` : ''}</p>}
    </div>
  );
}

/**
 * Ordering one item, one small step at a time: record an order (ordered, pending or back ordered), move it between
 * those, change its details, mark it arrived (adds the stock and closes it) or cancel it. Every write returns the item,
 * which replaces the row in place. A refused write (403 included) shows its reason here and changes nothing else.
 * After a save, or after backing out of a step, focus returns to the first action button.
 */
export default function OrderActions({ item, onSaved, onStale }: Props) {
  const [step, setStep] = useState<Step>({ kind: 'choose' });
  const [done, setDone] = useState<string | null>(null);
  const write = useWrite();
  const actions = useRef<HTMLDivElement>(null);
  const refocus = useRef(false);
  const order = item.open_order;
  const plural = item.count_unit === 'each' ? 'single units' : item.count_unit_label_plural;

  useEffect(() => {
    if (step.kind !== 'choose' || !refocus.current) return;
    refocus.current = false;
    actions.current?.querySelector<HTMLButtonElement>('button:not([disabled])')?.focus();
  }, [step, item]);

  const go = (next: Step) => { write.clear(); setDone(null); refocus.current = next.kind === 'choose'; setStep(next); };

  const send = (signature: string, work: (key: string) => Promise<MasterItem>, message: (next: MasterItem) => string) => {
    void write.run(signature, work, {
      onDone: (next) => { refocus.current = true; setStep({ kind: 'choose' }); setDone(message(next)); onSaved(next); },
      onConflict: (failure) => { if (failure.code === 'order_open') { refocus.current = true; setStep({ kind: 'choose' }); onStale(); } },
    });
  };

  const placeNew = (status: OpenOrderStatus, values: OrderSubmit) => {
    const input = { status, quantity: values.quantity, ...(values.expectedOn ? { expected_on: values.expectedOn } : {}), ...(values.note ? { note: values.note } : {}) };
    send(`order:${item.id}:${JSON.stringify(input)}`, (key) => placeOrder(item.id, input, key), () => `Marked ${STATUS_WORD[status].toLowerCase()}: ${quantityText(item, values.quantity)}.`);
  };

  const change = (open: OpenOrder, changes: OrderUpdate, message: string) => {
    send(`order-change:${open.id}:${JSON.stringify(changes)}`, (key) => updateOrder(open.id, changes, key), () => message);
  };

  const saveDetails = (open: OpenOrder, values: OrderSubmit) => {
    const changes: OrderUpdate = {};
    if (values.quantity !== open.quantity) changes.quantity = values.quantity;
    if (values.expectedOn !== (open.expected_on ?? '')) changes.expected_on = values.expectedOn === '' ? null : values.expectedOn;
    if (values.note !== (open.note ?? '')) changes.note = values.note === '' ? null : values.note;
    if (Object.keys(changes).length === 0) { go({ kind: 'choose' }); return; }
    change(open, changes, 'Order updated.');
  };

  const arrived = (open: OpenOrder, values: OrderSubmit) => {
    const input = { quantity: values.quantity, ...(values.note ? { note: values.note } : {}) };
    send(`arrived:${open.id}:${JSON.stringify(input)}`, (key) => markArrived(open.id, input, key), (next) => (
      next.order_state === 'needs_order' && next.to_order
        ? `${quantityText(item, values.quantity)} added to stock. Still short: order ${quantityText(next, next.to_order)} more.`
        : `${quantityText(item, values.quantity)} added to stock. The order is closed.`
    ));
  };

  const common = { busy: write.busy, error: write.error, retry: write.retry, serverFields: write.fields, onBack: () => go({ kind: 'choose' }), quantityLabel: `Quantity (${plural})` };

  return (
    <section className="space-y-3" aria-labelledby={`order-title-${item.id}`} data-testid="order-actions">
      <h3 id={`order-title-${item.id}`} className="font-heading text-base font-bold text-hub-ink">Order</h3>
      <div role="status" aria-live="polite">{done && <p className="text-sm font-semibold text-hub-success" data-testid="order-done">{done}</p>}</div>
      {order && <OpenOrderFacts item={item} order={order} />}

      {step.kind === 'choose' && !order && (
        <div className="space-y-2" ref={actions}>
          <p className={HINT}>Mark it once you place the order. The flag clears when it arrives.</p>
          <div className="flex flex-wrap gap-2">
            {STATUSES.map((status, index) => (
              <button key={status} type="button" className={index === 0 ? BTN_PRIMARY : BTN_SECONDARY} data-testid={`order-new-${status}`} onClick={() => go({ kind: 'new', status })}>
                {markLabel(status)}
              </button>
            ))}
          </div>
          <LiveNote tone="warning">{write.error}</LiveNote>
        </div>
      )}

      {step.kind === 'choose' && order && (
        <div className="space-y-3" ref={actions}>
          <div className="flex flex-wrap gap-2">
            <button type="button" className={BTN_PRIMARY} disabled={write.busy} data-testid="order-arrived" onClick={() => go({ kind: 'arrived' })}>Mark arrived</button>
            {STATUSES.filter((status) => status !== order.status).map((status) => (
              <button key={status} type="button" className={BTN_SECONDARY} disabled={write.busy} aria-busy={write.busy} data-testid={`order-set-${status}`}
                onClick={() => change(order, { status }, `Marked ${STATUS_WORD[status].toLowerCase()}.`)}>
                {markLabel(status)}
              </button>
            ))}
          </div>
          <div className="flex flex-wrap gap-2">
            <button type="button" className={BTN_SECONDARY} disabled={write.busy} data-testid="order-edit" onClick={() => go({ kind: 'details' })}>Change quantity, date or note</button>
            <button type="button" className={`${BTN} hub-btn--tertiary !text-hub-danger`} disabled={write.busy} data-testid="order-cancel" onClick={() => go({ kind: 'cancel' })}>Cancel order</button>
          </div>
          <LiveNote tone="warning">{write.error}</LiveNote>
        </div>
      )}

      {step.kind === 'new' && (
        <OrderForm {...common} key={`new-${step.status}`} testId="order-form" title={markLabel(step.status)} showDate
          hint={step.status === 'pending' ? 'Pending means it still has to be placed or approved.' : undefined}
          initial={{ quantity: String(suggestedQuantity(item)), expectedOn: '', note: '' }} submitLabel={`Save as ${STATUS_WORD[step.status].toLowerCase()}`}
          onSubmit={(values) => placeNew(step.status, values)} />
      )}

      {step.kind === 'details' && order && (
        <OrderForm {...common} key="details" testId="order-form" title="Change the order" showDate
          initial={{ quantity: String(order.quantity), expectedOn: order.expected_on ?? '', note: order.note ?? '' }} submitLabel="Save changes"
          onSubmit={(values) => saveDetails(order, values)} />
      )}

      {step.kind === 'arrived' && order && (
        <OrderForm {...common} key="arrived" testId="arrived-form" title="How many arrived?" showDate={false} quantityLabel={`Arrived (${plural})`}
          hint="This adds them to on hand and closes the order. If fewer came than were short, the item shows what is still needed."
          initial={{ quantity: String(order.quantity), expectedOn: '', note: '' }} submitLabel="Add to stock"
          onSubmit={(values) => arrived(order, values)} />
      )}

      {step.kind === 'cancel' && order && (
        <ConfirmStep testId="order-cancel-confirm" title="Cancel this order?" confirmLabel="Cancel order" busy={write.busy} error={write.error} retry={write.retry}
          body={<p>Nothing is added to stock. If the item is still short it shows as needing an order again.</p>}
          onConfirm={() => change(order, { status: 'cancelled' }, 'Order cancelled.')} onCancel={() => go({ kind: 'choose' })} />
      )}
    </section>
  );
}
