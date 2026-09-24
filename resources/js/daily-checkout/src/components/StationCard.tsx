import { Link } from 'react-router';
import { Station, STATION_IMAGES } from '../types';

interface StationCardProps {
  station: Station;
}

export default function StationCard({ station }: StationCardProps) {
  const imageUrl = STATION_IMAGES[station.station_number] ?? null;

  return (
    <Link
      to={`/stations/${station.id}`}
      data-testid="daily-station-card"
      aria-label={`Open ${station.name}`}
      className="daily-selector-card group block overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200/80 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md hover:ring-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-700 focus:ring-offset-2 touch-manipulation"
    >
      {/* Station Image */}
      <div className="relative h-40 overflow-hidden bg-slate-100 sm:h-48 2xl:h-56">
        {imageUrl ? (
          <img
            src={imageUrl}
            alt={`Station ${station.station_number}`}
            className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.02]"
            loading="lazy"
          />
        ) : (
          <div className="flex h-full items-center justify-center">
            <svg className="h-16 w-16 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M3 10.5l9-7 9 7v9a1 1 0 01-1 1h-5.5a1 1 0 01-1-1V14a1 1 0 00-1-1h-3a1 1 0 00-1 1v5.5a1 1 0 01-1 1H4a1 1 0 01-1-1v-9z" />
            </svg>
          </div>
        )}
        {/* Station number badge */}
        <div className="absolute left-3 top-3 rounded-md bg-slate-950/90 px-3 py-1.5 text-xs font-bold text-white backdrop-blur-sm">
          Station {station.station_number}
        </div>
        {/* Status indicator */}
        {!station.is_active && (
          <div className="absolute right-3 top-3 rounded-md bg-slate-600/90 px-2.5 py-1 text-xs font-semibold text-white backdrop-blur-sm">
            Inactive
          </div>
        )}
      </div>

      {/* Card Content */}
      <div className="p-5 2xl:p-6">
        <h3 className="mb-3 text-lg font-bold text-slate-950 font-heading 2xl:text-xl">
          {station.name}
        </h3>

        <div className="space-y-2">
          {/* Address */}
          <div className="flex items-start gap-2.5 text-sm text-slate-600">
            <svg className="mt-0.5 h-4 w-4 flex-shrink-0 text-blue-700" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            <span>{station.address}, {station.city}, {station.state}</span>
          </div>

          {/* Phone */}
          {station.phone && (
            <div className="flex items-center gap-2.5 text-sm text-slate-600">
              <svg className="h-4 w-4 flex-shrink-0 text-blue-700" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
              </svg>
              <span>{station.phone}</span>
            </div>
          )}
        </div>

        {/* View details indicator */}
        <div className="mt-4 flex min-h-11 items-center justify-end border-t border-slate-100 pt-3 text-sm font-semibold text-red-700 group-hover:text-red-800">
          View Station
          <svg className="ml-1 w-4 h-4 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
          </svg>
        </div>
      </div>
    </Link>
  );
}
