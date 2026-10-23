import { PackageCheck } from 'lucide-react';
import { EmptyState } from '../ui/StateViews';
import IssueRow from './IssueRow';
import { isReplacementTracked, sortReplacements } from './format';
import type { IssueSummary } from './types';
import { HINT } from './ui';

interface Props {
  issues: IssueSummary[];
  onOpen: (id: number) => void;
}

/** Ordered, ready for pickup and overdue items, most urgent first: overdue, pickup, then soonest delivery. */
export default function ReplacementsTab({ issues, onOpen }: Props) {
  const tracked = sortReplacements(issues.filter(isReplacementTracked));
  if (tracked.length === 0) {
    return (
      <EmptyState
        icon={<PackageCheck className="hub-empty__icon" aria-hidden="true" />}
        title="No replacements in progress"
        body="Items you mark as ordered or ready for pickup are tracked here until they are replaced."
      />
    );
  }
  return (
    <div className="space-y-3">
      <p className={HINT}>{tracked.length} {tracked.length === 1 ? 'item' : 'items'} waiting on a replacement. Overdue first, then ready for pickup, then soonest delivery.</p>
      <ul className="space-y-2" aria-label="Replacements in progress" data-testid="captain5-replacement-list">
        {tracked.map((issue) => <IssueRow key={issue.id} issue={issue} onOpen={onOpen} detail="delivery" />)}
      </ul>
    </div>
  );
}
