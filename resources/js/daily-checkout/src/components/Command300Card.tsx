import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { ChevronRight } from 'lucide-react';
import { COMMAND_300_IMAGE, bundledImageSize } from '../types';
import { fetchCommandSummary } from '../utils/commandCommunicationsApi';
import StationCardMedia from './StationCardMedia';
import { COMMAND_300_ACTING } from './acting/command300Role';
import { dutyLine, dutyTone } from './acting/format';
import { useCommand300Shift } from './acting/useCommand300Shift';
import Tag from './ui/Tag';

/** Quiet by design: a failed summary simply shows no badge; the Command page has the full state. */
function useUnreadCount(): number {
  const [unread, setUnread] = useState(0);
  useEffect(() => {
    let active = true;
    fetchCommandSummary()
      .then((summary) => { if (active) setUnread(summary.unread_count); })
      .catch(() => { if (active) setUnread(0); });
    return () => { active = false; };
  }, []);
  return unread;
}

/**
 * Peer of StationCard on the stations list. 300 is a unit profile with
 * its own route (/command-300); it is never a station record or a station id.
 * The stations list renders it only for accounts the server has cleared for 300.
 */
export default function Command300Card() {
  const unread = useUnreadCount();
  // Quiet on purpose: while loading, or on any failure, no shift line is better than a wrong name.
  const onDuty = useCommand300Shift({ poll: false }).data?.on_duty ?? null;

  return (
    <Link
      to="/command-300"
      data-testid="daily-command-card"
      aria-label="Open 300"
      className="daily-selector-card hub-panel group block overflow-hidden text-hub-ink transition-colors hover:border-hub-blue/40 touch-manipulation font-hub"
    >
      <StationCardMedia src={COMMAND_300_IMAGE} alt="Miami Beach Fire Rescue Command SUV" size={bundledImageSize(COMMAND_300_IMAGE)}>
        <span className="absolute left-3 top-3 rounded-hub-tag bg-hub-header px-2.5 py-1 text-[13px] font-bold leading-5 text-white">
          Shift Division Chief
        </span>
      </StationCardMedia>

      <div className="p-4 sm:p-5 2xl:p-6">
        <h3 className="mb-3 text-lg font-bold text-hub-ink font-heading 2xl:text-xl">300</h3>
        {onDuty && (
          <p className="mb-2" data-testid="daily-command-duty">
            <Tag tone={dutyTone(onDuty)} className="!whitespace-normal !py-1 max-w-full break-words">{dutyLine(onDuty, COMMAND_300_ACTING)}</Tag>
          </p>
        )}
        <p className="text-sm text-hub-n-600">Morning Lineup, Command Board, messages, Department Updates, and a read-only readiness overview of every station.</p>
        {unread > 0 && (
          <p className="mt-3" data-testid="daily-command-unread">
            <Tag tone="info">{unread} unread {unread === 1 ? 'message' : 'messages'}</Tag>
          </p>
        )}
        <div className="mt-4 flex min-h-11 items-center justify-end border-t border-hub-glass-border pt-3 text-sm font-semibold text-hub-blue group-hover:underline group-hover:underline-offset-4">
          Open 300
          <ChevronRight className="ml-1 h-4 w-4" aria-hidden="true" />
        </div>
      </div>
    </Link>
  );
}
