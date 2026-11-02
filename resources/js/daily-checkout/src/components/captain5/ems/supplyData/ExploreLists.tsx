import { ChevronRight } from 'lucide-react';
import { EmptyState } from '../../../ui/StateViews';
import { etDate } from '../logic';
import { HINT } from '../shared';
import { formatNumber, plural, quantityText } from './format.ts';
import type { Direction, TopItem, UnitRow } from './types.ts';

const rowClass = 'flex min-h-14 w-full min-w-0 items-center gap-3 rounded-hub-control px-3.5 py-3 text-left hover:bg-hub-blue-tint';

const directionText = (item: Pick<TopItem, 'direction' | 'previous_distribution_events'>): string => {
  const labels: Record<Direction, string> = {
    up: `Up from ${item.previous_distribution_events}`,
    down: `Down from ${item.previous_distribution_events}`,
    flat: `Same as before (${item.previous_distribution_events})`,
    new: 'New this period',
  };
  return labels[item.direction] ?? '';
};

interface SupplyProps {
  items: TopItem[];
  onOpen: (itemId: number) => void;
  onBrowseStock: () => void;
}

/** Ranked by distribution events, because quantities of different supplies cannot be compared. */
export function BySupplyList({ items, onOpen, onBrowseStock }: SupplyProps) {
  if (items.length === 0) {
    return (
      <EmptyState
        title="No supplies were distributed in this period"
        body="This list ranks supplies by how many times they were distributed from the supply room, most first. It fills in as Captain 5 reconciles Submitted Inventory Forms. You can still open any supply from Stock."
        action={<button type="button" className="hub-btn hub-btn--secondary !min-h-11" onClick={onBrowseStock}>Browse Stock</button>}
      />
    );
  }
  return (
    <div className="space-y-2">
      <p className={HINT}>Ranked by how many times each supply was distributed, most first.{items.length >= 10 ? ` Showing the top ${items.length}.` : ''}</p>
      <ol className="divide-y divide-hub-border-soft rounded-hub-control border border-hub-border bg-hub-well" aria-label="Supplies by distribution events" data-testid="by-supply-list">
        {items.map((item, index) => (
          <li key={item.item_id}>
            <button type="button" className={rowClass} onClick={() => onOpen(item.item_id)} data-row-key={`item:${item.item_id}`} aria-label={`${item.name}. ${item.distribution_events} ${plural(item.distribution_events, 'distribution event')}. Open details.`}>
              <span className="w-6 flex-none text-center text-sm font-semibold tabular-nums text-hub-n-600" aria-hidden="true">{index + 1}</span>
              <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="break-words font-semibold text-hub-ink">{item.name}</span>
                <span className={`${HINT} break-words`}>
                  {item.distribution_events} {plural(item.distribution_events, 'distribution event')} · {quantityText(item.distributed, item.count_unit)} · to {item.units_supplied} {plural(item.units_supplied, 'unit')}
                </span>
                <span className={HINT}>{directionText(item)}{item.category ? ` · ${item.category}` : ''}</span>
              </span>
              <ChevronRight className="h-5 w-5 flex-none text-hub-n-500" aria-hidden="true" />
            </button>
          </li>
        ))}
      </ol>
    </div>
  );
}

interface UnitProps {
  units: UnitRow[];
  onOpen: (unitKey: string) => void;
}

export function ByUnitList({ units, onOpen }: UnitProps) {
  if (units.length === 0) {
    return <EmptyState title="No units to show" body="Units appear here once the apparatus list is set up for medical inventory requests." />;
  }
  return (
    <ul className="divide-y divide-hub-border-soft rounded-hub-control border border-hub-border bg-hub-well" aria-label="Units" data-testid="by-unit-list">
      {units.map((unit) => {
        const quiet = unit.requests === 0 && unit.distribution_events === 0;
        return (
          <li key={unit.unit_key}>
            <button type="button" className={rowClass} onClick={() => onOpen(unit.unit_key)} data-row-key={`unit:${unit.unit_key}`} aria-label={`${unit.label}. ${quiet ? 'No activity in this period.' : `${unit.requests} ${plural(unit.requests, 'request')}.`} Open details.`}>
              <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="break-words font-semibold text-hub-ink">{unit.label}</span>
                {quiet
                  ? <span className={HINT}>No requests in this period</span>
                  : <span className={`${HINT} break-words`}>{unit.requests} {plural(unit.requests, 'request')} · {unit.distribution_events} {plural(unit.distribution_events, 'distribution event')} · {formatNumber(unit.items_distributed)} {plural(unit.items_distributed, 'supply', 'supplies')}</span>}
                <span className={HINT}>{unit.last_replenished_at ? `Last restocked ${etDate(unit.last_replenished_at)}` : 'No restock recorded'}</span>
              </span>
              <ChevronRight className="h-5 w-5 flex-none text-hub-n-500" aria-hidden="true" />
            </button>
          </li>
        );
      })}
    </ul>
  );
}
