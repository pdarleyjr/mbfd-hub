import { useState } from 'react';
import { House, Phone } from 'lucide-react';
import { stationImageUrl, type StationDetail } from '../../types';
import QuickLink, { type QuickLinkProps } from '../ui/QuickLink';

interface StationHeaderProps {
  station: StationDetail;
  assignedApparatusCount: number | null;
  assignedPersonnelCount: number | null;
  dormBedsCount: number | null;
  assignedUnits: string[];
  quickLinks: QuickLinkProps[];
}

/** The station's identity, its basic facts and its actions in one compact block, so the page's content starts high up. */
export default function StationHeader({ station, assignedApparatusCount, assignedPersonnelCount, dormBedsCount, assignedUnits, quickLinks }: StationHeaderProps) {
  const counts = [
    { label: 'Apparatus', value: assignedApparatusCount },
    { label: 'Personnel', value: assignedPersonnelCount },
    { label: 'Dorm beds', value: dormBedsCount },
  ].filter((count) => count.value !== null);

  return (
    <section className="hub-panel p-4 sm:p-5" aria-labelledby="station-heading" data-testid="station-header">
      <div className="flex flex-col gap-3 sm:flex-row sm:gap-5">
        <StationPhoto station={station} />
        <div className="flex min-w-0 flex-1 flex-col gap-1.5 lg:flex-row lg:items-center lg:justify-between lg:gap-8">
          <div className="min-w-0">
            <h1 id="station-heading" className="font-heading text-2xl font-bold leading-8 text-hub-ink">Station {station.station_number}</h1>
            <div className="flex flex-wrap items-center gap-x-4 text-[15px] leading-6 text-hub-n-600">
              <p>{station.address}, {station.city}, {station.state} {station.zip_code}</p>
              {(station.phone || station.fax) && (
                <p className="flex flex-wrap items-center gap-x-2 tabular-nums">
                  <Phone className="h-4 w-4" aria-hidden="true" />
                  {station.phone && <span>{station.phone}</span>}
                  {station.fax && <span>Fax {station.fax}</span>}
                </p>
              )}
            </div>
          </div>
          <dl aria-label="Station information" className="min-w-0 lg:flex-none" data-testid="station-facts">
            <div className="flex flex-wrap gap-x-4 gap-y-0.5 lg:justify-end">
              {counts.map((count) => (
                <div key={count.label} className="flex flex-row-reverse items-baseline gap-1.5">
                  <dt className="text-[13px] leading-5 text-hub-n-600">{count.label}</dt>
                  <dd className="text-[15px] font-bold leading-5 tabular-nums text-hub-ink">{count.value}</dd>
                </div>
              ))}
            </div>
            {assignedUnits.length > 0 && (
              <div className="mt-1 flex flex-wrap gap-1 lg:justify-end">
                <dt className="sr-only">Assigned units</dt>
                {assignedUnits.map((unit) => <dd key={unit} className="hub-tag !min-h-6 !px-2 !py-0 !text-[13px] font-semibold tabular-nums">{unit}</dd>)}
              </div>
            )}
          </dl>
        </div>
      </div>
      <nav aria-label="Station actions" className="mt-4">
        <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-3 lg:auto-cols-fr lg:grid-flow-col lg:grid-cols-none">
          {quickLinks.map((link, index) => <li key={link.label} className={spansFullRow(quickLinks, index) ? 'col-span-2 sm:col-span-1' : undefined}><QuickLink {...link} /></li>)}
        </ul>
      </nav>
    </section>
  );
}

/** On a phone's two-column grid the long lineup link takes its own row, and so does a last link left without a partner. */
function spansFullRow(links: QuickLinkProps[], index: number): boolean {
  const firstIsWide = links[0]?.label.startsWith('Morning Lineup') ?? false;
  if (firstIsWide && index === 0) return true;
  const paired = links.length - (firstIsWide ? 1 : 0);
  return index === links.length - 1 && paired % 2 === 1;
}

/** Recorded station photo (or bundled artwork); a branded placeholder when neither exists or the image fails. */
function StationPhoto({ station }: { station: StationDetail }) {
  const imageUrl = stationImageUrl(station);
  const [failedUrl, setFailedUrl] = useState<string | null>(null);
  const showImage = imageUrl !== null && failedUrl !== imageUrl;

  return (
    <div data-testid="station-profile-photo" className="relative h-20 w-full shrink-0 min-[360px]:h-[5.75rem] overflow-hidden rounded-hub-control border border-hub-glass-border bg-hub-n-100 sm:h-[7.5rem] sm:w-44 lg:w-52">
      {showImage ? (
        <img src={imageUrl} alt={`Station ${station.station_number} exterior`} className="h-full w-full object-cover object-[center_60%]" onError={() => setFailedUrl(imageUrl)} />
      ) : (
        <div className="flex h-full items-center justify-center gap-2 text-hub-n-600" role="img" aria-label={`No photo recorded for Station ${station.station_number}`}>
          <House className="h-8 w-8 text-hub-n-400" strokeWidth={1.5} aria-hidden="true" />
          <span className="rounded-hub-tag bg-hub-header px-2.5 py-1 text-[13px] font-bold leading-5 text-white">Station {station.station_number}</span>
        </div>
      )}
    </div>
  );
}
