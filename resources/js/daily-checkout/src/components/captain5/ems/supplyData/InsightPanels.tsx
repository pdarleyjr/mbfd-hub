import { HINT } from '../shared';
import { etDate } from '../logic';
import { formatNumber, hoursText, plural, quantityText } from './format.ts';
import type { CountAccuracy, DataQuality, Fulfillment, Overview } from './types.ts';
import { Disclosure, Figure, Stat } from './ui.tsx';

function FulfillmentBody({ fulfillment }: { fulfillment: Fulfillment }) {
  return (
    <div className="space-y-3">
      <dl className="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3">
        <Stat label="Submitted">{formatNumber(fulfillment.submitted)}</Stat>
        <Stat label="Acknowledged">{formatNumber(fulfillment.acknowledged)}</Stat>
        <Stat label="Sent back">{formatNumber(fulfillment.rejected)}</Stat>
        <Stat label="Corrected">{formatNumber(fulfillment.corrected)}</Stat>
        <Stat label="Reconciled">{formatNumber(fulfillment.reconciled)}</Stat>
        <Stat label="Still open">{formatNumber(fulfillment.open)}</Stat>
        <Stat label="Typical time to reconcile"><Figure value={fulfillment.median_hours_to_reconcile}>{fulfillment.median_hours_to_reconcile === null ? null : hoursText(fulfillment.median_hours_to_reconcile)}</Figure></Stat>
      </dl>
      <p className="text-sm text-hub-ink">
        In total, <strong className="tabular-nums">{formatNumber(fulfillment.qty_requested)}</strong> were asked for and <strong className="tabular-nums">{formatNumber(fulfillment.qty_distributed)}</strong> were distributed.
      </p>
      <p className={HINT}>These quantities add up different supplies, so read them as a rough balance between asked and given, not as a count of items.</p>
    </div>
  );
}

function CountAccuracyBody({ accuracy }: { accuracy: CountAccuracy }) {
  return (
    <div className="space-y-3">
      <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
        <Stat label="Counts that can be compared">{formatNumber(accuracy.comparable_counts)}</Stat>
        <Stat label="Coverage"><Figure value={accuracy.coverage_pct}>{accuracy.coverage_pct === null ? null : `${formatNumber(accuracy.coverage_pct)}%`}</Figure></Stat>
      </dl>
      {accuracy.note && <p className="text-sm text-hub-ink">{accuracy.note}</p>}
      {accuracy.items.length > 0 && (
        <ul className="divide-y divide-hub-border-soft rounded-hub-control border border-hub-border-soft" aria-label="Counted against expected">
          {accuracy.items.map((entry, index) => (
            <li key={`${entry.item_id ?? index}`} className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 px-3 py-2 text-sm">
              <span className="min-w-0 break-words font-semibold text-hub-ink">{entry.name ?? 'Supply'}</span>
              <span className="tabular-nums text-hub-ink">
                Counted <Figure value={entry.counted ?? entry.verified ?? null}>{(entry.counted ?? entry.verified) == null ? null : quantityText((entry.counted ?? entry.verified) as number, entry.count_unit)}</Figure>
                {' '}of an expected <Figure value={entry.expected ?? null}>{entry.expected === null || entry.expected === undefined ? null : formatNumber(entry.expected)}</Figure>
                {typeof entry.variance === 'number' && entry.variance !== 0 ? `, off by ${formatNumber(Math.abs(entry.variance))} ${entry.variance < 0 ? 'short' : 'over'}` : ''}
                {entry.counted_at ? ` (${etDate(entry.counted_at)})` : ''}
              </span>
            </li>
          ))}
        </ul>
      )}
      <p className={HINT}>A count is compared only when the count before it was also a physical count. Estimated starting numbers are never used as the baseline.</p>
    </div>
  );
}

const EXTRA_QUALITY = [
  ['uncounted_items', 'Supplies never counted'],
  ['negative_balances', 'Balances below zero'],
  ['items_without_minimum', 'Supplies with no minimum set'],
  ['requests_without_unit', 'Requests with no unit on file'],
  ['movements_on_uncounted_requests', 'Movements on requests that did not count'],
  ['trucks_with_more_than_one_unit_name', 'Trucks known by more than one name'],
] as const;

function DataQualityBody({ quality }: { quality: DataQuality }) {
  return (
    <div className="space-y-3">
      <dl className="grid grid-cols-1 gap-x-4 gap-y-3 min-[420px]:grid-cols-3">
        <Stat label="Movements with no unit" hint="Stock that left with no unit recorded.">{formatNumber(quality.unattributed_movements)}</Stat>
        <Stat label="Estimated supplies" hint="Not yet counted.">{formatNumber(quality.estimated_items)}</Stat>
        <Stat label="Unit conversions to settle" hint="A box or case with no count per package.">{formatNumber(quality.unresolved_conversions)}</Stat>
      </dl>
      <dl className="grid grid-cols-1 gap-x-4 gap-y-3 min-[420px]:grid-cols-3" data-testid="quality-extras">
        {EXTRA_QUALITY.map(([key, label]) => {
          const value = quality[key];
          return typeof value === 'number' && value > 0 ? <Stat key={key} label={label}>{formatNumber(value)}</Stat> : null;
        })}
      </dl>
      {(quality.insufficient_data ?? []).length > 0 && (
        <ul className="list-disc space-y-1 pl-5 text-sm text-hub-ink" aria-label="What is too thin to conclude">
          {(quality.insufficient_data ?? []).map((entry, index) => <li key={index}>{entry.message}</li>)}
        </ul>
      )}
      {quality.notes.length > 0 && (
        <ul className="list-disc space-y-1 pl-5 text-sm text-hub-ink" aria-label="Notes about the data">
          {quality.notes.map((note, index) => <li key={index}>{note}</li>)}
        </ul>
      )}
    </div>
  );
}

/** Fulfillment, Count accuracy and Data quality: collapsed until asked for, then the definitions behind every number. */
export default function InsightPanels({ data }: { data: Overview }) {
  const { fulfillment, count_accuracy: accuracy, data_quality: quality } = data;
  const definitions = Object.entries(data.definitions);
  const qualityIssues = quality.unattributed_movements + quality.unresolved_conversions;
  return (
    <div className="space-y-2" data-testid="supply-data-insights">
      <Disclosure title="Fulfillment" summary={`${fulfillment.reconciled} of ${fulfillment.submitted} ${plural(fulfillment.submitted, 'request')} reconciled`} testId="disclosure-fulfillment">
        <FulfillmentBody fulfillment={fulfillment} />
      </Disclosure>
      <Disclosure title="Count accuracy" summary={accuracy.comparable_counts === 0 ? 'No counts to compare yet' : `${accuracy.comparable_counts} ${plural(accuracy.comparable_counts, 'count')} compared`} testId="disclosure-accuracy">
        <CountAccuracyBody accuracy={accuracy} />
      </Disclosure>
      <Disclosure title="Data quality" summary={qualityIssues === 0 ? `${quality.estimated_items} estimated, nothing else to settle` : `${qualityIssues} ${plural(qualityIssues, 'thing')} to settle`} testId="disclosure-quality">
        <DataQualityBody quality={quality} />
      </Disclosure>
      {definitions.length > 0 && (
        <Disclosure title="What these numbers mean" summary="Plain definitions" testId="disclosure-definitions">
          <dl className="space-y-3">
            {definitions.map(([key, sentence]) => (
              <div key={key}>
                <dt className="text-sm font-semibold capitalize text-hub-ink">{key.replace(/_/g, ' ')}</dt>
                <dd className="m-0 text-sm text-hub-n-800">{sentence}</dd>
              </div>
            ))}
          </dl>
        </Disclosure>
      )}
    </div>
  );
}
