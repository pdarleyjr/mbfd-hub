import { useState } from 'react';
import { Scale } from 'lucide-react';
import { confirmReconciliation, fetchReconciliation } from '../../../utils/emsApi';
import { EmptyState, LoadError, SkeletonRows } from '../../ui/StateViews';
import Tag from '../../ui/Tag';
import { ExportLinks } from './SupplyCounts';
import { etDateTime, reconcileDelta, warningText } from './logic';
import { BTN_PRIMARY, ConfirmStep, HINT, LiveNote, useEmsLoad, useWrite } from './shared';
import type { EmsMeta, ReconcileRow, SnapshotDetail } from './types';

const value = (n: number | null): string => (n === null ? 'Not counted' : String(n));

function RowCard({ row }: { row: ReconcileRow }) {
  return (
    <li className={`space-y-2 rounded-hub-control border p-3 ${row.below_threshold ? 'border-hub-danger-border bg-hub-danger-bg' : row.warnings.length > 0 ? 'border-hub-warning-border bg-hub-warning-bg' : 'border-hub-border bg-hub-well'}`} data-testid="ems-reconcile-row">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <p className="min-w-0 break-words font-semibold text-hub-ink">{row.name}{row.location_code ? <span className={`${HINT} ml-2 font-normal`}>{row.location_code}</span> : null}</p>
        <span className="flex flex-wrap gap-1.5">
          {row.below_threshold && <Tag tone="danger">Below threshold</Tag>}
          {row.warnings.map((code) => <Tag key={code} tone="warning">{warningText(code)}</Tag>)}
        </span>
      </div>
      <dl className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
        <div><dt className={HINT}>Last count</dt><dd className="font-semibold tabular-nums text-hub-ink">{value(row.previous_quantity)}</dd><dd className={HINT}>{row.previous_basis_label}</dd></div>
        <div><dt className={HINT}>Since then</dt><dd className="text-hub-ink">{reconcileDelta(row)}</dd></div>
        <div><dt className={HINT}>Calculated now</dt><dd className="font-semibold tabular-nums text-hub-ink">{value(row.calculated_quantity)}</dd></div>
        <div><dt className={HINT}>Threshold</dt><dd className="text-hub-ink">{row.threshold_qty_base ?? 'Not set'}{row.threshold_source_text ? ` (${row.threshold_source_text})` : ''}</dd></div>
      </dl>
    </li>
  );
}

/** "Reconcile supplies issued since last inventory": a preview of the calculated stock, then one confirmation that is bound to that preview. */
export default function SupplyReconcile({ meta, onForbidden, onChanged }: { meta: EmsMeta; onForbidden: () => void; onChanged: () => void }) {
  const { data, error, loading, reload } = useEmsLoad(() => fetchReconciliation(), 'reconcile', { onForbidden });
  const [confirming, setConfirming] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'info' | 'warning'; text: string } | null>(null);
  const [saved, setSaved] = useState<SnapshotDetail | null>(null);
  const write = useWrite();

  const confirm = () => {
    if (!data) return;
    const expected = data.last_movement_id;
    void write.run(`move-${expected}`, (key) => confirmReconciliation(expected, key), {
      onDone: (detail) => {
        setConfirming(false);
        setSaved(detail);
        setNotice({ tone: 'info', text: detail.unchanged ? 'Nothing new to reconcile. The latest snapshot is already up to date.' : `Reconciliation saved${detail.version !== null ? ` as version ${detail.version}` : ''}. It is calculated, not a physical count.` });
        onChanged();
        void reload(true);
      },
      onConflict: (failure) => {
        setConfirming(false);
        setSaved(null);
        setNotice({ tone: 'warning', text: failure.isStalePreview ? `${failure.message} The preview below is refreshed. Review it, then confirm again.` : failure.message });
        void reload(true);
      },
    });
  };

  return (
    <div className="space-y-4" data-testid="ems-supply-reconcile">
      <p className="text-sm text-hub-ink">Work out what should be on the shelves from the last count and everything issued, received or adjusted since. Nothing is saved until you confirm.</p>
      <div aria-live="polite" data-testid="ems-reconcile-notice">
        {notice && <p role={notice.tone === 'warning' ? 'alert' : 'status'} className={`hub-callout p-3 text-sm ${notice.tone === 'warning' ? 'hub-callout--warning' : ''}`}>{notice.text}</p>}
      </div>
      {saved && <ExportLinks id={saved.id} />}

      {error && data && <LiveNote tone="warning">The preview could not be refreshed: {error}</LiveNote>}
      {error && !data && <LoadError title="The preview could not be loaded" message={error} onRetry={() => void reload(false)} />}
      {!error && loading && !data && <SkeletonRows rows={3} />}

      {data && (
        <div className="space-y-4" aria-busy={loading}>
          <p className={HINT}>
            {data.previous_snapshot
              ? `Starting from version ${data.previous_snapshot.version} (${data.previous_snapshot.kind_label}, ${etDateTime(data.previous_snapshot.finalized_at)}).`
              : 'There is no earlier count yet. Items with no earlier count stay "Not counted".'}
          </p>
          <ul className="flex flex-wrap gap-2 text-sm text-hub-ink" aria-label="Preview summary">
            <li><Tag>{data.summary.items} items</Tag></li>
            <li><Tag>{data.summary.movements_included} stock changes included</Tag></li>
            {data.summary.without_previous_count > 0 && <li><Tag tone="warning">{data.summary.without_previous_count} with no earlier count</Tag></li>}
            {data.summary.negative_results > 0 && <li><Tag tone="danger">{data.summary.negative_results} would be below zero</Tag></li>}
          </ul>

          {data.unmatched_issuances.length > 0 && (
            <section className="space-y-2 rounded-hub-control border border-hub-warning-border bg-hub-warning-bg p-3" aria-label="Issued with no matching supply room item" data-testid="ems-unmatched">
              <h3 className="font-heading text-base font-bold text-hub-ink">Issued with no matching supply room item</h3>
              <p className={HINT}>These were handed out on requests but did not take anything out of stock, so the numbers below do not include them. Fix them with Correct an issuance on the request.</p>
              <ul className="space-y-1 text-sm text-hub-ink">
                {data.unmatched_issuances.map((entry) => <li key={entry.event_id}>{entry.request_number}: {entry.quantity} of {entry.label}{entry.note ? `. ${entry.note}` : ''}</li>)}
              </ul>
            </section>
          )}

          {data.rows.length === 0
            ? <EmptyState icon={<Scale className="hub-empty__icon" aria-hidden="true" />} title="No items to reconcile" body="Add supply room items first." />
            : <ul className="space-y-2" aria-label="Calculated stock">{data.rows.map((row) => <RowCard key={row.supply_item_id} row={row} />)}</ul>}

          {meta.permissions.act && (
            <div className="space-y-3 border-t border-hub-border pt-4">
              {data.nothing_to_reconcile && <p className={HINT} data-testid="ems-nothing-to-reconcile">Nothing has been issued, received or adjusted since the last count, so there is nothing to reconcile.</p>}
              {!confirming && (
                <button type="button" className={BTN_PRIMARY} disabled={data.nothing_to_reconcile || data.rows.length === 0} onClick={() => { write.clear(); setConfirming(true); setNotice(null); setSaved(null); }}>
                  Confirm reconciliation
                </button>
              )}
              {confirming && (
                <ConfirmStep
                  testId="ems-confirm-reconcile"
                  title="Save these calculated numbers?"
                  body={<p>This records the calculated stock above as a new snapshot, in your name. It is not a physical count, and items with no earlier count stay "not counted". Confirming twice does not count anything twice.</p>}
                  confirmLabel="Save reconciliation" busy={write.busy} error={write.error} retry={write.retry} onConfirm={confirm} onCancel={() => setConfirming(false)}
                />
              )}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
