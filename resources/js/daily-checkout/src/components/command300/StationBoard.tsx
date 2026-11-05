import type { Ref } from 'react';
import { Building2 } from 'lucide-react';
import { EmptyState, LoadError, SkeletonRows } from '../ui/StateViews';
import StationCard from './StationCard';
import SummaryStrip from './SummaryStrip';
import type { CommandBoard } from './types';

interface StationBoardProps {
  board: CommandBoard | null;
  loading: boolean;
  error: string | null;
  onRetry: () => void;
  onOpen: (stationId: number) => void;
  /** Lets the page return focus to the card a station was opened from. */
  cardRef: (stationId: number) => Ref<HTMLButtonElement>;
}

/** The summary strip and one card per active station. The page owns loading, refresh and the detail sheet. */
export default function StationBoard({ board, loading, error, onRetry, onOpen, cardRef }: StationBoardProps) {
  if (!board) {
    if (error) return <LoadError title="The station board is unavailable" message={`${error} Messages are still available below.`} onRetry={onRetry} />;
    return (
      <div aria-busy={loading} data-testid="command-board-loading">
        <SkeletonRows rows={2} className="!h-24" />
      </div>
    );
  }

  return (
    <div className="space-y-4" data-testid="command-board">
      <SummaryStrip summary={board.summary} rosterAvailable={board.roster.available} />
      {!board.roster.available && board.stations.length > 0 && (
        <p className="hub-callout p-3 text-sm" role="status" data-testid="command-roster-note">{board.roster.message ?? "Today's roster has not arrived yet."} Crews appear on each station as soon as it does.</p>
      )}
      {board.stations.length === 0 ? (
        <EmptyState icon={<Building2 className="hub-empty__icon" aria-hidden="true" />} title="No active stations" body="Active stations appear here once they are set up in Administration." />
      ) : (
        <ul className="grid gap-3 md:grid-cols-2 2xl:grid-cols-3" aria-label="Stations">
          {board.stations.map((station) => (
            <StationCard key={station.id} station={station} ref={cardRef(station.id)} onOpen={() => onOpen(station.id)} />
          ))}
        </ul>
      )}
    </div>
  );
}
