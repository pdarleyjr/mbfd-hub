import { useCallback, useEffect, useState } from 'react';
import { History } from 'lucide-react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { fetchIssues, isCaptain5Forbidden } from '../../utils/captain5Api';
import { EmptyState, LoadError, SkeletonRows } from '../ui/StateViews';
import Tag from '../ui/Tag';
import { humanize } from '../ui/status';
import { dateTimeText, kindLabel, statusOf } from './format';
import type { IssueSummary } from './types';
import { FIELD, HINT, LABEL } from './ui';

const DEBOUNCE_MS = 300;

interface Props {
  onOpen: (id: number) => void;
  /** Bumped when an issue changes elsewhere so a reopened issue leaves this list. */
  version: number;
  onForbidden: () => void;
}

function ClosedRow({ issue, onOpen }: { issue: IssueSummary; onOpen: (id: number) => void }) {
  const status = statusOf(issue);
  return (
    <li>
      <button type="button" data-testid="captain5-history-row" onClick={() => onOpen(issue.id)}
        className="flex min-h-14 w-full flex-col gap-1.5 rounded-hub-control border border-hub-border bg-hub-well px-3.5 py-3 text-left transition-colors hover:bg-hub-blue-tint">
        <span className="flex flex-wrap items-baseline gap-x-2"><strong className="text-hub-ink">{issue.unit}</strong><span className="break-words text-hub-ink">{issue.item}</span></span>
        <span className={`${HINT} break-words`}>
          {issue.compartment} · Closed {dateTimeText(issue.resolved_at)}{issue.resolution_type ? ` · ${humanize(issue.resolution_type)}` : ''}
        </span>
        <span className="flex flex-wrap gap-1.5"><Tag tone={status.tone}>{status.label}</Tag><Tag>{kindLabel(issue.kind)}</Tag></span>
      </button>
    </li>
  );
}

export default function HistoryTab({ onOpen, version, onForbidden }: Props) {
  const [input, setInput] = useState('');
  const [query, setQuery] = useState('');
  const [issues, setIssues] = useState<IssueSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    const timer = setTimeout(() => setQuery(input), DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [input]);

  const retry = useCallback(() => setAttempt((n) => n + 1), []);

  useEffect(() => {
    let active = true;
    setError(null);
    fetchIssues('closed', query)
      .then((rows) => { if (active) setIssues(rows); })
      .catch((failure) => {
        if (!active) return;
        if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
        if (isCaptain5Forbidden(failure)) { onForbidden(); return; }
        setError(failure instanceof Error ? failure.message : 'Closed issues could not be loaded.');
      });
    return () => { active = false; };
  }, [query, attempt, version, onForbidden]);

  return (
    <div className="space-y-4">
      <div>
        <label className={LABEL} htmlFor="c5-history-search">Search closed issues</label>
        <input id="c5-history-search" type="search" className={`${FIELD} sm:max-w-md`} value={input} placeholder="Item or compartment" autoComplete="off" maxLength={60} onChange={(event) => setInput(event.target.value)} />
        <p className={`${HINT} mt-1`}>Shows issues closed in the last 90 days.</p>
      </div>
      {error && <LoadError title="Closed issues could not be loaded" message={error} onRetry={retry} />}
      {!error && issues === null && <SkeletonRows rows={3} />}
      {!error && issues !== null && issues.length === 0 && (
        <EmptyState icon={<History className="hub-empty__icon" aria-hidden="true" />} title={query ? 'No closed issues match your search' : 'No closed issues yet'} body={query ? 'Try a shorter word, or clear the search.' : 'Resolved and dismissed issues appear here.'} />
      )}
      {!error && issues !== null && issues.length > 0 && (
        <ul className="space-y-2" aria-label="Closed issues" data-testid="captain5-history-list">
          {issues.map((issue) => <ClosedRow key={issue.id} issue={issue} onOpen={onOpen} />)}
        </ul>
      )}
    </div>
  );
}
