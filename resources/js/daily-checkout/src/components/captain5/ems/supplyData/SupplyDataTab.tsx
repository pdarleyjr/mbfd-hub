import { useCallback, useEffect, useMemo, useRef } from 'react';
import { useNavigate, useSearchParams } from 'react-router';
import { hubDateInput } from '../../../../../../shared/timezone.ts';
import ItemDetail from './ItemDetail.tsx';
import OverviewScreen from './OverviewScreen.tsx';
import UnitDetail from './UnitDetail.tsx';
import { parseView, periodParams, SUPPLY_DATA_CLEARED } from './params.ts';
import type { ExploreView, PeriodSelection, StockFilter } from './types.ts';

export interface SupplyDataTabProps {
  /** People with EMS view may open a request from a unit's history (the Submitted Inventory Forms tab). */
  canOpenRequests: boolean;
  /** An EMS call answered 403: the page hides the EMS tabs, exactly as for the other EMS tabs. */
  onForbidden: () => void;
}

/**
 * The Supply Data tab. Everything the person chose lives in the address bar: `?tab=supply-data&period=90d&explore=unit`
 * and, inside a drill-down, `&item=12` or `&unit=unit:45`. A drill-down pushes one history entry, so the browser Back
 * button and the explicit Back button both return to the overview with the same period and filters.
 */
export default function SupplyDataTab({ canOpenRequests, onForbidden }: SupplyDataTabProps) {
  const [params, setParams] = useSearchParams();
  const navigate = useNavigate();
  const view = useMemo(() => parseView(params), [params]);
  const today = hubDateInput(new Date());
  const pushed = useRef(false);
  const lastRow = useRef<string | null>(null);
  const exploreRef = useRef<HTMLDivElement>(null);
  const rootRef = useRef<HTMLDivElement>(null);
  const detailOpen = view.itemId !== null || view.unitKey !== null;
  const wasOpen = useRef(detailOpen);

  const update = useCallback((changes: Record<string, string | null>, push = false) => {
    const next = new URLSearchParams(params);
    for (const [key, value] of Object.entries(changes)) {
      if (value === null || value === 'all') next.delete(key); else next.set(key, value);
    }
    setParams(next, { replace: !push, preventScrollReset: true });
  }, [params, setParams]);

  useEffect(() => { if (!detailOpen) pushed.current = false; }, [detailOpen]);

  // Returning to the overview puts focus back on the row that was opened.
  useEffect(() => {
    if (wasOpen.current && !detailOpen && lastRow.current) {
      const row = rootRef.current?.querySelector<HTMLElement>(`[data-row-key="${lastRow.current}"]`);
      row?.focus({ preventScroll: true });
      row?.scrollIntoView?.({ block: 'center' });
    }
    wasOpen.current = detailOpen;
  }, [detailOpen]);

  const back = useCallback((changes: Record<string, string | null>) => {
    if (pushed.current) { pushed.current = false; navigate(-1); } else update(changes);
  }, [navigate, update]);

  const selectPeriod = (selection: PeriodSelection) => update(periodParams(selection));
  const setExplore = (next: ExploreView) => update({ explore: next === 'supply' ? null : next, stock: null });
  const setStockFilter = (next: StockFilter) => update({ stock: next });
  const showStock = (filter: StockFilter) => {
    update({ explore: 'stock', stock: filter });
    window.requestAnimationFrame(() => exploreRef.current?.scrollIntoView?.({ block: 'start' }));
  };
  const openItem = (itemId: number) => {
    lastRow.current = `item:${itemId}`;
    pushed.current = true;
    update({ item: String(itemId) }, true);
  };
  const openUnit = (unitKey: string) => {
    lastRow.current = `unit:${unitKey}`;
    pushed.current = true;
    update({ unit: unitKey, item: null }, true);
  };
  /** The request opens in the Submitted Inventory Forms tab. One history entry is pushed, so Back returns here. */
  const openRequest = (uuid: string) => update({ tab: 'rescue', request: uuid, ...SUPPLY_DATA_CLEARED }, true);

  const itemOpen = view.itemId !== null;

  return (
    <div ref={rootRef} data-testid="supply-data-tab">
      <div hidden={detailOpen}>
        <OverviewScreen
          ref={exploreRef}
          selection={view.selection}
          today={today}
          explore={view.explore}
          stockFilter={view.stock}
          onSelectPeriod={selectPeriod}
          onExplore={setExplore}
          onStockFilter={setStockFilter}
          onShowStock={showStock}
          onOpenItem={openItem}
          onOpenUnit={openUnit}
          onForbidden={onForbidden}
        />
      </div>
      {itemOpen && view.itemId !== null && (
        <ItemDetail itemId={view.itemId} selection={view.selection} backLabel={view.unitKey !== null ? 'Back to unit' : 'Back to Supply Data'} onBack={() => back({ item: null })} onForbidden={onForbidden} />
      )}
      {!itemOpen && view.unitKey !== null && (
        <UnitDetail
          unitKey={view.unitKey}
          selection={view.selection}
          canOpenRequests={canOpenRequests}
          onOpenRequest={openRequest}
          onOpenItem={openItem}
          onBack={() => back({ unit: null })}
          onForbidden={onForbidden}
        />
      )}
    </div>
  );
}
