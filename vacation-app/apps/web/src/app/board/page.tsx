'use client';

import { useQuery } from '@tanstack/react-query';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import { api } from '@/lib/api';
import { BoardGrid } from './board-grid';
import { EmptyState } from './empty-state';
import { FilterBar } from './filter-bar';
import { MemberDetailDrawer } from './member-detail-drawer';
import { MemberSearch } from './member-search';
import { useBoardFilters } from './use-board-filters';

export default function BoardPage(): React.JSX.Element {
  const { filters, setFilters, toQuery } = useBoardFilters();
  const [activeMemberId, setActiveMemberId] = useState<string | null>(null);
  const [drawerOpen, setDrawerOpen] = useState(false);

  const openMember = (id: string): void => {
    setActiveMemberId(id);
    setDrawerOpen(true);
  };

  const query = useQuery({
    queryKey: ['board', filters],
    queryFn: () => api.board(toQuery()),
  });

  if (query.isLoading) {
    return (
      <div className="flex items-center justify-center py-24 text-stone-600">
        <Loader2 className="mr-2 h-5 w-5 animate-spin" aria-hidden />
        Loading board…
      </div>
    );
  }

  if (query.isError) {
    return (
      <div className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-700">
        Could not load board: {(query.error as Error).message}
      </div>
    );
  }

  const data = query.data;
  if (!data || (data.members.length === 0 && data.cells.length === 0)) {
    return <EmptyState />;
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:gap-3">
        <MemberSearch onPick={openMember} />
        <div className="flex-1">
          <FilterBar filters={filters} setFilters={setFilters} />
        </div>
      </div>
      <BoardGrid
        members={data.members}
        cells={data.cells}
        dateFrom={data.dateRange.from}
        dateTo={data.dateRange.to}
        onMemberClick={openMember}
      />
      <div className="text-xs text-stone-600">
        {data.members.length} of {data.pagination.totalMembers} members ·{' '}
        {data.cells.length} leave entries in the window
      </div>
      <MemberDetailDrawer
        memberId={activeMemberId}
        open={drawerOpen}
        onOpenChange={setDrawerOpen}
      />
    </div>
  );
}
