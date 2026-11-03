import Tag from '../../../ui/Tag';
import type { Tone } from '../../../ui/status';
import { LEVEL_WORD, type StockLevel } from './stockLevel';

/**
 * How a stock level looks. Every coloured row also carries the word (Stocked, Reorder soon, Low or out), so colour is
 * never the only signal. The tints and accents come from the shared success, warning and danger tokens.
 */
const TONE: Readonly<Record<StockLevel, Tone>> = { green: 'success', yellow: 'warning', red: 'danger' };

/** Row tint. */
export const LEVEL_ROW: Readonly<Record<StockLevel, string>> = {
  green: 'bg-hub-success-bg',
  yellow: 'bg-hub-warning-bg',
  red: 'bg-hub-danger-bg',
};

/** A lighter tint, for a level that is not from a number typed just now (the count form's blank rows). */
export const LEVEL_ROW_SOFT: Readonly<Record<StockLevel, string>> = {
  green: 'bg-hub-success-bg/50',
  yellow: 'bg-hub-warning-bg/60',
  red: 'bg-hub-danger-bg/60',
};

/** The left accent, drawn inside the element so table cells and list rows line up the same way. */
export const LEVEL_ACCENT: Readonly<Record<StockLevel, string>> = {
  green: 'shadow-[inset_4px_0_0_0_rgb(var(--hub-success))]',
  yellow: 'shadow-[inset_4px_0_0_0_rgb(var(--hub-warning))]',
  red: 'shadow-[inset_4px_0_0_0_rgb(var(--hub-danger))]',
};

/** Row classes for a level, or nothing when the item has no level (never counted, or no expected amount). */
export function levelRowClass(level: StockLevel | null | undefined, soft = false): string {
  if (!level) return '';
  return `${soft ? LEVEL_ROW_SOFT[level] : LEVEL_ROW[level]} ${LEVEL_ACCENT[level]}`;
}

interface Props {
  level: StockLevel | null | undefined;
  testId?: string;
  /** Read before the word by screen readers, for example "Before this count:". */
  prefix?: string;
  className?: string;
}

/** The short word for a level. Nothing when the item has no level. */
export default function LevelTag({ level, testId = 'stock-level', prefix, className = '' }: Props) {
  if (!level) return null;
  return (
    <Tag tone={TONE[level]} className={`!whitespace-normal ${className}`}>
      <span data-testid={testId} data-level={level}>
        {prefix && <span className="sr-only">{prefix} </span>}
        {LEVEL_WORD[level]}
      </span>
    </Tag>
  );
}
