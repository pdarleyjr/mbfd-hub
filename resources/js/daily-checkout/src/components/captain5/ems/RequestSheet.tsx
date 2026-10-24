import { useCallback, useEffect, useState } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../../utils/api';
import { EmsApiError, failureMessage, fetchRequest } from '../../../utils/emsApi';
import { LoadError } from '../../ui/StateViews';
import Tag from '../../ui/Tag';
import { Fact, Sheet } from '../ui';
import RequestActions, { panelKey, type Panel } from './RequestActions';
import { RequestHistory, RequestMessages } from './RequestThread';
import {
  LINE_TONE, REQUEST_TONE, etDateTime, groupLines, isRequestOpen, itemsText, lineCanBeMarkedNotIssued, lineFlags, memberText, newIssuances,
  outcomeText, requestedText, targetText,
} from './logic';
import { BTN_SECONDARY, HINT, LiveNote } from './shared';
import type { EmsMeta, RequestDetail, RequestLine } from './types';

interface Props {
  uuid: string | null;
  meta: EmsMeta;
  onClose: () => void;
  /** The request changed on the server: refresh the lists behind the sheet and the tab badge. */
  onChanged: () => void;
}

/** A panel that no longer fits the request (it was closed or reopened by someone else) is dropped. */
function panelFits(panel: Panel | null, request: RequestDetail): boolean {
  if (panel === null) return true;
  if (panel.kind === 'reopen') return request.status === 'closed';
  if (panel.kind === 'correct') return request.history.some((event) => event.action === 'issued');
  if (panel.kind === 'not-issued') return isRequestOpen(request) && request.lines.some((line) => line.id === panel.lineId && lineCanBeMarkedNotIssued(line));
  return isRequestOpen(request);
}

function LineCard({ line, canMark, onNotIssued }: { line: RequestLine; canMark: boolean; onNotIssued: (lineId: number) => void }) {
  const flags = lineFlags(line);
  return (
    <li data-testid="ems-line" className="space-y-2 rounded-hub-control border border-hub-border bg-hub-well p-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <p className="min-w-0 break-words font-semibold text-hub-ink">{line.label}{line.variant_label ? ` (${line.variant_label})` : ''}</p>
        <span className="flex flex-wrap gap-1.5">
          {flags.map((flag) => <Tag key={flag} tone="info">{flag}</Tag>)}
          <Tag tone={LINE_TONE[line.line_status] ?? 'neutral'}>{line.line_status_label}</Tag>
        </span>
      </div>
      <dl className="grid grid-cols-3 gap-2 text-sm">
        <div><dt className={HINT}>Requested</dt><dd className="font-semibold tabular-nums text-hub-ink">{requestedText(line)}</dd></div>
        <div><dt className={HINT}>Prepared</dt><dd className="font-semibold tabular-nums text-hub-ink">{line.prepared_qty ?? 'Not set'}</dd></div>
        <div><dt className={HINT}>Issued</dt><dd className="font-semibold tabular-nums text-hub-ink">{line.issued_qty}</dd></div>
      </dl>
      {line.min_text && <p className={HINT}>Bag minimum: {line.min_text}</p>}
      {line.controlled_substance && <p className={HINT}>Narcotics are restocked through the department controlled-substance procedure. This is recorded on the request only.</p>}
      {line.not_issued_reason && <p className="text-sm text-hub-ink">Not issued: {line.not_issued_reason}</p>}
      {canMark && (
        <button type="button" className={`${BTN_SECONDARY} !min-h-11`} onClick={() => onNotIssued(line.id)} aria-label={`Mark ${line.label} not issued`}>Mark not issued</button>
      )}
    </li>
  );
}

export default function RequestSheet({ uuid, meta, onClose, onChanged }: Props) {
  const [request, setRequest] = useState<RequestDetail | null>(null);
  const [error, setError] = useState<EmsApiError | null>(null);
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'info' | 'warning'; lines: string[] } | null>(null);
  const [panel, setPanel] = useState<Panel | null>(null);

  const load = useCallback(async (id: string, silent = false) => {
    if (!silent) setLoading(true);
    try {
      const detail = await fetchRequest(id);
      setRequest(detail);
      setError(null);
      setPanel((current) => (panelFits(current, detail) ? current : null));
      return detail;
    } catch (failure) {
      if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return null; }
      setError(failure instanceof EmsApiError ? failure : new EmsApiError(failureMessage(failure, 'The request could not be loaded.'), 0));
      return null;
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    setRequest(null);
    setError(null);
    setNotice(null);
    setPanel(null);
    if (uuid !== null) void load(uuid);
  }, [uuid, load]);

  const saved = (detail: RequestDetail, text: string | null) => {
    const outcomes = request ? newIssuances(request.history, detail.history).map(outcomeText) : [];
    const lines = outcomes.length > 0 ? ['Issued.', ...outcomes] : [text ?? 'Saved.'];
    setRequest(detail);
    setNotice({ tone: 'info', lines });
    onChanged();
  };

  const conflict = () => {
    setNotice({ tone: 'warning', lines: ['Someone else changed this request. This is the latest version. Check it, then try again; what you typed is kept.'] });
    if (uuid !== null) void load(uuid, true);
    onChanged();
  };

  const unavailable = error !== null && (error.status === 403 || error.status === 404);
  const canWork = request !== null && meta.permissions.act && isRequestOpen(request);

  return (
    <Sheet
      open={uuid !== null}
      onClose={onClose}
      testId="ems-request-sheet"
      title={request ? `${request.number}: ${targetText(request)}` : 'Rescue Inventory request'}
      description={request ? `${memberText(request)} · ${etDateTime(request.submitted_at)}` : undefined}
    >
      {loading && !request && <div role="status" aria-label="Loading request" className="space-y-3"><span className="hub-skeleton block !h-8 w-2/3" /><span className="hub-skeleton block !h-32 w-full" /></div>}

      {unavailable && (
        <div className="hub-callout p-4" role="alert" data-testid="ems-request-unavailable">
          <p className="font-semibold text-hub-ink">This request is not available to you</p>
          <p className="text-sm text-hub-n-600">It may have been removed, or your access does not include it.</p>
          <button type="button" className={`${BTN_SECONDARY} mt-3`} onClick={onClose}>Back to the list</button>
        </div>
      )}
      {error && !unavailable && !request && uuid !== null && (
        <LoadError title="The request could not be loaded" message={error.message} onRetry={() => void load(uuid)}>
          <button type="button" className={BTN_SECONDARY} onClick={onClose}>Close</button>
        </LoadError>
      )}

      {request && (
        <div className="space-y-6">
          <div className="flex flex-wrap items-center gap-1.5">
            <Tag tone={REQUEST_TONE[request.status] ?? 'neutral'}>{request.status_label}</Tag>
            <Tag>{request.target_kind === 'detail_event' ? 'Detail / Special Event' : request.target_kind === 'rescue_unit' ? 'Rescue unit' : 'Other unit'}</Tag>
          </div>

          <div aria-live="polite" data-testid="ems-sheet-notice">
            {notice && (
              <div role={notice.tone === 'warning' ? 'alert' : 'status'} className={`hub-callout space-y-1 p-3 text-sm ${notice.tone === 'warning' ? 'hub-callout--warning' : ''}`}>
                {notice.lines.map((line, index) => <p key={`${index}-${line}`} data-testid={index > 0 ? 'ems-issue-outcome' : undefined} className={index === 0 ? 'font-semibold' : ''}>{line}</p>)}
              </div>
            )}
          </div>
          {error && request && <LiveNote tone="warning">{error.message} What you see may be out of date.</LiveNote>}

          <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2" data-testid="ems-request-summary">
            <Fact label="Unit">{targetText(request)}</Fact>
            <Fact label="Member">{memberText(request)}</Fact>
            <Fact label="Submitted">{etDateTime(request.submitted_at)}</Fact>
            <Fact label="Items">{itemsText(request)}</Fact>
            <Fact label="Member's note">{request.member_note}</Fact>
            <Fact label="Closed">{request.closed_at ? `${etDateTime(request.closed_at)}${request.close_reason ? `: ${request.close_reason}` : ''}` : null}</Fact>
            <Fact label="Cancelled">{request.cancel_reason}</Fact>
          </dl>

          <RequestActions request={request} meta={meta} panel={panel} onPanel={setPanel} onSaved={saved} onConflict={conflict} />

          <section aria-labelledby="ems-lines-heading" className="space-y-4" data-testid="ems-lines">
            <h3 id="ems-lines-heading" className="font-heading text-base font-bold text-hub-ink">Items on this request</h3>
            {request.lines.length === 0 && <p className={HINT}>This request has no items. Read the member's note above.</p>}
            {groupLines(request.lines).map((group) => (
              <div key={group.bagKey} className="space-y-3">
                <h4 className="font-semibold text-hub-ink">{group.bagLabel}</h4>
                {group.sections.map((section) => (
                  <div key={section.label} className="space-y-2">
                    <p className={`${HINT} font-semibold uppercase tracking-wide`}>{section.label}</p>
                    <ul className="space-y-2" aria-label={`${group.bagLabel}, ${section.label}`}>
                      {section.lines.map((line) => (
                        <LineCard key={line.id} line={line} canMark={canWork && lineCanBeMarkedNotIssued(line) && panelKey(panel) !== `not-issued:${line.id}`}
                          onNotIssued={(lineId) => setPanel({ kind: 'not-issued', lineId })} />
                      ))}
                    </ul>
                  </div>
                ))}
              </div>
            ))}
          </section>

          <RequestMessages requestId={request.id} messages={request.messages} canPost={meta.permissions.act && request.status !== 'cancelled'} onPosted={() => void load(request.id, true)} />
          <RequestHistory history={request.history} />
        </div>
      )}
    </Sheet>
  );
}
