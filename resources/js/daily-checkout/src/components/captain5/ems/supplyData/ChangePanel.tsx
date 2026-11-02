import { BarChart3, Minus, TrendingDown, TrendingUp } from 'lucide-react';
import { EmptyState, SkeletonRows } from '../../../ui/StateViews';
import { HINT } from '../shared';
import { changeText, formatNumber, periodText, plural, previousPeriodText } from './format.ts';
import PeriodPicker from './PeriodPicker.tsx';
import TrendChart from './TrendChart.tsx';
import type { Overview, PeriodSelection } from './types.ts';
import { MeaningNote, Section, Stat } from './ui.tsx';

interface Props {
  /** The answer for the period now selected, or null while it loads or cannot be asked for yet. */
  data: Overview | null;
  loading: boolean;
  /** A custom range with no valid dates yet: nothing can be asked for. */
  needsDates: boolean;
  selection: PeriodSelection;
  today: string;
  /** The dates last shown, offered as the starting point for a custom range. */
  seed: { from: string; to: string } | null;
  onSelect: (selection: PeriodSelection) => void;
}

const COMPARE_ICON = { up: TrendingUp, down: TrendingDown, flat: Minus, none: Minus } as const;

function Headline({ data }: { data: Overview }) {
  const { headline, period } = data;
  const change = changeText(headline);
  const Icon = COMPARE_ICON[change.tone];
  return (
    <div className="rounded-hub-control border border-hub-border bg-hub-well p-3 sm:p-4" data-testid="supply-data-headline">
      <p className={HINT}>{periodText(period)}</p>
      <p className="flex flex-wrap items-baseline gap-x-2">
        <span className="font-heading text-4xl font-bold leading-10 tabular-nums text-hub-ink" data-testid="headline-events">{headline.distribution_events}</span>
        <span className="text-sm font-semibold text-hub-ink">{plural(headline.distribution_events, 'distribution event')}</span>
      </p>
      <p className="mt-1 flex items-start gap-1.5 text-sm text-hub-ink" data-testid="headline-change">
        <Icon className="mt-0.5 h-4 w-4 flex-none text-hub-n-600" aria-hidden="true" />
        <span>{change.text}</span>
      </p>
      <p className={`${HINT} mt-0.5`}>Previous period: {previousPeriodText(period)}</p>
      <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 border-t border-hub-border-soft pt-3 sm:grid-cols-4">
        <Stat label="Supplies distributed">{formatNumber(headline.items_distributed)}</Stat>
        <Stat label="Units supplied">{formatNumber(headline.units_supplied)}</Stat>
        <Stat label="Requests submitted">{formatNumber(headline.requests_submitted)}</Stat>
        <Stat label="Requests reconciled">{formatNumber(headline.requests_reconciled)}</Stat>
      </dl>
    </div>
  );
}

const thin = (data: Overview, section: string): string | null => data.data_quality.insufficient_data?.find((entry) => entry.section === section)?.message ?? null;

function Trend({ data }: { data: Overview }) {
  const hasActivity = data.headline.distribution_events > 0 || data.trend.some((bucket) => bucket.distribution_events > 0);
  if (!hasActivity) {
    return (
      <div className="space-y-2" data-testid="supply-data-trend-empty">
        <EmptyState
          icon={<BarChart3 className="hub-empty__icon" aria-hidden="true" />}
          title="Nothing has been distributed in this period yet"
          body="A distribution is recorded when Captain 5 reconciles a Submitted Inventory Form. As forms are reconciled, a weekly chart and the comparison with the previous period appear here."
        />
        {thin(data, 'distribution') && <p className={HINT} data-testid="trend-thin">{thin(data, 'distribution')}</p>}
        <MeaningNote label={data.label} />
      </div>
    );
  }
  return (
    <div className="space-y-2" data-testid="supply-data-trend">
      <TrendChart
        title={data.trend[0]?.month_start !== undefined ? 'Distribution events by month' : 'Distribution events by week'}
        buckets={data.trend}
        noun="distribution event"
        columns={[
          { name: 'Distribution events', value: (bucket) => bucket.distribution_events },
          { name: 'Requests', value: (bucket) => bucket.requests },
        ]}
      />
      {thin(data, 'trend') && <p className={HINT} data-testid="trend-thin">{thin(data, 'trend')}</p>}
      <MeaningNote label={data.label} />
    </div>
  );
}

/** "What's changing": the period picker (always on screen so focus stays put), one headline with its comparison, and one trend. */
export default function ChangePanel({ data, loading, needsDates, selection, today, seed, onSelect }: Props) {
  return (
    <Section title="What's changing" testId="supply-data-change">
      <PeriodPicker selection={selection} today={today} seed={seed} onSelect={onSelect} />
      {data ? (
        <>
          <Headline data={data} />
          <Trend data={data} />
        </>
      ) : loading ? (
        <SkeletonRows rows={2} className="!h-28" />
      ) : needsDates ? (
        <p className="text-sm text-hub-n-800" data-testid="supply-data-choose-dates">Choose both dates and select Show these dates to see this range.</p>
      ) : null}
    </Section>
  );
}
