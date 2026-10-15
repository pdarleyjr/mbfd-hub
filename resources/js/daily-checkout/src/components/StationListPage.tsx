import { useState, useEffect, useCallback } from 'react';
import { House, RefreshCw } from 'lucide-react';
import { Station } from '../types';
import { ApiClient, isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../utils/api';
import { useOffline } from '../hooks/useOffline';
import StationCard from './StationCard';
import { HubPageHeader } from '../../../hub-ui/HubShell';
import { EmptyState, LoadError } from './ui/StateViews';

const GRID = 'daily-station-grid grid grid-cols-1 gap-4 sm:gap-5 md:grid-cols-2 lg:grid-cols-3 lg:gap-6 xl:grid-cols-4 2xl:grid-cols-5';

type LoadMode = 'initial' | 'refresh';

export default function StationListPage() {
  const [stations, setStations] = useState<Station[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [refreshError, setRefreshError] = useState<string | null>(null);
  const [refreshNotice, setRefreshNotice] = useState('');
  const isOffline = useOffline();

  /**
   * A refresh keeps the current cards on screen and reports failure inline, so
   * a dropped connection never blanks a list the member was already using.
   */
  const fetchStations = useCallback(async (mode: LoadMode = 'initial') => {
    if (mode === 'refresh') {
      setRefreshing(true);
      setRefreshNotice('');
    } else {
      setLoading(true);
    }
    try {
      const data = await ApiClient.getStations();
      setStations(data);
      setError(null);
      setRefreshError(null);
      if (mode === 'refresh') setRefreshNotice('Station list updated.');
    } catch (err) {
      if (isApiAuthenticationError(err)) {
        redirectToLoginAfterSessionExpiry();
        return;
      }
      const message = err instanceof Error ? err.message : 'Failed to load stations';
      if (mode === 'refresh') {
        setRefreshError(message);
      } else {
        setError(message);
      }
    } finally {
      setLoading(false);
      setRefreshing(false);
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
        <LoadError title="Failed to load stations" message={error} onRetry={() => fetchStations()}>
          <a href="/" className="hub-btn hub-btn--secondary text-hub-ink">Hub home</a>
        </LoadError>
      </div>
    );
  }

  const refreshLabel = isOffline ? 'Refresh unavailable while offline' : refreshing ? 'Refreshing stations' : 'Refresh stations';

  return (
    <div data-hub-ui="2" className="font-hub">
      <HubPageHeader
        eyebrow="Station operations"
        title="MBFD Stations"
        description={<>
          Select your station below to access forms, inspections, apparatus information, and more.
          Each station page contains everything you need for daily operations.
        </>}
        actions={(
          <button
            type="button"
            data-testid="daily-station-refresh"
            className="hub-btn hub-btn--secondary !min-h-11 !min-w-11 gap-2"
            onClick={() => fetchStations('refresh')}
            disabled={refreshing || isOffline}
            aria-busy={refreshing}
            aria-label={refreshLabel}
            title={refreshLabel}
          >
            <RefreshCw className={`h-4 w-4 ${refreshing ? 'motion-safe:animate-spin' : ''}`} aria-hidden="true" />
            <span className="hidden sm:inline">{refreshing ? 'Refreshing' : 'Refresh'}</span>
          </button>
        )}
      />

      <p className="sr-only" role="status" aria-live="polite">{refreshNotice}</p>
      {refreshError && (
        <div className="hub-callout hub-callout--warning mb-4" role="alert">
          Stations could not be refreshed: {refreshError}. The list below may be out of date.
        </div>
      )}

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
