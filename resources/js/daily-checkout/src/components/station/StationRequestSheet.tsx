import type { StationRequestSummary } from '../../types';
import { formatHubDate } from '../../../../shared/timezone.ts';
import { Sheet } from '../captain5/ui';
import Tag, { StatusTag } from '../ui/Tag';
import { humanize } from '../ui/status';

/** One station request, read only. Everything here already came with the Overview. */
export default function StationRequestSheet({ request, onClose, returnFocusTo }: { request: StationRequestSummary | null; onClose: () => void; returnFocusTo: () => HTMLElement | null }) {
  return (
    <Sheet open={request !== null} onClose={onClose} returnFocusTo={returnFocusTo} testId="station-request-sheet" title={request?.title ?? 'Station request'} description={request?.request_number}>
      {request && (
        <div className="space-y-4" data-testid="station-request-detail">
          <div className="flex flex-wrap gap-2">
            <StatusTag status={request.status} />
            <StatusTag status={request.priority} suffix="priority" />
            <Tag>{request.request_type === 'repair_service' ? 'Repair / Service' : 'Equipment'}</Tag>
          </div>
          <p className="text-[13px] leading-5 text-hub-n-600">{request.room?.name || request.room_name_snapshot || 'Station-wide'} · Submitted {formatHubDate(request.created_at)}</p>
          {request.description && <p className="whitespace-pre-wrap break-words text-sm text-hub-n-800">{request.description}</p>}
          {request.current_public_response && <p className="rounded-hub-control border border-hub-info-border bg-hub-info-bg p-3 text-sm text-hub-n-800"><span className="font-semibold">Latest response:</span> {request.current_public_response}</p>}
          {(request.items?.length ?? 0) > 0 && (
            <section aria-label="Requested items">
              <h3 className="mb-2 font-heading text-base font-semibold text-hub-ink">Items</h3>
              <ul className="space-y-2">
                {request.items?.map((item) => (
                  <li key={item.id} className="hub-well px-3 py-2 text-sm">
                    <p className="break-words font-medium text-hub-ink">{item.item_name}{item.quantity ? ` (${item.quantity})` : ''}</p>
                    {item.reason && <p className="break-words text-[13px] text-hub-n-600">{item.reason}</p>}
                  </li>
                ))}
              </ul>
            </section>
          )}
          {(request.updates?.length ?? 0) > 0 && (
            <section aria-label="Updates">
              <h3 className="mb-2 font-heading text-base font-semibold text-hub-ink">Updates</h3>
              <ol className="space-y-2 border-l-2 border-hub-n-200 pl-4">
                {request.updates?.map((update) => (
                  <li key={update.id} className="text-sm text-hub-n-600">
                    <span className="font-semibold text-hub-ink">{humanize(update.status)}</span> · {formatHubDate(update.created_at)}
                    {update.public_note && <p className="mt-0.5 break-words">{update.public_note}</p>}
                  </li>
                ))}
              </ol>
            </section>
          )}
        </div>
      )}
    </Sheet>
  );
}
