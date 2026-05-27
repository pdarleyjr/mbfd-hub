'use client';

import { useVirtualizer } from '@tanstack/react-virtual';
import * as React from 'react';
import type { BoardMember, BoardCell } from '@mbfd-vacation/shared';
import { cn } from '@/lib/utils';

type Props = {
  members: BoardMember[];
  cells: BoardCell[];
  dateFrom: string;
  dateTo: string;
  onMemberClick?: (memberId: string) => void;
};

function daysBetween(from: string, to: string): string[] {
  const out: string[] = [];
  const start = new Date(`${from}T00:00:00Z`);
  const end = new Date(`${to}T00:00:00Z`);
  for (let d = new Date(start); d <= end; d.setUTCDate(d.getUTCDate() + 1)) {
    out.push(d.toISOString().slice(0, 10));
  }
  return out;
}

/** Map (memberId, dayDate, blockIndex) → cell for O(1) lookup. */
function indexCells(cells: BoardCell[]): Map<string, BoardCell> {
  const m = new Map<string, BoardCell>();
  for (const c of cells) {
    m.set(`${c.memberId}|${c.dayDate}|${c.blockIndex}`, c);
  }
  return m;
}

export function BoardGrid({ members, cells, dateFrom, dateTo, onMemberClick }: Props): React.JSX.Element {
  const days = React.useMemo(() => daysBetween(dateFrom, dateTo), [dateFrom, dateTo]);
  const cellIndex = React.useMemo(() => indexCells(cells), [cells]);

  // Column width: 56px desktop (28px AM + 28px PM), 36px mobile (18+18).
  // Row height: 36px desktop, 32px mobile.
  const parentRef = React.useRef<HTMLDivElement>(null);
  const COL_WIDTH = 56;
  const ROW_HEIGHT = 36;
  const MEMBER_COL_WIDTH = 200;

  const rowVirtualizer = useVirtualizer({
    count: members.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => ROW_HEIGHT,
    overscan: 8,
  });

  const colVirtualizer = useVirtualizer({
    count: days.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => COL_WIDTH,
    horizontal: true,
    overscan: 4,
  });

  return (
    <div
      ref={parentRef}
      className="relative max-h-[calc(100vh-220px)] overflow-auto rounded-lg border border-stone-200 bg-white shadow-sm"
    >
      <div
        style={{
          height: rowVirtualizer.getTotalSize() + ROW_HEIGHT,
          width: colVirtualizer.getTotalSize() + MEMBER_COL_WIDTH,
          position: 'relative',
        }}
      >
        {/* Sticky top-left corner */}
        <div
          className="sticky left-0 top-0 z-30 flex items-center border-b border-r border-stone-200 bg-stone-50 px-3 text-xs font-semibold uppercase tracking-wide text-stone-600"
          style={{
            width: MEMBER_COL_WIDTH,
            height: ROW_HEIGHT,
          }}
        >
          Member
        </div>

        {/* Sticky date header */}
        <div
          className="sticky top-0 z-20"
          style={{
            position: 'sticky',
            top: 0,
            height: ROW_HEIGHT,
            width: colVirtualizer.getTotalSize(),
            marginLeft: MEMBER_COL_WIDTH,
          }}
        >
          {colVirtualizer.getVirtualItems().map((vc) => {
            const day = days[vc.index];
            if (!day) return null;
            const d = new Date(`${day}T12:00:00Z`);
            return (
              <div
                key={vc.key}
                className="absolute flex flex-col items-center justify-center border-b border-r border-stone-200 bg-stone-50 text-[11px] font-semibold text-stone-700 tabular"
                style={{
                  left: vc.start,
                  width: vc.size,
                  height: ROW_HEIGHT,
                }}
              >
                <span>{d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}</span>
                <span className="text-[10px] text-stone-400">
                  {d.toLocaleDateString(undefined, { weekday: 'short' })}
                </span>
              </div>
            );
          })}
        </div>

        {/* Body */}
        <div style={{ marginTop: ROW_HEIGHT, marginLeft: 0 }}>
          {rowVirtualizer.getVirtualItems().map((vr) => {
            const member = members[vr.index];
            if (!member) return null;
            return (
              <div
                key={vr.key}
                className="absolute left-0 flex border-b border-stone-100 hover:bg-stone-50/60"
                style={{
                  top: vr.start + ROW_HEIGHT,
                  height: vr.size,
                  width: colVirtualizer.getTotalSize() + MEMBER_COL_WIDTH,
                }}
              >
                {/* Sticky member column */}
                <button
                  type="button"
                  onClick={() => onMemberClick?.(member.id)}
                  className={cn(
                    'sticky left-0 z-10 flex items-center gap-2 border-r border-stone-200 bg-white px-3 text-left text-sm',
                    onMemberClick && 'hover:bg-stone-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-700',
                  )}
                  style={{ width: MEMBER_COL_WIDTH, height: vr.size }}
                  aria-label={`Open details for ${member.lastName}, ${member.firstName}`}
                >
                  <span className="font-semibold tabular">{member.lastName}</span>
                  <span className="text-xs text-stone-600">
                    {member.rank?.code ?? ''}
                  </span>
                  <span className="ml-auto text-xs text-stone-400">{member.shift ?? ''}</span>
                </button>

                {/* Day cells */}
                {colVirtualizer.getVirtualItems().map((vc) => {
                  const day = days[vc.index];
                  if (!day) return null;
                  const am = cellIndex.get(`${member.id}|${day}|0`);
                  const pm = cellIndex.get(`${member.id}|${day}|1`);
                  return (
                    <div
                      key={vc.key}
                      className="absolute flex border-r border-stone-100"
                      style={{
                        left: vc.start + MEMBER_COL_WIDTH,
                        width: vc.size,
                        height: vr.size,
                      }}
                    >
                      <CellHalf cell={am} />
                      <CellHalf cell={pm} />
                    </div>
                  );
                })}
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}

function CellHalf({ cell }: { cell: BoardCell | undefined }): React.JSX.Element {
  if (!cell) {
    return <div className="flex-1 border-r border-stone-50" />;
  }
  return (
    <div
      className={cn(
        'flex flex-1 items-center justify-center border-r border-stone-50 text-[10px] font-bold uppercase',
      )}
      title={`${cell.leaveCode.label} (${cell.leaveCode.code})`}
      style={{
        color: cell.leaveCode.uiColor,
        backgroundColor: `${cell.leaveCode.uiColor}1A`, // 10% opacity
      }}
    >
      {cell.leaveCode.code}
    </div>
  );
}
