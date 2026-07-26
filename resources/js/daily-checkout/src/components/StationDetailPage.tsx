import { useState, useEffect, useRef, useCallback } from 'react';
import { Link, useParams } from 'react-router';
import {
  StationDetail,
  Apparatus,
  ApparatusInspectionSummary,
  StationInspectionSummary,
  FireEquipmentRequestSummary,
  SingleGasMeterSummary,
} from '../types';
import { ApiClient } from '../utils/api';

type TabId = 'overview' | 'rooms' | 'apparatus' | 'gas-meters' | 'equipment-requests' | 'inspections';

export default function StationDetailPage() {
  const { id } = useParams<{ id: string }>();
  const [station, setStation] = useState<StationDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<TabId>('overview');

  // Today's apparatus inspections
  const [todayInspections, setTodayInspections] = useState<ApparatusInspectionSummary[]>([]);
  const [todayInspectionsLoading, setTodayInspectionsLoading] = useState(true);

  // Tab data (lazy loaded)
  const [stationInspections, setStationInspections] = useState<StationInspectionSummary[]>([]);
  const [equipmentRequests, setEquipmentRequests] = useState<FireEquipmentRequestSummary[]>([]);
  const [gasMeters, setGasMeters] = useState<SingleGasMeterSummary[]>([]);
  const [tabDataLoaded, setTabDataLoaded] = useState<Record<string, boolean>>({});
  const [tabDataLoading, setTabDataLoading] = useState<Record<string, boolean>>({});

  // Sliding underline refs
  const tabContainerRef = useRef<HTMLDivElement>(null);
  const tabRefs = useRef<Record<string, HTMLButtonElement | null>>({});
  const [underlineStyle, setUnderlineStyle] = useState<{ left: number; width: number }>({ left: 0, width: 0 });

  const tabs: { id: TabId; label: string }[] = [
    { id: 'overview', label: 'Overview' },
    { id: 'rooms', label: 'Rooms' },
    { id: 'apparatus', label: 'Apparatus' },
    { id: 'gas-meters', label: 'Gas Meters' },
    { id: 'equipment-requests', label: 'Equipment Requests' },
    { id: 'inspections', label: 'Inspections' },
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

    const fetchStation = async () => {
      try {
        const data = await ApiClient.getStation(stationId);
        setStation(data);
        setError(null);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load station');
      } finally {
        setLoading(false);
      }
    };

    const fetchTodayInspections = async () => {
      try {
        const data = await ApiClient.getTodayApparatusInspections(stationId);
        setTodayInspections(data);
      } catch {
        // Non-critical, silently fail
      } finally {
        setTodayInspectionsLoading(false);
      }
    };

    fetchStation();
    fetchTodayInspections();
  }, [id]);

  // Lazy load tab data when tab changes
  useEffect(() => {
    if (!id || tabDataLoaded[activeTab]) return;
    const stationId = parseInt(id);

    const loadTabData = async () => {
      setTabDataLoading(prev => ({ ...prev, [activeTab]: true }));
      try {
        switch (activeTab) {
          case 'inspections': {
            const data = await ApiClient.getStationInspections(stationId);
            setStationInspections(data);
            break;
          }
          case 'equipment-requests': {
            const data = await ApiClient.getEquipmentRequests(stationId);
            setEquipmentRequests(data);
            break;
          }
          case 'gas-meters': {
            const data = await ApiClient.getGasMeters(stationId);
            setGasMeters(data);
            break;
          }
        }
      } catch {
        // Tab data load failure is non-critical
      } finally {
        setTabDataLoaded(prev => ({ ...prev, [activeTab]: true }));
        setTabDataLoading(prev => ({ ...prev, [activeTab]: false }));
      }
    };

    if (['inspections', 'equipment-requests', 'gas-meters'].includes(activeTab)) {
      loadTabData();
    }
  }, [activeTab, id, tabDataLoaded]);

  const getStatusBadgeClass = (status: string): string => {
    const map: Record<string, string> = {
      pass: 'bg-green-100 text-green-800',
      fail: 'bg-red-100 text-red-800',
      needs_attention: 'bg-amber-100 text-amber-800',
      pending: 'bg-blue-100 text-blue-800',
      approved: 'bg-green-100 text-green-800',
      denied: 'bg-red-100 text-red-800',
      fulfilled: 'bg-teal-100 text-teal-800',
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
        <Link
          to="/stations"
          className="mt-4 inline-block px-5 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors"
        >
          Back to Stations
        </Link>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Back button and header */}
      <div className="flex items-center justify-between">
        <Link
          to="/stations"
          className="inline-flex items-center text-neutral-500 hover:text-neutral-800"
        >
          <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
          Back to Stations
        </Link>
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
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
          <Link
            to={`/forms-hub/big-ticket-request`}
            className="flex items-center gap-2.5 p-3 bg-neutral-50 rounded-xl ring-1 ring-neutral-200/60 hover:bg-red-50 hover:ring-red-200 transition-all text-sm font-medium text-neutral-700 hover:text-red-700"
          >
            <svg className="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
            </svg>
            Big Ticket Request
          </Link>
          <Link
            to={`/forms-hub/equipment-request`}
            className="flex items-center gap-2.5 p-3 bg-neutral-50 rounded-xl ring-1 ring-neutral-200/60 hover:bg-sky-50 hover:ring-sky-200 transition-all text-sm font-medium text-neutral-700 hover:text-sky-700"
          >
            <svg className="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
            </svg>
            Equipment Request
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
                    <dt className="text-neutral-500">Active Apparatuses</dt>
                    <dd className="font-medium text-neutral-800 tabular-nums">{station.active_apparatuses_count || 0}</dd>
                  </div>
                  <div className="flex justify-between py-2.5 border-b border-neutral-200">
                    <dt className="text-neutral-500">Personnel</dt>
                    <dd className="font-medium text-neutral-800 tabular-nums">{station.personnel_count || 0}</dd>
                  </div>
                  <div className="flex justify-between py-2.5 border-b border-neutral-200 bg-neutral-50/50">
                    <dt className="text-neutral-500">Dorm Beds</dt>
                    <dd className="font-medium text-neutral-800 tabular-nums">{station.dorm_beds_count || 0}</dd>
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
                <div className="space-y-3 stagger-list">
                  {station.rooms.map((room) => (
                    <Link
                      key={room.id}
                      to={`/stations/${station.id}/rooms/${room.id}`}
                      className="block p-4 border border-neutral-200 rounded-lg hover:bg-neutral-50 hover-lift transition-all duration-200"
                    >
                      <div className="flex justify-between items-center">
                        <div>
                          <h4 className="font-semibold text-neutral-800">{room.name}</h4>
                          <p className="text-sm text-neutral-600">
                            {room.room_number && `Room ${room.room_number} \u2022 `}
                            <span className="capitalize">{(room.type || '').replace('_', ' ')}</span>
                          </p>
                        </div>
                        <div className="text-right text-sm text-neutral-500 tabular-nums">
                          <p>{room.assets_count || 0} assets</p>
                          <p>{room.audits_count || 0} audits</p>
                        </div>
                      </div>
                    </Link>
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
              {tabDataLoading['gas-meters'] ? (
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

          {/* ========== EQUIPMENT REQUESTS TAB ========== */}
          {activeTab === 'equipment-requests' && (
            <div>
              {tabDataLoading['equipment-requests'] ? (
                <TabSkeleton />
              ) : equipmentRequests.length > 0 ? (
                <div className="space-y-3 stagger-list">
                  {equipmentRequests.map((req) => (
                    <div
                      key={req.id}
                      className="p-4 border border-neutral-200 rounded-lg"
                    >
                      <div className="flex justify-between items-start mb-2">
                        <div>
                          <p className="font-semibold text-neutral-800">{req.equipment_type}</p>
                          <p className="text-sm text-neutral-600 mt-0.5">{req.description}</p>
                        </div>
                        <div className="flex gap-2 flex-shrink-0 ml-4">
                          <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getStatusBadgeClass(req.priority)}`}>
                            {req.priority}
                          </span>
                          <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getStatusBadgeClass(req.status)}`}>
                            {req.status}
                          </span>
                        </div>
                      </div>
                      <p className="text-xs text-neutral-500">
                        Requested by {req.requested_by_name} &middot; {formatDate(req.created_at)}
                      </p>
                    </div>
                  ))}
                </div>
              ) : (
                <EmptyState icon="request" title="No equipment requests" subtitle="Fire equipment requests for this station will appear here." />
              )}
            </div>
          )}

          {/* ========== STATION INSPECTIONS TAB ========== */}
          {activeTab === 'inspections' && (
            <div>
              {tabDataLoading['inspections'] ? (
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
