import { useState, useEffect, type ReactNode } from 'react';
import { Link, useParams } from 'react-router';
import {
  Activity, ChevronRight, ClipboardCheck, ClipboardList, DoorOpen, Gauge, ShieldCheck, Truck, Video, Wrench,
} from 'lucide-react';
import {
  StationDetail,
  Apparatus,
  DailyCheckoutMatrixRow,
  DailyCheckoutSummary,
  StationInspectionSummary,
  StationRequestSummary,
  ApparatusServiceTicketSummary,
  StationActivityEntry,
  SingleGasMeterSummary,
  VehicleIdentification,
  VehicleIdentificationUnit,
} from '../types';
import { ApiClient, isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../utils/api';
import { parseVehicleIdentificationUnits } from '../utils/vehicleIdentification';
import PreviousPageButton from './PreviousPageButton';
import StationVehicleIdentification from './StationVehicleIdentification';
import UnitAlertsBanner from './station/UnitAlertsBanner';
import DailyPageNav from './DailyPageNav';
import { identityForQueueCapture } from '../lib/offlineIdentity';
import { groupRoomsByArea, stationComplement } from '../utils/stationRoomBlueprint';
import Tabs, { type TabItem } from './ui/Tabs';
import type { QuickLinkProps } from './ui/QuickLink';
import StationHeader from './station/StationHeader';
import StationOverviewPanel from './station/StationOverviewPanel';
import StationShiftAlert from './station/StationShiftAlert';
import { useStationOverview } from './station/useStationOverview';
import Tag, { StatusTag } from './ui/Tag';
import { EmptyState, LoadError, SkeletonRows } from './ui/StateViews';
import { humanize, type Tone } from './ui/status';
import { formatHubDate, HUB_TIMEZONE } from '../../../shared/timezone.ts';

type TabId = 'requests' | 'service-repair' | 'overview' | 'rooms' | 'apparatus' | 'gas-meters' | 'inspections' | 'activity';

/** Station numbers that have a morning lineup conference room (the conference route takes the number, not the DB id). */
const LINEUP_STATION_NUMBERS = [1, 2, 3, 4, 6];
const LAZY_TABS: TabId[] = ['inspections', 'requests', 'service-repair', 'gas-meters', 'activity'];
const ROW = 'hub-well p-4';
const SECONDARY = 'text-[13px] leading-5 text-hub-n-600';

const formatDate = formatHubDate;
const formatTime = (dateStr: string): string => new Date(dateStr).toLocaleTimeString('en-US', { timeZone: HUB_TIMEZONE, hour: 'numeric', minute: '2-digit' });

export default function StationDetailPage() {
  const { id } = useParams<{ id: string }>();
  const [station, setStation] = useState<StationDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [stationLoadAttempt, setStationLoadAttempt] = useState(0);
  // Units at this station with open one-time vehicle questions; answered ones leave this list.
  const [identificationUnits, setIdentificationUnits] = useState<VehicleIdentificationUnit[]>([]);
  const [activeTab, setActiveTab] = useState<TabId>('overview');
  const stationOverview = useStationOverview(Number(id));

  // Tab data (lazy loaded)
  const [stationInspections, setStationInspections] = useState<StationInspectionSummary[]>([]);
  const [stationRequests, setStationRequests] = useState<StationRequestSummary[]>([]);
  const [requestScope, setRequestScope] = useState<'open' | 'all'>('open');
  // Only officers may open the Personnel Equipment Request; the server says who they are.
  const [canRequestPersonnelEquipment, setCanRequestPersonnelEquipment] = useState(false);
  useEffect(() => {
    let active = true;
    identityForQueueCapture({ redirectOnExpiry: false })
      .then((identity) => { if (active) setCanRequestPersonnelEquipment(identity.capabilities?.personnelEquipmentRequest === true); })
      .catch(() => { if (active) setCanRequestPersonnelEquipment(false); });
    return () => { active = false; };
  }, []);
  const [serviceTickets, setServiceTickets] = useState<ApparatusServiceTicketSummary[]>([]);
  const [serviceTicketScope, setServiceTicketScope] = useState<'open' | 'all'>('open');
  const [openServiceTicketCount, setOpenServiceTicketCount] = useState(0);
  const [activity, setActivity] = useState<StationActivityEntry[]>([]);
  const [gasMeters, setGasMeters] = useState<SingleGasMeterSummary[]>([]);
  const [tabDataLoaded, setTabDataLoaded] = useState<Record<string, boolean>>({});
  const [tabDataLoading, setTabDataLoading] = useState<Record<string, boolean>>({});
  const [tabDataError, setTabDataError] = useState<Record<string, string>>({});

  const tabs: TabItem<TabId>[] = [
    { id: 'overview', label: 'Overview' },
    { id: 'requests', label: 'Requests' },
    { id: 'service-repair', label: 'Service / Repair', badge: openServiceTicketCount, badgeLabel: 'open tickets' },
    { id: 'rooms', label: 'Rooms' },
    { id: 'apparatus', label: 'Apparatus' },
    { id: 'gas-meters', label: 'Gas meters' },
    { id: 'inspections', label: 'Inspections' },
    { id: 'activity', label: 'Activity' },
  ];

  // Fetch station data
  useEffect(() => {
    if (!id) return;
    const stationId = parseInt(id);
    let cancelled = false;
    setLoading(true);
    setError(null);

    const fetchStation = async () => {
      try {
        const data = await ApiClient.getStation(stationId);
        if (cancelled) return;
        setStation(data);
        setIdentificationUnits(parseVehicleIdentificationUnits(data.vehicle_identification_units));
        setError(null);
      } catch (err) {
        if (isApiAuthenticationError(err)) {
          redirectToLoginAfterSessionExpiry();
          return;
        }
        if (!cancelled) setError(err instanceof Error ? err.message : 'Failed to load station');
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    const fetchOpenServiceTicketCount = async () => {
      try {
        const count = await ApiClient.getOpenApparatusServiceTicketCount(stationId);
        if (!cancelled) setOpenServiceTicketCount(count);
      } catch {
        // Secondary status data must never block the station workspace.
      }
    };

    fetchStation();
    fetchOpenServiceTicketCount();
    return () => { cancelled = true; };
  }, [id, stationLoadAttempt]);

  // Lazy load tab data when tab changes
  useEffect(() => {
    if (!id || tabDataLoaded[activeTab]) return;
    const stationId = parseInt(id);

    const loadTabData = async () => {
      setTabDataLoading(prev => ({ ...prev, [activeTab]: true }));
      setTabDataError(prev => ({ ...prev, [activeTab]: '' }));
      try {
        switch (activeTab) {
          case 'inspections': {
            const data = await ApiClient.getStationInspections(stationId);
            setStationInspections(data);
            break;
          }
          case 'requests': {
            const data = await ApiClient.getStationRequests(stationId, 'all');
            setStationRequests(data);
            break;
          }
          case 'service-repair': {
            const data = await ApiClient.getApparatusServiceTickets(stationId, 'all');
            setServiceTickets(data);
            setOpenServiceTicketCount(data.filter((ticket) => ticket.is_open).length);
            break;
          }
          case 'activity': {
            const data = await ApiClient.getStationActivity(stationId);
            setActivity(data);
            break;
          }
          case 'gas-meters': {
            const data = await ApiClient.getGasMeters(stationId);
            setGasMeters(data);
            break;
          }
        }
      } catch (reason) {
        setTabDataError(prev => ({
          ...prev,
          [activeTab]: reason instanceof Error ? reason.message : 'This section could not be loaded.',
        }));
      } finally {
        setTabDataLoaded(prev => ({ ...prev, [activeTab]: true }));
        setTabDataLoading(prev => ({ ...prev, [activeTab]: false }));
      }
    };

    if (LAZY_TABS.includes(activeTab)) {
      loadTabData();
    }
  }, [activeTab, id, tabDataLoaded]);

  const identificationSaved = (apparatusId: number, next: VehicleIdentification | undefined) => {
    setIdentificationUnits((units) => units.flatMap((unit): VehicleIdentificationUnit[] => {
      if (unit.apparatus_id !== apparatusId) return [unit];

      return next ? [{ ...unit, vehicle_identification: next }] : [];
    }));
  };

  const retryTabData = (tab: TabId) => {
    setTabDataError(prev => ({ ...prev, [tab]: '' }));
    setTabDataLoaded(prev => ({ ...prev, [tab]: false }));
  };

  if (loading) {
    return (
      <div data-hub-ui="2" className="space-y-6 font-hub" role="status" aria-label="Loading station">
        <span className="hub-skeleton !h-11 w-24" />
        <div className="hub-panel space-y-3 p-4 sm:p-6">
          <span className="hub-skeleton !h-8 w-48" />
          <span className="hub-skeleton !h-4 w-64 max-w-full" />
          <div className="grid gap-3 pt-3 sm:grid-cols-2 xl:grid-cols-3">
            {[1, 2, 3, 4].map(i => <span key={i} className="hub-skeleton !h-12 !rounded-hub-control" />)}
          </div>
        </div>
        <div className="hub-panel space-y-4 p-4 sm:p-6">
          <div className="flex gap-4">{[1, 2, 3, 4].map(i => <span key={i} className="hub-skeleton !h-8 w-20" />)}</div>
          <span className="hub-skeleton !h-40 w-full !rounded-hub-control" />
        </div>
      </div>
    );
  }

  if (error || !station) {
    return (
      <div data-hub-ui="2" className="mx-auto max-w-lg font-hub">
        <LoadError title="Station unavailable" message={error || 'Station not found'} onRetry={() => setStationLoadAttempt((attempt) => attempt + 1)}>
          <PreviousPageButton contextual className="hub-btn hub-btn--secondary" />
        </LoadError>
      </div>
    );
  }

  const configuredComplement = stationComplement(station.station_number);
  const assignedUnits = station.assigned_units?.length
    ? station.assigned_units
    : configuredComplement?.assignedUnits ?? [];
  const assignedApparatusCount = station.assigned_apparatus_count
    ?? configuredComplement?.assignedApparatusCount
    ?? null;
  const assignedPersonnelCount = station.assigned_personnel_count
    ?? configuredComplement?.assignedPersonnelCount
    ?? null;
  const dormBedsCount = station.dorm_beds_count
    ?? configuredComplement?.dormBedsCount
    ?? null;
  const roomGroups = groupRoomsByArea(station.rooms ?? []);
  const dailyCheckout = isCanonicalDailyCheckoutSummary(station.daily_checkout)
    ? station.daily_checkout
    : null;
  const dailyCheckoutRows = new Map<number, DailyCheckoutMatrixRow>(
    dailyCheckout?.matrix.map((row): [number, DailyCheckoutMatrixRow] => [row.apparatus_id, row]) ?? [],
  );
  const stationNumber = Number(station.station_number);
  // Station pages are addressed by DB id. Daily routes take the in-app path; pages outside Daily need the /daily prefix.
  const stationPath = `/stations/${station.id}`;
  const stationReturn = encodeURIComponent(stationPath);
  const hubStationReturn = encodeURIComponent(`/daily${stationPath}`);
  const stationRequestPath = `/forms-hub/station-request?station_id=${station.id}&return_to=${stationReturn}`;
  const apparatusServicePath = `/employee/apparatus-service-request?station_id=${station.id}&return_to=${hubStationReturn}`;
  const stationInspectionPath = `/forms-hub/station-inspection?station_id=${station.id}&station_number=${stationNumber}&return_to=${stationReturn}`;

  const quickLinks: QuickLinkProps[] = [
    ...(LINEUP_STATION_NUMBERS.includes(stationNumber) ? [{
      label: stationNumber === 2 ? 'Morning Lineup Video Conference — Station 2' : 'Morning Lineup Video Conference',
      href: `/video-conferencing/stations/${stationNumber}`,
      icon: <Video size={20} />,
    }] : []),
    ...(canRequestPersonnelEquipment ? [{ label: 'Personnel Equipment Request', href: `/employee/personnel-equipment-request?station_id=${station.id}&return_to=${hubStationReturn}`, icon: <ShieldCheck size={20} /> }] : []),
    { label: 'Station Request', to: stationRequestPath, icon: <ClipboardList size={20} /> },
    { label: 'Apparatus Service', href: apparatusServicePath, icon: <Wrench size={20} /> },
    { label: 'Station Inspection', to: stationInspectionPath, icon: <ClipboardCheck size={20} /> },
    { label: 'Vehicle Inspection', to: `/vehicle-inspections?return_to=${stationReturn}`, icon: <Truck size={20} /> },
  ];

  const tabLoadState = (tab: TabId, label: string): ReactNode => {
    if (tabDataError[tab]) return <LoadError title={`${label} could not be loaded`} message={tabDataError[tab]} onRetry={() => retryTabData(tab)} />;
    if (tabDataLoading[tab] || !tabDataLoaded[tab]) return <SkeletonRows />;
    return null;
  };

  const renderPanel = (tab: TabId): ReactNode => {
    switch (tab) {
      case 'overview':
        return (
          <StationOverviewPanel
            stationId={station.id}
            overview={stationOverview.overview}
            loading={stationOverview.loading}
            error={stationOverview.error}
            onRetry={stationOverview.retry}
            onViewAllRequests={() => { setRequestScope('all'); setActiveTab('requests'); }}
          />
        );
      case 'rooms':
        return station.rooms && station.rooms.length > 0 ? (
          <div className="space-y-7">
            {roomGroups.map((group) => (
              <section key={group.key} aria-labelledby={`room-area-${group.key}`}>
                <div className="mb-3 flex flex-wrap items-end justify-between gap-2 border-b border-hub-glass-border pb-2">
                  <h3 id={`room-area-${group.key}`} className="font-heading text-lg font-semibold text-hub-ink">{group.label}</h3>
                  {group.key === 'dormitory' && <p aria-label="Dorm positions" className="text-sm font-semibold tabular-nums text-hub-blue">{group.dormPositions} dorm positions</p>}
                </div>
                <div className="grid gap-3 md:grid-cols-2">
                  {group.rooms.map((room) => (
                    <Link key={room.id} to={`${stationPath}/rooms/${room.id}`} className="hub-well flex min-h-20 items-start justify-between gap-4 p-4 transition-colors hover:border-hub-blue/40">
                      <div className="min-w-0">
                        <h4 className="font-semibold text-hub-ink">{room.name}</h4>
                        <p className={`mt-1 ${SECONDARY}`}>{room.capacity ? `${room.capacity} position${room.capacity === 1 ? '' : 's'}` : 'Station area'}</p>
                      </div>
                      <div className={`shrink-0 text-right tabular-nums ${SECONDARY}`}>
                        <p>{room.assets_count || 0} assets</p>
                        <p>{room.audits_count || 0} audits</p>
                      </div>
                    </Link>
                  ))}
                </div>
              </section>
            ))}
          </div>
        ) : <EmptyState icon={<DoorOpen className="hub-empty__icon" aria-hidden="true" />} title="No rooms recorded" body="Rooms appear here when they are added in Administration." />;
      case 'apparatus':
        return (
          <div className="space-y-5">
            <DailyCheckoutPanel dailyCheckout={dailyCheckout} />
            {station.apparatuses && station.apparatuses.length > 0 ? (
              <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                {station.apparatuses.map((apparatus: Apparatus) => (
                  <ApparatusCard key={apparatus.id} apparatus={apparatus} checkoutRow={dailyCheckoutRows.get(apparatus.id) ?? null} stationId={station.id} hubStationReturn={hubStationReturn} />
                ))}
              </div>
            ) : <EmptyState icon={<Truck className="hub-empty__icon" aria-hidden="true" />} title="No apparatus assigned" body="Apparatus appear here when they are assigned to this station." />}
          </div>
        );
      case 'gas-meters':
        return tabLoadState('gas-meters', 'Gas meters') ?? (gasMeters.length > 0 ? (
          <ul className="space-y-3">
            {gasMeters.map((meter) => (
              <li key={meter.id} className={`${ROW} flex flex-wrap items-start justify-between gap-3`}>
                <div className="min-w-0">
                  <p className="font-semibold text-hub-ink">Serial {meter.serial_number ?? 'not recorded'}</p>
                  <p className={SECONDARY}>Assigned to {meter.apparatus_name}</p>
                  <p className={`tabular-nums ${SECONDARY}`}>Activated {formatDate(meter.activation_date)} · Expires {formatDate(meter.expiration_date)}</p>
                </div>
                <div className="flex flex-col items-end gap-1">
                  <StatusTag status={meter.status} />
                  {meter.status === 'Valid' && <p className={`tabular-nums ${SECONDARY}`}>{meter.days_until_expiration} days remaining</p>}
                </div>
              </li>
            ))}
          </ul>
        ) : <EmptyState icon={<Gauge className="hub-empty__icon" aria-hidden="true" />} title="No gas meters assigned" body="Single gas meters appear here when they are assigned to apparatus at this station." />);
      case 'requests': {
        const visibleRequests = stationRequests.filter((request) => requestScope === 'all' || request.is_open);
        return (
          <div>
            <PanelHeading title="Station requests" description="Repair, service, and equipment history in one queue." filter={<ScopeFilter label="Request history filter" scope={requestScope} onChange={setRequestScope} />} />
            {tabLoadState('requests', 'Station requests') ?? (visibleRequests.length > 0 ? (
              <ul className="space-y-3">
                {visibleRequests.map((req) => (
                  <li key={req.id} className={ROW}>
                    <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                      <div className="min-w-0">
                        <p className="font-mono text-[13px] font-semibold text-hub-n-600">{req.request_number}</p>
                        <p className="mt-1 font-semibold text-hub-ink">{req.title}</p>
                        <p className="mt-0.5 text-sm text-hub-n-600">{req.description}</p>
                      </div>
                      <div className="flex flex-shrink-0 flex-wrap gap-2">
                        <Tag>{req.request_type === 'repair_service' ? 'Repair / Service' : 'Equipment'}</Tag>
                        <StatusTag status={req.priority} suffix="priority" />
                        <StatusTag status={req.status} />
                      </div>
                    </div>
                    {req.current_public_response && <LatestResponse label="Latest response" text={req.current_public_response} />}
                    <p className={`mt-3 ${SECONDARY}`}>{req.room?.name || req.room_name_snapshot || 'Station-wide'} · Submitted {formatDate(req.created_at)}</p>
                    {req.updates && req.updates.length > 1 && <UpdateHistory summary={`View ${req.updates.length} updates`} updates={req.updates} />}
                  </li>
                ))}
              </ul>
            ) : <EmptyState icon={<ClipboardList className="hub-empty__icon" aria-hidden="true" />} title={requestScope === 'open' ? 'No open station requests' : 'No station request history'} body="New repair, service, and equipment requests appear here." action={<Link to={stationRequestPath} className="hub-btn hub-btn--secondary">New station request</Link>} />)}
          </div>
        );
      }
      case 'service-repair': {
        const visibleTickets = serviceTickets.filter((ticket) => serviceTicketScope === 'all' || ticket.is_open);
        return (
          <div>
            <PanelHeading title="Apparatus service and repair" description="Operationally safe ticket status for units attributed to this station." filter={<ScopeFilter label="Service ticket history filter" scope={serviceTicketScope} onChange={setServiceTicketScope} />} />
            {tabLoadState('service-repair', 'Service tickets') ?? (visibleTickets.length > 0 ? (
              <ul className="space-y-3">
                {visibleTickets.map((ticket) => (
                  <li key={ticket.id} className={`${ROW} ${ticket.priority === 'urgent' && ticket.is_open ? '!border-hub-danger-border' : ''}`}>
                    <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                      <div className="min-w-0">
                        <p className="font-mono text-[13px] font-semibold text-hub-n-600">{ticket.ticket_number} · {ticket.unit_designation}</p>
                        <h4 className="mt-1 font-semibold text-hub-ink">{ticket.title}</h4>
                      </div>
                      <div className="flex flex-wrap gap-2">
                        <StatusTag status={ticket.priority} suffix="priority" />
                        <StatusTag status={ticket.status} />
                      </div>
                    </div>
                    {ticket.current_public_response && <LatestResponse label="Latest update" text={ticket.current_public_response} />}
                    <p className={`mt-3 ${SECONDARY}`}>
                      {ticket.service_type || humanize(ticket.category)} · Submitted {formatDate(ticket.created_at)}
                      {ticket.scheduled_for ? ` · Scheduled ${formatDate(ticket.scheduled_for)} at ${formatTime(ticket.scheduled_for)}` : ''}
                      {ticket.scheduled_location ? ` · ${ticket.scheduled_location}` : ''}
                      {ticket.expected_return_at ? ` · Expected return ${formatDate(ticket.expected_return_at)} at ${formatTime(ticket.expected_return_at)}` : ''}
                    </p>
                    {ticket.updates && ticket.updates.length > 1 && <UpdateHistory summary={`View ${ticket.updates.length} public updates`} updates={ticket.updates} />}
                  </li>
                ))}
              </ul>
            ) : <EmptyState icon={<Wrench className="hub-empty__icon" aria-hidden="true" />} title={serviceTicketScope === 'open' ? 'No open apparatus service tickets' : 'No apparatus service history'} body="Authenticated employee and Fleet requests appear here." action={<a href={apparatusServicePath} className="hub-btn hub-btn--secondary">Report a service need</a>} />)}
          </div>
        );
      }
      case 'inspections':
        return tabLoadState('inspections', 'Station inspections') ?? (stationInspections.length > 0 ? (
          <ul className="space-y-3">
            {stationInspections.map((inspection) => (
              <li key={inspection.id} className={ROW}>
                <div className="mb-2 flex flex-wrap items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="font-semibold text-hub-ink">{inspection.inspection_type || 'Station Inspection'}</p>
                    <p className={SECONDARY}>Inspector: {inspection.inspector_name}</p>
                  </div>
                  <StatusTag status={inspection.overall_status} fallback="Status not recorded" />
                </div>
                <p className={SECONDARY}>
                  {formatDate(inspection.inspection_date)}
                  {inspection.notes && ` · ${inspection.notes.substring(0, 100)}${inspection.notes.length > 100 ? '…' : ''}`}
                </p>
              </li>
            ))}
          </ul>
        ) : <EmptyState icon={<ClipboardCheck className="hub-empty__icon" aria-hidden="true" />} title="No station inspections" body="Station inspection records appear here when they are submitted." action={<Link to={stationInspectionPath} className="hub-btn hub-btn--secondary">Start a station inspection</Link>} />);
      case 'activity':
        return tabLoadState('activity', 'Station activity') ?? (activity.length > 0 ? (
          <ol className="space-y-3">
            {activity.map((entry, index) => (
              <li key={`${entry.type}-${entry.occurred_at}-${index}`} className={ROW}>
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <p className="min-w-0 font-semibold text-hub-ink">{entry.label}</p>
                  <StatusTag status={entry.status} />
                </div>
                <p className={`mt-1 ${SECONDARY}`}>{humanize(entry.type)} · {formatDate(entry.occurred_at)}</p>
              </li>
            ))}
          </ol>
        ) : <EmptyState icon={<Activity className="hub-empty__icon" aria-hidden="true" />} title="No station activity yet" body="Inspections, inventory, supply requests, station requests, and apparatus service tickets appear here." />);
    }
  };

  return (
    <div data-hub-ui="2" className="space-y-6 font-hub">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <DailyPageNav className="!mb-0" />
        <Tag tone={station.is_active ? 'success' : 'neutral'}>{station.is_active ? 'Active' : 'Inactive'}</Tag>
      </div>

      <StationHeader
        station={station}
        assignedApparatusCount={assignedApparatusCount}
        assignedPersonnelCount={assignedPersonnelCount}
        dormBedsCount={dormBedsCount}
        assignedUnits={assignedUnits}
        quickLinks={quickLinks}
      />

      <StationShiftAlert overview={stationOverview.overview} stationId={station.id} />

      <UnitAlertsBanner stationId={station.id} />
      {/* One-time vehicle questions for units without a Daily Checkout or Captain 5 page */}
      <StationVehicleIdentification units={identificationUnits} onUnitSaved={identificationSaved} />

      {/* Tabbed detail view */}
      <section className="hub-panel overflow-hidden" aria-label="Station details">
        <Tabs tabs={tabs} active={activeTab} onChange={setActiveTab} label="Station sections" idPrefix="station" renderPanel={renderPanel} panelClassName="p-4 sm:p-6" />
      </section>
    </div>
  );
}

// ===== Helper Components =====

function PanelHeading({ title, description, filter }: { title: string; description: string; filter: ReactNode }) {
  return (
    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h3 className="font-heading text-lg font-bold text-hub-ink">{title}</h3>
        <p className="text-sm text-hub-n-600">{description}</p>
      </div>
      {filter}
    </div>
  );
}

function ScopeFilter({ label, scope, onChange }: { label: string; scope: 'open' | 'all'; onChange: (scope: 'open' | 'all') => void }) {
  const option = (value: 'open' | 'all', text: string) => (
    <button type="button" aria-pressed={scope === value} onClick={() => onChange(value)}
      className={`min-h-12 rounded-hub-control px-4 text-sm font-semibold transition-colors ${scope === value ? 'bg-hub-blue text-white' : 'text-hub-n-600 hover:bg-hub-n-50 hover:text-hub-ink'}`}>
      {text}
    </button>
  );
  return <div role="group" aria-label={label} className="inline-flex gap-1 rounded-hub-control border border-hub-glass-border bg-hub-well p-1">{option('open', 'Open')}{option('all', 'All history')}</div>;
}

function LatestResponse({ label, text }: { label: string; text: string }) {
  return <p className="mt-3 rounded-hub-control border border-hub-info-border bg-hub-info-bg p-3 text-sm text-hub-n-800"><span className="font-semibold">{label}:</span> {text}</p>;
}

function UpdateHistory({ summary, updates }: { summary: string; updates: Array<{ id: number; status: string; created_at: string; public_note?: string | null }> }) {
  return (
    <details className="mt-3">
      <summary className="flex min-h-11 cursor-pointer items-center text-sm font-semibold text-hub-blue">{summary}</summary>
      <ol className="mt-2 space-y-2 border-l-2 border-hub-n-200 pl-4">
        {updates.map((update) => (
          <li key={update.id} className="text-sm text-hub-n-600">
            <span className="font-semibold text-hub-ink">{humanize(update.status)}</span> · {formatDate(update.created_at)}
            {update.public_note && <p className="mt-0.5">{update.public_note}</p>}
          </li>
        ))}
      </ol>
    </details>
  );
}

function ApparatusCard({ apparatus, checkoutRow, stationId, hubStationReturn }: { apparatus: Apparatus; checkoutRow: DailyCheckoutMatrixRow | null; stationId: number; hubStationReturn: string }) {
  const checkoutState = checkoutRow ? dailyCheckoutStatePresentation(checkoutRow) : null;
  const requirement = checkoutRow?.daily_checkout_requirement ?? apparatus.daily_checkout_requirement;

  return (
    <article aria-label={apparatus.name || apparatus.unit_id || `Apparatus ${apparatus.id}`} className={`${ROW} flex flex-col`}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <h4 className="font-semibold text-hub-ink">{apparatus.name || apparatus.unit_id}</h4>
          <p className={SECONDARY}>Unit: {apparatus.vehicle_number ?? apparatus.unit_id ?? apparatus.designation ?? 'Not recorded'}</p>
          <p className={SECONDARY}>Type: {humanize(apparatus.type)}</p>
        </div>
        {checkoutState ? <Tag tone={checkoutState.tone}>{checkoutState.label}</Tag> : <Tag tone="warning">Daily Checkout state unavailable</Tag>}
      </div>
      <p className="mt-3 text-[13px] font-medium text-hub-n-800">
        Daily Checkout requirement: {dailyCheckoutRequirementLabel(requirement)}
      </p>
      {checkoutRow && (
        <p className={`mt-1 ${SECONDARY}`}>
          {checkoutRow.included_in_required_total
            ? (checkoutRow.included_in_completed ? 'Counts as completed' : 'Required inspection not complete')
            : 'Excluded from required total'}
        </p>
      )}
      {(apparatus.current_defects_count ?? 0) > 0 && (
        <p className="mt-1 text-[13px] font-medium text-hub-warning">
          {apparatus.current_defects_count} open equipment {apparatus.current_defects_count === 1 ? 'issue' : 'issues'}
        </p>
      )}
      {checkoutRow?.revision_requested &&<p className="mt-1 text-[13px] font-medium text-hub-warning">Your input is requested for this inspection.</p>}
      {checkoutRow?.return_checkout_required && <p className="mt-1 text-[13px] font-medium text-hub-warning">Post-return checkout {checkoutRow.return_checkout_verified ? 'verified' : 'required'}.</p>}
      {apparatus.daily_checkout_requirement === 'unknown' && (
        <p className="mt-2 text-[13px] font-medium text-hub-warning">Daily Checkout policy needs confirmation</p>
      )}
      <div className="mt-auto flex flex-wrap gap-x-4 pt-2">
        {/* The inspection receipt validates return_to and offers the way back to this station. */}
        {apparatus.daily_checkout_requirement === 'required' && apparatus.slug && (
          <Link to={`/vehicle-inspections/${apparatus.slug}?return_to=${encodeURIComponent(`/stations/${stationId}`)}`} className="hub-link hub-link--standalone">
            Start Inspection
            <ChevronRight className="h-4 w-4" aria-hidden="true" />
          </Link>
        )}
        <a href={`/employee/apparatus-service-request?station_id=${stationId}&apparatus_id=${apparatus.id}&return_to=${hubStationReturn}`} className="hub-link hub-link--standalone">
          Report Service Need
        </a>
        {apparatus.daily_checkout_requirement === 'required' && apparatus.vehicle_id_required !== false && (
          <Link to={`/changeouts/new/${apparatus.id}?return_to=${encodeURIComponent(`/stations/${stationId}`)}`} className="hub-link hub-link--standalone">
            Change vehicle
          </Link>
        )}
      </div>
    </article>
  );
}

const DAILY_CHECKOUT_STATES = new Set([
  'checked',
  'attention',
  'review_pending',
  'not_checked',
  'out_of_service',
  'exempt',
  'classification_required',
]);

function isCanonicalDailyCheckoutSummary(value: unknown): value is DailyCheckoutSummary {
  if (!value || typeof value !== 'object') return false;

  const summary = value as Record<string, unknown>;
  const countKeys = [
    'required_total',
    'checked',
    'attention',
    'review_pending',
    'not_checked',
    'completed',
    'out_of_service',
    'exempt',
    'classification_required',
  ];

  if (
    !countKeys.every((key) => typeof summary[key] === 'number')
    || typeof summary.completion_available !== 'boolean'
    || !Array.isArray(summary.matrix)
    || (summary.completion_percent !== null && typeof summary.completion_percent !== 'number')
  ) {
    return false;
  }

  const canonical = summary as unknown as DailyCheckoutSummary;

  if (
    canonical.required_total !== canonical.checked + canonical.attention + canonical.review_pending + canonical.not_checked
    || canonical.completed !== canonical.checked + canonical.attention
    || (canonical.required_total === 0 && (canonical.completion_available || canonical.completion_percent !== null))
    || (canonical.required_total > 0 && (!canonical.completion_available || canonical.completion_percent === null))
  ) {
    return false;
  }

  return canonical.matrix.every(isDailyCheckoutMatrixRow);
}

function isDailyCheckoutMatrixRow(value: unknown): value is DailyCheckoutMatrixRow {
  if (!value || typeof value !== 'object') return false;

  const row = value as Record<string, unknown>;

  return typeof row.apparatus_id === 'number'
    && typeof row.state === 'string'
    && DAILY_CHECKOUT_STATES.has(row.state)
    && typeof row.daily_checkout_requirement === 'string'
    && typeof row.out_of_service === 'boolean'
    && typeof row.classification_required === 'boolean'
    && typeof row.included_in_required_total === 'boolean'
    && typeof row.included_in_completed === 'boolean'
    && typeof row.has_pending_submission === 'boolean'
    && typeof row.return_checkout_required === 'boolean'
    && typeof row.return_checkout_verified === 'boolean';
}

function DailyCheckoutPanel({ dailyCheckout }: { dailyCheckout: DailyCheckoutSummary | null }) {
  if (dailyCheckout === null) {
    return (
      <section aria-labelledby="daily-checkout-heading" className="rounded-hub-control border border-hub-warning-border bg-hub-warning-bg p-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 id="daily-checkout-heading" className="font-heading text-lg font-semibold text-hub-ink">Daily Checkout</h2>
          <Tag tone="warning">Unavailable</Tag>
        </div>
        <p className="mt-2 text-sm text-hub-warning-strong">The authoritative Daily Checkout result is unavailable. Readiness is not estimated from inspection records.</p>
      </section>
    );
  }

  const completionLabel = dailyCheckout.completion_available && dailyCheckout.completion_percent !== null
    ? `${dailyCheckout.completion_percent}%`
    : 'Completion unavailable';
  const summaryItems: Array<{ label: string; value: number; tone: Tone }> = [
    { label: 'Checked', value: dailyCheckout.checked, tone: 'success' },
    { label: 'Attention', value: dailyCheckout.attention, tone: 'warning' },
    { label: 'Submitted', value: dailyCheckout.review_pending, tone: 'info' },
    { label: 'Not checked', value: dailyCheckout.not_checked, tone: 'danger' },
    { label: 'Out of service', value: dailyCheckout.out_of_service, tone: 'neutral' },
    { label: 'Exempt', value: dailyCheckout.exempt, tone: 'neutral' },
    { label: 'Classification required', value: dailyCheckout.classification_required, tone: 'warning' },
  ];

  return (
    <section aria-labelledby="daily-checkout-heading" className="hub-well p-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h2 id="daily-checkout-heading" className="font-heading text-lg font-semibold text-hub-ink">Daily Checkout</h2>
          {dailyCheckout.completion_available ? (
            <p className="mt-1 text-sm tabular-nums text-hub-n-600">{dailyCheckout.completed} / {dailyCheckout.required_total} required inspections completed</p>
          ) : (
            <p className="mt-1 text-sm text-hub-n-600">No required apparatus — completion unavailable</p>
          )}
          <p className="mt-1 text-xs text-hub-n-600">Counted from 7 AM to 7 AM</p>
        </div>
        <span className="w-fit text-2xl font-bold tabular-nums text-hub-blue">{completionLabel}</span>
      </div>

      <dl className="mt-4 flex flex-wrap gap-2">
        {summaryItems.map((item) => (
          <div key={item.label} className={`hub-tag !min-h-8 !gap-2 !px-3 ${item.tone === 'neutral' ? '' : `hub-tag--${item.tone}`}`}>
            <dt className="font-medium">{item.label}</dt>
            <dd className="font-bold tabular-nums">{item.value}</dd>
          </div>
        ))}
      </dl>
    </section>
  );
}

function dailyCheckoutRequirementLabel(requirement: Apparatus['daily_checkout_requirement']): string {
  return requirement ? humanize(requirement) : 'Unavailable';
}

function dailyCheckoutStatePresentation(row: DailyCheckoutMatrixRow): { label: string; tone: Tone } {
  const labels: Record<DailyCheckoutMatrixRow['state'], { label: string; tone: Tone }> = {
    checked: { label: 'Checked', tone: 'success' },
    attention: { label: 'Attention', tone: 'warning' },
    review_pending: { label: 'Submitted', tone: 'info' },
    not_checked: { label: 'Not checked', tone: 'danger' },
    out_of_service: { label: 'Out of service', tone: 'neutral' },
    exempt: { label: 'Exempt', tone: 'neutral' },
    classification_required: { label: 'Classification required', tone: 'warning' },
  };

  return labels[row.state];
}
