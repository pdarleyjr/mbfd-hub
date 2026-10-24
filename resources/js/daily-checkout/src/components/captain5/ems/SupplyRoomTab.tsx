import { SupplyMovements, SupplySnapshots } from './SupplyHistory';
import SupplyCounts from './SupplyCounts';
import SupplyReconcile from './SupplyReconcile';
import SupplyReorder from './SupplyReorder';
import SupplyStock from './SupplyStock';
import { ChoiceChips } from './shared';
import type { EmsMeta } from './types';

export type SupplyView = 'stock' | 'counts' | 'reconcile' | 'reorder' | 'movements' | 'snapshots';

export const SUPPLY_VIEWS: Array<{ id: SupplyView; label: string }> = [
  { id: 'stock', label: 'Stock' },
  { id: 'counts', label: 'Counts' },
  { id: 'reconcile', label: 'Reconcile' },
  { id: 'reorder', label: 'Reorder' },
  { id: 'movements', label: 'Movements' },
  { id: 'snapshots', label: 'Snapshots' },
];

export const isSupplyView = (value: string | null): value is SupplyView => SUPPLY_VIEWS.some((view) => view.id === value);

const noop = () => undefined;

interface Props {
  meta: EmsMeta;
  view: SupplyView;
  onView: (view: SupplyView) => void;
  onForbidden: () => void;
}

/**
 * Medical Supply Room. Each part loads when it is opened, so a count, a reconciliation or a received order is
 * always read fresh. A part is remounted only when the person switches parts, so a finished action's
 * confirmation is never wiped by a refresh.
 */
export default function SupplyRoomTab({ meta, view, onView, onForbidden }: Props) {
  return (
    <div className="space-y-5" data-testid="ems-supply-tab">
      <ChoiceChips<SupplyView> label="Supply room sections" testId="ems-supply-views" value={view} onChange={onView} options={SUPPLY_VIEWS} />
      <div key={view}>
        {view === 'stock' && <SupplyStock meta={meta} onForbidden={onForbidden} />}
        {view === 'counts' && <SupplyCounts meta={meta} onForbidden={onForbidden} onChanged={noop} />}
        {view === 'reconcile' && <SupplyReconcile meta={meta} onForbidden={onForbidden} onChanged={noop} />}
        {view === 'reorder' && <SupplyReorder meta={meta} onForbidden={onForbidden} onChanged={noop} />}
        {view === 'movements' && <SupplyMovements onForbidden={onForbidden} />}
        {view === 'snapshots' && <SupplySnapshots onForbidden={onForbidden} />}
      </div>
    </div>
  );
}
