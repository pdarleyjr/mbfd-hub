import type { ReactNode } from 'react';
import { ChevronRight } from 'lucide-react';
import { ageText, dateTimeText, dayText } from './format';
import type { IssueSummary } from './types';
import { HINT, IssueTags } from './ui';

interface Props {
  issue: IssueSummary;
  onOpen: (id: number) => void;
  /** Replacement tracking shows the delivery line instead of the age. */
  detail?: 'age' | 'delivery';
  /** Inside a unit's group the unit is already the heading; it stays in the row for screen readers. */
  quietUnit?: boolean;
  /** A separate control beside the row's target, never nested inside it. */
  aside?: ReactNode;
  /** One more plain line under the status tags. */
  footnote?: ReactNode;
  testId?: string;
}

/** One whole-row button: a 44px+ target that works by touch, mouse and keyboard alike. */
export default function IssueRow({ issue, onOpen, detail = 'age', quietUnit = false, aside, footnote, testId = 'captain5-issue-row' }: Props) {
  const delivery = [
    issue.expected_delivery ? `Expected ${dayText(issue.expected_delivery)}` : 'No delivery date',
    issue.details.vendor_reference ? `Ref ${issue.details.vendor_reference}` : null,
  ].filter(Boolean).join(' · ');
  return (
    <li className={aside ? 'flex flex-col gap-2 sm:flex-row sm:items-stretch' : undefined}>
      <button
        type="button"
        data-testid={testId}
        onClick={() => onOpen(issue.id)}
        className="flex min-h-14 w-full min-w-0 flex-1 items-center gap-3 rounded-hub-control border border-hub-border bg-hub-well px-3.5 py-3 text-left transition-colors hover:bg-hub-blue-tint"
      >
        <span className="flex min-w-0 flex-1 flex-col gap-1.5">
          <span className="flex flex-wrap items-baseline gap-x-2">
            <strong className={quietUnit ? 'sr-only' : 'text-hub-ink'}>{issue.unit}</strong>
            <span className="min-w-0 break-words text-hub-ink">{issue.item}</span>
          </span>
          <span className={`${HINT} break-words`}>
            {issue.compartment} · {issue.issue_type} ·{' '}
            {detail === 'delivery'
              ? delivery
              : <>Reported <time dateTime={issue.first_reported_at ?? undefined} title={dateTimeText(issue.first_reported_at)}>{ageText(issue.first_reported_at)}</time></>}
          </span>
          <IssueTags issue={issue} />
          {footnote && <span className={`${HINT} break-words`}>{footnote}</span>}
        </span>
        <ChevronRight className="h-5 w-5 flex-none text-hub-n-500" aria-hidden="true" />
      </button>
      {aside}
    </li>
  );
}
