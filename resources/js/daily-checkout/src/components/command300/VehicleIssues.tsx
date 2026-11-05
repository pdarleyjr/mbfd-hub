import Tag, { StatusTag } from '../ui/Tag';
import { SECONDARY } from './format';
import type { UnitIssues } from './types';
import { formatHubDate } from '../../../../shared/timezone.ts';
import { humanize } from '../ui/status';

/** One vehicle's open defects and service tickets, read only. */
export default function VehicleIssues({ issues, showOrigin = false }: { issues: UnitIssues; showOrigin?: boolean }) {
  const hiddenDefects = issues.defect_count - issues.defects.length;
  const hiddenTickets = issues.ticket_count - issues.tickets.length;
  return (
    <div className="space-y-2">
      {issues.defects.length > 0 && (
        <ul className="space-y-2" aria-label="Open issues">
          {issues.defects.map((defect) => (
            <li key={defect.id} className="hub-well px-3 py-2" data-testid="command-defect">
              <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                <p className="min-w-0 break-words text-sm font-semibold text-hub-ink">{defect.item || 'Issue'}</p>
                <span className="flex flex-wrap gap-1.5">
                  {defect.severity === 'significant' && <Tag tone="danger">Significant damage</Tag>}
                  {showOrigin && defect.from_this_checkout && <Tag tone="info">Reported in this checkout</Tag>}
                </span>
              </div>
              <p className={`break-words ${SECONDARY}`}>
                {[defect.compartment, defect.issue_type ? humanize(defect.issue_type) : null, defect.reported_date ? `Reported ${formatHubDate(defect.reported_date)}` : null].filter(Boolean).join(' · ')}
              </p>
              {defect.notes && <p className={`whitespace-pre-wrap break-words ${SECONDARY}`}>{defect.notes}</p>}
            </li>
          ))}
        </ul>
      )}
      {hiddenDefects > 0 && <p className={SECONDARY}>and {hiddenDefects} more open {hiddenDefects === 1 ? 'issue' : 'issues'}</p>}
      {issues.tickets.length > 0 && (
        <ul className="space-y-2" aria-label="Open service tickets">
          {issues.tickets.map((ticket) => (
            <li key={ticket.id} className="hub-well flex flex-wrap items-start justify-between gap-x-3 gap-y-1 px-3 py-2" data-testid="command-ticket">
              <div className="min-w-0">
                <p className="break-words text-sm font-semibold text-hub-ink">{ticket.title}</p>
                <p className={SECONDARY}>Service ticket {ticket.ticket_number}{ticket.created_at ? ` · ${formatHubDate(ticket.created_at)}` : ''}</p>
              </div>
              <StatusTag status={ticket.status} />
            </li>
          ))}
        </ul>
      )}
      {hiddenTickets > 0 && <p className={SECONDARY}>and {hiddenTickets} more open {hiddenTickets === 1 ? 'ticket' : 'tickets'}</p>}
    </div>
  );
}
