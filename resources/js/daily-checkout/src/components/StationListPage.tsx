import { useState, useEffect, useRef } from 'react';
import { Station } from '../types';
import { ApiClient, isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../utils/api';
import StationCard from './StationCard';

export default function StationListPage() {
  const [stations, setStations] = useState<Station[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [pullDistance, setPullDistance] = useState(0);
  const touchStartY = useRef<number>(0);
  const containerRef = useRef<HTMLDivElement>(null);

  const fetchStations = async () => {
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
      setRefreshing(false);
    }
  };

  useEffect(() => {
    fetchStations();
  }, []);

  const handleTouchStart = (e: React.TouchEvent) => {
    if (containerRef.current && containerRef.current.scrollTop === 0) {
      touchStartY.current = e.touches[0].clientY;
    }
  };

  const handleTouchMove = (e: React.TouchEvent) => {
    if (containerRef.current && containerRef.current.scrollTop === 0) {
      const touchY = e.touches[0].clientY;
      const distance = touchY - touchStartY.current;
      if (distance > 0 && distance < 150) {
        setPullDistance(distance);
      }
    }
  };

  const handleTouchEnd = () => {
    if (pullDistance > 80 && !refreshing) {
      setRefreshing(true);
      if ('vibrate' in navigator) {
        navigator.vibrate(50);
      }
      fetchStations();
    }
    setPullDistance(0);
    touchStartY.current = 0;
  };

  if (loading) {
    return (
      <div className="space-y-8">
        {/* Skeleton header */}
        <div className="border-l-4 border-red-600 pl-4">
          <div className="skeleton mb-2 h-8 w-56"></div>
          <div className="skeleton h-4 w-80 max-w-full"></div>
        </div>
        {/* Skeleton cards */}
        <div className="daily-station-grid grid grid-cols-1 gap-4 sm:gap-5 md:grid-cols-2 lg:grid-cols-3 lg:gap-6 xl:grid-cols-4 2xl:grid-cols-5 2xl:gap-8">
          {[1, 2, 3, 4, 5].map(i => (
            <div key={i} className="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200/80">
              <div className="skeleton h-48 w-full rounded-none"></div>
              <div className="p-5">
                <div className="skeleton h-6 w-36 mb-3"></div>
                <div className="skeleton h-4 w-full mb-2"></div>
                <div className="skeleton h-4 w-40"></div>
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
        <p className="text-red-600 font-medium mb-2">Failed to load stations</p>
        <p className="mb-4 text-sm text-slate-600">{error}</p>
        <button
          onClick={() => {
            setLoading(true);
            fetchStations();
          }}
          className="mt-2 min-h-11 rounded-lg bg-red-700 px-5 py-2.5 font-semibold text-white transition-colors hover:bg-red-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700 touch-manipulation"
        >
          Retry
        </button>
      </div>
    );
  }

  return (
    <div
      ref={containerRef}
      onTouchStart={handleTouchStart}
      onTouchMove={handleTouchMove}
      onTouchEnd={handleTouchEnd}
      className="relative"
    >
      {/* Pull to refresh indicator */}
      {pullDistance > 0 && (
        <div
          className="flex justify-center items-center py-4 transition-opacity"
          style={{
            transform: `translateY(${Math.min(pullDistance, 80)}px)`,
            opacity: pullDistance / 80,
          }}
        >
          <svg className={`w-5 h-5 text-blue-700 ${pullDistance > 80 ? 'animate-spin' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          <span className="ml-2 text-sm font-medium text-slate-600">
            {pullDistance > 80 ? 'Release to refresh' : 'Pull to refresh'}
          </span>
        </div>
      )}

      {/* Refreshing indicator */}
      {refreshing && (
        <div className="flex items-center justify-center py-4 font-medium text-blue-700">
          <svg className="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          <span className="ml-2 text-sm">Refreshing...</span>
        </div>
      )}

      {/* Welcome Header */}
      <div className="mb-8 border-l-4 border-red-600 pl-4 md:mb-10">
        <p className="mb-1 text-xs font-bold uppercase tracking-wider text-blue-800">Station operations</p>
        <h1 className="mb-2 text-3xl font-bold text-slate-950 font-heading xl:text-4xl">MBFD Stations</h1>
        <p className="max-w-2xl leading-relaxed text-slate-600">
          Select your station below to access forms, inspections, apparatus information, and more.
          Each station page contains everything you need for daily operations.
        </p>
      </div>

      {/* Stations Grid */}
      <div data-testid="daily-station-grid" className="daily-station-grid stagger-list grid grid-cols-1 gap-4 sm:gap-5 md:grid-cols-2 lg:grid-cols-3 lg:gap-6 xl:grid-cols-4 2xl:grid-cols-5 2xl:gap-8">
        {stations.map((station) => (
          <StationCard key={station.id} station={station} />
        ))}
      </div>

      {stations.length === 0 && (
        <div className="mt-8 rounded-xl border border-dashed border-slate-300 bg-white/70 p-8 text-center text-slate-500">
          No stations available.
        </div>
      )}
    </div>
  );
}
