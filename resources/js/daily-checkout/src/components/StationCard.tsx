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
        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-teal-50 text-teal-700">
          Active
        </span>
      );
    }
    return (
      <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-neutral-100 text-neutral-600">
        Inactive
      </span>
    );
  };

  return (
    <Link
      to={`/stations/${station.id}`}
      className="block p-5 bg-neutral-100 rounded-xl ring-1 ring-neutral-200/60 transition-all duration-200 hover:shadow-lg hover:-translate-y-0.5 touch-manipulation"
    >
      <div className="flex justify-between items-start mb-3">
        <div>
          <h3 className="text-lg font-semibold text-neutral-800 font-heading mb-0.5">
            Station {station.station_number}
          </h3>
          <p className="text-neutral-600 font-medium text-sm">{station.name}</p>
        </div>
        {getStatusBadge()}
      </div>

      <div className="space-y-1.5">
        <div className="flex items-center text-sm text-neutral-500">
          <svg className="w-4 h-4 mr-2 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
          <span>{station.address}, {station.city}, {station.state}</span>
        </div>

        {station.phone && (
          <div className="flex items-center text-sm text-neutral-500">
            <svg className="w-4 h-4 mr-2 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
            </svg>
            <span>{station.phone}</span>
          </div>
        )}
      </div>

      {/* Flat stat chips — no nested cards */}
      <div className="mt-4 flex flex-wrap gap-3 text-sm">
        {enableApparatusForms && (
          <span className="inline-flex items-center gap-1.5 text-amber-700">
            <span className="w-2 h-2 rounded-full bg-amber-500"></span>
            {station.apparatuses_count || 0} Apparatuses
          </span>
        )}
        <span className="inline-flex items-center gap-1.5 text-teal-700">
          <span className="w-2 h-2 rounded-full bg-teal-500"></span>
          {station.rooms_count || 0} Rooms
        </span>
        <span className="inline-flex items-center gap-1.5 text-sky-700">
          <span className="w-2 h-2 rounded-full bg-sky-500"></span>
          {station.capital_projects_count || 0} Projects
        </span>
        <span className="inline-flex items-center gap-1.5 text-orange-700">
          <span className="w-2 h-2 rounded-full bg-orange-500"></span>
          {station.shop_works_count || 0} Shop Works
        </span>
      </div>

      <div className="mt-3 flex justify-end">
        <span className="text-red-600 text-sm font-medium">
          View Details →
        </span>
      </div>
    </Link>
  );
}
