import { useEffect, useRef, useState } from 'react';
import { CircleCheck } from 'lucide-react';
import { EmptyState } from '../ui/StateViews';
import ClearedIssues from './ClearedIssues';
import UnitIssueGroup from './UnitIssueGroup';
import { QUEUE_FILTERS, applyFilters, type KindFilter, type QueueFilter } from './filters';
import { sortQueue } from './format';
import { groupByUnit } from './issueGroups';
import type { IssueSummary, Workspace } from './types';
import { BTN_SECONDARY } from './ui';
import { useIssueClearing } from './useIssueClearing';

interface Props {
  workspace: Workspace;
  queue: QueueFilter;
  kind: KindFilter;
  /** The issue the URL points at: its unit, or the cleared list, opens to show it. */
  targetIssueId: number | null;
  onFilter: (queue: QueueFilter, kind: KindFilter) => void;
  onOpen: (id: number) => void;
  /** An issue changed from this tab: resolves once the queue behind it is fresh. */
  onChanged: () => Promise<void> | void;
}

const CHIP = 'inline-flex min-h-11 items-center gap-2 rounded-hub-control border px-3.5 text-sm font-semibold transition-colors';
const CHIP_OFF = 'border-hub-border bg-hub-well text-hub-ink hover:bg-hub-n-50';
const CHIP_ON = 'border-hub-blue bg-hub-blue text-white';

export default function IssuesTab({ workspace, queue, kind, targetIssueId, onFilter, onOpen, onChanged }: Props) {
  const { counts } = workspace;
  const rows: IssueSummary[] = sortQueue(applyFilters(workspace.issues, queue, kind));
  const groups = groupByUnit(rows);
  const clearing = useIssueClearing(onChanged);
  // A member's own open or close of a unit; a unit they have not touched follows the defaults below.
  const [choices, setChoices] = useState<ReadonlyMap<number, boolean>>(new Map());
  const [clearedOpen, setClearedOpen] = useState(false);

  const targetUnit = groups.find((group) => group.issues.some((issue) => issue.id === targetIssueId))?.apparatusId ?? null;
  const targetIsCleared = workspace.cleared_issues.some((issue) => issue.id === targetIssueId);
  // Remembered, so the unit stays open when the sheet closes and the link is gone.
  useEffect(() => {
    if (targetUnit !== null) setChoices((current) => new Map(current).set(targetUnit, true));
  }, [targetUnit]);
  useEffect(() => {
    if (targetIsCleared) setClearedOpen(true);
  }, [targetIsCleared]);
  // The button that was just tapped may be gone with its row, so focus moves to the result and its Undo.
  const statusRef = useRef<HTMLParagraphElement>(null);
  useEffect(() => {
    if (clearing.notice) statusRef.current?.focus();
  }, [clearing.notice]);

  const isExpanded = (apparatusId: number): boolean => choices.get(apparatusId) ?? (groups.length === 1 || apparatusId === targetUnit);
  const toggle = (apparatusId: number) => setChoices((current) => new Map(current).set(apparatusId, !isExpanded(apparatusId)));

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

      <p className="sr-only" role="status" aria-live="polite">
        {rows.length} {rows.length === 1 ? 'issue' : 'issues'} shown on {groups.length} {groups.length === 1 ? 'unit' : 'units'}
      </p>

      <div aria-live="polite" role="status">
        {clearing.notice && (
          <p ref={statusRef} tabIndex={-1} data-testid="captain5-clear-status" className={`hub-callout flex flex-wrap items-center justify-between gap-2 p-3 text-sm focus:outline-none ${clearing.notice.tone === 'warning' ? 'hub-callout--warning' : ''}`}>
            <span className="min-w-0 flex-1 break-words">{clearing.notice.text}</span>
            {clearing.notice.undo.length > 0 && (
              <button type="button" data-testid="captain5-clear-undo" className={BTN_SECONDARY} disabled={clearing.busy}
                onClick={() => void clearing.run('bring_back_to_captain5', clearing.notice?.undo ?? [])}>
                Undo
              </button>
            )}
          </p>
        )}
      </div>

      {groups.length > 0 ? (
        <ul className="space-y-2" aria-label="Open issues by unit" data-testid="captain5-issue-list">
          {groups.map((group) => (
            <UnitIssueGroup
              key={group.apparatusId}
              group={group}
              expanded={isExpanded(group.apparatusId)}
              busy={clearing.busy}
              onToggle={() => toggle(group.apparatusId)}
              onOpen={onOpen}
              onClear={(issues) => void clearing.run('send_to_logistics', issues)}
            />
          ))}
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

      <ClearedIssues
        issues={workspace.cleared_issues}
        open={clearedOpen}
        busy={clearing.busy}
        onToggle={setClearedOpen}
        onOpen={onOpen}
        onBringBack={(issue) => void clearing.run('bring_back_to_captain5', [issue])}
      />
    </div>
  );
}
