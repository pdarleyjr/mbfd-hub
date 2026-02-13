import { Link } from 'react-router-dom';
import { Station } from '../types';

const enableApparatusForms = import.meta.env.VITE_ENABLE_APPARATUS_FORMS === 'true';

interface StationCardProps {
  station: Station;
}

export default function StationCard({ station }: StationCardProps) {
  const getStatusBadge = () => {
    if (station.is_active) {
      return (
        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
          Active
        </span>
      );
    }
    return (
      <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
        Inactive
      </span>
    );
  };

  return (
    <Link
      to={`/stations/${station.id}`}
      className="block p-6 bg-white rounded-lg shadow-md hover:shadow-lg transition-shadow border border-gray-200 touch-manipulation"
    >
      <div className="flex justify-between items-start mb-4">
        <div>
          <h3 className="text-xl font-semibold text-gray-900 mb-1">
            Station {station.station_number}
          </h3>
          <p className="text-gray-700 font-medium">{station.name}</p>
        </div>
        {getStatusBadge()}
      </div>

      <div className="space-y-2">
        <div className="flex items-center text-sm text-gray-600">
          <svg className="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
          <span>{station.address}, {station.city}, {station.state}</span>
        </div>

        {station.phone && (
          <div className="flex items-center text-sm text-gray-600">
            <svg className="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
            </svg>
            <span>{station.phone}</span>
          </div>
        )}
      </div>

      <div className={`mt-4 grid ${enableApparatusForms ? 'grid-cols-2' : 'grid-cols-1'} gap-4`}>
        {enableApparatusForms && (
          <div className="text-center p-3 bg-blue-50 rounded-lg">
            <p className="text-2xl font-bold text-blue-600">
              {station.apparatuses_count || 0}
            </p>
            <p className="text-xs text-blue-700">Apparatuses</p>
          </div>
        )}
        <div className="text-center p-3 bg-purple-50 rounded-lg">
          <p className="text-2xl font-bold text-purple-600">
            {station.rooms_count || 0}
          </p>
          <p className="text-xs text-purple-700">Rooms</p>
        </div>
        <div className="text-center p-3 bg-green-50 rounded-lg">
          <p className="text-2xl font-bold text-green-600">
            {station.capital_projects_count || 0}
          </p>
          <p className="text-xs text-green-700">Capital Projects</p>
        </div>
        <div className="text-center p-3 bg-amber-50 rounded-lg">
          <p className="text-2xl font-bold text-amber-600">
            {station.shop_works_count || 0}
          </p>
          <p className="text-xs text-amber-700">Shop Works</p>
        </div>
      </div>

      <div className="mt-4 flex justify-end">
        <span className="text-blue-600 text-sm font-medium hover:text-blue-800">
          View Details →
        </span>
      </div>
    </Link>
  );
}
