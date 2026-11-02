import { forwardRef } from 'react';
import { ChoiceChips } from '../shared';
import { ByUnitList, BySupplyList } from './ExploreLists.tsx';
import { EXPLORE_OPTIONS } from './params.ts';
import StockList from './StockList.tsx';
import type { ExploreView, Overview, StockFilter } from './types.ts';
import { Section } from './ui.tsx';

interface Props {
  data: Overview;
  explore: ExploreView;
  stockFilter: StockFilter;
  onExplore: (view: ExploreView) => void;
  onStockFilter: (filter: StockFilter) => void;
  onOpenItem: (itemId: number) => void;
  onOpenUnit: (unitKey: string) => void;
}

const NOTES: Record<ExploreView, string> = {
  supply: 'Which supplies left the supply room most often in this period.',
  unit: 'Every unit, with what it asked for and what it was given.',
  stock: 'Every supply on the shelf, with how its number is known. Not tied to the time period.',
};

/** "Explore": one segmented control over three lists. Each row opens a drill-down that Back returns from. */
const ExploreSection = forwardRef<HTMLDivElement, Props>(function ExploreSection({ data, explore, stockFilter, onExplore, onStockFilter, onOpenItem, onOpenUnit }, ref) {
  return (
    <div ref={ref} data-testid="supply-data-explore">
      <Section title="Explore" note={NOTES[explore]}>
        <ChoiceChips<ExploreView> label="Explore by" testId="supply-data-explore-view" options={EXPLORE_OPTIONS} value={explore} onChange={onExplore} />
        {explore === 'supply' && <BySupplyList items={data.top_items} onOpen={onOpenItem} onBrowseStock={() => onExplore('stock')} />}
        {explore === 'unit' && <ByUnitList units={data.units} onOpen={onOpenUnit} />}
        {explore === 'stock' && <StockList stock={data.stock} filter={stockFilter} onFilter={onStockFilter} onOpen={onOpenItem} />}
      </Section>
    </div>
  );
});

export default ExploreSection;
