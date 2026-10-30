import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router';
import { ChevronRight } from 'lucide-react';
import { CAPTAIN_5_IMAGE, bundledImageSize } from '../types';
import { dutyLine, dutyTone } from './captain5/format';
import { fetchCaptain5Summary, isCaptain5Forbidden } from '../utils/captain5Api';
import type { Captain5Summary } from './captain5/types';
import DutyNote from './acting/DutyNote';
import { useVisibleRefresh } from './acting/useVisibleRefresh';
import StationCardMedia from './StationCardMedia';
import Tag from './ui/Tag';

/**
 * The server decides who sees Captain 5. The card appears only after /api/captain5/summary answers 200
 * with view access; a 403, an error or being offline leaves it out, and the station list never waits for it.
 * Later refreshes keep the card on screen through a failed request; only a refusal removes it.
 */
export function useCaptain5Summary(): Captain5Summary | null {
  const [summary, setSummary] = useState<Captain5Summary | null>(null);
  // Only the newest request may write state; a page that has gone away writes nothing.
  const latest = useRef(0);

  const load = useCallback((refreshing: boolean) => {
    const mine = ++latest.current;
    fetchCaptain5Summary()
      .then((loaded) => { if (mine === latest.current) setSummary(loaded.access?.view ? loaded : null); })
      .catch((failure) => { if (mine === latest.current && (!refreshing || isCaptain5Forbidden(failure))) setSummary(null); });
  }, []);

  useEffect(() => {
    load(false);
    return () => { latest.current += 1; };
  }, [load]);

  const refresh = useCallback(() => load(true), [load]);
  useVisibleRefresh(refresh);

  return summary;
}

/** Peer of Command300Card on the stations list; Captain 5 is an operations workspace with its own route. */
export default function Captain5Card({ summary }: { summary: Captain5Summary }) {
  const open = summary.open_issues;
  return (
    <Link
      to="/captain-5"
      data-testid="daily-captain5-card"
      aria-label="Open Captain 5"
      className="daily-selector-card hub-panel group block overflow-hidden text-hub-ink transition-colors hover:border-hub-blue/40 touch-manipulation font-hub"
    >
      <StationCardMedia src={CAPTAIN_5_IMAGE} alt="Miami Beach Fire Rescue Captain 5 EMS vehicle" size={bundledImageSize(CAPTAIN_5_IMAGE)}>
        <span className="absolute left-3 top-3 rounded-hub-tag bg-hub-header px-2.5 py-1 text-[13px] font-bold leading-5 text-white">
          EMS
        </span>
      </StationCardMedia>

      <div className="p-4 sm:p-5 2xl:p-6">
        <h3 className="mb-3 text-lg font-bold text-hub-ink font-heading 2xl:text-xl">Captain 5</h3>
        <p className="mb-2" data-testid="daily-captain5-duty">
          <Tag tone={dutyTone(summary.on_duty)} className="!whitespace-normal !py-1 max-w-full break-words">{dutyLine(summary.on_duty)}</Tag>
        </p>
        <DutyNote onDuty={summary.on_duty} testId="daily-captain5-duty-note" className="mb-2" />
        <p className="text-sm text-hub-n-600">EMS Operations: Rescue and medical equipment issues, replacements, shift handoff and messages.</p>
        <p className="mt-3" data-testid="daily-captain5-open">
          <Tag tone={open > 0 ? 'warning' : 'success'}>{open > 0 ? `${open} open ${open === 1 ? 'issue' : 'issues'}` : 'No open issues'}</Tag>
        </p>
        <div className="mt-4 flex min-h-11 items-center justify-end border-t border-hub-glass-border pt-3 text-sm font-semibold text-hub-blue group-hover:underline group-hover:underline-offset-4">
          Open Captain 5
          <ChevronRight className="ml-1 h-4 w-4" aria-hidden="true" />
        </div>
      </div>
    </Link>
  );
}
