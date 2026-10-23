import { ChevronRight } from 'lucide-react';
import Tag from '../ui/Tag';
import { dateTimeText } from './format';
import type { RescueDaily } from './types';
import { HINT } from './ui';

function Row({ daily, onOpen }: { daily: RescueDaily; onOpen: (id: number) => void }) {
  const submitted = daily.daily === 'submitted';
  const body = (
    <>
      <span className="flex min-w-0 flex-1 flex-col gap-1.5">
        <span className="flex flex-wrap items-baseline gap-x-2">
          <strong className="text-hub-ink">{daily.unit}</strong>
          {daily.station && <span className={HINT}>{daily.station}</span>}
        </span>
        <span className={`${HINT} break-words`}>
          {submitted
            ? `Submitted ${dateTimeText(daily.submitted_at)}${daily.submitted_by ? ` by ${daily.submitted_by}` : ''}`
            : "Today's daily checkout has not been submitted"}
        </span>
        <span className="flex flex-wrap gap-1.5">
          <Tag tone={submitted ? 'success' : 'warning'}>{submitted ? 'Daily submitted' : 'Daily not submitted'}</Tag>
          {daily.needs_approval && <Tag tone="warning">Needs approval</Tag>}
          {daily.open_issues > 0 && <Tag tone="info">{daily.open_issues} open {daily.open_issues === 1 ? 'issue' : 'issues'}</Tag>}
          {daily.status && daily.status.toLowerCase() !== 'in service' && <Tag>{daily.status}</Tag>}
        </span>
      </span>
      {submitted && daily.inspection_id !== null && (
        <span className="flex flex-none items-center gap-1 text-sm font-semibold text-hub-blue">
          View<span className="sr-only"> {daily.unit} inspection</span>
          <ChevronRight className="h-4 w-4" aria-hidden="true" />
        </span>
      )}
    </>
  );
  const shell = 'flex min-h-14 w-full items-center gap-3 rounded-hub-control border border-hub-border bg-hub-well px-3.5 py-3 text-left';
  return (
    <li>
      {submitted && daily.inspection_id !== null
        ? <button type="button" data-testid="captain5-rescue-row" className={`${shell} transition-colors hover:bg-hub-blue-tint`} onClick={() => onOpen(daily.inspection_id as number)}>{body}</button>
        : <div data-testid="captain5-rescue-row" className={shell}>{body}</div>}
    </li>
  );
}

export default function RescueDailies({ dailies, onOpenInspection }: { dailies: RescueDaily[]; onOpenInspection: (id: number) => void }) {
  return (
    <section aria-labelledby="c5-dailies-heading" className="space-y-3">
      <h3 id="c5-dailies-heading" className="font-heading text-lg font-bold text-hub-ink">Rescue daily checkouts</h3>
      {dailies.length === 0
        ? <p className={HINT}>No Rescue units are set up for Daily Checkout yet.</p>
        : <ul className="space-y-2" aria-label="Rescue daily checkouts">{dailies.map((daily) => <Row key={daily.apparatus_id} daily={daily} onOpen={onOpenInspection} />)}</ul>}
    </section>
  );
}
