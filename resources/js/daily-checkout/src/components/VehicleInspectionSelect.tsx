import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { Apparatus } from '../types';
import { ApiClient } from '../utils/api';

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
        <div className="text-center mb-8">
          <div className="skeleton h-8 w-56 mx-auto mb-2"></div>
          <div className="skeleton h-4 w-72 mx-auto"></div>
        </div>
        <div className="skeleton h-11 w-full mb-4 max-w-md mx-auto"></div>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {[1,2,3,4,5,6,7,8,9].map(i => (
            <div key={i} className="p-5 rounded-xl ring-1 ring-neutral-200/60 bg-neutral-100">
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
      <div className="text-center p-8">
        <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-red-50 mb-4">
          <svg className="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
          </svg>
        </div>
        <p className="text-red-600 font-medium mb-2">Failed to load vehicles</p>
        <p className="text-neutral-500 text-sm mb-4">{error}</p>
        <button
          onClick={() => window.location.reload()}
          className="px-5 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-medium"
        >
          Retry
        </button>
      </div>
    );
  }

  return (
    <div>
      <div className="text-center mb-6">
        <h1 className="text-3xl font-bold text-neutral-800 mb-2 font-heading">Vehicle Inspections</h1>
        <p className="text-neutral-500">Select a vehicle to begin the daily inspection</p>
      </div>

      {/* Search/filter */}
      <div className="max-w-md mx-auto mb-6">
        <div className="relative">
          <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          <input
            type="text"
            placeholder="Search by name, designation, or type..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="w-full pl-10 pr-4 py-2.5 bg-neutral-100 border border-neutral-200 rounded-lg text-sm text-neutral-800 placeholder-neutral-400 focus:outline-none focus:ring-2 focus:ring-red-500/30 focus:border-red-500 transition"
          />
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 stagger-list">
        {filteredApparatuses.map((apparatus) => {
          // Handle null slug (e.g., "Captain 5") — skip or show disabled
          if (!apparatus.slug) {
            return (
              <div
                key={apparatus.id}
                className="block p-5 bg-neutral-100/50 rounded-xl ring-1 ring-neutral-200/40 opacity-60 cursor-not-allowed"
              >
                <div className="flex items-center gap-4">
                  <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-neutral-200 text-neutral-500 font-bold text-lg flex-shrink-0">
                    {apparatus.designation ? apparatus.designation.charAt(0) : '?'}
                  </div>
                  <div className="flex-1 min-w-0">
                    <h3 className="text-lg font-semibold text-neutral-500 truncate">
                      {apparatus.designation || apparatus.name || 'Unknown'}
                    </h3>
                    <p className="text-sm text-neutral-400">
                      Vehicle #{apparatus.vehicle_number}
                    </p>
                    <p className="text-xs text-neutral-400 capitalize mt-0.5">
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
              className="block p-5 bg-neutral-100 rounded-xl ring-1 ring-neutral-200/60 hover:ring-red-300 hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200"
            >
              <div className="flex items-center gap-4">
                <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-red-50 text-red-600 font-bold text-lg flex-shrink-0">
                  {apparatus.designation ? apparatus.designation.charAt(0) : '🚒'}
                </div>
                <div className="flex-1 min-w-0">
                  <h3 className="text-lg font-semibold text-neutral-800 truncate">
                    {apparatus.designation || apparatus.name || 'Unknown'}
                  </h3>
                  <p className="text-sm text-neutral-500">
                    Vehicle #{apparatus.vehicle_number}
                  </p>
                  <p className="text-xs text-neutral-400 capitalize mt-0.5">
                    {apparatus.type}
                  </p>
                </div>
                <svg className="w-5 h-5 text-neutral-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                </svg>
              </div>
            </Link>
          );
        })}
      </div>

      {filteredApparatuses.length === 0 && !loading && (
        <div className="text-center text-neutral-400 mt-8 py-8">
          <svg className="w-12 h-12 mx-auto mb-3 text-neutral-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          {searchQuery ? `No vehicles matching "${searchQuery}"` : 'No vehicles available for inspection.'}
        </div>
      )}

      <div className="mt-8 text-center">
        <Link
          to="/"
          className="inline-flex items-center px-4 py-2 text-neutral-500 hover:text-neutral-800"
        >
          <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
          Back to MBFD Forms
        </Link>
      </div>
    </div>
  );
}
