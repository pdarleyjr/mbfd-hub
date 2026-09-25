import { useState, useEffect, useRef, useCallback } from 'react';
import { Link, useParams } from 'react-router';
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
} from '../types';
import { ApiClient, isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../utils/api';
import PreviousPageButton from './PreviousPageButton';
import { groupRoomsByArea, stationComplement } from '../utils/stationRoomBlueprint';

type TabId = 'requests' | 'service-repair' | 'overview' | 'rooms' | 'apparatus' | 'gas-meters' | 'inspections' | 'activity';

export default function StationDetailPage() {
  const { id } = useParams<{ id: string }>();
  const [station, setStation] = useState<StationDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [stationLoadAttempt, setStationLoadAttempt] = useState(0);
  const [activeTab, setActiveTab] = useState<TabId>('overview');

  // Tab data (lazy loaded)
  const [stationInspections, setStationInspections] = useState<StationInspectionSummary[]>([]);
  const [stationRequests, setStationRequests] = useState<StationRequestSummary[]>([]);
  const [requestScope, setRequestScope] = useState<'open' | 'all'>('open');
  const [serviceTickets, setServiceTickets] = useState<ApparatusServiceTicketSummary[]>([]);
  const [serviceTicketScope, setServiceTicketScope] = useState<'open' | 'all'>('open');
  const [openServiceTicketCount, setOpenServiceTicketCount] = useState(0);
  const [activity, setActivity] = useState<StationActivityEntry[]>([]);
  const [gasMeters, setGasMeters] = useState<SingleGasMeterSummary[]>([]);
  const [tabDataLoaded, setTabDataLoaded] = useState<Record<string, boolean>>({});
  const [tabDataLoading, setTabDataLoading] = useState<Record<string, boolean>>({});
  const [tabDataError, setTabDataError] = useState<Record<string, string>>({});

  // Sliding underline refs
  const tabContainerRef = useRef<HTMLDivElement>(null);
  const tabRefs = useRef<Record<string, HTMLButtonElement | null>>({});
  const [underlineStyle, setUnderlineStyle] = useState<{ left: number; width: number }>({ left: 0, width: 0 });

  const tabs: { id: TabId; label: string; badge?: number }[] = [
    { id: 'overview', label: 'Overview' },
    { id: 'requests', label: 'Requests' },
    { id: 'service-repair', label: 'Service / Repair', badge: openServiceTicketCount },
    { id: 'rooms', label: 'Rooms' },
    { id: 'apparatus', label: 'Apparatus' },
    { id: 'inspections', label: 'Inspections' },
    { id: 'activity', label: 'Activity' },
  ];

  const updateUnderline = useCallback(() => {
    const activeButton = tabRefs.current[activeTab];
    const container = tabContainerRef.current;
    if (activeButton && container) {
      const containerRect = container.getBoundingClientRect();
      const buttonRect = activeButton.getBoundingClientRect();
      setUnderlineStyle({
        left: buttonRect.left - containerRect.left + container.scrollLeft,
        width: buttonRect.width,
      });
    }
  }, [activeTab]);

  useEffect(() => {
    updateUnderline();
  }, [activeTab, updateUnderline]);

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

    if (['inspections', 'requests', 'service-repair', 'gas-meters', 'activity'].includes(activeTab)) {
      loadTabData();
    }
  }, [activeTab, id, tabDataLoaded]);

  const retryTabData = (tab: TabId) => {
    setTabDataError(prev => ({ ...prev, [tab]: '' }));
    setTabDataLoaded(prev => ({ ...prev, [tab]: false }));
  };

  const getStatusBadgeClass = (status: string): string => {
    const map: Record<string, string> = {
      pass: 'bg-hub-success/10 text-hub-success',
      fail: 'bg-hub-danger/10 text-hub-danger',
      needs_attention: 'bg-hub-warning/10 text-hub-warning',
      pending: 'bg-hub-blue/10 text-hub-blue',
      approved: 'bg-hub-success/10 text-hub-success',
      denied: 'bg-hub-danger/10 text-hub-danger',
      fulfilled: 'bg-hub-success/10 text-hub-success',
      acknowledged: 'bg-hub-warning/10 text-hub-warning',
      under_review: 'bg-hub-warning/10 text-hub-warning',
      scheduled: 'bg-hub-blue/10 text-hub-blue',
      ordered: 'bg-hub-blue/10 text-hub-blue',
      in_progress: 'bg-hub-blue/10 text-hub-blue',
      awaiting_parts: 'bg-hub-surface-muted text-hub-ink-secondary',
      waiting_for_parts: 'bg-hub-surface-muted text-hub-ink-secondary',
      submitted: 'bg-hub-warning/10 text-hub-warning',
      awaiting_vendor: 'bg-hub-surface-muted text-hub-ink-secondary',
      on_hold: 'bg-hub-surface-muted text-hub-ink-secondary',
      completed: 'bg-hub-success/10 text-hub-success',
      cancelled: 'bg-hub-danger/10 text-hub-danger',
      low: 'bg-hub-surface-muted text-hub-ink-secondary',
      medium: 'bg-hub-blue/10 text-hub-blue',
      high: 'bg-hub-warning/10 text-hub-warning',
      critical: 'bg-hub-danger/10 text-hub-danger',
      routine: 'bg-hub-surface-muted text-hub-ink-secondary',
      attention: 'bg-hub-warning/10 text-hub-warning',
      urgent: 'bg-hub-danger/10 text-hub-danger',
    };
    return map[status] ?? 'bg-hub-surface-muted text-hub-ink-secondary';
  };

  const formatDate = (dateStr: string): string => {
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  };

  const formatTime = (dateStr: string): string => {
    const date = new Date(dateStr);
    return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  };

  if (loading) {
    return (
      <div className="space-y-6 font-hub">
        <div className="skeleton h-6 w-32"></div>
        <div className="bg-hub-surface rounded-2xl ring-1 ring-hub-border/80 p-6">
          <div className="skeleton h-8 w-48 mb-2"></div>
          <div className="skeleton h-5 w-64 mb-2"></div>
          <div className="skeleton h-4 w-80 mb-6"></div>
          <div className="flex flex-wrap gap-4">
            {[1, 2, 3, 4].map(i => <div key={i} className="skeleton h-10 w-36"></div>)}
          </div>
        </div>
        <div className="bg-hub-surface rounded-2xl ring-1 ring-hub-border/80 p-6">
          <div className="flex gap-4 mb-6">
            {[1, 2, 3, 4].map(i => <div key={i} className="skeleton h-10 w-24"></div>)}
          </div>
          <div className="skeleton h-40 w-full"></div>
        </div>
      </div>
    );
  }

  if (error || !station) {
    return (
      <div className="text-center p-8 font-hub">
        <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-hub-danger/10 mb-4">
          <svg className="w-8 h-8 text-hub-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
          </svg>
        </div>
        <p className="text-hub-danger font-medium mb-2">{error || 'Station not found'}</p>
        <div className="mt-4 flex flex-wrap justify-center gap-3">
          <button type="button" onClick={() => setStationLoadAttempt((attempt) => attempt + 1)} className="min-h-12 rounded-lg bg-hub-blue px-5 font-semibold text-white hover:bg-hub-blue-strong">Retry</button>
          <PreviousPageButton className="inline-flex min-h-12 items-center rounded-lg bg-hub-blue px-5 font-semibold text-white transition-colors hover:bg-hub-blue-strong" />
        </div>
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

  return (
    <div className="space-y-6 font-hub">
      {/* Back button and header */}
      <div className="flex items-center justify-between">
        <PreviousPageButton
          className="inline-flex items-center text-hub-muted hover:text-hub-ink"
        >
          <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
          Back to previous page
        </PreviousPageButton>
        {station.is_active ? (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-hub-success/10 text-hub-success">Active</span>
        ) : (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-hub-surface-muted text-hub-ink-secondary">Inactive</span>
        )}
      </div>

      {/* ============================== */}
      {/* FIRST CARD: Station Info + Quick Links */}
      {/* ============================== */}
      <div className="bg-hub-surface rounded-2xl ring-1 ring-hub-border/80 p-6">
        <div className="flex flex-col md:flex-row md:items-start md:justify-between mb-6">
          <div>
            <h1 className="text-3xl font-bold text-hub-ink mb-2 font-heading">
              Station {station.station_number}
            </h1>
            <p className="text-hub-muted mt-1">
              {station.address}, {station.city}, {station.state} {station.zip_code}
            </p>
            {station.phone && (
              <p className="text-hub-muted mt-1 flex items-center gap-2">
                <svg className="w-4 h-4 text-hub-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                </svg>
                {station.phone}
              </p>
            )}
          </div>
        </div>

        {/* Quick Links */}
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6 gap-3 mb-6">
          {[1, 2, 3, 4, 6].includes(stationNumber) && (
            <a
              href={`/video-conferencing/stations/${stationNumber}`}
              className="flex min-h-12 items-center gap-2.5 p-3 bg-hub-blue/10 rounded-xl ring-1 ring-hub-blue/30 hover:bg-hub-blue/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus transition-colors text-sm font-semibold text-hub-blue"
            >
              <svg className="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
              </svg>
              {stationNumber === 2 ? 'Morning Lineup Video Conference — Station 2' : 'Morning Lineup Video Conference'}
            </a>
          )}
          {stationNumber === 2 && (
            <a
              href={`/employee/video-conferencing/command?return_to=${encodeURIComponent(`/daily/stations/${stationNumber}`)}`}
              className="flex min-h-12 items-center gap-2.5 rounded-xl bg-hub-red p-3 text-sm font-bold text-white ring-1 ring-hub-red/30 transition-colors hover:bg-hub-red-strong focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus"
            >
              <svg className="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12.75 11.25 15 15 9.75m6-4.5A9 9 0 1 1 3 12a9 9 0 0 1 18 0Z" />
              </svg>
              Morning Lineup — 300 Command
            </a>
          )}
          <a
            href={`/employee/personnel-equipment-request?station_id=${station.id}&return_to=${encodeURIComponent(`/daily/stations/${station.id}`)}`}
            className="flex min-h-12 items-center gap-2.5 p-3 bg-hub-blue/10 rounded-xl ring-1 ring-hub-blue/30 hover:bg-hub-blue/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus transition-colors text-sm font-semibold text-hub-blue"
          >
            <svg className="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.955 11.955 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
            </svg>
            Personnel Equipment Request
          </a>
          <Link
            to={`/forms-hub/station-request?station_id=${station.id}&return_to=${encodeURIComponent(`/stations/${station.id}`)}`}
            className="flex min-h-12 items-center gap-2.5 p-3 bg-hub-blue/10 rounded-xl ring-1 ring-hub-blue/30 hover:bg-hub-blue/20 transition-all text-sm font-semibold text-hub-blue"
          >
            <svg className="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
            </svg>
            Station Request
          </Link>
          <a
            href={`/employee/apparatus-service-request?station_id=${station.id}&return_to=${encodeURIComponent(`/daily/stations/${station.id}`)}`}
            className="flex min-h-12 items-center gap-2.5 rounded-xl bg-hub-blue/10 p-3 text-sm font-semibold text-hub-blue ring-1 ring-hub-blue/30 transition-colors hover:bg-hub-blue/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus"
          >
            <svg className="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11.42 15.17 17.25 21A2.652 2.652 0 1 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26" /></svg>
            Apparatus Service
          </a>
          <Link
            to={`/forms-hub/station-inspection`}
            className="flex items-center gap-2.5 p-3 bg-hub-blue/10 rounded-xl ring-1 ring-hub-blue/30 hover:bg-hub-blue/20 transition-all text-sm font-medium text-hub-blue"
          >
            <svg className="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Station Inspection
          </Link>
          <Link
            to={`/vehicle-inspections`}
            className="flex items-center gap-2.5 p-3 bg-hub-blue/10 rounded-xl ring-1 ring-hub-blue/30 hover:bg-hub-blue/20 transition-all text-sm font-medium text-hub-blue"
          >
            <svg className="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
            </svg>
            Vehicle Inspection
          </Link>
        </div>

      </div>

      {/* ============================== */}
      {/* SECOND CARD: Tabbed Detail View */}
      {/* ============================== */}
      <div className="bg-hub-surface rounded-2xl ring-1 ring-hub-border/80 overflow-hidden">
        {/* Tab Bar */}
        <div
          ref={tabContainerRef}
          className="relative flex overflow-x-auto border-b border-hub-border scroll-snap-x-mandatory"
        >
          {tabs.map((tab) => (
            <button
              key={tab.id}
              ref={(el) => { tabRefs.current[tab.id] = el; }}
              onClick={() => setActiveTab(tab.id)}
              className={`min-h-[48px] px-5 py-3 text-sm font-medium whitespace-nowrap transition-colors flex-shrink-0 scroll-snap-align-start ${
                activeTab === tab.id
                  ? 'text-hub-red bg-hub-red/10'
                  : 'text-hub-muted hover:text-hub-ink hover:bg-hub-canvas'
              }`}
            >
              <span>{tab.label}</span>
              {typeof tab.badge === 'number' && tab.badge > 0 && <span className="ml-2 inline-flex min-w-6 items-center justify-center rounded-full bg-hub-red px-1.5 py-0.5 text-xs font-bold text-white">{tab.badge}</span>}
            </button>
          ))}
          <div
            className="absolute bottom-0 h-0.5 bg-hub-red transition-all duration-250"
            style={{
              left: `${underlineStyle.left}px`,
              width: `${underlineStyle.width}px`,
              transitionTimingFunction: 'cubic-bezier(0.25, 1, 0.5, 1)',
            }}
          />
        </div>

        <div className="p-6">
          {/* ========== OVERVIEW TAB ========== */}
          {activeTab === 'overview' && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <h3 className="text-lg font-semibold text-hub-ink mb-4 font-heading">Station Information</h3>
                <dl className="space-y-0">
                  <div className="flex justify-between py-2.5 border-b border-hub-border">
                    <dt className="text-hub-muted">Station Number</dt>
                    <dd className="font-medium text-hub-ink tabular-nums">{station.station_number}</dd>
                  </div>
                  <div className="flex justify-between py-2.5 border-b border-hub-border bg-hub-canvas/50">
                    <dt className="text-hub-muted">Assigned Apparatus</dt>
                    <dd className="font-medium text-hub-ink tabular-nums">{assignedApparatusCount ?? 'Unknown'}</dd>
                  </div>
                  <div className="flex justify-between py-2.5 border-b border-hub-border">
                    <dt className="text-hub-muted">Assigned Personnel</dt>
                    <dd className="font-medium text-hub-ink tabular-nums">{assignedPersonnelCount ?? 'Unknown'}</dd>
                  </div>
                  <div className="flex justify-between py-2.5 border-b border-hub-border bg-hub-canvas/50">
                    <dt className="text-hub-muted">Dorm Beds</dt>
                    <dd className="font-medium text-hub-ink tabular-nums">{dormBedsCount ?? 'Unknown'}</dd>
                  </div>
                  <div className="grid gap-1 py-2.5 border-b border-hub-border">
                    <dt className="text-hub-muted">Assigned Units</dt>
                    <dd className="font-medium text-hub-ink">{assignedUnits.length ? assignedUnits.join(' · ') : 'Unknown'}</dd>
                  </div>
                  {station.fax && (
                    <div className="flex justify-between py-2.5 border-b border-hub-border">
                      <dt className="text-hub-muted">Fax</dt>
                      <dd className="font-medium text-hub-ink">{station.fax}</dd>
                    </div>
                  )}
                </dl>
              </div>
              <div>
                <h3 className="text-lg font-semibold text-hub-ink mb-4 font-heading">Location</h3>
                <dl className="space-y-0">
                  <div className="flex justify-between py-2.5 border-b border-hub-border">
                    <dt className="text-hub-muted">Address</dt>
                    <dd className="font-medium text-right text-hub-ink">{station.address}</dd>
                  </div>
                  <div className="flex justify-between py-2.5 border-b border-hub-border bg-hub-canvas/50">
                    <dt className="text-hub-muted">City</dt>
                    <dd className="font-medium text-hub-ink">{station.city}</dd>
                  </div>
                  <div className="flex justify-between py-2.5 border-b border-hub-border">
                    <dt className="text-hub-muted">State</dt>
                    <dd className="font-medium text-hub-ink">{station.state}</dd>
                  </div>
                  <div className="flex justify-between py-2.5 border-b border-hub-border bg-hub-canvas/50">
                    <dt className="text-hub-muted">ZIP Code</dt>
                    <dd className="font-medium text-hub-ink">{station.zip_code}</dd>
                  </div>
                  {station.latitude && station.longitude && (
                    <div className="flex justify-between py-2.5 border-b border-hub-border">
                      <dt className="text-hub-muted">Coordinates</dt>
                      <dd className="font-medium text-hub-ink tabular-nums">{station.latitude}, {station.longitude}</dd>
                    </div>
                  )}
                </dl>
              </div>
            </div>
          )}

          {/* ========== ROOMS TAB ========== */}
          {activeTab === 'rooms' && (
            <div>
              {station.rooms && station.rooms.length > 0 ? (
                <div className="space-y-7 stagger-list">
                  {roomGroups.map((group) => (
                    <section key={group.key} aria-labelledby={`room-area-${group.key}`}>
                      <div className="mb-3 flex flex-wrap items-end justify-between gap-2 border-b border-hub-border pb-2">
                        <h3 id={`room-area-${group.key}`} className="font-heading text-lg font-semibold text-hub-ink">{group.label}</h3>
                        {group.key === 'dormitory' && <p aria-label="Dorm positions" className="text-sm font-semibold tabular-nums text-hub-blue">{group.dormPositions} dorm positions</p>}
                      </div>
                      <div className="grid gap-3 md:grid-cols-2">
                        {group.rooms.map((room) => (
                          <Link
                            key={room.id}
                            to={`/stations/${station.id}/rooms/${room.id}`}
                            className="block min-h-24 rounded-xl border border-hub-border p-4 transition-all duration-200 hover-lift hover:border-hub-blue/30 hover:bg-hub-blue/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus"
                          >
                            <div className="flex items-start justify-between gap-4">
                              <div>
                                <h4 className="font-semibold text-hub-ink">{room.name}</h4>
                                <p className="mt-1 text-sm text-hub-ink-secondary">
                                  {room.capacity ? `${room.capacity} position${room.capacity === 1 ? '' : 's'}` : 'Station area'}
                                </p>
                              </div>
                              <div className="shrink-0 text-right text-sm text-hub-muted tabular-nums">
                                <p>{room.assets_count || 0} assets</p>
                                <p>{room.audits_count || 0} audits</p>
                              </div>
                            </div>
                          </Link>
                        ))}
                      </div>
                    </section>
                  ))}
                </div>
              ) : (
                <EmptyState icon="room" title="No rooms recorded" subtitle="Rooms will appear here when added in the admin panel." />
              )}
            </div>
          )}

          {/* ========== ASSIGNED APPARATUS TAB ========== */}
          {activeTab === 'apparatus' && (
            <div className="space-y-5">
              <DailyCheckoutPanel dailyCheckout={dailyCheckout} />
              {station.apparatuses && station.apparatuses.length > 0 ? (
                <div className="stagger-list grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 2xl:gap-6">
                  {station.apparatuses.map((apparatus: Apparatus) => {
                    const checkoutRow = dailyCheckoutRows.get(apparatus.id) ?? null;
                    const checkoutState = checkoutRow ? dailyCheckoutStatePresentation(checkoutRow) : null;
                    const requirement = checkoutRow?.daily_checkout_requirement ?? apparatus.daily_checkout_requirement;

                    return (
                      <article
                        key={apparatus.id}
                        aria-label={apparatus.name || apparatus.unit_id || `Apparatus ${apparatus.id}`}
                        className="rounded-lg border border-hub-border p-4 transition-all duration-200 hover:bg-hub-canvas 2xl:p-5"
                      >
                        <div className="flex flex-wrap items-start justify-between gap-2">
                          <div>
                            <h4 className="font-semibold text-hub-ink">{apparatus.name || apparatus.unit_id}</h4>
                            <p className="text-sm text-hub-ink-secondary">Unit: {apparatus.vehicle_number ?? apparatus.unit_id ?? apparatus.designation ?? 'Not recorded'}</p>
                            <p className="text-sm text-hub-muted capitalize">Type: {apparatus.type}</p>
                          </div>
                          {checkoutState ? (
                            <span className={`w-fit rounded-full px-2.5 py-1 text-xs font-semibold ${checkoutState.className}`}>{checkoutState.label}</span>
                          ) : (
                            <span className="w-fit rounded-full bg-hub-warning/10 px-2.5 py-1 text-xs font-semibold text-hub-warning">Daily Checkout state unavailable</span>
                          )}
                        </div>
                        <p className="mt-3 text-xs font-medium text-hub-ink-secondary">
                          Daily Checkout requirement: {dailyCheckoutRequirementLabel(requirement)}
                        </p>
                        {checkoutRow && (
                          <p className="mt-1 text-xs text-hub-muted">
                            {checkoutRow.included_in_required_total
                              ? (checkoutRow.included_in_completed ? 'Counts as completed' : 'Required inspection not complete')
                              : 'Excluded from required total'}
                          </p>
                        )}
                        {checkoutRow?.revision_requested && <p className="mt-1 text-xs font-medium text-hub-warning">Your input is requested for this inspection.</p>}
                        {checkoutRow?.return_checkout_required && <p className="mt-1 text-xs font-medium text-hub-warning">Post-return checkout {checkoutRow.return_checkout_verified ? 'verified' : 'required'}.</p>}
                        {apparatus.daily_checkout_requirement === 'required' && apparatus.slug && (
                          <Link
                            to={`/vehicle-inspections/${apparatus.slug}`}
                            className="mt-2 inline-flex min-h-11 items-center text-xs font-medium text-hub-blue hover:text-hub-blue-strong"
                          >
                            Start Inspection
                            <svg className="ml-1 w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" /></svg>
                          </Link>
                        )}
                        {apparatus.daily_checkout_requirement === 'unknown' && (
                          <p className="mt-2 text-xs font-medium text-hub-warning">Daily Checkout policy needs confirmation</p>
                        )}
                        <a
                          href={`/employee/apparatus-service-request?station_id=${station.id}&apparatus_id=${apparatus.id}&return_to=${encodeURIComponent(`/daily/stations/${station.id}`)}`}
                          className="mt-2 ml-4 inline-flex min-h-11 items-center text-xs font-semibold text-hub-blue hover:text-hub-blue"
                        >
                          Report Service Need
                        </a>
                      </article>
                    );
                  })}
                </div>
              ) : (
                <EmptyState icon="apparatus" title="No apparatus assigned" subtitle="Apparatus will appear here when assigned to this station." />
              )}
            </div>
          )}

          {/* ========== GAS METERS TAB ========== */}
          {activeTab === 'gas-meters' && (
            <div>
              {tabDataError['gas-meters'] ? (
                <TabLoadError message={tabDataError['gas-meters']} onRetry={() => retryTabData('gas-meters')} />
              ) : tabDataLoading['gas-meters'] ? (
                <TabSkeleton />
              ) : gasMeters.length > 0 ? (
                <div className="space-y-3 stagger-list">
                  {gasMeters.map((meter) => (
                    <div
                      key={meter.id}
                      className="flex items-center justify-between p-4 border border-hub-border rounded-lg"
                    >
                      <div>
                        <p className="font-semibold text-hub-ink">S/N: {meter.serial_number}</p>
                        <p className="text-sm text-hub-ink-secondary">Assigned to: {meter.apparatus_name}</p>
                        <p className="text-sm text-hub-muted">
                          Activated: {formatDate(meter.activation_date)} &middot; Expires: {formatDate(meter.expiration_date)}
                        </p>
                      </div>
                      <div className="text-right">
                        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                          meter.status === 'Valid' ? 'bg-hub-success/10 text-hub-success' : 'bg-hub-danger/10 text-hub-danger'
                        }`}>
                          {meter.status}
                        </span>
                        {meter.status === 'Valid' && (
                          <p className="text-xs text-hub-muted mt-1">{meter.days_until_expiration}d remaining</p>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <EmptyState icon="meter" title="No gas meters assigned" subtitle="Single gas meters will appear here when assigned to apparatus at this station." />
              )}
            </div>
          )}

          {/* ========== CANONICAL STATION REQUESTS TAB ========== */}
          {activeTab === 'requests' && (
            <div>
              <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                  <h3 className="font-heading text-lg font-bold text-hub-ink">Station requests</h3>
                  <p className="text-sm text-hub-muted">Repair, service, and equipment history in one queue.</p>
                </div>
                <div className="inline-flex rounded-xl bg-hub-surface-muted p-1" aria-label="Request history filter">
                  <button type="button" onClick={() => setRequestScope('open')} className={`min-h-11 rounded-lg px-4 text-sm font-semibold ${requestScope === 'open' ? 'bg-hub-surface text-hub-blue shadow-sm' : 'text-hub-ink-secondary'}`}>Open</button>
                  <button type="button" onClick={() => setRequestScope('all')} className={`min-h-11 rounded-lg px-4 text-sm font-semibold ${requestScope === 'all' ? 'bg-hub-surface text-hub-blue shadow-sm' : 'text-hub-ink-secondary'}`}>All history</button>
                </div>
              </div>
              {tabDataError.requests ? (
                <TabLoadError message={tabDataError.requests} onRetry={() => retryTabData('requests')} />
              ) : tabDataLoading.requests ? (
                <TabSkeleton />
              ) : stationRequests.filter((request) => requestScope === 'all' || request.is_open).length > 0 ? (
                <div className="space-y-3 stagger-list">
                  {stationRequests.filter((request) => requestScope === 'all' || request.is_open).map((req) => (
                    <div
                      key={req.id}
                      className="rounded-xl border border-hub-border p-4"
                    >
                      <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                        <div>
                          <p className="font-mono text-xs font-semibold text-hub-muted">{req.request_number}</p>
                          <p className="mt-1 font-semibold text-hub-ink">{req.title}</p>
                          <p className="text-sm text-hub-ink-secondary mt-0.5">{req.description}</p>
                        </div>
                        <div className="flex flex-wrap gap-2 flex-shrink-0">
                          <span className="inline-flex items-center rounded-full bg-hub-blue/10 px-2.5 py-1 text-xs font-medium text-hub-blue">{req.request_type === 'repair_service' ? 'Repair / Service' : 'Equipment'}</span>
                          <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getStatusBadgeClass(req.priority)}`}>
                            {req.priority}
                          </span>
                          <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getStatusBadgeClass(req.status)}`}>
                            {req.status}
                          </span>
                        </div>
                      </div>
                      {req.current_public_response && <div className="mt-3 rounded-lg bg-hub-blue/10 p-3 text-sm text-hub-blue"><span className="font-semibold">Latest response:</span> {req.current_public_response}</div>}
                      <p className="mt-3 text-xs text-hub-muted">
                        {req.room?.name || req.room_name_snapshot || 'Station-wide'} &middot; Submitted {formatDate(req.created_at)}
                      </p>
                      {req.updates && req.updates.length > 1 && <details className="mt-3"><summary className="min-h-11 cursor-pointer py-2 text-sm font-semibold text-hub-blue">View {req.updates.length} updates</summary><ol className="mt-2 space-y-2 border-l-2 border-hub-blue/30 pl-4">{req.updates.map((update) => <li key={update.id} className="text-sm text-hub-ink-secondary"><span className="font-semibold text-hub-ink">{update.status.replaceAll('_', ' ')}</span> · {formatDate(update.created_at)}{update.public_note && <p className="mt-0.5">{update.public_note}</p>}</li>)}</ol></details>}
                    </div>
                  ))}
                </div>
              ) : (
                <EmptyState icon="request" title={requestScope === 'open' ? 'No open station requests' : 'No station request history'} subtitle="New repair, service, and equipment requests will appear here." />
              )}
            </div>
          )}

          {/* ========== APPARATUS SERVICE / REPAIR TAB ========== */}
          {activeTab === 'service-repair' && (
            <div>
              <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                  <h3 className="font-heading text-lg font-bold text-hub-ink">Apparatus service and repair</h3>
                  <p className="text-sm text-hub-muted">Operationally safe ticket status for units attributed to this station.</p>
                </div>
                <div className="inline-flex rounded-xl bg-hub-surface-muted p-1" aria-label="Service ticket history filter">
                  <button type="button" onClick={() => setServiceTicketScope('open')} className={`min-h-12 rounded-lg px-4 text-sm font-semibold ${serviceTicketScope === 'open' ? 'bg-hub-surface text-hub-blue shadow-sm' : 'text-hub-ink-secondary'}`}>Open</button>
                  <button type="button" onClick={() => setServiceTicketScope('all')} className={`min-h-12 rounded-lg px-4 text-sm font-semibold ${serviceTicketScope === 'all' ? 'bg-hub-surface text-hub-blue shadow-sm' : 'text-hub-ink-secondary'}`}>All history</button>
                </div>
              </div>
              {tabDataError['service-repair'] ? (
                <TabLoadError message={tabDataError['service-repair']} onRetry={() => retryTabData('service-repair')} />
              ) : tabDataLoading['service-repair'] ? (
                <TabSkeleton />
              ) : serviceTickets.filter((ticket) => serviceTicketScope === 'all' || ticket.is_open).length > 0 ? (
                <div className="space-y-3 stagger-list">
                  {serviceTickets.filter((ticket) => serviceTicketScope === 'all' || ticket.is_open).map((ticket) => (
                    <article key={ticket.id} className={`rounded-xl border p-4 ${ticket.priority === 'urgent' && ticket.is_open ? 'border-hub-danger/30 bg-hub-danger/10' : 'border-hub-border'}`}>
                      <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                        <div>
                          <p className="font-mono text-xs font-semibold text-hub-muted">{ticket.ticket_number} · {ticket.unit_designation}</p>
                          <h4 className="mt-1 font-semibold text-hub-ink">{ticket.title}</h4>
                        </div>
                        <div className="flex flex-wrap gap-2">
                          <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${getStatusBadgeClass(ticket.priority)}`}>{ticket.priority}</span>
                          <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${getStatusBadgeClass(ticket.status)}`}>{ticket.status.replaceAll('_', ' ')}</span>
                        </div>
                      </div>
                      {ticket.current_public_response && <p className="mt-3 rounded-lg bg-hub-blue/10 p-3 text-sm text-hub-blue"><strong>Latest update:</strong> {ticket.current_public_response}</p>}
                      <p className="mt-3 text-xs text-hub-muted">
                        {ticket.service_type || ticket.category.replaceAll('_', ' ')} · Submitted {formatDate(ticket.created_at)}
                        {ticket.scheduled_for ? ` · Scheduled ${formatDate(ticket.scheduled_for)} at ${formatTime(ticket.scheduled_for)}` : ''}
                        {ticket.scheduled_location ? ` · ${ticket.scheduled_location}` : ''}
                        {ticket.expected_return_at ? ` · Expected return ${formatDate(ticket.expected_return_at)} at ${formatTime(ticket.expected_return_at)}` : ''}
                      </p>
                      {ticket.updates && ticket.updates.length > 1 && <details className="mt-3"><summary className="min-h-12 cursor-pointer py-3 text-sm font-semibold text-hub-blue">View {ticket.updates.length} public updates</summary><ol className="space-y-2 border-l-2 border-hub-blue/30 pl-4">{ticket.updates.map((update) => <li key={update.id} className="text-sm text-hub-ink-secondary"><strong className="text-hub-ink">{update.status.replaceAll('_', ' ')}</strong> · {formatDate(update.created_at)}{update.public_note && <p className="mt-0.5">{update.public_note}</p>}</li>)}</ol></details>}
                    </article>
                  ))}
                </div>
              ) : (
                <EmptyState icon="apparatus" title={serviceTicketScope === 'open' ? 'No open apparatus service tickets' : 'No apparatus service history'} subtitle="Authenticated employee and Fleet requests will appear here." />
              )}
            </div>
          )}

          {/* ========== STATION INSPECTIONS TAB ========== */}
          {activeTab === 'inspections' && (
            <div>
              {tabDataError.inspections ? (
                <TabLoadError message={tabDataError.inspections} onRetry={() => retryTabData('inspections')} />
              ) : tabDataLoading['inspections'] ? (
                <TabSkeleton />
              ) : stationInspections.length > 0 ? (
                <div className="space-y-3 stagger-list">
                  {stationInspections.map((inspection) => (
                    <div
                      key={inspection.id}
                      className="p-4 border border-hub-border rounded-lg"
                    >
                      <div className="flex justify-between items-start mb-2">
                        <div>
                          <p className="font-semibold text-hub-ink">
                            {inspection.inspection_type || 'Station Inspection'}
                          </p>
                          <p className="text-sm text-hub-ink-secondary">
                            Inspector: {inspection.inspector_name}
                          </p>
                        </div>
                        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getStatusBadgeClass(inspection.overall_status)}`}>
                          {(inspection.overall_status || '').replace('_', ' ')}
                        </span>
                      </div>
                      <p className="text-xs text-hub-muted">
                        {formatDate(inspection.inspection_date)}
                        {inspection.notes && ` \u2022 ${inspection.notes.substring(0, 100)}${inspection.notes.length > 100 ? '...' : ''}`}
                      </p>
                    </div>
                  ))}
                </div>
              ) : (
                <EmptyState icon="inspection" title="No station inspections" subtitle="Station inspection records will appear here when submitted." />
              )}
            </div>
          )}

          {/* ========== UNIFIED STATION ACTIVITY TAB ========== */}
          {activeTab === 'activity' && (
            <div>
              {tabDataError.activity ? <TabLoadError message={tabDataError.activity} onRetry={() => retryTabData('activity')} /> : tabDataLoading.activity ? <TabSkeleton /> : activity.length > 0 ? (
                <ol className="space-y-3">
                  {activity.map((entry, index) => (
                    <li key={`${entry.type}-${entry.occurred_at}-${index}`} className="flex gap-3 rounded-xl border border-hub-border p-4">
                      <span className="mt-1 h-2.5 w-2.5 flex-none rounded-full bg-hub-blue" aria-hidden="true" />
                      <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                          <p className="font-semibold text-hub-ink">{entry.label}</p>
                          <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${getStatusBadgeClass(entry.status)}`}>{entry.status.replaceAll('_', ' ')}</span>
                        </div>
                        <p className="mt-1 text-xs uppercase tracking-wide text-hub-muted">{entry.type.replaceAll('_', ' ')} · {formatDate(entry.occurred_at)}</p>
                      </div>
                    </li>
                  ))}
                </ol>
              ) : <EmptyState icon="request" title="No station activity yet" subtitle="Inspections, inventory, supply requests, station requests, and apparatus service tickets will appear here." />}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

// ===== Helper Components =====

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
      <section aria-labelledby="daily-checkout-heading" className="rounded-xl border border-hub-warning/30 bg-hub-warning/10 p-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 id="daily-checkout-heading" className="font-heading text-lg font-semibold text-hub-ink">Daily Checkout</h2>
          <span className="rounded-full bg-hub-warning/10 px-2.5 py-1 text-xs font-semibold text-hub-warning">Unavailable</span>
        </div>
        <p className="mt-2 text-sm text-hub-warning">The authoritative Daily Checkout result is unavailable. Readiness is not estimated from inspection records.</p>
      </section>
    );
  }

  const completionLabel = dailyCheckout.completion_available && dailyCheckout.completion_percent !== null
    ? `${dailyCheckout.completion_percent}%`
    : 'Completion unavailable';
  const summaryItems = [
    { label: 'Checked', value: dailyCheckout.checked, className: 'bg-hub-success/10 text-hub-success' },
    { label: 'Attention', value: dailyCheckout.attention, className: 'bg-hub-warning/10 text-hub-warning' },
    { label: 'Submitted', value: dailyCheckout.review_pending, className: 'bg-hub-blue/10 text-hub-blue' },
    { label: 'Not checked', value: dailyCheckout.not_checked, className: 'bg-hub-danger/10 text-hub-danger' },
    { label: 'Out of service', value: dailyCheckout.out_of_service, className: 'bg-hub-surface-muted text-hub-ink-secondary' },
    { label: 'Exempt', value: dailyCheckout.exempt, className: 'bg-hub-surface-muted text-hub-ink-secondary' },
    { label: 'Classification required', value: dailyCheckout.classification_required, className: 'bg-hub-warning/10 text-hub-warning' },
  ];

  return (
    <section aria-labelledby="daily-checkout-heading" className="rounded-xl border border-hub-border bg-hub-canvas/50 p-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h2 id="daily-checkout-heading" className="font-heading text-lg font-semibold text-hub-ink">Daily Checkout</h2>
          {dailyCheckout.completion_available ? (
            <p className="mt-1 text-sm text-hub-ink-secondary">{dailyCheckout.completed} / {dailyCheckout.required_total} required inspections completed</p>
          ) : (
            <p className="mt-1 text-sm text-hub-ink-secondary">No required apparatus — completion unavailable</p>
          )}
        </div>
        <span className="w-fit rounded-full bg-hub-blue/10 px-3 py-1 text-sm font-bold tabular-nums text-hub-blue">{completionLabel}</span>
      </div>

      <dl className="mt-4 flex flex-wrap gap-2">
        {summaryItems.map((item) => (
          <div key={item.label} className={`inline-flex items-baseline gap-2 rounded-full px-3 py-2 ${item.className}`}>
            <dt className="text-xs font-medium">{item.label}</dt>
            <dd className="text-sm font-bold tabular-nums">{item.value}</dd>
          </div>
        ))}
      </dl>

    </section>
  );
}

function dailyCheckoutRequirementLabel(requirement: Apparatus['daily_checkout_requirement']): string {
  if (!requirement) return 'Unavailable';

  const label = requirement.replaceAll('_', ' ');

  return label.charAt(0).toUpperCase() + label.slice(1);
}

function dailyCheckoutStatePresentation(row: DailyCheckoutMatrixRow): { label: string; className: string } {
  const labels: Record<DailyCheckoutMatrixRow['state'], { label: string; className: string }> = {
    checked: { label: 'Checked', className: 'bg-hub-success/10 text-hub-success' },
    attention: { label: 'Attention', className: 'bg-hub-warning/10 text-hub-warning' },
    review_pending: { label: 'Submitted', className: 'bg-hub-blue/10 text-hub-blue' },
    not_checked: { label: 'Not checked', className: 'bg-hub-danger/10 text-hub-danger' },
    out_of_service: { label: 'Out of service', className: 'bg-hub-surface-muted text-hub-ink-secondary' },
    exempt: { label: 'Exempt', className: 'bg-hub-surface-muted text-hub-ink-secondary' },
    classification_required: { label: 'Classification required', className: 'bg-hub-warning/10 text-hub-warning' },
  };

  return labels[row.state];
}

function TabSkeleton() {
  return (
    <div className="space-y-3">
      {[1, 2, 3].map(i => (
        <div key={i} className="skeleton h-16 w-full rounded-lg"></div>
      ))}
    </div>
  );
}

function TabLoadError({ message, onRetry }: { message: string; onRetry: () => void }) {
  return <div role="alert" className="rounded-xl border border-hub-danger/30 bg-hub-danger/10 p-5 text-hub-danger"><p className="font-semibold">{message}</p><button type="button" onClick={onRetry} className="mt-4 min-h-12 rounded-xl bg-hub-blue px-5 font-semibold text-white hover:bg-hub-blue-strong">Retry</button></div>;
}

function EmptyState({ icon, title, subtitle }: { icon: string; title: string; subtitle: string }) {
  const iconPaths: Record<string, string> = {
    room: 'M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 00.75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 00-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0112 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 01-.673-.38m0 0A2.18 2.18 0 013 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 013.413-.387m7.5 0V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25v.894m7.5 0a48.667 48.667 0 00-7.5 0M12 12.75h.008v.008H12v-.008z',
    apparatus: 'M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12',
    meter: 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z',
    request: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
    inspection: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
  };

  return (
    <div className="text-center py-12">
      <svg className="w-12 h-12 mx-auto mb-3 text-hub-muted-soft" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d={iconPaths[icon] || iconPaths.room} />
      </svg>
      <p className="text-hub-ink-secondary font-medium mb-1">{title}</p>
      <p className="text-hub-muted text-sm">{subtitle}</p>
    </div>
  );
}
