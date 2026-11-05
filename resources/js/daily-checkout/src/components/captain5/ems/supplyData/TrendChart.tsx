import { useId, useState } from 'react';
import { BTN_SECONDARY } from '../shared';
import { bucketLabel, formatNumber, isMonthly, plural } from './format.ts';
import type { TrendBucket } from './types.ts';

export interface ChartColumn {
  /** Column heading in the numbers table, for example "Distribution events". */
  name: string;
  value: (bucket: TrendBucket) => number | null | undefined;
}

interface Props {
  /** What the chart is, in a few words ("Distribution events by week"). Names the image and captions the table. */
  title: string;
  buckets: TrendBucket[];
  /** The first column is the one drawn; any others appear only in the numbers table. */
  columns: ChartColumn[];
  /** One noun for the drawn value, used in the spoken summary ("distribution event"). */
  noun: string;
  testId?: string;
}

const W = 320;
const H = 148;
const TOP = 18;
const BASE = 122;
const SIDE = 4;

const valueOf = (column: ChartColumn, bucket: TrendBucket): number => {
  const value = column.value(bucket);
  return typeof value === 'number' && Number.isFinite(value) ? value : 0;
};

/**
 * A small bar chart drawn as inline SVG: no chart library, nothing that depends on hover. The picture is named for
 * screen readers, and "Show numbers" opens the same figures as a table, so no one needs the picture to read the data.
 */
export default function TrendChart({ title, buckets, columns, noun, testId }: Props) {
  const [showNumbers, setShowNumbers] = useState(false);
  const id = useId();
  const monthly = isMonthly(buckets);
  const plotted = columns[0];
  const values = buckets.map((bucket) => valueOf(plotted, bucket));
  const total = values.reduce((sum, value) => sum + value, 0);
  const peakValue = Math.max(0, ...values);
  const peakIndex = peakValue > 0 ? values.indexOf(peakValue) : -1;
  const slot = (W - SIDE * 2) / Math.max(buckets.length, 1);
  const barWidth = Math.min(28, slot * 0.64);
  const labelEvery = Math.ceil(buckets.length / 7);
  const showValues = buckets.length <= 14;
  const unit = monthly ? 'month' : 'week';
  const peakLong = peakIndex >= 0 ? bucketLabel(buckets[peakIndex], monthly).long : '';
  const peakPhrase = monthly ? peakLong : `the ${peakLong.charAt(0).toLowerCase()}${peakLong.slice(1)}`;
  const summary = peakIndex >= 0
    ? `${formatNumber(total)} ${plural(total, noun)} over ${buckets.length} ${plural(buckets.length, unit)}. The most was ${formatNumber(peakValue)}, in ${peakPhrase}.`
    : `No ${plural(0, noun)} over ${buckets.length} ${plural(buckets.length, unit)}.`;

  return (
    <figure className="m-0 space-y-2" data-testid={testId ?? 'trend-chart'}>
      <svg viewBox={`0 0 ${W} ${H}`} className="block h-auto w-full max-w-xl" role="img" aria-labelledby={`${id}-t`} aria-describedby={`${id}-d`}>
        <title id={`${id}-t`}>{title}</title>
        <desc id={`${id}-d`}>{summary}</desc>
        <line x1={SIDE} x2={W - SIDE} y1={BASE} y2={BASE} className="stroke-hub-n-200" strokeWidth="1" />
        {buckets.map((bucket, index) => {
          const value = values[index];
          const height = peakValue > 0 ? (value / peakValue) * (BASE - TOP) : 0;
          const x = SIDE + slot * index + (slot - barWidth) / 2;
          const label = bucketLabel(bucket, monthly);
          return (
            <g key={`${bucket.week_start ?? bucket.month_start ?? index}`}>
              {value > 0 && <rect x={x} y={BASE - height} width={barWidth} height={height} rx="2" className="fill-hub-blue" />}
              {showValues && value > 0 && (
                <text x={x + barWidth / 2} y={BASE - height - 4} textAnchor="middle" fontSize="11" fontWeight="600" className="fill-hub-n-800">{formatNumber(value)}</text>
              )}
              {index % labelEvery === 0 && (
                <text x={x + barWidth / 2} y={BASE + 16} textAnchor="middle" fontSize="11" className="fill-hub-n-600">{label.short}</text>
              )}
            </g>
          );
        })}
      </svg>
      <figcaption className="flex flex-wrap items-center gap-x-3 gap-y-1">
        <span className="text-[13px] leading-5 text-hub-n-600">{title}</span>
        <button
          type="button"
          className={`${BTN_SECONDARY} !px-3`}
          aria-expanded={showNumbers}
          aria-controls={`${id}-table`}
          onClick={() => setShowNumbers((open) => !open)}
          data-testid="chart-show-numbers"
        >
          {showNumbers ? 'Hide numbers' : 'Show numbers'}
        </button>
      </figcaption>
      <div id={`${id}-table`} hidden={!showNumbers}>
        {showNumbers && (
          <table className="w-full max-w-xl border-collapse text-sm" data-testid="chart-numbers">
            <caption className="sr-only">{title}</caption>
            <thead>
              <tr className="border-b border-hub-border text-left">
                <th scope="col" className="py-2 pr-3 font-semibold text-hub-n-600">{monthly ? 'Month' : 'Week'}</th>
                {columns.map((column) => <th key={column.name} scope="col" className="py-2 pl-3 text-right font-semibold text-hub-n-600">{column.name}</th>)}
              </tr>
            </thead>
            <tbody>
              {buckets.map((bucket, index) => (
                <tr key={`${bucket.week_start ?? bucket.month_start ?? index}`} className="border-b border-hub-border-soft">
                  <th scope="row" className="py-2 pr-3 text-left font-normal text-hub-ink">{bucketLabel(bucket, monthly).long}</th>
                  {columns.map((column) => {
                    const value = column.value(bucket);
                    return <td key={column.name} className="py-2 pl-3 text-right tabular-nums text-hub-ink">{typeof value === 'number' ? formatNumber(value) : '—'}</td>;
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </figure>
  );
}
