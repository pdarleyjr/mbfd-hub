import { useCallback, useEffect, useRef, useState } from 'react';
import type { IssueSummary } from '../../types/issueHistory.ts';
import { describeIssueHistoryError, fetchClosedIssues } from '../../utils/issueHistoryApi';
import { closedIssuesHeading } from '../../utils/issueHistoryFormat.ts';
import IssueCard from './IssueCard';
import './findings.css';

interface Props {
  apparatusId: number;
  /** From the live list; the page is read again when this changes while the section is open. */
  closedCount: number;
}

type Status = 'idle' | 'loading' | 'ready' | 'error';

/** Earlier closed issues, on demand: nothing is asked until the member opens it, then 20 at a time. */
export default function ClosedIssues({ apparatusId, closedCount }: Props) {
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<IssueSummary[]>([]);
  const [next, setNext] = useState<string | null>(null);
  const [status, setStatus] = useState<Status>('idle');
  const [message, setMessage] = useState('');
  const loadedFor = useRef<number | null>(null);
  const latestRequest = useRef(0);

  const loadPage = useCallback((cursor: string | null) => {
    const mine = ++latestRequest.current;
    setStatus('loading');
    fetchClosedIssues(apparatusId, cursor).then((page) => {
      if (mine !== latestRequest.current) return;
      setItems(previous => {
        const seen = new Set(cursor === null ? [] : previous.map(issue => issue.id));

        return [...(cursor === null ? [] : previous), ...page.data.filter(issue => !seen.has(issue.id))];
      });
      setNext(page.next_cursor);
      setStatus('ready');
    }).catch((error: unknown) => {
      if (mine !== latestRequest.current) return;
      setMessage(describeIssueHistoryError(error, 'Closed issues'));
      setStatus('error');
    });
  }, [apparatusId]);

  useEffect(() => {
    if (!open || loadedFor.current === closedCount) return;
    loadedFor.current = closedCount;
    loadPage(null);
  }, [open, closedCount, loadPage]);

  if (closedCount === 0 && items.length === 0) return null;

  return (
    <details className="findings-closed" onToggle={event => setOpen(event.currentTarget.open)}>
      <summary>{closedIssuesHeading(closedCount)}</summary>
      <div className="findings-closed__body" aria-busy={status === 'loading'}>
        {items.length > 0 && <ul className="findings-list">{items.map(issue => <li key={issue.id}><IssueCard issue={issue} /></li>)}</ul>}
        {status === 'loading' && <p className="inspection-muted" role="status">Loading closed issues...</p>}
        {status === 'error' && (
          <div role="alert" className="issue-card__error">
            <p>{message}</p>
            <button type="button" className="hub-btn hub-btn--secondary" onClick={() => { loadedFor.current = closedCount; loadPage(items.length > 0 ? next : null); }}>Try again</button>
          </div>
        )}
        {status === 'ready' && items.length === 0 && <p className="inspection-muted">No closed issues were found.</p>}
        {status !== 'loading' && next !== null && <button type="button" className="hub-btn hub-btn--secondary" onClick={() => loadPage(next)}>Show more closed issues</button>}
      </div>
    </details>
  );
}
