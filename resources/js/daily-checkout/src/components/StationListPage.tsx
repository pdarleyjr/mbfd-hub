import { useState, useEffect, useRef } from 'react';
import { Link } from 'react-router-dom';
import { Station } from '../types';
import { ApiClient } from '../utils/api';
import StationCard from './StationCard';

type SortField = 'station_number' | 'name' | 'apparatuses_count' | 'rooms_count' | 'capital_projects_count';
type SortDirection = 'asc' | 'desc';

export default function StationListPage() {
  const [stations, setStations] = useState<Station[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [pullDistance, setPullDistance] = useState(0);
  const [searchQuery, setSearchQuery] = useState('');
  const [sortField, setSortField] = useState<SortField>('station_number');
  const [sortDirection, setSortDirection] = useState<SortDirection>('asc');
  const [showInactive, setShowInactive] = useState(false);
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

  const handleSort = (field: SortField) => {
    if (sortField === field) {
      setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field);
      setSortDirection('asc');
    }
  };

  const filteredAndSortedStations = stations
    .filter(station => {
      if (!showInactive && !station.is_active) return false;
      if (searchQuery) {
        const query = searchQuery.toLowerCase();
        return (
          station.name.toLowerCase().includes(query) ||
          station.station_number.toString().includes(query) ||
          station.address.toLowerCase().includes(query) ||
          station.city.toLowerCase().includes(query)
        );
      }
      return true;
    })
    .sort((a, b) => {
      let aVal: any = a[sortField];
      let bVal: any = b[sortField];
      
      if (typeof aVal === 'string') {
        aVal = aVal.toLowerCase();
        bVal = bVal.toLowerCase();
      }
      
      if (aVal < bVal) return sortDirection === 'asc' ? -1 : 1;
      if (aVal > bVal) return sortDirection === 'asc' ? 1 : -1;
      return 0;
    });

  const getSortIndicator = (field: SortField) => {
    if (sortField !== field) return null;
    return sortDirection === 'asc' ? ' ↑' : ' ↓';
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center min-h-64">
        <div className="text-lg">Loading stations...</div>
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
            fetchStations();
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
        <h1 className="text-3xl font-bold text-gray-900 mb-2">MBFD Stations</h1>
        <p className="text-gray-600">Manage and monitor all fire stations</p>
      </div>

      {/* Search and Filter Bar */}
      <div className="mb-6 space-y-4">
        <div className="flex flex-col sm:flex-row gap-4">
          <div className="flex-1">
            <input
              type="text"
              placeholder="Search stations..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            />
          </div>
          <label className="flex items-center space-x-2 px-4 py-2 bg-gray-100 rounded-lg cursor-pointer">
            <input
              type="checkbox"
              checked={showInactive}
              onChange={(e) => setShowInactive(e.target.checked)}
              className="rounded text-blue-600 focus:ring-blue-500"
            />
            <span className="text-sm text-gray-700">Show Inactive</span>
          </label>
        </div>

        {/* Sort Controls */}
        <div className="flex flex-wrap gap-2">
          <span className="text-sm text-gray-600 self-center">Sort by:</span>
          {[
            { field: 'station_number' as SortField, label: 'Station #' },
            { field: 'name' as SortField, label: 'Name' },
            { field: 'apparatuses_count' as SortField, label: 'Apparatuses' },
            { field: 'rooms_count' as SortField, label: 'Rooms' },
            { field: 'capital_projects_count' as SortField, label: 'Projects' },
          ].map(({ field, label }) => (
            <button
              key={field}
              onClick={() => handleSort(field)}
              className={`px-3 py-1 text-sm rounded-full transition-colors ${
                sortField === field
                  ? 'bg-blue-500 text-white'
                  : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
              }`}
            >
              {label}{getSortIndicator(field)}
            </button>
          ))}
        </div>
      </div>

      {/* Results count */}
      <div className="mb-4 text-sm text-gray-600">
        Showing {filteredAndSortedStations.length} of {stations.length} stations
        {searchQuery && ` matching "${searchQuery}"`}
      </div>

      {/* Stations Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        {filteredAndSortedStations.map((station) => (
          <StationCard key={station.id} station={station} />
        ))}
      </div>

      {filteredAndSortedStations.length === 0 && (
        <div className="text-center text-gray-500 mt-8">
          {stations.length === 0 
            ? 'No stations available.'
            : 'No stations match your search criteria.'}
        </div>
      )}

      {/* Back to Home */}
      <div className="mt-8 text-center">
        <Link
          to="/"
          className="inline-flex items-center px-4 py-2 text-gray-600 hover:text-gray-900"
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
