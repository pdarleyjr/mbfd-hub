import { forwardRef, useRef } from 'react';
import { LoadError, SkeletonRows } from '../../../ui/StateViews';
import { etDateTime } from '../logic';
import { HINT, LiveNote, useEmsLoad } from '../shared';
import AttentionPanel from './AttentionPanel.tsx';
import ChangePanel from './ChangePanel.tsx';
import ExploreSection from './ExploreSection.tsx';
import InsightPanels from './InsightPanels.tsx';
import { fetchOverview } from './api.ts';
import { periodText } from './format.ts';
import { exportHref, selectionKey, selectionReady } from './params.ts';
import type { ExploreView, Overview, PeriodSelection, StockFilter } from './types.ts';
import { ExportLink } from './ui.tsx';

interface Props {
  selection: PeriodSelection;
  today: string;
  explore: ExploreView;
  stockFilter: StockFilter;
  onSelectPeriod: (selection: PeriodSelection) => void;
  onExplore: (view: ExploreView) => void;
  onStockFilter: (filter: StockFilter) => void;
  onShowStock: (filter: StockFilter) => void;
  onOpenItem: (itemId: number) => void;
  onOpenUnit: (unitKey: string) => void;
  onForbidden: () => void;
}

/**
 * The overview: what matters now, what is changing, and Explore. The answer remembers which period it is for, so choosing
 * another period never shows the old period's numbers under the new choice; the period picker stays on screen throughout.
 */
const OverviewScreen = forwardRef<HTMLDivElement, Props>(function OverviewScreen(props, exploreRef) {
  const { selection, today, explore, stockFilter, onSelectPeriod, onExplore, onStockFilter, onShowStock, onOpenItem, onOpenUnit, onForbidden } = props;
  const ready = selectionReady(selection, today);
  const key = selectionKey(selection);
  const { data: loaded, error, loading, reload } = useEmsLoad(
    async () => ({ key, value: ready ? await fetchOverview(selection) : null }),
    key,
    { onForbidden },
  );
  const data: Overview | null = loaded !== null && loaded.key === key ? loaded.value : null;
  const lastPeriod = useRef<Overview['period'] | null>(null);
  if (data) lastPeriod.current = data.period;
  const seed = lastPeriod.current ? { from: lastPeriod.current.from, to: lastPeriod.current.to } : null;
  const waiting = ready && data === null && !error;

  return (
    <div className="space-y-6" data-testid="supply-data-overview">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 space-y-1">
          <h2 className="font-heading text-lg font-bold text-hub-ink">Supply Data</h2>
          <p className={HINT}>Where supplies went from the supply room, and what needs attention.</p>
          {data && <p className={HINT} data-testid="supply-data-asof">As of {etDateTime(data.generated_at)}</p>}
        </div>
        <ExportLink href={exportHref(selection)} />
      </div>

      <div className="sr-only" role="status" aria-live="polite">{data ? `Supply Data for ${periodText(data.period)} is showing.` : ''}</div>

      {error && !data && <LoadError title="Supply Data could not be loaded" message={error} onRetry={() => void reload(false)} />}
      {error && data && <LiveNote tone="warning">Supply Data could not be refreshed: {error} What you see may be out of date.</LiveNote>}

      {data && (data.scope_notes ?? []).length > 0 && <LiveNote>{(data.scope_notes ?? []).join(' ')}</LiveNote>}
      {data && <AttentionPanel attention={data.attention} onShow={onShowStock} />}
      {waiting && !data && <SkeletonRows rows={2} className="!h-24" />}

      <ChangePanel data={data} loading={waiting} needsDates={!ready} selection={selection} today={today} seed={seed} onSelect={onSelectPeriod} />

      {data && (
        <>
          <ExploreSection ref={exploreRef} data={data} explore={explore} stockFilter={stockFilter} onExplore={onExplore} onStockFilter={onStockFilter} onOpenItem={onOpenItem} onOpenUnit={onOpenUnit} />
          <InsightPanels data={data} />
        </>
      )}
    </div>
  );
});

export default OverviewScreen;
