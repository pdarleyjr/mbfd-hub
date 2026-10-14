import { useState, useEffect, useCallback } from 'react';
import { House } from 'lucide-react';
import { Station } from '../types';
import { ApiClient, isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../utils/api';
import StationCard from './StationCard';
import { HubPageHeader } from '../../../hub-ui/HubShell';
import { EmptyState, LoadError } from './ui/StateViews';

const GRID = 'daily-station-grid grid grid-cols-1 gap-4 sm:gap-5 md:grid-cols-2 lg:grid-cols-3 lg:gap-6 xl:grid-cols-4 2xl:grid-cols-5';

export default function StationListPage() {
  const [stations, setStations] = useState<Station[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchStations = useCallback(async () => {
    setLoading(true);
    try {
      const data = await ApiClient.getStations();
      setStations(data);
      setError(null);
    } catch (err) {
      if (isApiAuthenticationError(err)) {
        redirectToLoginAfterSessionExpiry();
        return;
      }
      setError(err instanceof Error ? err.message : 'Failed to load stations');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchStations();
  }, [fetchStations]);

  if (loading) {
    return (
      <div data-hub-ui="2" className="space-y-6 font-hub" role="status" aria-label="Loading stations">
        <div className="border-b border-hub-border pb-5">
          <span className="hub-skeleton mb-3 !h-7 w-56" />
          <span className="hub-skeleton !h-4 w-80 max-w-full" />
        </div>
        <div className={GRID}>
          {[1, 2, 3, 4, 5].map(i => (
            <div key={i} className="hub-panel overflow-hidden">
              <span className="hub-skeleton !h-48 w-full !rounded-none" />
              <div className="space-y-2 p-5">
                <span className="hub-skeleton !h-5 w-36" />
                <span className="hub-skeleton w-full" />
                <span className="hub-skeleton w-40" />
              </div>
            </div>
          ))}
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div data-hub-ui="2" className="mx-auto max-w-lg font-hub">
        <LoadError title="Failed to load stations" message={error} onRetry={fetchStations}>
          <a href="/" className="hub-btn hub-btn--secondary text-hub-ink">Hub home</a>
        </LoadError>
      </div>
    );
  }

  return (
    <div data-hub-ui="2" className="font-hub">
      <HubPageHeader eyebrow="Station operations" title="MBFD Stations" description={<>
          Select your station below to access forms, inspections, apparatus information, and more.
          Each station page contains everything you need for daily operations.
        </>} />

      {stations.length > 0 ? (
        <div data-testid="daily-station-grid" className={GRID}>
          {stations.map((station) => (
            <StationCard key={station.id} station={station} />
          ))}
        </div>
      ) : (
        <div className="hub-panel">
          <EmptyState
            icon={<House className="hub-empty__icon" aria-hidden="true" />}
            title="No stations available"
            body="Stations appear here once they are set up in Administration."
            action={<a href="/" className="hub-btn hub-btn--secondary">Hub home</a>}
          />
        </div>
      )}
    </div>
  );
}
