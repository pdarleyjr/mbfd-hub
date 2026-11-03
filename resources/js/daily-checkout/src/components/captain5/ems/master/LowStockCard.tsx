import { useMemo, useState } from 'react';
import { BTN, HINT } from '../shared';
import { shelfName } from './logic';
import { lowStockAdvice, lowStockHeading, lowStockItems, stockOfText } from './lowStock';
import type { MasterItem } from './types';

interface Props {
  items: readonly MasterItem[];
  onOpen: (item: MasterItem) => void;
  /** h2 on the standalone page (its h1 is the page title), h3 under the tab's own h2. */
  headingLevel?: 2 | 3;
}

/** Rows shown before "Show all N", so the card stays short on a phone. */
export const LOW_STOCK_COLLAPSED = 5;

/**
 * The "Low or out" card at the top of the master list, for anyone who can read it. Each red item shows what is left and
 * exactly how many to order to get back to the expected amount; an item with an open order shows that order instead.
 * Pressing an item opens its sheet, where the order is placed.
 */
export default function LowStockCard({ items, onOpen, headingLevel = 3 }: Props) {
  const [showAll, setShowAll] = useState(false);
  const low = useMemo(() => lowStockItems(items), [items]);
  const Heading = headingLevel === 2 ? 'h2' : 'h3';

  if (low.length === 0) {
    return <p className="text-sm font-semibold text-hub-success" data-testid="low-stock-none">Nothing is low or out.</p>;
  }

  const shown = showAll ? low : low.slice(0, LOW_STOCK_COLLAPSED);
  return (
    <section aria-labelledby="low-stock-heading" data-testid="low-stock-card"
      className="space-y-2 rounded-hub-card border border-hub-danger-border bg-hub-danger-bg px-3 py-3 shadow-[inset_4px_0_0_0_rgb(var(--hub-danger))] sm:px-4">
      <div>
        <Heading id="low-stock-heading" className="font-heading text-base font-bold text-hub-ink" data-testid="low-stock-heading">{lowStockHeading(low.length)}</Heading>
        <p className={HINT}>Order these to bring each one back up to its expected amount.</p>
      </div>
      <ul className="divide-y divide-hub-danger-border" aria-labelledby="low-stock-heading">
        {shown.map((item) => {
          const advice = lowStockAdvice(item);
          return (
            <li key={item.id}>
              <button type="button" data-testid="low-stock-item" data-item-id={item.id} aria-haspopup="dialog" onClick={() => onOpen(item)}
                className="flex min-h-11 w-full flex-col items-start gap-0.5 rounded-hub-control py-2 text-left sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                <span className="min-w-0 [overflow-wrap:anywhere]">
                  <span className="font-semibold leading-snug text-hub-ink underline decoration-hub-danger-border decoration-1 underline-offset-4" data-testid="low-stock-name">{item.name}</span>
                  {item.estimated === true && (
                    <span className="ml-1.5 inline-block rounded-hub-control border border-hub-border bg-hub-n-0 px-1.5 text-[12px] font-semibold leading-5 text-hub-n-600" data-testid="low-stock-estimate">Estimate</span>
                  )}
                  <span className="block text-[13px] leading-5 text-hub-n-600">
                    <span data-testid="low-stock-shelf">{item.location_label ?? shelfName(item)}</span>
                    <span aria-hidden="true"> · </span>
                    <span className="sr-only">, </span>
                    <span data-testid="low-stock-amount">{stockOfText(item)}</span>
                  </span>
                </span>
                <span data-testid="low-stock-advice" data-kind={advice.kind}
                  className={`flex-none text-sm ${advice.kind === 'order' ? 'font-bold text-hub-danger' : 'font-semibold text-hub-ink'}`}>
                  {advice.text}
                </span>
              </button>
            </li>
          );
        })}
      </ul>
      {low.length > LOW_STOCK_COLLAPSED && (
        <button type="button" className={`${BTN} hub-btn--tertiary`} data-testid="low-stock-more" aria-expanded={showAll} onClick={() => setShowAll((open) => !open)}>
          {showAll ? 'Show fewer' : `Show all ${low.length}`}
        </button>
      )}
    </section>
  );
}
