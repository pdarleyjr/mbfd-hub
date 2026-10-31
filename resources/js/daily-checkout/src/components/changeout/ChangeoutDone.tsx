import { useEffect, useRef } from 'react';
import { Link } from 'react-router';
import { CheckCircle2, Clock } from 'lucide-react';
import DailyPageNav from '../DailyPageNav';
import { dailyRouterPath } from './changeoutLogic';
import type { ChangeoutDoneState } from './types';

/** The last screen, in plain words. It reads from history state, so a reload stays here instead of starting over. */
export default function ChangeoutDone({ done }: { done: ChangeoutDoneState }) {
  const { summary, waiting, stationPath } = done;
  const headingRef = useRef<HTMLHeadingElement>(null);
  const sentToFleet = summary.status === 'pending_fleet';
  const heading = sentToFleet
    ? 'Sent to Fleet. Your equipment choices are saved.'
    : summary.message ?? `${summary.unit_designation} is now on truck ${summary.to_vehicle_number ?? 'not recorded'}.`;
  const nextCheckout = sentToFleet ? null : dailyRouterPath(summary.next_daily_checkout_path);
  const Icon = sentToFleet ? Clock : CheckCircle2;

  useEffect(() => { headingRef.current?.focus(); }, []);

  return (
    <div data-hub-ui="2" className="mx-auto max-w-2xl space-y-6 font-hub">
      <DailyPageNav fallback={stationPath} className="!mb-0" />
      <section className="hub-panel space-y-4 p-4 sm:p-6">
        <div className="flex items-start gap-3">
          <Icon className={`mt-1 h-6 w-6 flex-none ${sentToFleet ? 'text-hub-warning' : 'text-hub-success'}`} aria-hidden="true" />
          <h1 ref={headingRef} tabIndex={-1} className="font-heading text-xl font-bold text-hub-ink sm:text-2xl">{heading}</h1>
        </div>

        {sentToFleet && <p className="text-sm text-hub-n-800">Nothing changes on either truck until Fleet decides.</p>}

        <p className="text-sm tabular-nums text-hub-n-800">
          {summary.moved} moved, {summary.left_behind} left on truck {summary.from_vehicle_number ?? 'not recorded'}, {summary.open} still open.
        </p>

        {waiting.length > 0 && (
          <div className="hub-well p-4">
            <h2 className="font-semibold text-hub-ink">Waiting for verification</h2>
            <ul className="mt-2 space-y-1 text-sm text-hub-n-800">
              {waiting.map((name) => <li key={name}>{name}</li>)}
            </ul>
          </div>
        )}

        <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
          {nextCheckout && (
            <Link to={nextCheckout} className="hub-btn hub-btn--primary hub-btn--wrap !min-h-11">
              Start Daily Checkout on truck {summary.to_vehicle_number ?? 'not recorded'}
            </Link>
          )}
          <Link to={stationPath} className={`hub-btn hub-btn--wrap !min-h-11 ${nextCheckout ? 'hub-btn--secondary' : 'hub-btn--primary'}`}>Back to station</Link>
          <Link to={`/changeouts/${summary.id}?return_to=${encodeURIComponent(stationPath)}`} className="hub-btn hub-btn--tertiary hub-btn--wrap !min-h-11">See the record</Link>
        </div>
      </section>
    </div>
  );
}
