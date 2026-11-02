import { HINT } from '../shared';
import { etDate } from '../logic';
import { formatNumber, hoursText, plural, quantityText } from './format.ts';
import type { CountAccuracy, DataQuality, Fulfillment, Overview } from './types.ts';
import { Disclosure, Figure, Stat } from './ui.tsx';

function FulfillmentBody({ fulfillment }: { fulfillment: Fulfillment }) {
  const left = fulfillment.all_submissions - fulfillment.submitted;
  return (
    <div className="space-y-3">
      <dl className="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3">
        <Stat label="Requests that counted">{formatNumber(fulfillment.submitted)}</Stat>
        <Stat label="Acknowledged">{formatNumber(fulfillment.acknowledged)}</Stat>
        <Stat label="Sent back">{formatNumber(fulfillment.rejected)}</Stat>
        <Stat label="Corrected">{formatNumber(fulfillment.corrected)}</Stat>
        <Stat label="Reconciled">{formatNumber(fulfillment.reconciled)}</Stat>
        <Stat label="Still open">{formatNumber(fulfillment.open)}</Stat>
        <Stat label="Finished another way">{formatNumber(fulfillment.completed_other_workflow)}</Stat>
        <Stat label="Typical time to reconcile"><Figure value={fulfillment.median_hours_to_reconcile}>{fulfillment.median_hours_to_reconcile === null ? null : hoursText(fulfillment.median_hours_to_reconcile)}</Figure></Stat>
      </dl>
      <p className={HINT} data-testid="fulfillment-scope">
        A request counts unless it was cancelled, sent back or replaced by its correction, so a corrected request is one.
        {left > 0 ? ` ${fulfillment.all_submissions} ${plural(fulfillment.all_submissions, 'form was', 'forms were')} submitted in all.` : ''}
      </p>
      <p className="text-sm text-hub-ink">
        In total, <strong className="tabular-nums">{formatNumber(fulfillment.qty_requested)}</strong> were asked for on those requests and <strong className="tabular-nums">{formatNumber(fulfillment.qty_distributed)}</strong> were distributed.
      </p>
      <p className={HINT}>These totals add up different supplies, so read them as a rough balance between asked and given. A supply's own distributed number comes from the stock ledger and can differ.</p>
    </div>
  );
}

function CountAccuracyBody({ accuracy }: { accuracy: CountAccuracy }) {
  return (
    <div className="space-y-3">
      <dl className="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4">
        <Stat label="Count lines">{formatNumber(accuracy.counted_lines)}</Stat>
        <Stat label="Can be compared">{formatNumber(accuracy.comparable_counts)}</Stat>
        <Stat label="Matched exactly"><Figure value={accuracy.exact_matches}>{accuracy.exact_matches === null ? null : formatNumber(accuracy.exact_matches)}</Figure></Stat>
        <Stat label="Coverage"><Figure value={accuracy.coverage_pct}>{accuracy.coverage_pct === null ? null : `${formatNumber(accuracy.coverage_pct)}%`}</Figure></Stat>
      </dl>
      {accuracy.note && <p className="text-sm text-hub-ink">{accuracy.note}</p>}
      {accuracy.items.length > 0 && (
        <ul className="divide-y divide-hub-border-soft rounded-hub-control border border-hub-border-soft" aria-label="Counted against expected">
          {accuracy.items.map((entry) => (
            <li key={`${entry.item_id}-${entry.counted_at}`} className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 px-3 py-2 text-sm">
              <span className="min-w-0 break-words font-semibold text-hub-ink">{entry.name}</span>
              <span className="tabular-nums text-hub-ink">
                Counted {formatNumber(entry.counted)} of an expected {formatNumber(entry.expected)}
                {entry.variance !== 0 ? `, off by ${formatNumber(Math.abs(entry.variance))} ${entry.variance < 0 ? 'short' : 'over'}` : ', a match'}
                {` (${etDate(entry.counted_at)})`}
              </span>
            </li>
          ))}
        </ul>
      )}
      <p className={HINT}>A count is compared only when the count before it was also a physical count. Estimated starting numbers are never used as the baseline. Largest differences first.</p>
    </div>
  );
}

const QUALITY_COUNTS = [
  ['unattributed_movements', 'Movements with no unit', 'Stock that left with no unit recorded.'],
  ['movements_on_uncounted_requests', 'Movements on requests that did not count', 'Cancelled, sent back or replaced requests.'],
  ['uncounted_items', 'Supplies never counted', 'No number at all yet.'],
  ['unresolved_conversions', 'Unit conversions to settle', 'A box or case with no count per package.'],
  ['negative_balances', 'Balances below zero', 'More left the shelf than was counted in.'],
  ['items_without_minimum', 'Supplies with no minimum', 'So they are never flagged as low.'],
  ['requests_without_unit', 'Requests with no unit on file', 'The truck row was deleted.'],
  ['trucks_with_more_than_one_unit_name', 'Trucks with more than one name', 'Their requests are kept together.'],
] as const;

/** Counts that need attention: everything but the estimates, which the "What matters now" count already shows. */
export const qualityIssueCount = (quality: DataQuality): number => QUALITY_COUNTS.reduce((sum, [key]) => sum + quality[key], 0) + quality.estimates_far_above_order_level.length;

function DataQualityBody({ quality }: { quality: DataQuality }) {
  const shown = QUALITY_COUNTS.filter(([key]) => quality[key] > 0);
  return (
    <div className="space-y-3">
      <dl className="grid grid-cols-1 gap-x-4 gap-y-3 min-[420px]:grid-cols-2 sm:grid-cols-3" data-testid="quality-counts">
        <Stat label="Estimated supplies" hint="Not yet counted.">{formatNumber(quality.estimated_items)}</Stat>
        {shown.map(([key, label, hint]) => <Stat key={key} label={label} hint={hint}>{formatNumber(quality[key])}</Stat>)}
      </dl>
      {shown.length === 0 && quality.estimates_far_above_order_level.length === 0 && <p className="text-sm text-hub-ink">Nothing else to settle.</p>}
      {quality.estimates_far_above_order_level.length > 0 && (
        <div className="space-y-1" data-testid="quality-suspects">
          <p className="text-sm font-semibold text-hub-ink">Estimates far above the order level</p>
          <p className={HINT}>These best-guess numbers may have been entered in the wrong unit. Counting them will settle it.</p>
          <ul className="list-disc space-y-1 pl-5 text-sm text-hub-ink">
            {quality.estimates_far_above_order_level.map((entry) => (
              <li key={entry.item_id}>{entry.name}: {entry.on_hand === null ? 'no number' : quantityText(entry.on_hand, entry.count_unit)}, {formatNumber(entry.times_order_level)} times its order level</li>
            ))}
          </ul>
        </div>
      )}
      {quality.insufficient_data.length > 0 && (
        <ul className="list-disc space-y-1 pl-5 text-sm text-hub-ink" aria-label="What is too thin to conclude">
          {quality.insufficient_data.map((entry, index) => <li key={index}>{entry.message}</li>)}
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
  const issues = qualityIssueCount(quality);
  return (
    <div className="space-y-2" data-testid="supply-data-insights">
      <Disclosure title="Fulfillment" summary={`${fulfillment.reconciled} of ${fulfillment.submitted} ${plural(fulfillment.submitted, 'request')} reconciled`} testId="disclosure-fulfillment">
        <FulfillmentBody fulfillment={fulfillment} />
      </Disclosure>
      <Disclosure title="Count accuracy" summary={accuracy.comparable_counts === 0 ? 'No counts to compare yet' : `${accuracy.comparable_counts} ${plural(accuracy.comparable_counts, 'count')} compared`} testId="disclosure-accuracy">
        <CountAccuracyBody accuracy={accuracy} />
      </Disclosure>
      <Disclosure title="Data quality" summary={issues === 0 ? `${quality.estimated_items} estimated, nothing else to settle` : `${issues} ${plural(issues, 'thing')} to settle`} testId="disclosure-quality">
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
