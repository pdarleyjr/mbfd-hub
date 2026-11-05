/**
 * Stock levels: green (stocked), yellow (reorder soon) and red (low or out), worked out the same way the server does
 * (app/Services/Ems/StockLevel.php). Yellow starts at half the expected amount and red at a quarter, unless someone has
 * set their own line for the item. Pure functions with no imports, so `node --test` loads this file directly.
 */

export type StockLevel = 'green' | 'yellow' | 'red';

/** Whole count units: at or below `reorder` the item is yellow, at or below `critical` (or at 0) it is red. */
export interface StockLines { reorder: number; critical: number }

/** The short word every coloured row carries, so colour is never the only signal. */
export const LEVEL_WORD: Readonly<Record<StockLevel, string>> = { green: 'Stocked', yellow: 'Reorder soon', red: 'Low or out' };

const isNumber = (value: unknown): value is number => typeof value === 'number' && Number.isFinite(value);

/** The preset yellow line: half the expected amount, rounded down. */
export const presetReorder = (expected: number): number => Math.floor(expected / 2);

/** The preset red line: a quarter of the expected amount, rounded down. */
export const presetCritical = (expected: number): number => Math.floor(expected / 4);

/**
 * The two lines for an expected amount. A null override means "use the preset". Yellow is never above the expected
 * amount (a lowered expected amount wins over a person's higher line, as on the server) and red is never above yellow.
 * No lines (null) when there is no expected amount, or it is under 1.
 */
export function stockLines(expected: number | null | undefined, reorderOverride: number | null = null, criticalOverride: number | null = null): StockLines | null {
  if (!isNumber(expected) || expected < 1) return null;
  const reorder = Math.min(Math.floor(expected), isNumber(reorderOverride) ? reorderOverride : presetReorder(expected));
  const critical = Math.min(isNumber(criticalOverride) ? criticalOverride : presetCritical(expected), reorder);
  return { reorder, critical };
}

/** Where an on-hand amount (in count units) sits. Null when it was never counted or there are no lines. */
export function levelFor(onHand: number | null | undefined, lines: StockLines | null): StockLevel | null {
  if (lines === null || !isNumber(onHand)) return null;
  if (onHand <= 0 || onHand <= lines.critical) return 'red';
  if (onHand <= lines.reorder) return 'yellow';
  return 'green';
}

export const isStockLevel = (value: unknown): value is StockLevel => value === 'green' || value === 'yellow' || value === 'red';

interface LineBasis {
  expected: number | null;
  reorder_at?: number | null;
  critical_at?: number | null;
}

/**
 * The lines an item has on the server, used as given (null from the server means no lines). An older answer without
 * the fields falls back to the presets from the expected amount.
 */
export function itemLines(item: LineBasis): StockLines | null {
  if (item.reorder_at === undefined) return stockLines(item.expected);
  if (!isNumber(item.reorder_at)) return null;
  const critical = isNumber(item.critical_at) ? item.critical_at : isNumber(item.expected) ? presetCritical(item.expected) : 0;
  return { reorder: item.reorder_at, critical: Math.min(critical, item.reorder_at) };
}
