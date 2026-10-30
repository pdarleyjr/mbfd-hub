import { dutyNote } from './format';
import type { OnDuty } from './types';

/** Outside the Daily Checkout app, so a plain link rather than a router link. */
const DAILY_ROSTER_PATH = '/daily-roster';

interface Props {
  onDuty: OnDuty;
  testId: string;
  /** Only where it is safe: the station-list cards are links themselves, and a link cannot sit inside a link. */
  linkToRoster?: boolean;
  className?: string;
}

/** The small line under the shift line. Renders nothing when there is nothing to say. */
export default function DutyNote({ onDuty, testId, linkToRoster = false, className = '' }: Props) {
  const note = dutyNote(onDuty);
  if (!note) return null;
  return (
    <p className={`text-[13px] leading-5 text-hub-n-600 ${className}`} data-testid={testId}>
      {note.pointsToRoster && linkToRoster
        ? <a href={DAILY_ROSTER_PATH} className="inline-flex min-h-11 items-center font-semibold text-hub-blue underline underline-offset-4">{note.text}</a>
        : note.text}
    </p>
  );
}
