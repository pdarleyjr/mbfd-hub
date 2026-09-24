import { useState, useEffect } from 'react';
import { Link } from 'react-router';
import { Apparatus } from '../types';
import { ApiClient } from '../utils/api';
import PreviousPageButton from './PreviousPageButton';
import InspectionRevisions from './InspectionRevisions';

export default function VehicleInspectionSelect() {
  const [apparatuses, setApparatuses] = useState<Apparatus[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [searchQuery, setSearchQuery] = useState('');

  useEffect(() => {
    const fetchApparatuses = async () => {
      try {
        const data = await ApiClient.getApparatuses();
        setApparatuses(data);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load vehicles');
      } finally {
        setLoading(false);
      }
    };
    fetchApparatuses();
  }, []);

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
      <div className="space-y-6">
        <div className="mb-8 border-l-4 border-red-600 pl-4">
          <div className="skeleton mb-2 h-8 w-56"></div>
          <div className="skeleton h-4 w-72 max-w-full"></div>
        </div>
        <div className="skeleton h-11 w-full mb-4 max-w-md mx-auto"></div>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 2xl:gap-6">
          {[1,2,3,4,5,6,7,8,9].map(i => (
            <div key={i} className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200/80">
              <div className="flex items-center gap-4">
                <div className="skeleton w-12 h-12 rounded-lg"></div>
                <div className="flex-1">
                  <div className="skeleton h-5 w-32 mb-1"></div>
                  <div className="skeleton h-3 w-24"></div>
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="mx-auto max-w-lg rounded-xl border border-red-200 bg-red-50 p-8 text-center">
        <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-red-50 mb-4">
          <svg className="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
          </svg>
        </div>
        <p className="text-red-600 font-medium mb-2">Failed to load vehicles</p>
        <p className="mb-4 text-sm text-slate-600">{error}</p>
        <button
          onClick={() => window.location.reload()}
          className="min-h-11 rounded-lg bg-red-700 px-5 py-2.5 font-semibold text-white transition-colors hover:bg-red-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700"
        >
          Retry
        </button>
      </div>
    );
  }

  return (
    <div>
      <InspectionRevisions />
      <div className="mb-6 border-l-4 border-red-600 pl-4">
        <p className="mb-1 text-xs font-bold uppercase tracking-wider text-blue-800">Daily Checkout</p>
        <h1 className="mb-2 text-3xl font-bold text-slate-950 font-heading">Vehicle Inspections</h1>
        <p className="text-slate-600">Select a vehicle to begin the daily inspection.</p>
      </div>

      {/* Search/filter */}
      <div className="mx-auto mb-6 max-w-md">
        <div className="relative">
          <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          <input
            id="vehicle-search"
            type="text"
            aria-label="Search vehicles"
            placeholder="Search by name, designation, or type..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="min-h-11 w-full rounded-lg border border-slate-300 bg-slate-100 py-2.5 pl-10 pr-4 text-sm text-slate-950 placeholder-slate-400 transition focus:border-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-700/20"
          />
        </div>
      </div>

      <div className="stagger-list grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 2xl:gap-6">
        {filteredApparatuses.map((apparatus) => {
          // Handle null slug (e.g., "Captain 5") — skip or show disabled
          if (!apparatus.slug) {
            return (
              <div
                key={apparatus.id}
                className="block cursor-not-allowed rounded-xl bg-slate-100/70 p-5 opacity-70 ring-1 ring-slate-200/80"
              >
                <div className="flex items-center gap-4">
                  <div className="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-lg bg-slate-200 text-lg font-bold text-slate-600">
                    {apparatus.designation ? apparatus.designation.charAt(0) : '?'}
                  </div>
                  <div className="flex-1 min-w-0">
                    <h3 className="truncate text-lg font-semibold text-slate-600">
                      {apparatus.designation || apparatus.name || 'Unknown'}
                    </h3>
                    {apparatus.vehicle_number && <p className="text-sm text-slate-500">Vehicle #{apparatus.vehicle_number}</p>}
                    <p className="mt-0.5 text-xs capitalize text-slate-500">
                      {apparatus.type} · No inspection available
                    </p>
                  </div>
                </div>
              </div>
            );
          }

          return (
            <Link
              key={apparatus.id}
              to={`/vehicle-inspections/${apparatus.slug}`}
              className="block min-h-24 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200/80 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md hover:ring-blue-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700"
            >
              <div className="flex items-center gap-4">
                <div className="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-lg bg-blue-50 text-lg font-bold text-blue-900">
                  {apparatus.designation ? apparatus.designation.charAt(0) : '🚒'}
                </div>
                <div className="flex-1 min-w-0">
                  <h3 className="truncate text-lg font-semibold text-slate-950">
                    {apparatus.designation || apparatus.name || 'Unknown'}
                  </h3>
                  {apparatus.vehicle_number && <p className="text-sm text-slate-600">Vehicle #{apparatus.vehicle_number}</p>}
                  <p className="mt-0.5 text-xs capitalize text-slate-500">
                    {apparatus.type}
                  </p>
                </div>
                <svg className="h-5 w-5 flex-shrink-0 text-red-700" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                </svg>
              </div>
            </Link>
          );
        })}
      </div>

      {filteredApparatuses.length === 0 && !loading && (
        <div className="mt-8 rounded-xl border border-dashed border-slate-300 bg-white/70 py-8 text-center text-slate-500">
          <svg className="mx-auto mb-3 h-12 w-12 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          {searchQuery ? `No vehicles matching "${searchQuery}"` : 'No vehicles available for inspection.'}
        </div>
      )}

      <div className="mt-8 text-center">
        <PreviousPageButton
          className="inline-flex min-h-11 items-center px-4 py-2 font-semibold text-slate-600 hover:text-slate-950"
        >
          <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
          Back to previous page
        </PreviousPageButton>
      </div>
    </div>
  );
}
