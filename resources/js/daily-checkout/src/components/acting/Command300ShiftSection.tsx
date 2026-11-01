import { BTN_SECONDARY } from '../captain5/ui';
import Tag from '../ui/Tag';
import DutyNote from './DutyNote';
import { COMMAND_300_DUTY, dutyLine, dutyTone } from './format';
import type { Command300Shift } from './useCommand300Shift';

/**
 * The top of the 300 page: who is the shift's 300 today, from the TeleStaff roster.
 * Loading and failure are shown as such; nothing here ever guesses a name.
 */
export default function Command300ShiftSection({ shift }: { shift: Command300Shift }) {
  const { data, loading, error, retry } = shift;

  if (!data && loading) {
    return <span className="hub-skeleton block !h-8 w-72 max-w-full" role="status" aria-label="Loading the shift 300" data-testid="command-duty-loading" />;
  }

  if (!data) {
    return (
      <div className="hub-callout hub-callout--warning flex flex-wrap items-center gap-3 p-3 text-sm" role="alert" data-testid="command-duty-error">
        <span className="min-w-0 flex-1">The shift 300 could not be loaded. {error}</span>
        <button type="button" className={BTN_SECONDARY} onClick={retry}>Try again</button>
      </div>
    );
  }

  return (
    <div className="space-y-2" data-testid="command-duty">
      <p className="flex flex-wrap items-center gap-2 text-sm" data-testid="command-duty-line">
        <Tag tone={dutyTone(data.on_duty)} className="!text-sm !whitespace-normal !py-1 max-w-full break-words">{dutyLine(data.on_duty, COMMAND_300_DUTY)}</Tag>
      </p>
      <DutyNote onDuty={data.on_duty} testId="command-duty-note" linkToRoster />
      {error && (
        <p className="text-[13px] leading-5 text-hub-n-600" role="status" data-testid="command-duty-stale">
          The shift 300 could not be refreshed: {error} The name above may be out of date.
        </p>
      )}
    </div>
  );
}
