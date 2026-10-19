import { Link } from 'react-router';
import { ChevronRight, MapPin, Phone } from 'lucide-react';
import { Station, bundledImageSize, stationImageUrl } from '../types';
import StationCardMedia from './StationCardMedia';

interface StationCardProps {
  station: Station;
}

/** Level-1 glass card; the whole card is one link to the station profile (route uses the station DB id). */
export default function StationCard({ station }: StationCardProps) {
  const imageUrl = stationImageUrl(station);

  return (
    <Link
      to={`/stations/${station.id}`}
      data-testid="daily-station-card"
      aria-label={`Open ${station.name}`}
      className="daily-selector-card hub-panel group block overflow-hidden text-hub-ink transition-colors hover:border-hub-blue/40 touch-manipulation font-hub"
    >
      {/* Station Image */}
      <StationCardMedia src={imageUrl} alt={`Station ${station.station_number}`} size={bundledImageSize(imageUrl)}>
        {/* Station number: an opaque label on the photo, no blur on a level-1 surface. */}
        <span className="absolute left-3 top-3 rounded-hub-tag bg-hub-header px-2.5 py-1 text-[13px] font-bold leading-5 text-white">
          Station {station.station_number}
        </span>
        {!station.is_active && (
          <span className="hub-tag absolute right-3 top-3">Inactive</span>
        )}
      </StationCardMedia>

      {/* Card Content */}
      <div className="p-4 sm:p-5 2xl:p-6">
        <h3 className="mb-3 text-lg font-bold text-hub-ink font-heading 2xl:text-xl">
          {station.name}
        </h3>

        <div className="space-y-2 text-sm text-hub-n-600">
          <div className="flex items-start gap-2.5">
            <MapPin className="mt-0.5 h-4 w-4 flex-shrink-0 text-hub-blue" aria-hidden="true" />
            <span>{station.address}, {station.city}, {station.state}</span>
          </div>
          {station.phone && (
            <div className="flex items-center gap-2.5">
              <Phone className="h-4 w-4 flex-shrink-0 text-hub-blue" aria-hidden="true" />
              <span className="tabular-nums">{station.phone}</span>
            </div>
          )}
        </div>

        <div className="mt-4 flex min-h-11 items-center justify-end border-t border-hub-glass-border pt-3 text-sm font-semibold text-hub-blue group-hover:underline group-hover:underline-offset-4">
          View Station
          <ChevronRight className="ml-1 h-4 w-4" aria-hidden="true" />
        </div>
      </div>
    </Link>
  );
}
