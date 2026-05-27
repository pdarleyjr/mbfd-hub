'use client';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { BoardFilterState } from './use-board-filters';

const SHIFTS = ['A', 'B', 'C', 'STAFF'] as const;
const RANKS = ['DC', 'CAPT', 'LT', 'FF', 'PROB'] as const;

export function FilterBar({
  filters,
  setFilters,
}: {
  filters: BoardFilterState;
  setFilters: (patch: Partial<BoardFilterState>) => void;
}): React.JSX.Element {
  const toggle = (key: 'shift' | 'rank', value: string): void => {
    const current = filters[key];
    setFilters({
      [key]: current.includes(value)
        ? current.filter((v) => v !== value)
        : [...current, value],
    } as Partial<BoardFilterState>);
  };

  return (
    <div className="flex flex-wrap items-center gap-3 rounded-lg border border-stone-200 bg-white p-3 shadow-sm">
      <span className="text-xs font-semibold uppercase tracking-wide text-stone-600">
        Shift
      </span>
      <div className="flex gap-1">
        {SHIFTS.map((s) => (
          <button
            key={s}
            type="button"
            onClick={() => toggle('shift', s)}
            className={cn(
              'rounded-md border px-2.5 py-1 text-xs font-semibold transition-colors',
              filters.shift.includes(s)
                ? 'border-brand-700 bg-brand-700 text-white'
                : 'border-stone-200 bg-white text-stone-800 hover:bg-stone-100',
            )}
          >
            {s}
          </button>
        ))}
      </div>

      <span className="ml-4 text-xs font-semibold uppercase tracking-wide text-stone-600">
        Rank
      </span>
      <div className="flex flex-wrap gap-1">
        {RANKS.map((r) => (
          <button
            key={r}
            type="button"
            onClick={() => toggle('rank', r)}
            className={cn(
              'rounded-md border px-2.5 py-1 text-xs font-semibold transition-colors',
              filters.rank.includes(r)
                ? 'border-admin-850 bg-admin-850 text-white'
                : 'border-stone-200 bg-white text-stone-800 hover:bg-stone-100',
            )}
          >
            {r}
          </button>
        ))}
      </div>

      <label className="ml-4 flex items-center gap-2 text-sm text-stone-800">
        <input
          type="checkbox"
          className="h-4 w-4 accent-brand-700"
          checked={filters.onlyWithLeave}
          onChange={(e) => setFilters({ onlyWithLeave: e.target.checked })}
        />
        Only members with leave
      </label>

      <div className="ml-auto flex items-center gap-2">
        <input
          type="date"
          value={filters.from ?? ''}
          onChange={(e) => setFilters({ from: e.target.value || undefined })}
          className="h-9 rounded-md border border-stone-200 bg-white px-2 text-sm"
        />
        <span className="text-stone-400">→</span>
        <input
          type="date"
          value={filters.to ?? ''}
          onChange={(e) => setFilters({ to: e.target.value || undefined })}
          className="h-9 rounded-md border border-stone-200 bg-white px-2 text-sm"
        />
        <Button
          variant="ghost"
          size="sm"
          onClick={() =>
            setFilters({ shift: [], rank: [], from: undefined, to: undefined, onlyWithLeave: false })
          }
        >
          Clear
        </Button>
      </div>
    </div>
  );
}
