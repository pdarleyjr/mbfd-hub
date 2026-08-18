import { useState, useEffect, useRef, useCallback } from 'react';
import { Link, useParams } from 'react-router';
import {
  StationDetail,
  Apparatus,
  ApparatusInspectionSummary,
  StationInspectionSummary,
  StationRequestSummary,
  StationActivityEntry,
  SingleGasMeterSummary,
} from '../types';
import { ApiClient } from '../utils/api';
import PreviousPageButton from './PreviousPageButton';
import { groupRoomsByArea, stationComplement } from '../utils/stationRoomBlueprint';

type TabId = 'requests' | 'overview' | 'rooms' | 'apparatus' | 'gas-meters' | 'inspections' | 'activity';

export default function StationDetailPage() {
  const { id } = useParams<{ id: string }>();
  const [station, setStation] = useState<StationDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [stationLoadAttempt, setStationLoadAttempt] = useState(0);
  const [activeTab, setActiveTab] = useState<TabId>('requests');

  // Today's apparatus inspections
  const [todayInspections, setTodayInspections] = useState<ApparatusInspectionSummary[]>([]);
  const [todayInspectionsLoading, setTodayInspectionsLoading] = useState(true);

  // Tab data (lazy loaded)
  const [stationInspections, setStationInspections] = useState<StationInspectionSummary[]>([]);
  const [stationRequests, setStationRequests] = useState<StationRequestSummary[]>([]);
  const [requestScope, setRequestScope] = useState<'open' | 'all'>('open');
  const [activity, setActivity] = useState<StationActivityEntry[]>([]);
  const [gasMeters, setGasMeters] = useState<SingleGasMeterSummary[]>([]);
  const [tabDataLoaded, setTabDataLoaded] = useState<Record<string, boolean>>({});
  const [tabDataLoading, setTabDataLoading] = useState<Record<string, boolean>>({});
  const [tabDataError, setTabDataError] = useState<Record<string, string>>({});

  // Sliding underline refs
  const tabContainerRef = useRef<HTMLDivElement>(null);
  const tabRefs = useRef<Record<string, HTMLButtonElement | null>>({});
  const [underlineStyle, setUnderlineStyle] = useState<{ left: number; width: number }>({ left: 0, width: 0 });

  const tabs: { id: TabId; label: string }[] = [
    { id: 'requests', label: 'Requests' },
    { id: 'overview', label: 'Overview' },
    { id: 'rooms', label: 'Rooms' },
    { id: 'apparatus', label: 'Apparatus' },
    { id: 'gas-meters', label: 'Gas Meters' },
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
    setTodayInspectionsLoading(true);

    const fetchStation = async () => {
      try {
        const data = await ApiClient.getStation(stationId);
        if (cancelled) return;
        setStation(data);
        setError(null);
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Failed to load station');
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    const fetchTodayInspections = async () => {
      try {
        const data = await ApiClient.getTodayApparatusInspections(stationId);
        if (!cancelled) setTodayInspections(data);
      } catch {
        // Non-critical, silently fail
      } finally {
        if (!cancelled) setTodayInspectionsLoading(false);
      }
    };

    fetchStation();
    fetchTodayInspections();
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

    if (['inspections', 'requests', 'gas-meters', 'activity'].includes(activeTab)) {
      loadTabData();
    }
  }, [activeTab, id, tabDataLoaded]);

  const retryTabData = (tab: TabId) => {
    setTabDataError(prev => ({ ...prev, [tab]: '' }));
    setTabDataLoaded(prev => ({ ...prev, [tab]: false }));
  };

  const getStatusBadgeClass = (status: string): string => {
    const map: Record<string, string> = {
      pass: 'bg-green-100 text-green-800',
      fail: 'bg-red-100 text-red-800',
      needs_attention: 'bg-amber-100 text-amber-800',
      pending: 'bg-blue-100 text-blue-800',
      approved: 'bg-green-100 text-green-800',
      denied: 'bg-red-100 text-red-800',
      fulfilled: 'bg-teal-100 text-teal-800',
      acknowledged: 'bg-amber-100 text-amber-800',
      under_review: 'bg-amber-100 text-amber-800',
      scheduled: 'bg-blue-100 text-blue-800',
      ordered: 'bg-blue-100 text-blue-800',
      in_progress: 'bg-blue-100 text-blue-800',
      awaiting_parts: 'bg-stone-100 text-stone-700',
      awaiting_vendor: 'bg-stone-100 text-stone-700',
      on_hold: 'bg-stone-100 text-stone-700',
      completed: 'bg-green-100 text-green-800',
      cancelled: 'bg-red-100 text-red-800',
      low: 'bg-neutral-100 text-neutral-700',
      medium: 'bg-blue-100 text-blue-800',
      high: 'bg-orange-100 text-orange-800',
      critical: 'bg-red-100 text-red-800',
    };
    return map[status] ?? 'bg-neutral-100 text-neutral-700';
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
      <div className="space-y-6">
        <div className="skeleton h-6 w-32"></div>
        <div className="bg-white rounded-2xl ring-1 ring-neutral-200/80 p-6">
          <div className="skeleton h-8 w-48 mb-2"></div>
          <div className="skeleton h-5 w-64 mb-2"></div>
          <div className="skeleton h-4 w-80 mb-6"></div>
          <div className="flex flex-wrap gap-4">
            {[1, 2, 3, 4].map(i => <div key={i} className="skeleton h-10 w-36"></div>)}
          </div>
        </div>
        <div className="bg-white rounded-2xl ring-1 ring-neutral-200/80 p-6">
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
      <div className="text-center p-8">
        <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-red-50 mb-4">
          <svg className="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
          </svg>
        </div>
        <p className="text-red-600 font-medium mb-2">{error || 'Station not found'}</p>
        <div className="mt-4 flex flex-wrap justify-center gap-3">
          <button type="button" onClick={() => setStationLoadAttempt((attempt) => attempt + 1)} className="min-h-12 rounded-lg bg-blue-700 px-5 font-semibold text-white hover:bg-blue-800">Retry</button>
          <PreviousPageButton className="inline-flex min-h-12 items-center rounded-lg bg-red-600 px-5 font-semibold text-white transition-colors hover:bg-red-700" />
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

  return (
    <div className="space-y-6">
      {/* Back button and header */}
      <div className="flex items-center justify-between">
        <PreviousPageButton
          className="inline-flex items-center text-neutral-500 hover:text-neutral-800"
        >
          <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
          Back to previous page
        </PreviousPageButton>
        {station.is_active ? (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Active</span>
        ) : (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">Inactive</span>
        )}
      </div>

      {/* ============================== */}
      {/* FIRST CARD: Station Info + Quick Links + Today's Inspections */}
      {/* ============================== */}
      <div className="bg-white rounded-2xl ring-1 ring-neutral-200/80 p-6">
        <div className="flex flex-col md:flex-row md:items-start md:justify-between mb-6">
          <div>
            <h1 className="text-3xl font-bold text-neutral-800 mb-2 font-heading">
              Station {station.station_number}
            </h1>
            <p className="text-neutral-500 mt-1">
              {station.address}, {station.city}, {station.state} {station.zip_code}
            </p>
            {station.phone && (
              <p className="text-neutral-500 mt-1 flex items-center gap-2">
                <svg className="w-4 h-4 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                </svg>
                {station.phone}
              </p>
            )}
          </div>
        </div>

        {/* Quick Links */}
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-3 mb-6">
          {[1, 2, 3, 4, 6].includes(station.station_number) && (
            <a
              href={`/employee/video-conferencing?room=lineup&join_as=sta${station.station_number}`}
              className="flex min-h-12 items-center gap-2.5 p-3 bg-blue-50 rounded-xl ring-1 ring-blue-200/80 hover:bg-blue-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700 transition-colors text-sm font-semibold text-blue-800"
            >
              <svg className="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
              </svg>
              Morning Lineup
            </a>
          )}
          <a
            href={`/employee/personnel-equipment-request?station_id=${station.id}&return_to=${encodeURIComponent(`/daily/stations/${station.id}`)}`}
            className="flex min-h-12 items-center gap-2.5 p-3 bg-amber-50 rounded-xl ring-1 ring-amber-300/80 hover:bg-amber-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700 transition-colors text-sm font-semibold text-amber-950"
          >
            <svg className="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.955 11.955 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
            </svg>
            Personnel Equipment Request
          </a>
          <Link
            to={`/forms-hub/station-request?station_id=${station.id}&return_to=${encodeURIComponent(`/stations/${station.id}`)}`}
            className="flex min-h-12 items-center gap-2.5 p-3 bg-blue-50 rounded-xl ring-1 ring-blue-200/80 hover:bg-blue-100 transition-all text-sm font-semibold text-blue-800"
          >
            <svg className="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
            </svg>
            Station Request
          </Link>
          <Link
            to={`/forms-hub/station-inspection`}
            className="flex items-center gap-2.5 p-3 bg-neutral-50 rounded-xl ring-1 ring-neutral-200/60 hover:bg-teal-50 hover:ring-teal-200 transition-all text-sm font-medium text-neutral-700 hover:text-teal-700"
          >
            <svg className="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Station Inspection
          </Link>
          <Link
            to={`/vehicle-inspections`}
            className="flex items-center gap-2.5 p-3 bg-neutral-50 rounded-xl ring-1 ring-neutral-200/60 hover:bg-amber-50 hover:ring-amber-200 transition-all text-sm font-medium text-neutral-700 hover:text-amber-700"
          >
            <svg className="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
            </svg>
            Vehicle Inspection
          </Link>
        </div>

        {/* Today's Vehicle Inspections */}
        <div>
          <h3 className="text-sm font-semibold text-neutral-500 uppercase tracking-wider mb-3">
            Today's Vehicle Inspections
          </h3>
          {todayInspectionsLoading ? (
            <div className="space-y-2">
              {[1, 2].map(i => (
                <div key={i} className="skeleton h-12 w-full rounded-lg"></div>
              ))}
            </div>
          ) : todayInspections.length > 0 ? (
            <div className="space-y-2">
              {todayInspections.map((inspection) => (
                <div
                  key={inspection.id}
                  className="flex items-center justify-between p-3 bg-neutral-50 rounded-lg ring-1 ring-neutral-200/60"
                >
                  <div className="flex items-center gap-3">
                    <div className={`w-2 h-2 rounded-full flex-shrink-0 ${inspection.defect_count > 0 ? 'bg-amber-500' : 'bg-green-500'}`} />
                    <div>
                      <p className="text-sm font-medium text-neutral-800">{inspection.apparatus_name}</p>
                      <p className="text-xs text-neutral-500">
                        {inspection.operator_name} &middot; {inspection.shift} Shift
                      </p>
                    </div>
                  </div>
                  <div className="text-right">
                    <p className="text-xs text-neutral-500">{formatTime(inspection.completed_at)}</p>
                    {inspection.defect_count > 0 && (
                      <p className="text-xs text-amber-600 font-medium">{inspection.defect_count} defect{inspection.defect_count !== 1 ? 's' : ''}</p>
                    )}
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-sm text-neutral-400 italic py-2">No inspections submitted today</p>
          )}
        </div>
      </div>

      {/* ============================== */}
      {/* SECOND CARD: Tabbed Detail View */}
      {/* ============================== */}
      <div className="bg-white rounded-2xl ring-1 ring-neutral-200/80 overflow-hidden">
        {/* Tab Bar */}
        <div
          ref={tabContainerRef}
          className="relative flex overflow-x-auto border-b border-neutral-200 scroll-snap-x-mandatory"
        >
          {tabs.map((tab) => (
            <button
              key={tab.id}
              ref={(el) => { tabRefs.current[tab.id] = el; }}
              onClick={() => setActiveTab(tab.id)}
              className={`min-h-[48px] px-5 py-3 text-sm font-medium whitespace-nowrap transition-colors flex-shrink-0 scroll-snap-align-start ${
                activeTab === tab.id
                  ? 'text-red-600 bg-red-50/50'
                  : 'text-neutral-500 hover:text-neutral-800 hover:bg-neutral-50'
              }`}
            >
              {tab.label}
            </button>
          ))}
          <div
            className="absolute bottom-0 h-0.5 bg-red-600 transition-all duration-250"
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
                <h3 className="text-lg font-semibold text-neutral-800 mb-4 font-heading">Station Information</h3>
                <dl className="space-y-0">
                  <div className="flex justify-between py-2.5 border-b border-neutral-200">
                    <dt className="text-neutral-500">Station Number</dt>
                    <dd className="font-medium text-neutral-800 tabular-nums">{station.station_number}</dd>
                  </div>
                  <div className="flex justify-between py-2.5 border-b border-neutral-200 bg-neutral-50/50">
                    <dt className="text-neutral-500">Assigned Apparatus</dt>
                    <dd className="font-medium text-neutral-800 tabular-nums">{assignedApparatusCount ?? 'Unknown'}</dd>
                  </div>
                  <div className="flex justify-between py-2.5 border-b border-neutral-200">
                    <dt className="text-neutral-500">Assigned Personnel</dt>
                    <dd className="font-medium text-neutral-800 tabular-nums">{assignedPersonnelCount ?? 'Unknown'}</dd>
                  </div>
                  <div className="flex justify-between py-2.5 border-b border-neutral-200 bg-neutral-50/50">
                    <dt className="text-neutral-500">Dorm Beds</dt>
                    <dd className="font-medium text-neutral-800 tabular-nums">{dormBedsCount ?? 'Unknown'}</dd>
                  </div>
                  <div className="grid gap-1 py-2.5 border-b border-neutral-200">
                    <dt className="text-neutral-500">Assigned Units</dt>
                    <dd className="font-medium text-neutral-800">{assignedUnits.length ? assignedUnits.join(' · ') : 'Unknown'}</dd>
                  </div>
                  {station.fax && (
                    <div className="flex justify-between py-2.5 border-b border-neutral-200">
                      <dt className="text-neutral-500">Fax</dt>
                      <dd className="font-medium text-neutral-800">{station.fax}</dd>
                    </div>
                  )}
                </dl>
              </div>
              <div>
                <h3 className="text-lg font-semibold text-neutral-800 mb-4 font-heading">Location</h3>
                <dl className="space-y-0">
                  <div className="flex justify-between py-2.5 border-b border-neutral-200">
                    <dt className="text-neutral-500">Address</dt>
                    <dd className="font-medium text-right text-neutral-800">{station.address}</dd>
                  </div>
                  <div className="flex justify-between py-2.5 border-b border-neutral-200 bg-neutral-50/50">
                    <dt className="text-neutral-500">City</dt>
                    <dd className="font-medium text-neutral-800">{station.city}</dd>
                  </div>
                  <div className="flex justify-between py-2.5 border-b border-neutral-200">
                    <dt className="text-neutral-500">State</dt>
                    <dd className="font-medium text-neutral-800">{station.state}</dd>
                  </div>
                  <div className="flex justify-between py-2.5 border-b border-neutral-200 bg-neutral-50/50">
                    <dt className="text-neutral-500">ZIP Code</dt>
                    <dd className="font-medium text-neutral-800">{station.zip_code}</dd>
                  </div>
                  {station.latitude && station.longitude && (
                    <div className="flex justify-between py-2.5 border-b border-neutral-200">
                      <dt className="text-neutral-500">Coordinates</dt>
                      <dd className="font-medium text-neutral-800 tabular-nums">{station.latitude}, {station.longitude}</dd>
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
                      <div className="mb-3 flex flex-wrap items-end justify-between gap-2 border-b border-neutral-200 pb-2">
                        <h3 id={`room-area-${group.key}`} className="font-heading text-lg font-semibold text-neutral-800">{group.label}</h3>
                        {group.key === 'dormitory' && <p aria-label="Dorm positions" className="text-sm font-semibold tabular-nums text-blue-800">{group.dormPositions} dorm positions</p>}
                      </div>
                      <div className="grid gap-3 md:grid-cols-2">
                        {group.rooms.map((room) => (
                          <Link
                            key={room.id}
                            to={`/stations/${station.id}/rooms/${room.id}`}
                            className="block min-h-24 rounded-xl border border-neutral-200 p-4 transition-all duration-200 hover-lift hover:border-blue-300 hover:bg-blue-50/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700"
                          >
                            <div className="flex items-start justify-between gap-4">
                              <div>
                                <h4 className="font-semibold text-neutral-800">{room.name}</h4>
                                <p className="mt-1 text-sm text-neutral-600">
                                  {room.capacity ? `${room.capacity} position${room.capacity === 1 ? '' : 's'}` : 'Station area'}
                                </p>
                              </div>
                              <div className="shrink-0 text-right text-sm text-neutral-500 tabular-nums">
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
            <div>
              {station.apparatuses && station.apparatuses.length > 0 ? (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 stagger-list">
                  {station.apparatuses.map((apparatus: Apparatus) => (
                    <div
                      key={apparatus.id}
                      className="p-4 border border-neutral-200 rounded-lg hover:bg-neutral-50 transition-all duration-200"
                    >
                      <h4 className="font-semibold text-neutral-800">{apparatus.name || apparatus.unit_id}</h4>
                      <p className="text-sm text-neutral-600">Unit: {apparatus.vehicle_number}</p>
                      <p className="text-sm text-neutral-500 capitalize">Type: {apparatus.type}</p>
                      {apparatus.slug && (
                        <Link
                          to={`/vehicle-inspections/${apparatus.slug}`}
                          className="mt-2 inline-flex items-center text-xs text-red-600 font-medium hover:text-red-700"
                        >
                          Start Inspection
                          <svg className="ml-1 w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" /></svg>
                        </Link>
                      )}
                    </div>
                  ))}
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
                      className="flex items-center justify-between p-4 border border-neutral-200 rounded-lg"
                    >
                      <div>
                        <p className="font-semibold text-neutral-800">S/N: {meter.serial_number}</p>
                        <p className="text-sm text-neutral-600">Assigned to: {meter.apparatus_name}</p>
                        <p className="text-sm text-neutral-500">
                          Activated: {formatDate(meter.activation_date)} &middot; Expires: {formatDate(meter.expiration_date)}
                        </p>
                      </div>
                      <div className="text-right">
                        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                          meter.status === 'Valid' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                        }`}>
                          {meter.status}
                        </span>
                        {meter.status === 'Valid' && (
                          <p className="text-xs text-neutral-500 mt-1">{meter.days_until_expiration}d remaining</p>
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
                  <h3 className="font-heading text-lg font-bold text-neutral-900">Station requests</h3>
                  <p className="text-sm text-neutral-500">Repair, service, and equipment history in one queue.</p>
                </div>
                <div className="inline-flex rounded-xl bg-neutral-100 p-1" aria-label="Request history filter">
                  <button type="button" onClick={() => setRequestScope('open')} className={`min-h-11 rounded-lg px-4 text-sm font-semibold ${requestScope === 'open' ? 'bg-white text-blue-800 shadow-sm' : 'text-neutral-600'}`}>Open</button>
                  <button type="button" onClick={() => setRequestScope('all')} className={`min-h-11 rounded-lg px-4 text-sm font-semibold ${requestScope === 'all' ? 'bg-white text-blue-800 shadow-sm' : 'text-neutral-600'}`}>All history</button>
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
                      className="rounded-xl border border-neutral-200 p-4"
                    >
                      <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                        <div>
                          <p className="font-mono text-xs font-semibold text-neutral-500">{req.request_number}</p>
                          <p className="mt-1 font-semibold text-neutral-900">{req.title}</p>
                          <p className="text-sm text-neutral-600 mt-0.5">{req.description}</p>
                        </div>
                        <div className="flex flex-wrap gap-2 flex-shrink-0">
                          <span className="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-800">{req.request_type === 'repair_service' ? 'Repair / Service' : 'Equipment'}</span>
                          <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getStatusBadgeClass(req.priority)}`}>
                            {req.priority}
                          </span>
                          <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getStatusBadgeClass(req.status)}`}>
                            {req.status}
                          </span>
                        </div>
                      </div>
                      {req.current_public_response && <div className="mt-3 rounded-lg bg-blue-50 p-3 text-sm text-blue-900"><span className="font-semibold">Latest response:</span> {req.current_public_response}</div>}
                      <p className="mt-3 text-xs text-neutral-500">
                        {req.room?.name || req.room_name_snapshot || 'Station-wide'} &middot; Submitted {formatDate(req.created_at)}
                      </p>
                      {req.updates && req.updates.length > 1 && <details className="mt-3"><summary className="min-h-11 cursor-pointer py-2 text-sm font-semibold text-blue-800">View {req.updates.length} updates</summary><ol className="mt-2 space-y-2 border-l-2 border-blue-100 pl-4">{req.updates.map((update) => <li key={update.id} className="text-sm text-neutral-600"><span className="font-semibold text-neutral-800">{update.status.replaceAll('_', ' ')}</span> · {formatDate(update.created_at)}{update.public_note && <p className="mt-0.5">{update.public_note}</p>}</li>)}</ol></details>}
                    </div>
                  ))}
                </div>
              ) : (
                <EmptyState icon="request" title={requestScope === 'open' ? 'No open station requests' : 'No station request history'} subtitle="New repair, service, and equipment requests will appear here." />
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
                      className="p-4 border border-neutral-200 rounded-lg"
                    >
                      <div className="flex justify-between items-start mb-2">
                        <div>
                          <p className="font-semibold text-neutral-800">
                            {inspection.inspection_type || 'Station Inspection'}
                          </p>
                          <p className="text-sm text-neutral-600">
                            Inspector: {inspection.inspector_name}
                          </p>
                        </div>
                        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getStatusBadgeClass(inspection.overall_status)}`}>
                          {(inspection.overall_status || '').replace('_', ' ')}
                        </span>
                      </div>
                      <p className="text-xs text-neutral-500">
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
                    <li key={`${entry.type}-${entry.occurred_at}-${index}`} className="flex gap-3 rounded-xl border border-neutral-200 p-4">
                      <span className="mt-1 h-2.5 w-2.5 flex-none rounded-full bg-blue-600" aria-hidden="true" />
                      <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                          <p className="font-semibold text-neutral-900">{entry.label}</p>
                          <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${getStatusBadgeClass(entry.status)}`}>{entry.status.replaceAll('_', ' ')}</span>
                        </div>
                        <p className="mt-1 text-xs uppercase tracking-wide text-neutral-500">{entry.type.replaceAll('_', ' ')} · {formatDate(entry.occurred_at)}</p>
                      </div>
                    </li>
                  ))}
                </ol>
              ) : <EmptyState icon="request" title="No station activity yet" subtitle="Inspections, inventory, supply requests, and station requests will appear here." />}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

// ===== Helper Components =====

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
  return <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-5 text-red-800"><p className="font-semibold">{message}</p><button type="button" onClick={onRetry} className="mt-4 min-h-12 rounded-xl bg-blue-700 px-5 font-semibold text-white hover:bg-blue-800">Retry</button></div>;
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
      <svg className="w-12 h-12 mx-auto mb-3 text-neutral-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d={iconPaths[icon] || iconPaths.room} />
      </svg>
      <p className="text-neutral-600 font-medium mb-1">{title}</p>
      <p className="text-neutral-400 text-sm">{subtitle}</p>
    </div>
  );
}
