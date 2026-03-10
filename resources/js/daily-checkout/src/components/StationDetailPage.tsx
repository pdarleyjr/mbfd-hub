import { useState, useEffect, useRef, useCallback } from 'react';
import { Link, useParams } from 'react-router-dom';
import { StationDetail, Room, Apparatus, CapitalProject, ShopWork } from '../types';
import { ApiClient } from '../utils/api';

const enableApparatusForms = import.meta.env.VITE_ENABLE_APPARATUS_FORMS === 'true';

export default function StationDetailPage() {
  const { id } = useParams<{ id: string }>();
  const [station, setStation] = useState<StationDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'overview' | 'apparatuses' | 'rooms' | 'projects' | 'shopworks'>('overview');

  // Phase 4.3: Sliding underline refs
  const tabContainerRef = useRef<HTMLDivElement>(null);
  const tabRefs = useRef<Record<string, HTMLButtonElement | null>>({});
  const [underlineStyle, setUnderlineStyle] = useState<{ left: number; width: number }>({ left: 0, width: 0 });

  const tabs = [
    { id: 'overview', label: 'Overview' },
    ...(enableApparatusForms ? [{ id: 'apparatuses', label: 'Apparatuses' }] : []),
    { id: 'rooms', label: 'Rooms' },
    { id: 'projects', label: 'Projects' },
    { id: 'shopworks', label: 'Shop Works' },
  ];

  // Phase 4.3: Update underline position when active tab changes
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

  const fetchStation = async () => {
    if (!id) return;
    try {
      const data = await ApiClient.getStation(parseInt(id));
      setStation(data);
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load station');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchStation();
  }, [id]);

  const getStatusBadge = (isActive: boolean) => {
    if (isActive) {
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

  const getProjectStatusBadge = (status: string) => {
    const statusColors: Record<string, string> = {
      planning: 'bg-blue-100 text-blue-800',
      in_progress: 'bg-yellow-100 text-yellow-800',
      on_hold: 'bg-orange-100 text-orange-800',
      completed: 'bg-green-100 text-green-800',
      cancelled: 'bg-red-100 text-red-800',
    };
    const colorClass = statusColors[status] || 'bg-gray-100 text-gray-800';
    return (
      <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${colorClass}`}>
        {status.replace('_', ' ')}
      </span>
    );
  };

  if (loading) {
    return (
      <div className="space-y-6">
        <div className="skeleton h-6 w-32"></div>
        <div className="bg-neutral-100 rounded-xl ring-1 ring-neutral-200/60 p-6">
          <div className="skeleton h-8 w-48 mb-2"></div>
          <div className="skeleton h-5 w-64 mb-2"></div>
          <div className="skeleton h-4 w-80 mb-6"></div>
          <div className="flex flex-wrap gap-4">
            {[1,2,3,4,5].map(i => <div key={i} className="skeleton h-4 w-28"></div>)}
          </div>
        </div>
        <div className="bg-neutral-100 rounded-xl ring-1 ring-neutral-200/60 p-6">
          <div className="flex gap-4 mb-6">
            {[1,2,3,4].map(i => <div key={i} className="skeleton h-10 w-24"></div>)}
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
        {getStatusBadge(station.is_active)}
      </div>

      {/* Station Header */}
      <div className="bg-neutral-100 rounded-xl ring-1 ring-neutral-200/60 p-6">
        <div className="flex flex-col md:flex-row md:items-start md:justify-between">
          <div>
            <h1 className="text-3xl font-bold text-neutral-800 mb-2 font-heading">
              Station {station.station_number}
            </h1>
            <p className="text-xl text-neutral-600 font-medium">{station.name}</p>
            <p className="text-neutral-500 mt-2">
              {station.address}, {station.city}, {station.state} {station.zip_code}
            </p>
          </div>
          {station.phone && (
            <div className="mt-4 md:mt-0 flex items-center text-neutral-500">
              <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
              </svg>
              <span>{station.phone}</span>
            </div>
          )}
        </div>

        {/* Flat stat chips — no nested cards */}
        <div className="mt-6 flex flex-wrap gap-4 text-sm tabular-nums">
          {enableApparatusForms && (
            <span className="inline-flex items-center gap-1.5 text-amber-700 font-medium">
              <span className="w-2 h-2 rounded-full bg-amber-500"></span>
              {station.apparatuses_count || 0} Apparatuses
            </span>
          )}
          <span className="inline-flex items-center gap-1.5 text-teal-700 font-medium">
            <span className="w-2 h-2 rounded-full bg-teal-500"></span>
            {station.rooms_count || 0} Rooms
          </span>
          <span className="inline-flex items-center gap-1.5 text-sky-700 font-medium">
            <span className="w-2 h-2 rounded-full bg-sky-500"></span>
            {station.capital_projects_count || 0} Capital Projects
          </span>
          <span className="inline-flex items-center gap-1.5 text-amber-700 font-medium">
            <span className="w-2 h-2 rounded-full bg-amber-400"></span>
            {station.under_25k_projects_count || 0} Under 25K
          </span>
          <span className="inline-flex items-center gap-1.5 text-red-700 font-medium">
            <span className="w-2 h-2 rounded-full bg-red-500"></span>
            {station.shop_works_count || 0} Shop Works
          </span>
        </div>
      </div>

      {/* Tabs with sliding underline (Phase 4.3) + scroll-snap (Phase 6.3) */}
      <div className="bg-neutral-100 rounded-xl ring-1 ring-neutral-200/60 overflow-hidden">
        <div
          ref={tabContainerRef}
          className="relative flex overflow-x-auto border-b border-neutral-200 scroll-snap-x-mandatory"
        >
          {tabs.map((tab) => (
            <button
              key={tab.id}
              ref={(el) => { tabRefs.current[tab.id] = el; }}
              onClick={() => setActiveTab(tab.id as typeof activeTab)}
              className={`min-h-[48px] px-6 py-3 text-sm font-medium whitespace-nowrap transition-colors flex-shrink-0 scroll-snap-align-start ${
                activeTab === tab.id
                  ? 'text-red-600 bg-red-50/50'
                  : 'text-neutral-500 hover:text-neutral-800 hover:bg-neutral-50'
              }`}
            >
              {tab.label}
            </button>
          ))}
          {/* Animated sliding underline */}
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
          {/* Overview Tab */}
          {activeTab === 'overview' && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <h3 className="text-lg font-semibold text-neutral-800 mb-4 font-heading">Station Information</h3>
                <dl className="space-y-0">
                  <div className="flex justify-between py-2.5 border-b border-neutral-200">
                    <dt className="text-neutral-500">Station Number</dt>
                    <dd className="font-medium text-neutral-800 tabular-nums">{station.station_number}</dd>
                  </div>
                  {enableApparatusForms && (
                    <div className="flex justify-between py-2.5 border-b border-neutral-200 bg-neutral-50/50">
                      <dt className="text-neutral-500">Active Apparatuses</dt>
                      <dd className="font-medium text-neutral-800 tabular-nums">{station.active_apparatuses_count || 0}</dd>
                    </div>
                  )}
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

          {/* Apparatuses Tab */}
          {enableApparatusForms && activeTab === 'apparatuses' && (
            <div>
              {station.apparatuses && station.apparatuses.length > 0 ? (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 stagger-list">
                  {station.apparatuses.map((apparatus) => (
                    <div
                      key={apparatus.id}
                      className="p-4 border border-neutral-200 rounded-lg hover:bg-neutral-50 hover-lift transition-all duration-200"
                    >
                      <h4 className="font-semibold text-neutral-800">{apparatus.name || apparatus.unit_id}</h4>
                      <p className="text-sm text-neutral-600">Unit: {apparatus.vehicle_number}</p>
                      <p className="text-sm text-neutral-500 capitalize">Type: {apparatus.type}</p>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="text-center py-12">
                  <svg className="w-12 h-12 mx-auto mb-3 text-neutral-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                  </svg>
                  <p className="text-neutral-600 font-medium mb-1">No apparatuses assigned</p>
                  <p className="text-neutral-400 text-sm">Apparatuses will appear here when assigned to this station.</p>
                </div>
              )}
            </div>
          )}

          {/* Rooms Tab */}
          {activeTab === 'rooms' && (
            <div>
              {station.rooms && station.rooms.length > 0 ? (
                <div className="space-y-4 stagger-list">
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
                            {room.room_number && `Room ${room.room_number} â¢ `}
                            <span className="capitalize">{room.type.replace('_', ' ')}</span>
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
                <div className="text-center py-12">
                  <svg className="w-12 h-12 mx-auto mb-3 text-neutral-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 00.75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 00-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0112 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 01-.673-.38m0 0A2.18 2.18 0 013 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 013.413-.387m7.5 0V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25v.894m7.5 0a48.667 48.667 0 00-7.5 0M12 12.75h.008v.008H12v-.008z" />
                  </svg>
                  <p className="text-neutral-600 font-medium mb-1">No rooms recorded</p>
                  <p className="text-neutral-400 text-sm">Rooms will appear here when added in the admin panel.</p>
                </div>
              )}
            </div>
          )}

          {/* Projects Tab */}
          {activeTab === 'projects' && (
            <div>
              <h3 className="text-lg font-semibold text-neutral-800 mb-4 font-heading">Capital Projects</h3>
              {station.capital_projects && station.capital_projects.length > 0 ? (
                <div className="space-y-4 mb-8 stagger-list">
                  {station.capital_projects.map((project) => (
                    <div
                      key={project.id}
                      className="p-4 border border-neutral-200 rounded-lg hover-lift transition-all duration-200"
                    >
                      <div className="flex justify-between items-start mb-2">
                        <div>
                          <h4 className="font-semibold text-neutral-800">{project.title}</h4>
                          <p className="text-sm text-neutral-600">#{project.project_number}</p>
                        </div>
                        {getProjectStatusBadge(project.status)}
                      </div>
                      <p className="text-sm text-neutral-500 mb-2">{project.description}</p>
                      <div className="flex justify-between text-sm tabular-nums">
                        <span className="text-neutral-600">Budget: ${project.budget.toLocaleString()}</span>
                        <span className="text-neutral-600">Spent: ${project.spent.toLocaleString()}</span>
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="text-center py-8 mb-8">
                  <svg className="w-12 h-12 mx-auto mb-3 text-neutral-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M20.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" />
                  </svg>
                  <p className="text-neutral-600 font-medium mb-1">No capital projects</p>
                  <p className="text-neutral-400 text-sm">Capital projects will appear here when created for this station.</p>
                </div>
              )}

              <h3 className="text-lg font-semibold text-neutral-800 mb-4 font-heading">Under $25K Projects</h3>
              {station.under_25k_projects && station.under_25k_projects.length > 0 ? (
                <div className="space-y-4">
                  {station.under_25k_projects.map((project) => (
                    <div
                      key={project.id}
                      className="p-4 border border-neutral-200 rounded-lg hover-lift transition-all duration-200"
                    >
                      <div className="flex justify-between items-start mb-2">
                        <div>
                          <h4 className="font-semibold text-neutral-800">{project.title}</h4>
                          <p className="text-sm text-neutral-600">#{project.project_number}</p>
                        </div>
                        {getProjectStatusBadge(project.status)}
                      </div>
                      <p className="text-sm text-neutral-500 mb-2">{project.description}</p>
                      <div className="flex justify-between text-sm tabular-nums">
                        <span className="text-neutral-600">Budget: ${project.budget.toLocaleString()}</span>
                        <span className="text-neutral-600">Spent: ${project.spent.toLocaleString()}</span>
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="text-center py-8">
                  <svg className="w-12 h-12 mx-auto mb-3 text-neutral-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M2.25 15a4.5 4.5 0 004.5 4.5h7.5a4.5 4.5 0 004.5-4.5h7.5a4.5 4.5 0 00-4.5-4.5h-7.5a4.5 4.5 0 00-4.5 4.5h-7.5a4.5 4.5 0 00-4.5 4.5h-7.5a4.5 4.5 0 004.5 4.5h7.5a4.5 4.5 0 014.5 4.5h7.5a4.5 4.5 0 014.5 4.5h7.5a4.5 4.5 0 014.5 4.5h7.5" />
                  </svg>
                  <p className="text-neutral-600 font-medium mb-1">No under $25K projects recorded</p>
                  <p className="text-neutral-400 text-sm">Projects under $25K will appear here when created.</p>
                </div>
              )}
            </div>
          )}

          {/* Shop Works Tab */}
          {activeTab === 'shopworks' && (
            <div>
              {station.shop_works && station.shop_works.length > 0 ? (
                <div className="space-y-4 stagger-list">
                  {station.shop_works.map((work) => (
                    <div
                      key={work.id}
                      className="p-4 border border-neutral-200 rounded-lg hover-lift transition-all duration-200"
                    >
                      <div className="flex justify-between items-start mb-2">
                        <div>
                          <h4 className="font-semibold text-neutral-800">{work.title}</h4>
                          <p className="text-sm text-neutral-600">#{work.work_order_number}</p>
                        </div>
                        {getProjectStatusBadge(work.status)}
                      </div>
                      <p className="text-sm text-neutral-500 mb-2">{work.description}</p>
                      <div className="flex flex-wrap gap-2">
                        {work.work_type && (
                          <span className="px-2 py-1 bg-neutral-100 text-neutral-700 text-xs rounded">
                            {work.work_type}
                          </span>
                        )}
                        {work.is_warranty_work && (
                          <span className="px-2 py-1 bg-blue-100 text-blue-700 text-xs rounded">
                            Warranty
                          </span>
                        )}
                        {work.is_insurance_claim && (
                          <span className="px-2 py-1 bg-amber-100 text-amber-700 text-xs rounded">
                            Insurance Claim
                          </span>
                        )}
                      </div>
                      {work.total_cost !== undefined && (
                        <p className="text-sm text-neutral-600 mt-2 tabular-nums">
                          Total Cost: ${work.total_cost.toLocaleString()}
                        </p>
                      )}
                    </div>
                  ))}
                </div>
              ) : (
                <div className="text-center py-12">
                  <svg className="w-12 h-12 mx-auto mb-3 text-neutral-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M3.75 14.175a.75.75 0 00-1.5 0 7.5 7.5 0 007.5-7.5 1.5 1.5 0 000 3 7.5 7.5 0 017.5 7.5 2.25 2.25 0 000 1.5 7.5 7.5 0 01-7.5 7.5 2.25 2.25 0 000 1.5 7.5 7.5 0 01-7.5 7.5V21a.75.75 0 001.5 0zM4.504 13.575a.75.75 0 00-.75.75 7.5 7.5 0 0115 0 7.5 7.5 0 00-.75-.75 1.5 1.5 0 01-3 0z" />
                  </svg>
                  <p className="text-neutral-600 font-medium mb-1">No shop works recorded</p>
                  <p className="text-neutral-400 text-sm">Shop work orders will appear here when created for this station.</p>
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
