import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { Apparatus } from '../types';
import { ApiClient } from '../utils/api';

export default function VehicleInspectionSelect() {
  const [apparatuses, setApparatuses] = useState<Apparatus[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

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

  if (loading) {
    return (
      <div className="flex justify-center items-center min-h-64">
        <div className="text-lg text-gray-500">Loading vehicles...</div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="text-center text-red-600 p-4">
        <p>Error: {error}</p>
        <button
          onClick={() => window.location.reload()}
          className="mt-4 px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
        >
          Retry
        </button>
      </div>
    );
  }

  return (
    <div>
      <div className="text-center mb-8">
        <h1 className="text-3xl font-bold text-gray-900 mb-2">Vehicle Inspections</h1>
        <p className="text-gray-600">Select a vehicle to begin the daily inspection</p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {apparatuses.map((apparatus) => (
          <Link
            key={apparatus.id}
            to={`/vehicle-inspections/${apparatus.slug}`}
            className="block p-5 bg-white rounded-xl shadow-md border border-gray-200 hover:border-red-300 hover:shadow-lg transition-all"
          >
            <div className="flex items-center gap-4">
              <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-red-100 text-red-600 font-bold text-lg flex-shrink-0">
                {apparatus.designation ? apparatus.designation.charAt(0) : '🚒'}
              </div>
              <div className="flex-1 min-w-0">
                <h3 className="text-lg font-semibold text-gray-900 truncate">
                  {apparatus.designation || apparatus.name || 'Unknown'}
                </h3>
                <p className="text-sm text-gray-500">
                  Vehicle #{apparatus.vehicle_number}
                </p>
                <p className="text-xs text-gray-400 capitalize mt-0.5">
                  {apparatus.type}
                </p>
              </div>
              <svg className="w-5 h-5 text-gray-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
              </svg>
            </div>
          </Link>
        ))}
      </div>

      {apparatuses.length === 0 && (
        <div className="text-center text-gray-500 mt-8">
          No vehicles available for inspection.
        </div>
      )}

      <div className="mt-8 text-center">
        <Link
          to="/"
          className="inline-flex items-center px-4 py-2 text-gray-600 hover:text-gray-900"
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
