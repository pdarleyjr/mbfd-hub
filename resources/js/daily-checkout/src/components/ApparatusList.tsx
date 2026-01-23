import { useState, useEffect, useRef } from 'react';
import { Link } from 'react-router-dom';
import { Apparatus } from '../types';
import { ApiClient } from '../utils/api';

export default function ApparatusList() {
  const [apparatuses, setApparatuses] = useState<Apparatus[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [pullDistance, setPullDistance] = useState(0);
  const touchStartY = useRef<number>(0);
  const containerRef = useRef<HTMLDivElement>(null);

  const fetchApparatuses = async () => {
    try {
      const data = await ApiClient.getApparatuses();
      setApparatuses(data);
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load apparatuses');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  useEffect(() => {
    fetchApparatuses();
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
      fetchApparatuses();
    }
    setPullDistance(0);
    touchStartY.current = 0;
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center min-h-64">
        <div className="text-lg">Loading apparatuses...</div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="text-center text-red-600 p-4">
        <p>Error: {error}</p>
        <button
          onClick={() => {
            setLoading(true);
            fetchApparatuses();
          }}
          className="mt-4 px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 touch-manipulation"
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
          <div className={`text-blue-600 ${pullDistance > 80 ? 'animate-spin' : ''}`}>
            ↻
          </div>
          <span className="ml-2 text-sm text-gray-600">
            {pullDistance > 80 ? 'Release to refresh' : 'Pull to refresh'}
          </span>
        </div>
      )}

      {/* Refreshing indicator */}
      {refreshing && (
        <div className="flex justify-center items-center py-4 text-blue-600">
          <div className="animate-spin">↻</div>
          <span className="ml-2 text-sm">Refreshing...</span>
        </div>
      )}

      <div className="text-center mb-8">
        <h1 className="text-3xl font-bold text-gray-900 mb-2">MBFD Daily Checkout</h1>
        <p className="text-gray-600">Select an apparatus to begin the daily inspection</p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        {apparatuses.map((apparatus) => (
          <Link
            key={apparatus.id}
            to={`/apparatus/${apparatus.slug}`}
            className="block p-6 bg-white rounded-lg shadow-md hover:shadow-lg transition-shadow border border-gray-200 touch-manipulation"
          >
            <div className="text-center">
              <h2 className="text-xl font-semibold text-gray-900 mb-2">
                {apparatus.name || apparatus.unit_id || 'Unknown'}
              </h2>
              <p className="text-gray-600 mb-1">Unit: {apparatus.vehicle_number}</p>
              <p className="text-sm text-gray-500 capitalize">Type: {apparatus.type}</p>
              <div className="mt-4">
                <span className="inline-block px-3 py-1 bg-blue-100 text-blue-800 text-sm rounded-full">
                  Start Inspection
                </span>
              </div>
            </div>
          </Link>
        ))}
      </div>

      {apparatuses.length === 0 && (
        <div className="text-center text-gray-500 mt-8">
          No apparatuses available for inspection.
        </div>
      )}
    </div>
  );
}