/**
 * Pure wording for the "Low or out" card at the top of the master list: which items are red, how much of each is left,
 * and exactly how many to order to get back to the expected amount (or the open order, when one is already placed).
 * No React and no fetch, so `node --test` loads it directly.
 */
import { formatQty } from './logic.ts';
import { isOpenStatus, quantityText, STATUS_WORD } from './orderLogic.ts';
import type { MasterItem } from './types.ts';

type LowBasis = Pick<MasterItem, 'level' | 'on_hand' | 'expected' | 'to_order' | 'open_order' | 'count_unit' | 'count_unit_label' | 'count_unit_label_plural'>;

/** Every red item, in list order. */
export const lowStockItems = <T extends Pick<MasterItem, 'level'>>(items: readonly T[]): T[] => items.filter((item) => item.level === 'red');

/** "Nov 9" for a plain YYYY-MM-DD date, with no time zone shift. Empty when there is no date. */
export function shortDay(day: string | null | undefined): string {
  if (!day || !/^\d{4}-\d{2}-\d{2}$/.test(day)) return '';
  const [year, month, date] = day.split('-').map(Number);
  return new Intl.DateTimeFormat('en-US', { timeZone: 'UTC', month: 'short', day: 'numeric' }).format(new Date(Date.UTC(year, month - 1, date)));
}

/** "2 of 4", "1 of 3 boxes". */
export function stockOfText(item: LowBasis): string {
  const onHand = formatQty(item.on_hand ?? 0);
  return item.expected === null ? `${onHand} on hand` : `${onHand} of ${quantityText(item, item.expected)}`;
}

export type AdviceKind = 'order' | 'open' | 'none';

/**
 * What to do about a red item: "Order 15", "Order 2 boxes"; or, when an order is already open, its status
 * ("Ordered 4 boxes", "Back ordered 4 boxes, expected Nov 9") instead of asking again.
 */
export function lowStockAdvice(item: LowBasis): { kind: AdviceKind; text: string } {
  const open = item.open_order;
  if (open && isOpenStatus(open.status)) {
    const when = shortDay(open.expected_on);
    return { kind: 'open', text: `${STATUS_WORD[open.status]} ${quantityText(item, open.quantity)}${when ? `, expected ${when}` : ''}` };
  }
  if (item.to_order !== null && item.to_order > 0) return { kind: 'order', text: `Order ${quantityText(item, item.to_order)}` };
  return { kind: 'none', text: 'Nothing to order' };
}

/** "1 item is low or out", "3 items are low or out". */
export const lowStockHeading = (count: number): string => (count === 1 ? '1 item is low or out' : `${count} items are low or out`);
