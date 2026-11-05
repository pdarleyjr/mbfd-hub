import IssueRow from './IssueRow';
import type { IssueSummary } from './types';
import { BTN_SECONDARY, HINT } from './ui';

interface Props {
  issues: IssueSummary[];
  hours: number;
  busy: boolean;
  onOpen: (id: number) => void;
  onClear: (issue: IssueSummary) => void;
}

const windowText = (hours: number): string => (hours % 24 === 0 ? `${hours / 24} ${hours === 24 ? 'day' : 'days'}` : `${hours} hours`);

/**
 * Fixed issues stay in view for a few days so it is clear they were resolved, and by whom: a member's checkout or
 * Mark resolved closes a routine issue by itself. Not open, not counted. Clear takes one off early; History keeps it.
 */
export default function RecentlyResolved({ issues, hours, busy, onOpen, onClear }: Props) {
  if (issues.length === 0) return null;
  return (
    <section aria-labelledby="c5-recently-resolved" data-testid="captain5-recently-resolved" className="space-y-2">
      <h2 id="c5-recently-resolved" className="font-heading text-base font-bold text-hub-ink">
        Recently resolved (<span className="tabular-nums">{issues.length}</span>)
      </h2>
      <p className={HINT}>Already closed. They leave this list after {windowText(hours)} or when you clear them, and stay in History.</p>
      <ul className="space-y-2" aria-label="Recently resolved issues" data-testid="captain5-resolved-list">
        {issues.map((issue) => (
          <IssueRow
            key={issue.id}
            issue={issue}
            onOpen={onOpen}
            testId="captain5-resolved-row"
            aside={(
              <button
                type="button"
                data-testid="captain5-clear-resolved"
                className={`${BTN_SECONDARY} flex-none`}
                disabled={busy}
                onClick={() => onClear(issue)}
                aria-label={`Clear from list: ${issue.unit}, ${issue.item}`}
              >
                Clear
              </button>
            )}
          />
        ))}
      </ul>
    </section>
  );
}
