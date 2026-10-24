import { useState, type FormEvent } from 'react';
import { cancelOrder, fetchAdvisories, fetchOrders, placeOrder, receiveOrder } from '../../../utils/emsApi';
import { LoadError, SkeletonRows } from '../../ui/StateViews';
import Tag from '../../ui/Tag';
import { etDateTime, parseWhole, suggestedOrderText, thresholdText } from './logic';
import { BTN, BTN_PRIMARY, BTN_SECONDARY, ConfirmStep, FIELD, Field, HINT, LiveNote, NumberInput, useEmsLoad, useWrite } from './shared';
import type { Advisory, AdvisoryBase, EmsMeta, SupplyOrder } from './types';

function OrderForm({ advisory, onPlaced, onCancel }: { advisory: Advisory; onPlaced: () => void; onCancel: () => void }) {
  const [packages, setPackages] = useState(advisory.suggested_packages === null ? '' : String(advisory.suggested_packages));
  const [supplier, setSupplier] = useState(advisory.supplier_code ?? '');
  const [itemNumber, setItemNumber] = useState(advisory.item_number ?? '');
  const [notes, setNotes] = useState('');
  const [error, setError] = useState<string | null>(null);
  const write = useWrite();

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const count = parseWhole(packages, 1, 100_000);
    if (count === null || Number.isNaN(count)) { setError('Enter how many packages were ordered, from 1 to 100000.'); return; }
    setError(null);
    const body = { supply_item_id: advisory.supply_item_id, packages: count, ...(supplier.trim() ? { supplier_code: supplier.trim() } : {}), ...(itemNumber.trim() ? { item_number: itemNumber.trim() } : {}), ...(notes.trim() ? { notes: notes.trim() } : {}) };
    void write.run(JSON.stringify(body), (key) => placeOrder(body, key), { onDone: onPlaced });
  };

  return (
    <form onSubmit={submit} noValidate aria-label={`Record an order for ${advisory.name}`} data-testid="ems-order-form" className="space-y-3 rounded-hub-control border border-hub-border bg-hub-n-25 p-3">
      <p className="text-sm text-hub-ink">Record this only after the order has really been placed. This does not place an order for you.</p>
      <div className="grid gap-3 sm:grid-cols-3">
        <Field label={`Packages ordered${advisory.package_unit && advisory.package_unit !== 'each' ? ` (${advisory.package_unit})` : ''}`} required error={error ?? write.fields.packages}>
          {(aria) => <NumberInput {...aria} maxLength={6} value={packages} disabled={write.busy} onChange={setPackages} />}
        </Field>
        <Field label="Supplier code" hint="Historical, unverified">
          {(aria) => <input {...aria} className={FIELD} value={supplier} maxLength={40} disabled={write.busy} onChange={(event) => setSupplier(event.target.value)} />}
        </Field>
        <Field label="Item number" hint="Historical, unverified">
          {(aria) => <input {...aria} className={FIELD} value={itemNumber} maxLength={80} disabled={write.busy} onChange={(event) => setItemNumber(event.target.value)} />}
        </Field>
      </div>
      <Field label="Notes (optional)">
        {(aria) => <input {...aria} className={FIELD} value={notes} maxLength={1000} disabled={write.busy} onChange={(event) => setNotes(event.target.value)} />}
      </Field>
      <LiveNote tone="warning">{write.error}</LiveNote>
      <div className="flex flex-wrap gap-2">
        <button type="submit" className={BTN_PRIMARY} disabled={write.busy} aria-busy={write.busy}>{write.busy ? 'Saving…' : write.retry ? 'Retry: Record order placed' : 'Record order placed'}</button>
        <button type="button" className={`${BTN} hub-btn--tertiary`} disabled={write.busy} onClick={onCancel}>Cancel</button>
      </div>
    </form>
  );
}

function AdvisoryCard({ advisory, canAct, onChanged }: { advisory: Advisory; canAct: boolean; onChanged: () => void }) {
  const [recording, setRecording] = useState(false);
  const placed = advisory.open_orders.length > 0;
  return (
    <li className="space-y-2 rounded-hub-control border border-hub-danger-border bg-hub-danger-bg p-3" data-testid="ems-advisory">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <p className="min-w-0 break-words font-semibold text-hub-ink">{advisory.name}{advisory.location_code ? <span className={`${HINT} ml-2 font-normal`}>{advisory.location_code}</span> : null}</p>
        <span className="flex flex-wrap gap-1.5">
          <Tag tone="danger">Reorder recommended</Tag>
          {placed && <Tag tone="info">Order placed</Tag>}
          {advisory.needs_review && <Tag tone="warning">Needs review</Tag>}
        </span>
      </div>
      <p className="text-sm text-hub-ink"><span className="text-base font-bold tabular-nums">{advisory.current_quantity}</span> on hand <span className={HINT}>({advisory.current_basis_label})</span></p>
      <p className={HINT}>{thresholdText({ threshold_qty_base: advisory.threshold_qty_base, threshold_source_text: advisory.threshold_source_text })}.</p>
      <p className="text-sm text-hub-ink">Suggested order: {suggestedOrderText(advisory)}</p>
      <p className={HINT}>Supplier {advisory.supplier_code ?? 'not listed'}{advisory.item_number ? `, item number ${advisory.item_number}` : ''}. Historical, unverified.</p>
      {placed && (
        <ul className="text-sm text-hub-ink">{advisory.open_orders.map((order) => <li key={order.id}>An order for {order.packages} {order.packages === 1 ? 'package' : 'packages'} was recorded {etDateTime(order.placed_at)}. It has not been received yet.</li>)}</ul>
      )}
      {canAct && !recording && <button type="button" className={BTN_SECONDARY} onClick={() => setRecording(true)} aria-label={`Record order placed for ${advisory.name}`}>Record order placed</button>}
      {recording && <OrderForm advisory={advisory} onCancel={() => setRecording(false)} onPlaced={() => { setRecording(false); onChanged(); }} />}
    </li>
  );
}

function OrderRow({ order, canAct, onChanged }: { order: SupplyOrder; canAct: boolean; onChanged: () => void }) {
  const [mode, setMode] = useState<'none' | 'receive' | 'cancel'>('none');
  const [units, setUnits] = useState('');
  const [error, setError] = useState<string | null>(null);
  const write = useWrite();
  const needsUnits = order.quantity_base === null;

  const receive = (event?: FormEvent) => {
    event?.preventDefault();
    const quantity = parseWhole(units, 1, 10_000_000);
    if (needsUnits && (quantity === null || Number.isNaN(quantity))) { setError('The package size is not known, so enter how many single units arrived.'); return; }
    if (quantity !== null && Number.isNaN(quantity)) { setError('Enter a whole number of single units.'); return; }
    setError(null);
    void write.run(`receive-${quantity ?? 'all'}`, (key) => receiveOrder(order.id, needsUnits || units.trim() !== '' ? quantity : null, key), { onDone: () => { setMode('none'); onChanged(); } });
  };

  return (
    <li className="space-y-2 rounded-hub-control border border-hub-border bg-hub-well p-3" data-testid="ems-order">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <p className="min-w-0 break-words font-semibold text-hub-ink">{order.item_name ?? 'Item'}: {order.packages} {order.packages === 1 ? 'package' : 'packages'}</p>
        <Tag tone={order.status === 'placed' ? 'info' : order.status === 'received' ? 'success' : 'neutral'}>{order.status === 'placed' ? 'Order placed' : order.status === 'received' ? 'Received' : 'Cancelled'}</Tag>
      </div>
      <p className={HINT}>Recorded {etDateTime(order.placed_at)}{order.placed_by_name ? ` by ${order.placed_by_name}` : ''}.{order.received_at ? ` Received ${etDateTime(order.received_at)}.` : ''}{order.cancelled_at ? ` Cancelled ${etDateTime(order.cancelled_at)}.` : ''}{order.notes ? ` ${order.notes}` : ''}</p>
      {canAct && order.status === 'placed' && mode === 'none' && (
        <div className="flex flex-wrap gap-2">
          <button type="button" className={BTN_PRIMARY} onClick={() => setMode('receive')} aria-label={`Mark order for ${order.item_name ?? 'item'} received`}>Mark received</button>
          <button type="button" className={BTN_SECONDARY} onClick={() => { write.clear(); setMode('cancel'); }} aria-label={`Cancel order for ${order.item_name ?? 'item'}`}>Cancel order</button>
        </div>
      )}
      {mode === 'receive' && (
        <form onSubmit={receive} noValidate aria-label="Mark order received" className="space-y-3 rounded-hub-control border border-hub-border bg-hub-n-25 p-3">
          <p className="text-sm text-hub-ink">{needsUnits ? 'Enter how many single units arrived. They are added to stock.' : `The ${order.quantity_base} units ordered are added to stock. Enter a different number only if less or more arrived.`}</p>
          <Field label="Single units received" required={needsUnits} error={error ?? write.fields.quantity_base}>
            {(aria) => <NumberInput {...aria} maxLength={8} value={units} disabled={write.busy} onChange={setUnits} />}
          </Field>
          <LiveNote tone="warning">{write.error}</LiveNote>
          <div className="flex flex-wrap gap-2">
            <button type="submit" className={BTN_PRIMARY} disabled={write.busy} aria-busy={write.busy}>{write.busy ? 'Saving…' : write.retry ? 'Retry: Mark received' : 'Mark received'}</button>
            <button type="button" className={`${BTN} hub-btn--tertiary`} disabled={write.busy} onClick={() => setMode('none')}>Cancel</button>
          </div>
        </form>
      )}
      {mode === 'cancel' && (
        <ConfirmStep title="Cancel this order?" body={<p>This only changes the record here. It does not cancel anything with the supplier.</p>} confirmLabel="Cancel order"
          busy={write.busy} error={write.error} retry={write.retry} onCancel={() => setMode('none')}
          onConfirm={() => void write.run('cancel', (key) => cancelOrder(order.id, key), { onDone: () => { setMode('none'); onChanged(); } })} />
      )}
    </li>
  );
}

function SimpleList({ title, note, items }: { title: string; note: string; items: AdvisoryBase[] }) {
  if (items.length === 0) return null;
  return (
    <section className="space-y-1" aria-label={title}>
      <h3 className="font-heading text-base font-bold text-hub-ink">{title}</h3>
      <p className={HINT}>{note}</p>
      <ul className="list-disc space-y-0.5 pl-5 text-sm text-hub-ink">{items.map((item) => <li key={item.supply_item_id}>{item.name}{item.location_code ? ` (${item.location_code})` : ''}{item.threshold_source_text ? `. Source says ${item.threshold_source_text}` : ''}</li>)}</ul>
    </section>
  );
}

/** Reorder advice (computed, never an order) and the orders people recorded. "Reorder recommended" and "Order placed" are different tags. */
export default function SupplyReorder({ meta, onForbidden, onChanged }: { meta: EmsMeta; onForbidden: () => void; onChanged: () => void }) {
  const advice = useEmsLoad(() => fetchAdvisories(), 'advice', { onForbidden });
  const orders = useEmsLoad(() => fetchOrders(), 'orders', { onForbidden });
  const canAct = meta.permissions.act;
  const refresh = () => { void advice.reload(true); void orders.reload(true); onChanged(); };

  return (
    <div className="space-y-6" data-testid="ems-supply-reorder">
      {advice.error && !advice.data && <LoadError title="Reorder advice could not be loaded" message={advice.error} onRetry={() => void advice.reload(false)} />}
      {advice.loading && !advice.data && <SkeletonRows rows={3} />}
      {advice.data && (
        <>
          <p className={HINT} data-testid="ems-advice-note">{advice.data.note}</p>
          <section className="space-y-2" aria-label="Reorder recommended">
            <h3 className="font-heading text-base font-bold text-hub-ink">Reorder recommended ({advice.data.advisories.length})</h3>
            {advice.data.advisories.length === 0
              ? <p className="text-sm text-hub-ink">Nothing with a known threshold is below it right now.</p>
              : <ul className="space-y-2">{advice.data.advisories.map((advisory) => <AdvisoryCard key={advisory.supply_item_id} advisory={advisory} canAct={canAct} onChanged={refresh} />)}</ul>}
          </section>
          <SimpleList title="Needs a count first" note="These have a threshold but no count yet, so we cannot say whether they are low." items={advice.data.needs_count} />
          <SimpleList title="Threshold not worked out" note="The order sheet text could not be turned into a number. Someone with settings access can set it on the item." items={advice.data.unknown_threshold} />
        </>
      )}

      <section className="space-y-2" aria-label="Orders">
        <h3 className="font-heading text-base font-bold text-hub-ink">Orders recorded</h3>
        {orders.error && !orders.data && <LoadError title="Orders could not be loaded" message={orders.error} onRetry={() => void orders.reload(false)} />}
        {orders.loading && !orders.data && <SkeletonRows rows={2} />}
        {orders.data && orders.data.length === 0 && <p className="text-sm text-hub-ink">No orders have been recorded yet.</p>}
        {orders.data && orders.data.length > 0 && <ul className="space-y-2">{orders.data.map((order) => <OrderRow key={order.id} order={order} canAct={canAct} onChanged={refresh} />)}</ul>}
      </section>
    </div>
  );
}
