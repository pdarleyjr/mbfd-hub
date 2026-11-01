import { useCallback, useEffect, useState } from 'react';
import { useParams, useSearchParams } from 'react-router';
import { ChevronDown } from 'lucide-react';
import { formatHubDate } from '../../../../shared/timezone.ts';
import { ApiRequestError, isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { getChangeout } from '../../utils/changeoutApi';
import DailyPageNav from '../DailyPageNav';
import { LoadError, SkeletonRows } from '../ui/StateViews';
import { isLeftLine } from './changeoutParse';
import { stationReturnPath } from './changeoutLogic';
import type { ChangeoutLine, ChangeoutSummary } from './types';

function LineList({ lines }: { lines: ChangeoutLine[] }) {
  return (
    <ul className="hub-well divide-y divide-hub-n-100">
      {lines.map((line) => (
        <li key={line.id} className="px-4 py-3 text-sm">
          <p className="font-semibold text-hub-ink">{line.name}</p>
          {line.compartment && <p className="text-hub-n-600">{line.compartment}</p>}
          {line.note && <p className="text-hub-n-800">{line.note}</p>}
        </li>
      ))}
    </ul>
  );
}

const DetailRow = ({ label, value }: { label: string; value: string }) => (
  <div className="flex justify-between gap-4 px-4 py-3">
    <dt className="text-hub-n-600">{label}</dt>
    <dd className="text-right font-medium tabular-nums text-hub-ink">{value}</dd>
  </div>
);

/** What happened, read-only. There is nothing to act on here: the changeout is already done. */
function ReceiptBody({ summary }: { summary: ChangeoutSummary }) {
  const left = summary.lines.filter(isLeftLine);
  const took = summary.lines.filter((line) => !isLeftLine(line));
  const tookCount = summary.lines.length > 0 ? took.length : summary.moved;
  const leftCount = summary.lines.length > 0 ? left.length : summary.leftCount;
  const fromTruck = summary.from_vehicle_number ?? 'not recorded';

  return (
    <>
      <section className="hub-panel space-y-3 p-4 sm:p-6">
        <h1 className="font-heading text-xl font-bold tabular-nums text-hub-ink sm:text-2xl">
          {summary.message ?? `${summary.unit_designation} moved from truck ${fromTruck} to truck ${summary.to_vehicle_number ?? 'not recorded'}.`}
        </h1>
        <dl className="hub-well divide-y divide-hub-n-100 text-sm">
          <DetailRow label="From truck" value={summary.from_vehicle_number ?? 'Not recorded'} />
          <DetailRow label="To truck" value={summary.to_vehicle_number ?? 'Not recorded'} />
          {summary.effective_at && <DetailRow label="When" value={formatHubDate(summary.effective_at)} />}
          {summary.submitted_by_name && <DetailRow label="Who" value={summary.submitted_by_name} />}
          {tookCount + leftCount > 0 && <DetailRow label="Equipment" value={`Took ${tookCount}, left ${leftCount}`} />}
        </dl>
        {summary.note && <p className="text-sm text-hub-n-800"><span className="font-semibold">Note:</span> {summary.note}</p>}
      </section>

      {leftCount > 0 && (
        <section aria-labelledby="changeout-left" className="hub-panel p-4 sm:p-6">
          <h2 id="changeout-left" className="mb-3 font-heading text-lg font-semibold tabular-nums text-hub-ink">Left on truck {fromTruck}</h2>
          {left.length > 0
            ? <LineList lines={left} />
            : (
              <ul className="hub-well divide-y divide-hub-n-100">
                {summary.left_behind.map((line, index) => (
                  <li key={`${line.name}-${index}`} className="px-4 py-3 text-sm">
                    <p className="font-semibold text-hub-ink">{line.name}</p>
                    {line.note && <p className="text-hub-n-800">{line.note}</p>}
                  </li>
                ))}
              </ul>
            )}
        </section>
      )}
      {took.length > 0 && (
        <details className="group hub-panel p-4 sm:p-6">
          <summary className="flex min-h-11 cursor-pointer items-center justify-between gap-3 font-heading text-lg font-semibold tabular-nums text-hub-ink">
            Took ({took.length})
            <ChevronDown className="h-5 w-5 flex-none text-hub-n-500 group-open:rotate-180" aria-hidden="true" />
          </summary>
          <div className="mt-3"><LineList lines={took} /></div>
        </details>
      )}
    </>
  );
}

/** Route for /changeouts/:id: from and to, what was taken and left, who and when. */
export default function ChangeoutReceipt() {
  const { id } = useParams<{ id: string }>();
  const [search] = useSearchParams();
  const changeoutId = /^\d+$/.test(id ?? '') ? Number(id) : null;
  const [summary, setSummary] = useState<ChangeoutSummary | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  const returnTo = search.get('return_to');

  const refresh = useCallback(() => setAttempt((current) => current + 1), []);

  useEffect(() => {
    if (changeoutId === null) {
      setError('This link does not point to a changeout.');
      return;
    }
    let active = true;
    setError(null);
    getChangeout(changeoutId)
      .then((next) => { if (active) setSummary(next); })
      .catch((failure: unknown) => {
        if (!active) return;
        if (isApiAuthenticationError(failure)) {
          redirectToLoginAfterSessionExpiry();
          return;
        }
        setError(failure instanceof ApiRequestError ? failure.message : 'The connection dropped before this record loaded.');
      });

    return () => { active = false; };
  }, [attempt, changeoutId]);

  const stationPath = stationReturnPath(returnTo, summary?.station_id ?? null);

  return (
    <div data-hub-ui="2" className="mx-auto max-w-2xl space-y-4 font-hub">
      <DailyPageNav fallback={stationPath} className="!mb-0" />
      {summary
        ? <ReceiptBody summary={summary} />
        : error
          ? <LoadError title="This changeout could not be loaded" message={error} onRetry={refresh} />
          : <div className="hub-panel p-4 sm:p-6"><SkeletonRows /></div>}
    </div>
  );
}
