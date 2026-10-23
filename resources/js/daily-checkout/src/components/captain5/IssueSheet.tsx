import { useCallback, useEffect, useState } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { Captain5ApiError, fetchIssue } from '../../utils/captain5Api';
import { humanize } from '../ui/status';
import { LoadError } from '../ui/StateViews';
import IssueActionPanel from './IssueActionPanel';
import IssueThread from './IssueThread';
import IssueTimeline from './IssueTimeline';
import { ageText, dateTimeText, dayText, kindLabel } from './format';
import type { IssueDetail } from './types';
import { BTN_SECONDARY, Fact, IssueTags, Sheet } from './ui';

interface Props {
  issueId: number | null;
  onClose: () => void;
  /** The issue changed on the server: refresh the lists behind the sheet. */
  onChanged: () => void;
  onOpenInspection: (id: number) => void;
}

function Summary({ issue }: { issue: IssueDetail }) {
  return (
    <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2" data-testid="captain5-issue-summary">
      <Fact label="Unit">{issue.unit} ({kindLabel(issue.kind)})</Fact>
      <Fact label="Compartment">{issue.compartment}</Fact>
      <Fact label="Problem">{issue.issue_type}</Fact>
      <Fact label="First reported">
        <time dateTime={issue.first_reported_at ?? undefined} title={dateTimeText(issue.first_reported_at)}>{ageText(issue.first_reported_at)}</time>
        {issue.first_reported_by ? ` by ${issue.first_reported_by}` : ''}
      </Fact>
      <Fact label="Last seen">{issue.last_observed_at ? ageText(issue.last_observed_at) : null}</Fact>
      <Fact label="Ordered">{issue.details.parts_ordered}</Fact>
      <Fact label="Vendor reference">{issue.details.vendor_reference}</Fact>
      <Fact label="Expected delivery">{issue.expected_delivery ? `${dayText(issue.expected_delivery)}${issue.overdue ? ' (overdue)' : ''}` : null}</Fact>
      <Fact label="Pickup instructions">{issue.details.pickup_instructions}</Fact>
      <Fact label="Resolution">{issue.resolution_type ? humanize(issue.resolution_type) : null}</Fact>
      <Fact label="Resolution notes">{issue.resolution_notes}</Fact>
      <Fact label="Closed">{issue.resolved_at ? dateTimeText(issue.resolved_at) : null}</Fact>
    </dl>
  );
}

export default function IssueSheet({ issueId, onClose, onChanged, onOpenInspection }: Props) {
  const [issue, setIssue] = useState<IssueDetail | null>(null);
  const [error, setError] = useState<Captain5ApiError | null>(null);
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'success' | 'warning'; text: string } | null>(null);

  const load = useCallback(async (id: number, silent = false) => {
    if (!silent) setLoading(true);
    try {
      setIssue(await fetchIssue(id));
      setError(null);
    } catch (failure) {
      if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
      setError(failure instanceof Captain5ApiError ? failure : new Captain5ApiError('The issue could not be loaded.', 0));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    setIssue(null);
    setError(null);
    setNotice(null);
    if (issueId !== null) void load(issueId);
  }, [issueId, load]);

  const unavailable = error !== null && (error.status === 403 || error.status === 404);

  return (
    <Sheet
      open={issueId !== null}
      onClose={onClose}
      testId="captain5-issue-sheet"
      title={issue ? `${issue.unit}: ${issue.item}` : 'Issue'}
      description={issue ? `${kindLabel(issue.kind)} issue #${issue.id}` : undefined}
    >
      {loading && !issue && <div role="status" aria-label="Loading issue" className="space-y-3"><span className="hub-skeleton block !h-8 w-2/3" /><span className="hub-skeleton block !h-32 w-full" /></div>}

      {unavailable && (
        <div className="hub-callout p-4" role="alert" data-testid="captain5-issue-unavailable">
          <p className="font-semibold text-hub-ink">This issue is not available to you</p>
          <p className="text-sm text-hub-n-600">It may be outside Captain 5&apos;s scope, or it was removed. Nothing else is affected.</p>
          <button type="button" className={`${BTN_SECONDARY} mt-3`} onClick={onClose}>Back to the queue</button>
        </div>
      )}
      {error && !unavailable && !issue && issueId !== null && (
        <LoadError title="The issue could not be loaded" message={error.message} onRetry={() => void load(issueId)}>
          <button type="button" className={BTN_SECONDARY} onClick={onClose}>Close</button>
        </LoadError>
      )}

      {issue && (
        <div className="space-y-6">
          <IssueTags issue={issue} />
          {notice && (
            <p role={notice.tone === 'warning' ? 'alert' : 'status'} data-testid="captain5-issue-notice"
              className={`hub-callout p-3 text-sm ${notice.tone === 'warning' ? 'hub-callout--warning' : ''}`}>{notice.text}</p>
          )}
          {error && <p role="alert" className="hub-callout hub-callout--warning p-3 text-sm">{error.message}</p>}
          <Summary issue={issue} />
          {issue.origin_inspection_id !== null && (
            <button type="button" className={BTN_SECONDARY} onClick={() => onOpenInspection(issue.origin_inspection_id as number)}>View originating inspection</button>
          )}
          <IssueActionPanel
            key={issue.id}
            issue={issue}
            onResult={(updated, confirmation) => { setIssue(updated); setNotice({ tone: 'success', text: confirmation }); onChanged(); }}
            onConflict={(fresh) => {
              setNotice({ tone: 'warning', text: 'Updated by someone else. This is the latest version of the issue.' });
              if (fresh) setIssue(fresh); else void load(issue.id, true);
              onChanged();
            }}
          />
          <IssueThread issue={issue} onPosted={(updated) => { setIssue(updated); onChanged(); }} />
          <IssueTimeline issue={issue} onOpenInspection={onOpenInspection} />
        </div>
      )}
    </Sheet>
  );
}
