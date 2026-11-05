import Tag from '../../../ui/Tag';
import { deductedDisplay, formatQty, unitWord } from './logic';
import { orderBadge } from './orderLogic';
import type { MasterItem } from './types';

/** The order badge on its own: "Order 3 boxes", "Ordered 3 boxes" and so on. Nothing when nothing needs attention. */
export function OrderBadge({ item }: { item: MasterItem }) {
  const badge = orderBadge(item);
  if (!badge) return null;
  return (
    <Tag tone={badge.tone} className="!whitespace-normal max-w-full text-left [overflow-wrap:anywhere]">
      <span data-testid="order-badge" data-state={item.order_state}>{badge.text}</span>
    </Tag>
  );
}

/** More was taken than the ledger held: on hand shows 0 until someone counts the shelf. Same shape as the Estimate tag. */
export function CountDueTag({ testId, className = '' }: { testId: string; className?: string }) {
  return (
    <span className={`rounded-hub-control border border-hub-danger-border bg-hub-danger-bg px-1.5 text-[12px] font-semibold leading-5 text-hub-danger ${className}`}
      data-testid={testId}>Count due</span>
  );
}

/**
 * The one stock figure: the number in big type, the unit small, a red minus when stock left since the last count,
 * and under it one short order badge when the item needs ordering or has an open order.
 */
export default function OnHandCell({ item }: { item: MasterItem }) {
  const badge = <OrderBadge item={item} />;
  if (item.on_hand === null) {
    return (
      <span className="flex flex-col items-start gap-1" data-testid="on-hand-cell">
        <span className="text-sm font-semibold text-hub-n-600" data-testid="on-hand">Not counted</span>
        {item.count_due === true && <CountDueTag testId="on-hand-count-due" />}
        {badge}
      </span>
    );
  }
  const unit = unitWord(item, item.on_hand);
  const deducted = deductedDisplay(item);
  return (
    <span className="flex flex-col items-start gap-1" data-testid="on-hand-cell">
      <span className="flex flex-wrap items-baseline gap-x-1.5 gap-y-0" data-testid="on-hand">
        <span className="font-heading text-xl font-bold tabular-nums leading-7 text-hub-ink" data-testid="on-hand-number">{formatQty(item.on_hand)}</span>
        {unit !== '' && <span className="text-[13px] leading-5 text-hub-n-600">{unit}</span>}
        {item.estimated === true && <span className="rounded-hub-control border border-hub-border px-1.5 text-[12px] font-semibold leading-5 text-hub-n-600" data-testid="on-hand-estimate">Estimate</span>}
        {item.count_due === true && <CountDueTag testId="on-hand-count-due" />}
        {deducted && (
          <span className="text-base font-bold tabular-nums text-hub-danger" data-testid="on-hand-deducted">
            <span aria-hidden="true">{deducted.text}</span>
            <span className="sr-only">{deducted.label}</span>
          </span>
        )}
      </span>
      {badge}
    </span>
  );
}
