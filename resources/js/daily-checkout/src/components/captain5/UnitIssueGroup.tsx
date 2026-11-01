import { useId } from 'react';
import { ChevronDown } from 'lucide-react';
import Tag from '../ui/Tag';
import IssueRow from './IssueRow';
import { ageText, dateTimeText } from './format';
import type { IssueGroup } from './issueGroups';
import type { IssueSummary } from './types';
import { BTN_SECONDARY, HINT } from './ui';

interface Props {
  group: IssueGroup;
  expanded: boolean;
  busy: boolean;
  onToggle: () => void;
  onOpen: (id: number) => void;
  onClear: (issues: IssueSummary[]) => void;
}

/** One unit's issues: a real disclosure, with each issue's own row and clear control once it is open. */
export default function UnitIssueGroup({ group, expanded, busy, onToggle, onOpen, onClear }: Props) {
  const panelId = useId();
  const count = group.issues.length;
  return (
    <li data-testid="captain5-unit-group" className="rounded-hub-control border border-hub-border bg-hub-n-25">
      <button
        type="button"
        data-testid="captain5-unit-row"
        aria-expanded={expanded}
        aria-controls={panelId}
        onClick={onToggle}
        className="flex min-h-14 w-full items-center gap-3 rounded-hub-control px-3.5 py-3 text-left transition-colors hover:bg-hub-blue-tint"
      >
        <span className="flex min-w-0 flex-1 flex-col gap-1.5">
          <span className="flex flex-wrap items-baseline gap-x-2">
            <strong className="text-hub-ink">{group.unit}</strong>
            <span className="tabular-nums text-hub-ink">{count} {count === 1 ? 'issue' : 'issues'}</span>
          </span>
          <span className="flex flex-wrap items-center gap-x-2 gap-y-1.5">
            <Tag tone={group.worst.tone}>{group.worst.label}</Tag>
            <span className={HINT}>
              {count === 1 ? 'Reported ' : 'Oldest reported '}
              <time dateTime={group.oldestReportedAt ?? undefined} title={dateTimeText(group.oldestReportedAt)}>{ageText(group.oldestReportedAt)}</time>
            </span>
          </span>
        </span>
        <ChevronDown className={`h-5 w-5 flex-none text-hub-n-500 motion-safe:transition-transform ${expanded ? 'rotate-180' : ''}`} aria-hidden="true" />
      </button>
      <div id={panelId} hidden={!expanded} className="space-y-2 border-t border-hub-border p-2 sm:p-3">
        {expanded && (
          <>
            <ul className="space-y-2" aria-label={`${group.unit} issues`}>
              {group.issues.map((issue) => (
                <IssueRow
                  key={issue.id}
                  issue={issue}
                  onOpen={onOpen}
                  quietUnit
                  aside={(
                    <button
                      type="button"
                      data-testid="captain5-clear-issue"
                      className={`${BTN_SECONDARY} flex-none`}
                      disabled={busy}
                      onClick={() => onClear([issue])}
                      aria-label={`Clear for Logistics: ${group.unit}, ${issue.item}`}
                    >
                      Clear for Logistics
                    </button>
                  )}
                />
              ))}
            </ul>
            {count > 1 && (
              <button type="button" data-testid="captain5-clear-unit" className={`${BTN_SECONDARY} w-full sm:w-auto`} disabled={busy} onClick={() => onClear(group.issues)}>
                Clear all {count} for Logistics
              </button>
            )}
          </>
        )}
      </div>
    </li>
  );
}
