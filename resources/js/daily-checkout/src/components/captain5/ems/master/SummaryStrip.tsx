import { Fragment } from 'react';
import { ORDER_FILTERS, summaryLabel, type OrderFilter } from './orderLogic';
import type { OrderSummary } from './types';

interface Props {
  summary: OrderSummary;
  active: OrderFilter | null;
  onChange: (filter: OrderFilter | null) => void;
}

const TONE: Record<OrderFilter, string> = {
  needs_order: 'text-hub-danger',
  pending: 'text-hub-ink',
  ordered: 'text-hub-blue-strong',
  backordered: 'text-hub-warning-strong',
};

/**
 * "3 to order · 1 pending · 2 ordered · 1 back ordered". Each part filters the list across every shelf; pressing it
 * again goes back to the shelf view. Parts at zero are hidden, except the one that is pressed, so it can be cleared.
 */
export default function SummaryStrip({ summary, active, onChange }: Props) {
  const parts = ORDER_FILTERS.filter((filter) => summary[filter] > 0 || filter === active);
  if (parts.length === 0) return null;
  return (
    <div role="group" aria-label="Orders" data-testid="order-summary" className="flex flex-wrap items-center gap-x-1 gap-y-1">
      {parts.map((filter, index) => (
        <Fragment key={filter}>
          {index > 0 && <span aria-hidden="true" className="text-hub-n-500">·</span>}
          <button type="button" aria-pressed={active === filter} data-testid={`summary-${filter}`}
            onClick={() => onChange(active === filter ? null : filter)}
            className={`inline-flex min-h-11 items-center rounded-hub-control px-2.5 text-sm font-semibold underline-offset-4 ${TONE[filter]} ${active === filter ? 'bg-hub-blue-tint underline ring-1 ring-hub-blue' : 'hover:underline'}`}>
            {summaryLabel(filter, summary[filter])}
          </button>
        </Fragment>
      ))}
    </div>
  );
}
