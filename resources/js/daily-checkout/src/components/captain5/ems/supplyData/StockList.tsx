import { useMemo, useState } from 'react';
import { ChevronRight, PackageSearch } from 'lucide-react';
import { EmptyState } from '../../../ui/StateViews';
import Tag from '../../../ui/Tag';
import { etDate } from '../logic';
import { BTN_SECONDARY, FIELD, HINT, LABEL } from '../shared';
import { daysOfSupplyText, plural, quantityText } from './format.ts';
import type { StockFilter, StockRow } from './types.ts';
import { BasisNote, StockStatusTag } from './ui.tsx';

const PAGE = 50;

const FILTERS: Array<{ id: StockFilter; label: string; test: (row: StockRow) => boolean }> = [
  { id: 'all', label: 'All', test: () => true },
  { id: 'zero', label: 'Zero on hand', test: (row) => row.status === 'zero' },
  { id: 'below_minimum', label: 'Below minimum', test: (row) => row.status === 'below_minimum' },
  { id: 'near_minimum', label: 'Near minimum', test: (row) => row.status === 'near_minimum' },
  { id: 'ok', label: 'OK', test: (row) => row.status === 'ok' },
  { id: 'estimated', label: 'Estimated', test: (row) => row.basis === 'estimate' },
  { id: 'reorder', label: 'Needs reorder', test: (row) => row.needs_reorder },
  { id: 'oldest_count', label: 'Oldest count first', test: () => true },
];

/** Never-counted supplies sort first, then the longest ago. */
const byOldestCount = (a: StockRow, b: StockRow): number => {
  if (a.last_counted_at === b.last_counted_at) return a.name.localeCompare(b.name);
  if (a.last_counted_at === null) return -1;
  if (b.last_counted_at === null) return 1;
  return a.last_counted_at < b.last_counted_at ? -1 : 1;
};

const matches = (row: StockRow, query: string): boolean => {
  const needle = query.trim().toLowerCase();
  if (needle === '') return true;
  return [row.name, row.category, row.location].some((part) => (part ?? '').toLowerCase().includes(needle));
};

function OnHand({ row }: { row: StockRow }) {
  if (row.on_hand === null) return <span className="text-sm font-semibold text-hub-n-600">Not counted</span>;
  return (
    <span className="font-heading text-xl font-bold leading-7 tabular-nums text-hub-ink" data-testid="stock-on-hand">
      {quantityText(row.on_hand, row.count_unit)}
    </span>
  );
}

function StockRowItem({ row, onOpen }: { row: StockRow; onOpen: (itemId: number) => void }) {
  const detail = [row.location, row.category].filter(Boolean).join(' · ');
  return (
    <li>
      <button
        type="button"
        onClick={() => onOpen(row.item_id)}
        data-row-key={`item:${row.item_id}`}
        className="flex min-h-14 w-full min-w-0 items-center gap-3 rounded-hub-control px-3.5 py-3 text-left hover:bg-hub-blue-tint"
      >
        <span className="flex min-w-0 flex-1 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
        <span className="flex min-w-0 flex-col gap-1">
          <span className="break-words font-semibold text-hub-ink">{row.name}</span>
          {detail !== '' && <span className={`${HINT} break-words`}>{detail}</span>}
          <span className="flex flex-wrap items-center gap-1.5">
            <StockStatusTag status={row.status} />
            {row.needs_reorder && <Tag tone="warning">Needs reorder{row.recommended_order !== null ? `: order ${quantityText(row.recommended_order, row.count_unit)}` : ''}</Tag>}
          </span>
          {row.days_of_supply !== null && <span className={HINT}>About {daysOfSupplyText(row.days_of_supply)} at the recent distribution pace</span>}
        </span>
        <span className="flex flex-none flex-col items-start gap-1 sm:items-end sm:text-right">
          <OnHand row={row} />
          <span className="flex flex-wrap items-center gap-1.5 sm:justify-end"><BasisNote basis={row.basis} /></span>
          <span className={HINT}>{row.last_counted_at ? `Counted ${etDate(row.last_counted_at)}` : 'Never counted'}</span>
        </span>
        </span>
        <ChevronRight className="h-5 w-5 flex-none text-hub-n-500" aria-hidden="true" />
      </button>
    </li>
  );
}

interface Props {
  stock: StockRow[];
  filter: StockFilter;
  onFilter: (filter: StockFilter) => void;
  onOpen: (itemId: number) => void;
}

/** Every supply with its status, on-hand number and how that number is known. Filters mirror the "What matters now" counts. */
export default function StockList({ stock, filter, onFilter, onOpen }: Props) {
  const [query, setQuery] = useState('');
  const [shown, setShown] = useState(PAGE);
  const active = FILTERS.find((entry) => entry.id === filter) ?? FILTERS[0];

  const counts = useMemo(() => Object.fromEntries(FILTERS.map((entry) => [entry.id, stock.filter(entry.test).length])) as Record<StockFilter, number>, [stock]);
  const rows = useMemo(() => {
    const filtered = stock.filter((row) => active.test(row) && matches(row, query));
    return filter === 'oldest_count' ? [...filtered].sort(byOldestCount) : filtered;
  }, [stock, active, query, filter]);

  return (
    <div className="space-y-3" data-testid="stock-list">
      <div role="group" aria-label="Show supplies" className="flex flex-wrap gap-2">
        {FILTERS.map((entry) => (
          <button
            key={entry.id}
            type="button"
            aria-pressed={entry.id === filter}
            onClick={() => { setShown(PAGE); onFilter(entry.id); }}
            className={`hub-btn !min-h-11 !px-3 ${entry.id === filter ? 'hub-btn--primary' : 'hub-btn--secondary'}`}
          >
            {entry.label}{entry.id === 'oldest_count' ? '' : ` (${counts[entry.id]})`}
          </button>
        ))}
      </div>

      <div>
        <label className={LABEL} htmlFor="supply-data-stock-search">Search supplies</label>
        <input id="supply-data-stock-search" type="search" className={FIELD} value={query} placeholder="Name, shelf or category" autoComplete="off" maxLength={100} onChange={(event) => { setQuery(event.target.value); setShown(PAGE); }} />
      </div>

      <p className={HINT} role="status" aria-live="polite" data-testid="stock-count">{rows.length} {plural(rows.length, 'supply', 'supplies')}{filter === 'oldest_count' ? ', never-counted first' : ''}</p>

      {rows.length === 0 ? (
        <EmptyState
          icon={<PackageSearch className="hub-empty__icon" aria-hidden="true" />}
          title={stock.length === 0 ? 'No stock to show yet' : 'No supplies match'}
          body={stock.length === 0 ? 'Supplies appear here once they are on the Master Supply Room Inventory.' : 'Try a different filter or fewer words.'}
          action={stock.length > 0 ? <button type="button" className={BTN_SECONDARY} onClick={() => { setQuery(''); onFilter('all'); }}>Show all supplies</button> : undefined}
        />
      ) : (
        <>
          <ul className="divide-y divide-hub-border-soft rounded-hub-control border border-hub-border bg-hub-well" aria-label="Stock">
            {rows.slice(0, shown).map((row) => <StockRowItem key={row.item_id} row={row} onOpen={onOpen} />)}
          </ul>
          {rows.length > shown && (
            <button type="button" className={BTN_SECONDARY} onClick={() => setShown((count) => count + PAGE)}>Show {Math.min(PAGE, rows.length - shown)} more</button>
          )}
        </>
      )}
    </div>
  );
}
