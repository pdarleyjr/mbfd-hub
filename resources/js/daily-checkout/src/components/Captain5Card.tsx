import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { ChevronRight } from 'lucide-react';
import { CAPTAIN_5_IMAGE, bundledImageSize } from '../types';
import { fetchCaptain5Summary } from '../utils/captain5Api';
import type { Captain5Summary } from './captain5/types';
import StationCardMedia from './StationCardMedia';
import Tag from './ui/Tag';

/**
 * The server decides who sees Captain 5. The card appears only after /api/captain5/summary answers 200
 * with view access; a 403, an error or being offline leaves it out, and the station list never waits for it.
 */
export function useCaptain5Summary(): Captain5Summary | null {
  const [summary, setSummary] = useState<Captain5Summary | null>(null);
  useEffect(() => {
    let active = true;
    fetchCaptain5Summary()
      .then((loaded) => { if (active) setSummary(loaded.access?.view ? loaded : null); })
      .catch(() => { if (active) setSummary(null); });
    return () => { active = false; };
  }, []);
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
