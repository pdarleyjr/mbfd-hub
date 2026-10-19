import { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router';
import { ChevronRight, Search, Truck } from 'lucide-react';
import { Apparatus } from '../types';
import { ApiClient } from '../utils/api';
import DailyPageNav from './DailyPageNav';
import InspectionRevisions from './InspectionRevisions';
import { HubPageHeader } from '../../../hub-ui/HubShell';
import Tag from './ui/Tag';
import { EmptyState, LoadError } from './ui/StateViews';
import { humanize, type Tone } from './ui/status';

const GRID = 'grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5';

/** Operational status reads as text in a tag; the tone only reinforces it. */
function apparatusStatus(status: string | undefined): { label: string; tone: Tone } {
  const label = status?.trim() ? status.trim() : 'Status unavailable';
  const key = label.toLowerCase();
  if (key === 'in service') return { label, tone: 'success' };
  if (key === 'out of service') return { label, tone: 'danger' };
  if (key === 'status unavailable') return { label, tone: 'neutral' };
  return { label, tone: 'warning' };
}

function UnitMark({ apparatus, muted = false }: { apparatus: Apparatus; muted?: boolean }) {
  const initial = apparatus.designation?.trim().charAt(0);
  return (
    <span className={`flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-hub-control text-lg font-bold ${muted ? 'border border-hub-glass-border bg-hub-n-100 text-hub-n-600' : 'border border-hub-glass-border bg-hub-well text-hub-blue'}`} aria-hidden="true">
      {initial || <Truck className="h-6 w-6" />}
    </span>
  );
}

export default function VehicleInspectionSelect() {
  const [apparatuses, setApparatuses] = useState<Apparatus[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [searchQuery, setSearchQuery] = useState('');

  const fetchApparatuses = useCallback(async () => {
    setLoading(true);
    try {
      setApparatuses(await ApiClient.getApparatuses());
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load vehicles');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchApparatuses();
  }, [fetchApparatuses]);

  const filteredApparatuses = apparatuses.filter((a) => {
    if (!searchQuery.trim()) return true;
    const q = searchQuery.toLowerCase();
    return (
      (a.designation || '').toLowerCase().includes(q) ||
      (a.name || '').toLowerCase().includes(q) ||
      (a.vehicle_number || '').toLowerCase().includes(q) ||
      (a.type || '').toLowerCase().includes(q)
    );
  });

  if (loading) {
    return (
      <div data-hub-ui="2" className="space-y-6 font-hub" role="status" aria-label="Loading vehicles">
        <DailyPageNav />
        <div className="border-b border-hub-border pb-5">
          <span className="hub-skeleton mb-3 !h-7 w-56" />
          <span className="hub-skeleton !h-4 w-72 max-w-full" />
        </div>
        <span className="hub-skeleton !h-11 w-full max-w-md !rounded-hub-control" />
        <div className={GRID}>
          {[1, 2, 3, 4, 5, 6].map(i => (
            <div key={i} className="hub-panel flex items-center gap-4 p-4">
              <span className="hub-skeleton !h-12 !w-12 !rounded-hub-control" />
              <div className="flex-1 space-y-2">
                <span className="hub-skeleton !h-5 w-32" />
                <span className="hub-skeleton w-24" />
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
        <DailyPageNav />
        <LoadError title="Failed to load vehicles" message={error} onRetry={fetchApparatuses} />
      </div>
    );
  }

  return (
    <div data-hub-ui="2" className="font-hub">
      <DailyPageNav />
      <InspectionRevisions />
      <HubPageHeader eyebrow="Daily Checkout" title="Vehicle Inspections" description="Select a vehicle to begin the daily inspection." />

      <div className="relative mb-6 max-w-md">
        <Search className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-hub-n-500" aria-hidden="true" />
        <input
          id="vehicle-search"
          type="search"
          aria-label="Search vehicles"
          placeholder="Search by name, designation, or type"
          value={searchQuery}
          onChange={(e) => setSearchQuery(e.target.value)}
          className="min-h-11 w-full rounded-hub-control border border-hub-control-border bg-hub-well py-2.5 pl-10 pr-4 text-sm text-hub-ink placeholder:text-hub-n-500 focus:border-hub-focus focus:outline-none"
        />
      </div>

      {filteredApparatuses.length > 0 && (
        <ul className={GRID} aria-label="Vehicles">
          {filteredApparatuses.map((apparatus) => {
            const status = apparatusStatus(apparatus.status);
            const title = apparatus.designation || apparatus.name || 'Unknown';
            const details = (
              <>
                <h3 className="truncate text-base font-semibold text-hub-ink">{title}</h3>
                {apparatus.vehicle_number && <p className="text-sm tabular-nums text-hub-n-600">Vehicle #{apparatus.vehicle_number}</p>}
                <p className="text-[13px] text-hub-n-600">{humanize(apparatus.type, 'Type not recorded')}</p>
              </>
            );

            // A unit without a checklist route (for example a command vehicle) is listed but cannot be opened.
            if (!apparatus.slug) {
              return (
                <li key={apparatus.id} className="hub-panel flex min-h-24 items-start gap-4 p-4">
                  <UnitMark apparatus={apparatus} muted />
                  <div className="min-w-0 flex-1">
                    {details}
                    <div className="mt-2 flex flex-wrap gap-2"><Tag>No inspection available</Tag></div>
                  </div>
                </li>
              );
            }

            return (
              <li key={apparatus.id}>
                <Link
                  to={`/vehicle-inspections/${apparatus.slug}`}
                  className="hub-panel group flex h-full min-h-24 items-start gap-4 p-4 transition-colors hover:border-hub-blue/40"
                >
                  <UnitMark apparatus={apparatus} />
                  <div className="min-w-0 flex-1">
                    {details}
                    <div className="mt-2 flex flex-wrap gap-2"><Tag tone={status.tone}>{status.label}</Tag></div>
                  </div>
                  <ChevronRight className="mt-3 h-5 w-5 flex-shrink-0 text-hub-blue" aria-hidden="true" />
                </Link>
              </li>
            );
          })}
        </ul>
      )}

      {filteredApparatuses.length === 0 && (
        <div className="hub-panel mt-2">
          <EmptyState
            icon={<Search className="hub-empty__icon" aria-hidden="true" />}
            title={searchQuery ? `No vehicles matching "${searchQuery}"` : 'No vehicles available for inspection'}
            body={searchQuery ? 'Check the designation or vehicle number, or clear the search.' : 'Vehicles appear here once they are assigned a Daily Checkout.'}
            action={searchQuery ? <button type="button" className="hub-btn hub-btn--secondary" onClick={() => setSearchQuery('')}>Clear search</button> : undefined}
          />
        </div>
      )}
    </div>
  );
}
