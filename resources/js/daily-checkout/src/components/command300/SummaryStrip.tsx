import type { ReactNode } from 'react';
import type { BoardSummary } from './types';
import type { Tone } from '../ui/status';

const TONE_BAR: Record<Tone, string> = {
  success: 'border-l-hub-success',
  warning: 'border-l-hub-warning',
  danger: 'border-l-hub-danger',
  info: 'border-l-hub-blue',
  neutral: 'border-l-hub-n-300',
};

function Tile({ label, value, detail, tone, testId }: { label: string; value: ReactNode; detail?: ReactNode; tone: Tone; testId: string }) {
  return (
    <div className={`hub-well min-w-0 border-l-4 px-3 py-3 sm:px-4 ${TONE_BAR[tone]}`} data-testid={testId}>
      <dt className="text-[13px] font-semibold leading-5 text-hub-n-600">{label}</dt>
      <dd className="font-heading text-2xl font-bold tabular-nums text-hub-ink">{value}</dd>
      {detail && <dd className="text-[13px] leading-5 text-hub-n-600">{detail}</dd>}
    </div>
  );
}

/** The whole department in five numbers, so the 300 can tell at a glance whether anything needs a closer look. */
export default function SummaryStrip({ summary, rosterAvailable }: { summary: BoardSummary; rosterAvailable: boolean }) {
  const missing = summary.checkouts_expected - summary.checkouts_done;
  return (
    <dl className="grid grid-cols-2 gap-2.5 lg:grid-cols-5" aria-label="Department summary" data-testid="command-summary">
      <Tile
        testId="summary-checkouts"
        label="Truck checkouts"
        value={<>{summary.checkouts_done}<span className="text-base font-semibold text-hub-n-600"> of {summary.checkouts_expected}</span></>}
        detail={missing > 0 ? `${missing} still to come` : summary.checkouts_expected > 0 ? 'All submitted' : 'None expected'}
        tone={missing > 0 ? 'warning' : 'success'}
      />
      <Tile
        testId="summary-requests"
        label="Open requests"
        value={summary.requests_open}
        detail={`${summary.requests_new_today} new this shift · ${summary.requests_pending} not acknowledged`}
        tone={summary.requests_pending > 0 ? 'warning' : 'neutral'}
      />
      <Tile
        testId="summary-vehicles"
        label="Vehicles with issues"
        value={summary.vehicles_with_issues}
        detail={summary.units_out_of_service > 0 ? `${summary.units_out_of_service} out of service` : 'None out of service'}
        tone={summary.vehicles_with_issues > 0 ? 'warning' : 'success'}
      />
      <Tile
        testId="summary-crew"
        label="Working now"
        value={rosterAvailable ? summary.crew_working : '—'}
        detail={rosterAvailable ? 'Members on the roster' : 'Roster not available yet'}
        tone="neutral"
      />
      <Tile
        testId="summary-attention"
        label="Stations to look at"
        value={<>{summary.stations_needing_attention}<span className="text-base font-semibold text-hub-n-600"> of {summary.stations}</span></>}
        detail={summary.stations_needing_attention > 0 ? 'Marked Needs attention' : 'Nothing urgent'}
        tone={summary.stations_needing_attention > 0 ? 'danger' : 'success'}
      />
    </dl>
  );
}
