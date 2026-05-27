'use client';

import { useRouter, useSearchParams } from 'next/navigation';
import { useCallback, useMemo } from 'react';

export type BoardFilterState = {
  shift: string[];
  rank: string[];
  from?: string;
  to?: string;
  onlyWithLeave: boolean;
  page: number;
};

export function useBoardFilters(): {
  filters: BoardFilterState;
  setFilters: (patch: Partial<BoardFilterState>) => void;
  toQuery: () => URLSearchParams;
} {
  const router = useRouter();
  const sp = useSearchParams();

  const filters: BoardFilterState = useMemo(
    () => ({
      shift: sp.getAll('shift'),
      rank: sp.getAll('rank'),
      from: sp.get('from') ?? undefined,
      to: sp.get('to') ?? undefined,
      onlyWithLeave: sp.get('onlyWithLeave') === 'true',
      page: Number(sp.get('page') ?? 1),
    }),
    [sp],
  );

  const setFilters = useCallback(
    (patch: Partial<BoardFilterState>) => {
      const next = { ...filters, ...patch };
      const usp = new URLSearchParams();
      next.shift.forEach((s) => usp.append('shift', s));
      next.rank.forEach((r) => usp.append('rank', r));
      if (next.from) usp.set('from', next.from);
      if (next.to) usp.set('to', next.to);
      if (next.onlyWithLeave) usp.set('onlyWithLeave', 'true');
      if (next.page > 1) usp.set('page', String(next.page));
      router.replace(`/board?${usp.toString()}`);
    },
    [filters, router],
  );

  const toQuery = useCallback(() => {
    const usp = new URLSearchParams();
    filters.shift.forEach((s) => usp.append('shift', s));
    filters.rank.forEach((r) => usp.append('rank', r));
    if (filters.from) usp.set('from', filters.from);
    if (filters.to) usp.set('to', filters.to);
    if (filters.onlyWithLeave) usp.set('onlyWithLeave', 'true');
    usp.set('page', String(filters.page));
    return usp;
  }, [filters]);

  return { filters, setFilters, toQuery };
}
