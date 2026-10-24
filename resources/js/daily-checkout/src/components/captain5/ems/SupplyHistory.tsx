import { useState } from 'react';
import { History, Layers } from 'lucide-react';
import { fetchMovements, fetchSnapshot, fetchSnapshots } from '../../../utils/emsApi';
import { EmptyState, LoadError, SkeletonRows } from '../../ui/StateViews';
import Tag from '../../ui/Tag';
import { ExportLinks } from './SupplyCounts';
import { etDateTime, movementText, warningText } from './logic';
import { BTN_PRIMARY, BTN_SECONDARY, HINT, LiveNote, Pager, useEmsLoad } from './shared';
import type { SnapshotLine } from './types';

const VALUE_SOURCE: Record<string, string> = { counted: 'Counted', calculated: 'Calculated', not_counted: 'Not counted' };

function entryText(line: SnapshotLine): string {
  const entry = line.entry ?? {};
  if ('previous_quantity' in entry) return `Earlier ${entry.previous_quantity ?? 'none'}, issued ${Number(entry.issued ?? 0)}, received ${Number(entry.received ?? 0)}, adjusted ${Number(entry.adjusted ?? 0)}`;
  return ([['cases', 'cases'], ['boxes', 'boxes or packs'], ['each', 'each']] as const)
    .filter(([key]) => entry[key] !== undefined && entry[key] !== null)
    .map(([key, label]) => `${entry[key]} ${label}`).join(', ');
}

/** Every change to stock, newest first: issued, received or adjusted. Entries are never edited; a correction is a new entry. */
export function SupplyMovements({ onForbidden }: { onForbidden: () => void }) {
  const [page, setPage] = useState(1);
  const { data, error, loading, reload } = useEmsLoad(() => fetchMovements(page), `movements-${page}`, { onForbidden });

  return (
    <div className="space-y-4" data-testid="ems-supply-movements">
      <p className={HINT}>Every stock change, newest first. Times are Eastern. A mistake is fixed by a new entry that points back at the old one.</p>
      {error && data && <LiveNote tone="warning">{error}</LiveNote>}
      {error && !data && <LoadError title="Stock changes could not be loaded" message={error} onRetry={() => void reload(false)} />}
      {loading && !data && <SkeletonRows rows={4} />}
      {data && data.movements.length === 0 && <EmptyState icon={<History className="hub-empty__icon" aria-hidden="true" />} title="No stock changes yet" body="Issuing from a request, receiving an order or adjusting stock appears here." />}
      {data && data.movements.length > 0 && (
        <div className="space-y-3" aria-busy={loading}>
          <ul className="space-y-2" aria-label="Stock changes">
            {data.movements.map((movement) => (
              <li key={movement.id} className="space-y-1 rounded-hub-control border border-hub-border bg-hub-well p-3" data-testid="ems-movement">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="min-w-0 break-words font-semibold text-hub-ink">{movement.item_name ?? 'Item'}</p>
                  <Tag tone={movement.quantity_base < 0 ? 'warning' : 'success'}>{movementText(movement)}</Tag>
                </div>
                <p className={HINT}>{etDateTime(movement.occurred_at)}{movement.actor_name ? ` by ${movement.actor_name}` : ''}{movement.reason ? `. ${movement.reason}` : ''}{movement.corrects_movement_id ? '. Corrects an earlier entry.' : ''}</p>
              </li>
            ))}
          </ul>
          <Pager page={data.meta.page} lastPage={data.meta.last_page} total={data.meta.total} noun="stock changes" busy={loading} onPage={setPage} />
        </div>
      )}
    </div>
  );
}

function SnapshotView({ id, onBack }: { id: string; onBack: () => void }) {
  const { data, error, loading, reload } = useEmsLoad(() => fetchSnapshot(id), `snapshot-${id}`);
  return (
    <div className="space-y-4" data-testid="ems-snapshot-detail">
      <button type="button" className={BTN_SECONDARY} onClick={onBack}>Back to snapshots</button>
      {error && !data && <LoadError title="The snapshot could not be loaded" message={error} onRetry={() => void reload(false)} />}
      {loading && !data && <SkeletonRows rows={3} />}
      {data && (
        <>
          <div className="space-y-1">
            <h3 className="font-heading text-lg font-bold text-hub-ink">{data.kind_label}{data.version !== null ? `, version ${data.version}` : ' (draft)'}</h3>
            <p className={HINT}>{data.status === 'final' ? `Final. ${data.finalized_name ?? ''} ${etDateTime(data.finalized_at)}.` : `Draft started ${etDateTime(data.started_at)}.`} {data.scope_location_code ? `Location ${data.scope_location_code}.` : 'All locations.'}</p>
            {data.kind === 'reconciliation' && <p className={HINT}>Calculated from the last counts and the stock changes since. This is not a physical count.</p>}
          </div>
          <ExportLinks id={data.id} />
          <ul className="space-y-2" aria-label="Snapshot lines">
            {data.lines.map((line) => (
              <li key={line.supply_item_id} className="space-y-1 rounded-hub-control border border-hub-border bg-hub-well p-3" data-testid="ems-snapshot-line">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="min-w-0 break-words font-semibold text-hub-ink">{line.item_name}{line.location_code ? <span className={`${HINT} ml-2 font-normal`}>{line.location_code}</span> : null}</p>
                  <span className="flex flex-wrap items-center gap-1.5">
                    <span className="text-base font-bold tabular-nums text-hub-ink">{line.quantity_base ?? 'Not counted'}</span>
                    <Tag tone={line.value_source === 'counted' ? 'success' : line.value_source === 'calculated' ? 'info' : 'neutral'}>{VALUE_SOURCE[line.value_source] ?? line.value_source}</Tag>
                  </span>
                </div>
                {entryText(line) && <p className={HINT}>{entryText(line)}</p>}
                {line.warnings.length > 0 && <p className="flex flex-wrap gap-1.5">{line.warnings.map((code) => <Tag key={code} tone="warning">{warningText(code)}</Tag>)}</p>}
              </li>
            ))}
          </ul>
        </>
      )}
    </div>
  );
}

/** Counts and reconciliations, newest first, with a read-only view and Excel and PDF downloads. */
export function SupplySnapshots({ onForbidden }: { onForbidden: () => void }) {
  const [open, setOpen] = useState<string | null>(null);
  const { data, error, loading, reload } = useEmsLoad(() => fetchSnapshots(), 'snapshots', { onForbidden });
  if (open) return <SnapshotView id={open} onBack={() => setOpen(null)} />;

  return (
    <div className="space-y-4" data-testid="ems-supply-snapshots">
      <p className={HINT}>A snapshot is a saved picture of the shelves: a physical count, or a calculated reconciliation. Final snapshots never change.</p>
      {error && !data && <LoadError title="Snapshots could not be loaded" message={error} onRetry={() => void reload(false)} />}
      {loading && !data && <SkeletonRows rows={3} />}
      {data && data.length === 0 && <EmptyState icon={<Layers className="hub-empty__icon" aria-hidden="true" />} title="No snapshots yet" body="Finalize a count or confirm a reconciliation to create one." />}
      {data && data.length > 0 && (
        <ul className="space-y-2" aria-label="Snapshots">
          {data.map((snapshot) => (
            <li key={snapshot.id} className="flex flex-wrap items-center justify-between gap-3 rounded-hub-control border border-hub-border bg-hub-well p-3" data-testid="ems-snapshot">
              <div className="min-w-0">
                <p className="font-semibold text-hub-ink">{snapshot.kind_label}{snapshot.version !== null ? `, version ${snapshot.version}` : ''} <Tag tone={snapshot.status === 'final' ? 'success' : 'warning'}>{snapshot.status === 'final' ? 'Final' : 'Draft'}</Tag></p>
                <p className={HINT}>{snapshot.scope_location_code ? `Location ${snapshot.scope_location_code}` : 'All locations'}. {snapshot.counted_count} of {snapshot.line_count} items have a number. {snapshot.status === 'final' ? `${snapshot.finalized_name ?? ''} ${etDateTime(snapshot.finalized_at)}` : `Started ${etDateTime(snapshot.started_at)}`}.</p>
              </div>
              <button type="button" className={BTN_PRIMARY} onClick={() => setOpen(snapshot.id)} aria-label={`View ${snapshot.kind_label}${snapshot.version !== null ? ` version ${snapshot.version}` : ''}`}>View</button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
