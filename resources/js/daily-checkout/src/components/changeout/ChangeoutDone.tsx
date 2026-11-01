import { useEffect, useRef } from 'react';
import { Link } from 'react-router';
import { CheckCircle2 } from 'lucide-react';
import DailyPageNav from '../DailyPageNav';
import { dailyRouterPath } from './changeoutLogic';
import type { ChangeoutDoneState } from './types';

/** The last screen, in plain words. It reads from history state, so a reload stays here instead of starting over. */
export default function ChangeoutDone({ done }: { done: ChangeoutDoneState }) {
  const { summary, left, stationPath } = done;
  const headingRef = useRef<HTMLHeadingElement>(null);
  const toTruck = summary.to_vehicle_number ?? 'not recorded';
  const fromTruck = summary.from_vehicle_number ?? 'not recorded';
  const heading = summary.message ?? `${summary.unit_designation} is now on truck ${toTruck}.`;
  const nextCheckout = dailyRouterPath(summary.next_daily_checkout_path);
  const statusLine = summary.old_truck_status && !heading.includes(summary.old_truck_status)
    ? `Truck ${fromTruck} is marked ${summary.old_truck_status}.`
    : null;

  useEffect(() => { headingRef.current?.focus(); }, []);

  return (
    <div data-hub-ui="2" className="mx-auto max-w-2xl space-y-6 font-hub">
      <DailyPageNav fallback={stationPath} className="!mb-0" />
      <section className="hub-panel space-y-4 p-4 sm:p-6">
        <div className="flex items-start gap-3">
          <CheckCircle2 className="mt-1 h-6 w-6 flex-none text-hub-success" aria-hidden="true" />
          <h1 ref={headingRef} tabIndex={-1} className="font-heading text-xl font-bold tabular-nums text-hub-ink focus:outline-none sm:text-2xl">{heading}</h1>
        </div>

        <div className="space-y-1 text-sm text-hub-n-800">
          {statusLine && <p className="tabular-nums">{statusLine}</p>}
          {summary.displaced_unit && <p className="tabular-nums">{summary.displaced_unit} is now on truck {fromTruck}.</p>}
          {summary.new_truck_record && <p>Fleet will add the truck's details.</p>}
          <p>Fleet has been told. Nobody needs to approve this.</p>
        </div>

        {left.length > 0 && (
          <div className="hub-well p-4">
            <h2 className="font-semibold tabular-nums text-hub-ink">Left on truck {fromTruck}</h2>
            <ul className="mt-2 space-y-1 text-sm text-hub-n-800">
              {left.map((line, index) => <li key={`${line.name}-${index}`}>{line.name}{line.note ? `: ${line.note}` : ''}</li>)}
            </ul>
          </div>
        )}
        {/* With no equipment list there is nothing to say either way: the member's note is the record. */}
        {left.length === 0 && summary.moved > 0 && <p className="text-sm tabular-nums text-hub-n-800">Took all {summary.moved} items. Nothing was left on truck {fromTruck}.</p>}

        <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
          {nextCheckout && (
            <Link to={nextCheckout} className="hub-btn hub-btn--primary hub-btn--wrap !min-h-11">
              Start Daily Checkout on truck {toTruck}
            </Link>
          )}
          <Link to={stationPath} className={`hub-btn hub-btn--wrap !min-h-11 ${nextCheckout ? 'hub-btn--secondary' : 'hub-btn--primary'}`}>Back to station</Link>
          <Link to={`/changeouts/${summary.id}?return_to=${encodeURIComponent(stationPath)}`} className="hub-btn hub-btn--tertiary hub-btn--wrap !min-h-11">See the record</Link>
        </div>
      </section>
    </div>
  );
}
