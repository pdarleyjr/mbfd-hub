import { Link } from 'react-router';
import { ChevronRight } from 'lucide-react';
import { COMMAND_300_IMAGE, bundledImageSize } from '../types';
import StationCardMedia from './StationCardMedia';

/**
 * Peer of StationCard on the stations list. 300 Command is a unit profile with
 * its own route (/command-300); it is never a station record or a station id.
 */
export default function Command300Card() {
  return (
    <Link
      to="/command-300"
      data-testid="daily-command-card"
      aria-label="Open 300 Command"
      className="daily-selector-card hub-panel group block overflow-hidden text-hub-ink transition-colors hover:border-hub-blue/40 touch-manipulation font-hub"
    >
      <StationCardMedia src={COMMAND_300_IMAGE} alt="Miami Beach Fire Rescue Command SUV" size={bundledImageSize(COMMAND_300_IMAGE)}>
        <span className="absolute left-3 top-3 rounded-hub-tag bg-hub-header px-2.5 py-1 text-[13px] font-bold leading-5 text-white">
          Command
        </span>
      </StationCardMedia>

      <div className="p-4 sm:p-5 2xl:p-6">
        <h3 className="mb-3 text-lg font-bold text-hub-ink font-heading 2xl:text-xl">300 Command</h3>
        <p className="text-sm text-hub-n-600">Morning Lineup, Command Board and a read-only readiness overview of every station.</p>
        <div className="mt-4 flex min-h-11 items-center justify-end border-t border-hub-glass-border pt-3 text-sm font-semibold text-hub-blue group-hover:underline group-hover:underline-offset-4">
          View Command
          <ChevronRight className="ml-1 h-4 w-4" aria-hidden="true" />
        </div>
      </div>
    </Link>
  );
}
