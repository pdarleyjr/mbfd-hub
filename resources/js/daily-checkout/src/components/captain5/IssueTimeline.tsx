import { humanize } from '../ui/status';
import { ageText, dateTimeText, dayText, statusLabel } from './format';
import type { IssueDetail, IssueEvent } from './types';
import { BTN_SECONDARY, HINT } from './ui';

const DETAIL_LABELS: Record<string, string> = {
  vendor_reference: 'Vendor reference',
  expected_delivery: 'Expected delivery',
  parts_ordered: 'Ordered',
  pickup_instructions: 'Pickup',
};

const asText = (value: unknown): string | null => (typeof value === 'string' && value.trim() !== '' ? value : null);

/** Plain lines for the details an event carries; internal bookkeeping never reaches the client. */
function eventLines(event: IssueEvent): string[] {
  const lines: string[] = [];
  const reason = asText(event.details.reason);
  if (reason) lines.push(`Reason: ${reason}`);
  const resolution = asText(event.details.resolution_type);
  if (resolution) lines.push(`Resolution: ${humanize(resolution)}`);
  const notes = asText(event.details.resolution_notes);
  if (notes) lines.push(`Notes: ${notes}`);
  const bag = (event.details.details ?? event.details.changed_details) as Record<string, unknown> | undefined;
  if (bag && typeof bag === 'object') {
    for (const [key, label] of Object.entries(DETAIL_LABELS)) {
      const value = asText(bag[key]);
      if (value) lines.push(`${label}: ${key === 'expected_delivery' ? dayText(value) : value}`);
    }
  }
  return lines;
}

function eventTitle(event: IssueEvent): string {
  if (event.type === 'cleared_from_captain5') return `Cleared from Captain 5 by ${event.actor}`;
  if (event.type === 'returned_to_captain5') return `Brought back to Captain 5 by ${event.actor}`;
  if (event.from && event.to && event.from !== event.to) return `${statusLabel(event.from)} to ${statusLabel(event.to)}`;
  return humanize(event.type);
}

interface Props {
  issue: IssueDetail;
  onOpenInspection: (id: number) => void;
}

export default function IssueTimeline({ issue, onOpenInspection }: Props) {
  return (
    <>
      <section aria-labelledby="c5-history-heading" className="space-y-3" data-testid="captain5-timeline">
        <h3 id="c5-history-heading" className="font-heading text-base font-bold text-hub-ink">History</h3>
        {issue.events.length === 0
          ? <p className={HINT}>No history recorded yet.</p>
          : (
            <ol className="space-y-3 border-l border-hub-border pl-4">
              {issue.events.map((event) => (
                <li key={event.id} data-testid="captain5-event">
                  <p className="text-sm font-semibold text-hub-ink">{eventTitle(event)}</p>
                  <p className={HINT}>{event.actor} · <time dateTime={event.at ?? undefined} title={dateTimeText(event.at)}>{ageText(event.at)}</time></p>
                  {event.member_message && <p className="mt-1 whitespace-pre-wrap break-words text-sm text-hub-ink">Message to crew: {event.member_message}</p>}
                  {event.internal_note && <p className="mt-1 whitespace-pre-wrap break-words text-sm text-hub-ink">Internal note: {event.internal_note}</p>}
                  {eventLines(event).map((line) => <p key={line} className="break-words text-sm text-hub-n-600">{line}</p>)}
                </li>
              ))}
            </ol>
          )}
      </section>

      <section aria-labelledby="c5-observations-heading" className="space-y-3" data-testid="captain5-observations">
        <h3 id="c5-observations-heading" className="font-heading text-base font-bold text-hub-ink">Observed on checkouts</h3>
        {issue.observations.length === 0
          ? <p className={HINT}>No checkout observations recorded.</p>
          : (
            <ul className="space-y-2">
              {issue.observations.map((observation) => (
                <li key={`${observation.inspection_id}-${observation.at}`} className="rounded-hub-control border border-hub-border bg-hub-well p-3">
                  <p className="text-sm font-semibold text-hub-ink">
                    {humanize(observation.observation)}{observation.reported_status ? ` (${observation.reported_status})` : ''}
                  </p>
                  <p className={HINT}>{observation.by ?? 'Crew member'} · <time dateTime={observation.at ?? undefined} title={dateTimeText(observation.at)}>{ageText(observation.at)}</time></p>
                  {observation.notes && <p className="mt-1 whitespace-pre-wrap break-words text-sm text-hub-ink">{observation.notes}</p>}
                  <button type="button" className={`${BTN_SECONDARY} mt-2`} onClick={() => onOpenInspection(observation.inspection_id)}>View inspection</button>
                </li>
              ))}
            </ul>
          )}
      </section>
    </>
  );
}
