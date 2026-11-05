import { useEffect, useState } from 'react';
import { CircleCheck } from 'lucide-react';
import type { StationRequestSummary } from '../../types';
import { formatHubDate } from '../../../../shared/timezone.ts';
import { canMarkResolved } from '../../utils/stationRequestResolveApi';
import { BTN_PRIMARY, Sheet } from '../captain5/ui';
import Tag, { StatusTag } from '../ui/Tag';
import { humanize } from '../ui/status';
import StationRequestResolveSheet from './StationRequestResolveSheet';

interface StationRequestSheetProps {
  request: StationRequestSummary | null;
  onClose: () => void;
  returnFocusTo: () => HTMLElement | null;
  /** Called after the member marks the request resolved, so the list behind the sheet can refresh. */
  onResolved?: () => void;
}

/**
 * One station request. Everything shown already came with the list or the Overview. When the server says this
 * member may close it (payload `member_resolution.eligible`), a "Mark resolved" button opens the short form.
 */
export default function StationRequestSheet({ request, onClose, returnFocusTo, onResolved }: StationRequestSheetProps) {
  const [resolving, setResolving] = useState(false);
  const [resolvedId, setResolvedId] = useState<number | null>(null);
  useEffect(() => { setResolving(false); }, [request?.id]);
  const showResolve = request !== null && onResolved !== undefined && canMarkResolved(request) && resolvedId !== request.id;

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
          {showResolve && (
            <button type="button" className={`${BTN_PRIMARY} w-full sm:w-auto`} onClick={() => setResolving(true)} data-testid="station-request-mark-resolved">
              Mark resolved
            </button>
          )}
          {resolvedId === request.id && (
            <p className="flex items-center gap-2 text-sm font-semibold text-hub-ink" role="status"><CircleCheck className="h-4 w-4 text-hub-success" aria-hidden="true" />Marked resolved</p>
          )}
          {request.current_public_response && <p className="rounded-hub-control border border-hub-info-border bg-hub-info-bg p-3 text-sm text-hub-n-800"><span className="font-semibold">Latest response:</span> {request.current_public_response}</p>}
          {request.ai_receipt && <p className="text-[13px] leading-5 text-hub-n-600" data-testid="station-request-ai-receipt"><span className="font-semibold text-hub-ink">{request.ai_receipt.sender}:</span> {request.updates?.find((update) => update.is_auto_receipt)?.public_note || 'Received'}</p>}
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
                    <span className="font-semibold text-hub-ink">{humanize(update.status)}</span> · {formatHubDate(update.created_at)}{update.sender ? <> · <span className="font-semibold text-hub-ink">{update.sender}</span></> : null}
                    {update.public_note && <p className="mt-0.5 break-words">{update.public_note}</p>}
                  </li>
                ))}
              </ol>
            </section>
          )}
        </div>
      )}
      <StationRequestResolveSheet
        request={resolving ? request : null}
        layer={1}
        onClose={() => setResolving(false)}
        onResolved={() => { if (request) setResolvedId(request.id); onResolved?.(); }}
      />
    </Sheet>
  );
}
