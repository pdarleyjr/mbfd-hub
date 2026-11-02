import { useCallback, useEffect, useRef, useState } from 'react';
import { ChevronDown } from 'lucide-react';
import type { IssueSummary, TimelineEntry } from '../../types/issueHistory.ts';
import { describeIssueHistoryError, fetchIssueHistory } from '../../utils/issueHistoryApi';
import { formatEventDate, issuePresentation, reportedByLine } from '../../utils/issueHistoryFormat.ts';
import Tag from '../ui/Tag';
import IssueTimeline from './IssueTimeline';
import './findings.css';

type TimelineState =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'error'; message: string }
  | { status: 'loaded'; entries: TimelineEntry[] };

interface Props {
  issue: IssueSummary;
}

/**
 * One issue on this apparatus: where it is, what was reported and by whom, its status and the latest action. The
 * full timeline loads only when the history is opened, and again when a refresh shows the issue changed.
 */
export default function IssueCard({ issue }: Props) {
  const [open, setOpen] = useState(false);
  const [timeline, setTimeline] = useState<TimelineState>({ status: 'idle' });
  const latestRequest = useRef(0);
  const presentation = issuePresentation(issue);
  const latest = issue.latest_action;
  const latestDate = formatEventDate(latest?.at);
  // Anything that adds to the history changes this, so an open timeline is read again after a refresh.
  const revision = `${issue.action_status}|${issue.history_count}|${latest?.at ?? ''}`;

  const load = useCallback(() => {
    const mine = ++latestRequest.current;
    setTimeline(previous => (previous.status === 'loaded' ? previous : { status: 'loading' }));
    fetchIssueHistory(issue.id).then((history) => {
      if (mine === latestRequest.current) setTimeline({ status: 'loaded', entries: history.timeline });
    }).catch((error: unknown) => {
      // A failed re-read keeps the timeline already on screen.
      if (mine === latestRequest.current) setTimeline(previous => (previous.status === 'loaded' ? previous : { status: 'error', message: describeIssueHistoryError(error, 'The history') }));
    });
  }, [issue.id]);

  useEffect(() => {
    if (open) load();
    else latestRequest.current += 1;
  }, [open, revision, load]);

  return (
    <article data-hub-ui="2" className={`issue-card is-${presentation.phase}`} data-issue-id={issue.id} data-issue-phase={presentation.phase}>
      <div className="issue-card__head">
        <div className="issue-card__title">
          <strong>{issue.item !== '' ? issue.item : 'Item not recorded'}</strong>
          <small>{issue.compartment !== '' ? issue.compartment : 'Location not recorded'}</small>
        </div>
        <div className="issue-card__tags">
          <Tag tone={presentation.tone}>{presentation.label}</Tag>
          {presentation.phase === 'active' && issue.severity === 'significant' && <Tag tone="danger">Significant</Tag>}
        </div>
      </div>
      {presentation.detail && <p className="issue-card__line">{presentation.detail}</p>}
      {issue.description ? <p className="issue-card__description">{issue.description}</p> : <p className="issue-card__line">No description was recorded.</p>}
      <p className="issue-card__line">{reportedByLine(issue)}</p>
      {issue.recurrence_of_defect_id !== null && <p className="issue-card__line">This came back after an earlier issue on the same item.</p>}
      {latest && (
        <p className="issue-card__line" data-latest-action>
          Latest action: {latest.label}{latest.actor_name ? ` by ${latest.actor_name}` : ''}{latestDate ? ` on ${latestDate}` : ''}
          {latest.text && <span className="issue-card__quote">{latest.text}</span>}
        </p>
      )}
      <details className="issue-card__history" onToggle={event => setOpen(event.currentTarget.open)}>
        <summary>
          <span>{open ? 'Hide history' : 'Show history'}{issue.history_count > 0 ? ` (${issue.history_count})` : ''}</span>
          <ChevronDown size={18} aria-hidden="true" />
        </summary>
        <div className="issue-card__timeline" aria-busy={timeline.status === 'loading'}>
          {timeline.status === 'loading' && <p className="inspection-muted" role="status">Loading history...</p>}
          {timeline.status === 'error' && (
            <div role="alert" className="issue-card__error">
              <p>{timeline.message}</p>
              <button type="button" className="hub-btn hub-btn--secondary" onClick={load}>Try again</button>
            </div>
          )}
          {timeline.status === 'loaded' && <IssueTimeline entries={timeline.entries} />}
        </div>
      </details>
    </article>
  );
}
