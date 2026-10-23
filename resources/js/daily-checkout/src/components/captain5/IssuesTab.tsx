import { CircleCheck } from 'lucide-react';
import { EmptyState } from '../ui/StateViews';
import IssueRow from './IssueRow';
import { QUEUE_FILTERS, applyFilters, type KindFilter, type QueueFilter } from './filters';
import { sortQueue } from './format';
import type { IssueSummary, Workspace } from './types';
import { BTN_SECONDARY } from './ui';

interface Props {
  workspace: Workspace;
  queue: QueueFilter;
  kind: KindFilter;
  onFilter: (queue: QueueFilter, kind: KindFilter) => void;
  onOpen: (id: number) => void;
}

const CHIP = 'inline-flex min-h-11 items-center gap-2 rounded-hub-control border px-3.5 text-sm font-semibold transition-colors';
const CHIP_OFF = 'border-hub-border bg-hub-well text-hub-ink hover:bg-hub-n-50';
const CHIP_ON = 'border-hub-blue bg-hub-blue text-white';

export default function IssuesTab({ workspace, queue, kind, onFilter, onOpen }: Props) {
  const { counts } = workspace;
  const rows: IssueSummary[] = sortQueue(applyFilters(workspace.issues, queue, kind));
  const kinds: Array<{ id: KindFilter; label: string; count: number }> = [
    { id: 'all', label: 'All', count: counts.open },
    { id: 'rescue', label: 'Rescue', count: counts.rescue },
    { id: 'medical', label: 'Medical', count: counts.medical },
  ];

  return (
    <div className="space-y-4">
      {workspace.issues_truncated && (
        <p role="status" className="hub-callout hub-callout--warning p-3 text-sm">
          More open issues exist than this list can show. The newest are listed; use History search or ask Logistics for the full list.
        </p>
      )}
      <div role="group" aria-label="Filter by unit type" className="flex flex-wrap gap-2">
        {kinds.map((option) => (
          <button key={option.id} type="button" aria-pressed={kind === option.id} onClick={() => onFilter(queue, option.id)}
            className={`${CHIP} ${kind === option.id ? CHIP_ON : CHIP_OFF}`}>
            {option.label} <span className="tabular-nums">{option.count}</span>
          </button>
        ))}
      </div>
      <div role="group" aria-label="Filter by status" className="flex flex-wrap gap-2">
        {QUEUE_FILTERS.map((filter) => (
          <button key={filter.id} type="button" aria-pressed={queue === filter.id} onClick={() => onFilter(filter.id, kind)}
            className={`${CHIP} ${queue === filter.id ? CHIP_ON : CHIP_OFF}`}>
            {filter.label} <span className="tabular-nums">{counts[filter.count]}</span>
          </button>
        ))}
      </div>

      <p className="sr-only" role="status" aria-live="polite">{rows.length} {rows.length === 1 ? 'issue' : 'issues'} shown</p>

      {rows.length > 0 ? (
        <ul className="space-y-2" aria-label="Open issues" data-testid="captain5-issue-list">
          {rows.map((issue) => <IssueRow key={issue.id} issue={issue} onOpen={onOpen} />)}
        </ul>
      ) : workspace.issues.length === 0 ? (
        <EmptyState
          icon={<CircleCheck className="hub-empty__icon" aria-hidden="true" />}
          title="No open issues"
          body="Nothing in Captain 5's scope needs attention right now. Closed issues are under History."
        />
      ) : (
        <EmptyState
          title="No issues match these filters"
          body="Other open issues are hidden by the filters above."
          action={<button type="button" className={BTN_SECONDARY} onClick={() => onFilter('all', 'all')}>Show all open issues</button>}
        />
      )}
    </div>
  );
}
