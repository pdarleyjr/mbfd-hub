'use client';

import { useQuery } from '@tanstack/react-query';
import { Loader2, Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';

/**
 * Debounced typeahead search across active members by last name, first
 * name, or employee ID. The list shows below the input and `onPick`
 * fires when the user chooses one (used by the board to open the
 * member detail drawer).
 */
export function MemberSearch({
  onPick,
}: {
  onPick: (memberId: string) => void;
}): React.JSX.Element {
  const [value, setValue] = useState('');
  const [debounced, setDebounced] = useState('');
  const [open, setOpen] = useState(false);
  const wrapperRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const t = setTimeout(() => setDebounced(value.trim()), 150);
    return () => clearTimeout(t);
  }, [value]);

  // Close on outside click.
  useEffect(() => {
    function onDocClick(e: MouseEvent): void {
      if (!wrapperRef.current?.contains(e.target as Node)) setOpen(false);
    }
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, []);

  const q = useQuery({
    queryKey: ['memberSearch', debounced],
    queryFn: () => api.searchMembers(debounced),
    enabled: debounced.length > 0,
    staleTime: 30_000,
  });

  return (
    <div ref={wrapperRef} className="relative w-full sm:w-80">
      <div className="relative">
        <Search
          aria-hidden
          className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-stone-500"
        />
        <input
          type="search"
          value={value}
          onChange={(e) => {
            setValue(e.target.value);
            setOpen(true);
          }}
          onFocus={() => value.length > 0 && setOpen(true)}
          placeholder="Search by last name, first name, or emp ID"
          className="h-9 w-full rounded-md border border-stone-200 bg-white pl-8 pr-8 text-sm placeholder:text-stone-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-700"
        />
        {value.length > 0 && (
          <button
            type="button"
            onClick={() => {
              setValue('');
              setOpen(false);
            }}
            aria-label="Clear search"
            className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-0.5 text-stone-500 hover:bg-stone-100 hover:text-stone-800"
          >
            <X className="h-3.5 w-3.5" />
          </button>
        )}
      </div>

      {open && debounced.length > 0 && (
        <div className="absolute left-0 right-0 top-full z-30 mt-1 max-h-80 overflow-auto rounded-md border border-stone-200 bg-white shadow-lg">
          {q.isLoading && (
            <div className="flex items-center justify-center gap-2 px-3 py-4 text-sm text-stone-500">
              <Loader2 className="h-4 w-4 animate-spin" aria-hidden /> Searching…
            </div>
          )}
          {!q.isLoading && (q.data?.matches.length ?? 0) === 0 && (
            <div className="px-3 py-3 text-sm text-stone-500">No matches.</div>
          )}
          {q.data?.matches.map((m) => (
            <button
              key={m.id}
              type="button"
              onClick={() => {
                onPick(m.id);
                setOpen(false);
              }}
              className={cn(
                'flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-stone-100',
              )}
            >
              <span className="flex-1 truncate font-medium text-stone-900">
                {m.lastName}, {m.firstName}
              </span>
              <span className="rounded bg-stone-200 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-stone-700">
                #{m.employeeId}
              </span>
              {m.rank && (
                <span className="rounded bg-admin-850 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
                  {m.rank.code}
                </span>
              )}
              {m.shift && (
                <span className="rounded bg-brand-700 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
                  {m.shift}
                </span>
              )}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
