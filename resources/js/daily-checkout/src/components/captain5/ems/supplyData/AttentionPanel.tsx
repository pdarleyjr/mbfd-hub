import { BTN_SECONDARY } from '../shared';
import { plural } from './format.ts';
import type { Attention, StockFilter } from './types.ts';
import { Figure, Section } from './ui.tsx';

interface Indicator {
  key: 'below_minimum' | 'zero_stock' | 'estimated' | 'needs_reorder';
  label: string;
  explain: string;
  filter: StockFilter;
  /** Which tone a non-zero value gets; zero is always quiet. */
  tone: 'danger' | 'warning' | 'info';
}

const INDICATORS: Indicator[] = [
  { key: 'below_minimum', label: 'Below minimum', explain: 'Under the level the supply room wants to keep.', filter: 'below_minimum', tone: 'warning' },
  { key: 'zero_stock', label: 'Zero on hand', explain: 'Counted or calculated as none left.', filter: 'zero', tone: 'danger' },
  { key: 'estimated', label: 'Estimated — not yet counted', explain: 'Best-guess numbers until someone counts the shelf.', filter: 'estimated', tone: 'info' },
  { key: 'needs_reorder', label: 'Needs reorder', explain: 'At or under the order level.', filter: 'reorder', tone: 'warning' },
];

const TONE_TEXT = { danger: 'text-hub-danger', warning: 'text-hub-warning', info: 'text-hub-info' } as const;

interface Props {
  attention: Attention;
  onShow: (filter: StockFilter) => void;
}

/** "What matters now": four counts that each open the Stock list already filtered, and one plain line about counting. */
export default function AttentionPanel({ attention, onShow }: Props) {
  const stale = attention.not_counted_recently;
  return (
    <Section title="What matters now" note="Tap a count to see which supplies it is. These are today's numbers, not tied to the time period below." testId="supply-data-attention">
      <ul className="grid grid-cols-2 gap-2 lg:grid-cols-4">
        {INDICATORS.map((indicator) => {
          const value = attention[indicator.key];
          const quiet = value === 0;
          const supplies = typeof value === 'number' ? `${value} ${plural(value, 'supply', 'supplies')}` : 'unknown number of supplies';
          return (
            <li key={indicator.key} className="min-w-0">
              <button
                type="button"
                onClick={() => onShow(indicator.filter)}
                data-testid={`attention-${indicator.key}`}
                aria-label={`${indicator.label}: ${supplies}. Show these supplies.`}
                className="flex h-full min-h-[5.5rem] w-full flex-col items-start gap-1 rounded-hub-control border border-hub-border bg-hub-well p-3 text-left hover:bg-hub-blue-tint"
              >
                <span className={`font-heading text-3xl font-bold leading-8 ${quiet ? 'text-hub-ink' : TONE_TEXT[indicator.tone]}`}><Figure value={value} /></span>
                <span className="text-sm font-semibold leading-5 text-hub-ink">{indicator.label}</span>
                <span className="text-[12px] leading-4 text-hub-n-600">{indicator.explain}</span>
              </button>
            </li>
          );
        })}
      </ul>
      <div className="flex flex-wrap items-center justify-between gap-2 rounded-hub-control border border-hub-border-soft bg-hub-n-25 px-3 py-2" data-testid="attention-stale">
        <p className="text-sm text-hub-ink">
          {stale === 0
            ? 'Every supply has been counted recently.'
            : <><strong className="tabular-nums">{stale}</strong> {plural(stale, 'supply has', 'supplies have')} not been counted recently.</>}
          <span className="block text-[12px] leading-4 text-hub-n-600">No physical count in 10 days, or ever. An estimate is not a count.</span>
        </p>
        {stale > 0 && <button type="button" className={`${BTN_SECONDARY} !px-3`} onClick={() => onShow('not_counted')}>Show these supplies, oldest count first</button>}
      </div>
    </Section>
  );
}
