import { useState, useEffect, useRef } from 'react';
import { Link } from 'react-router-dom';
import { Station } from '../types';
import { ApiClient } from '../utils/api';
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
      <div className="space-y-6">
        <div className="text-center mb-8">
          <div className="skeleton h-8 w-48 mx-auto mb-2"></div>
          <div className="skeleton h-4 w-64 mx-auto"></div>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {[1,2,3,4,5,6].map(i => (
            <div key={i} className="p-5 rounded-xl ring-1 ring-neutral-200/60 bg-neutral-100">
              <div className="skeleton h-6 w-32 mb-2"></div>
              <div className="skeleton h-4 w-48 mb-4"></div>
              <div className="skeleton h-4 w-full mb-2"></div>
              <div className="flex gap-3 mt-4">
                <div className="skeleton h-4 w-24"></div>
                <div className="skeleton h-4 w-20"></div>
                <div className="skeleton h-4 w-20"></div>
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
        <p className="text-red-600 font-medium mb-2">Failed to load stations</p>
        <p className="text-neutral-500 text-sm mb-4">{error}</p>
        <button
          onClick={() => {
            setLoading(true);
            fetchStations();
          }}
          className="mt-2 px-5 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors touch-manipulation font-medium"
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
            opacity: pullDistance / 80
          }}
        >
          <svg className={`w-5 h-5 text-red-600 ${pullDistance > 80 ? 'animate-spin' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          <span className="ml-2 text-sm text-neutral-500">
            {pullDistance > 80 ? 'Release to refresh' : 'Pull to refresh'}
          </span>
        </div>
      )}

      {/* Refreshing indicator */}
      {refreshing && (
        <div className="flex justify-center items-center py-4 text-red-600">
          <svg className="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          <span className="ml-2 text-sm">Refreshing...</span>
        </div>
      )}

      <div className="text-center mb-8">
        <h1 className="text-3xl font-bold text-neutral-800 mb-2 font-heading">MBFD Stations</h1>
        <p className="text-neutral-500">Manage and monitor all fire stations</p>
      </div>

      {/* Stations Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 stagger-list">
        {stations.map((station) => (
          <StationCard key={station.id} station={station} />
        ))}
      </div>

      {stations.length === 0 && (
        <div className="text-center text-neutral-400 mt-8">
          No stations available.
        </div>
      )}

      {/* Back to Home */}
      <div className="mt-8 text-center">
        <Link
          to="/"
          className="inline-flex items-center px-4 py-2 text-neutral-500 hover:text-neutral-800"
        >
          <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
          Back to Daily Checkout
        </Link>
      </div>
    </div>
  );
}
