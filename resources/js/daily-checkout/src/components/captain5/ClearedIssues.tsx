import IssueRow from './IssueRow';
import { dateTimeText } from './format';
import type { IssueSummary } from './types';
import { BTN_SECONDARY, HINT } from './ui';

interface Props {
  issues: IssueSummary[];
  open: boolean;
  busy: boolean;
  onToggle: (open: boolean) => void;
  onOpen: (id: number) => void;
  onBringBack: (issue: IssueSummary) => void;
}

/** Off the queue and out of every count, but never out of reach. Logistics closing one moves it to History. */
export default function ClearedIssues({ issues, open, busy, onToggle, onOpen, onBringBack }: Props) {
  if (issues.length === 0) return null;
  return (
    <details
      data-testid="captain5-cleared-section"
      open={open}
      onToggle={(event) => onToggle(event.currentTarget.open)}
      className="rounded-hub-control border border-hub-border bg-hub-n-25 px-3.5 py-1"
    >
      <summary className="flex min-h-11 cursor-pointer items-center text-sm font-semibold text-hub-ink">
        Cleared for Logistics (<span className="tabular-nums">{issues.length}</span>)
      </summary>
      <div className="space-y-2 pb-3">
        <p className={HINT}>Still open with Logistics. Not in your queue or counts. Bring one back to work it here.</p>
        <ul className="space-y-2" aria-label="Issues cleared for Logistics" data-testid="captain5-cleared-list">
          {issues.map((issue) => (
            <IssueRow
              key={issue.id}
              issue={issue}
              onOpen={onOpen}
              testId="captain5-cleared-row"
              footnote={`Cleared ${dateTimeText(issue.captain5_cleared_at)}${issue.captain5_cleared_by ? ` by ${issue.captain5_cleared_by}` : ''}`}
              aside={(
                <button
                  type="button"
                  data-testid="captain5-bring-back"
                  className={`${BTN_SECONDARY} flex-none`}
                  disabled={busy}
                  onClick={() => onBringBack(issue)}
                  aria-label={`Bring back: ${issue.unit}, ${issue.item}`}
                >
                  Bring back
                </button>
              )}
            />
          ))}
        </ul>
      </div>
    </details>
  );
}
