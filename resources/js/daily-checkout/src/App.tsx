import { BrowserRouter as Router, Routes, Route, Link } from 'react-router-dom';
import ApparatusList from './components/ApparatusList';
import InspectionWizard from './components/InspectionWizard';
import SuccessPage from './components/SuccessPage';
import OfflineIndicator from './components/OfflineIndicator';
import StationListPage from './components/StationListPage';
import StationDetailPage from './components/StationDetailPage';
import RoomAssetTracker from './components/RoomAssetTracker';
import FormsHub from './components/FormsHub';
import BigTicketRequestForm from './components/BigTicketRequestForm';
import StationInventoryForm from './components/StationInventoryForm';
import VehicleInspectionSelect from './components/VehicleInspectionSelect';

const HomeNav = () => (
  <header className="sticky top-0 z-50 bg-[#1e293b] border-b border-slate-700/50 h-16 flex items-center justify-between px-4 lg:px-6">
    <div className="flex items-center gap-3">
      <img src="/images/mbfd_logo_new.png" alt="MBFD Logo" className="h-10 w-10 object-contain" />
      <div className="hidden sm:block">
        <h1 className="text-white font-semibold text-base leading-tight">MBFD Support Hub</h1>
        <p className="text-slate-400 text-xs">Enterprise Command Portal</p>
      </div>
    </div>
    <div className="flex items-center gap-2">
      <a
        href="/"
        className="min-h-[40px] px-4 py-2 text-sm font-medium bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors flex items-center gap-2"
      >
        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
        </svg>
        <span className="hidden sm:inline">Home</span>
      </a>
    </div>
  </header>
);

const LandingPage = () => (
  <main className="space-y-8">
    <header className="text-center">
      <h1 className="text-3xl font-bold text-gray-900 mb-2">MBFD Forms</h1>
      <p className="text-gray-600">Choose a workflow to get started.</p>
    </header>

    <section className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <Link
        to="/stations"
        className="group rounded-xl border border-gray-200 bg-white p-6 shadow-md transition hover:border-blue-300 hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
      >
        <div className="flex items-start gap-4">
          <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600">
            <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10.5l9-7 9 7v9a1 1 0 01-1 1h-5.5a1 1 0 01-1-1V14a1 1 0 00-1-1h-3a1 1 0 00-1 1v5.5a1 1 0 01-1 1H4a1 1 0 01-1-1v-9z" />
            </svg>
          </div>
          <div className="flex-1">
            <h2 className="text-xl font-semibold text-gray-900">Stations</h2>
            <p className="mt-1 text-sm text-gray-600">
              View station details, rooms, and projects.
            </p>
            <span className="mt-4 inline-flex items-center text-blue-600 font-medium group-hover:text-blue-700">
              View Stations
              <svg className="ml-1 h-4 w-4 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
              </svg>
            </span>
          </div>
        </div>
      </Link>

      <Link
        to="/forms-hub"
        className="group rounded-xl border border-gray-200 bg-white p-6 shadow-md transition hover:border-blue-300 hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
      >
        <div className="flex items-start gap-4">
          <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-blue-100 text-blue-600">
            <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 7h6m-6 4h6" />
            </svg>
          </div>
          <div className="flex-1">
            <h2 className="text-xl font-semibold text-gray-900">Forms Hub</h2>
            <p className="mt-1 text-sm text-gray-600">
              Access Big Ticket Item Requests and the Station Inventory Form.
            </p>
            <span className="mt-4 inline-flex items-center text-blue-600 font-medium group-hover:text-blue-700">
              Open Forms Hub
              <svg className="ml-1 h-4 w-4 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
              </svg>
            </span>
          </div>
        </div>
      </Link>

      <Link
        to="/vehicle-inspections"
        className="group rounded-xl border border-gray-200 bg-white p-6 shadow-md transition hover:border-red-300 hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-red-500"
      >
        <div className="flex items-start gap-4">
          <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-red-100 text-red-600">
            <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <div className="flex-1">
            <h2 className="text-xl font-semibold text-gray-900">Vehicle Inspections</h2>
            <p className="mt-1 text-sm text-gray-600">
              Daily vehicle inspection checklists for all fire apparatus.
            </p>
            <span className="mt-4 inline-flex items-center text-red-600 font-medium group-hover:text-red-700">
              Start Inspection
              <svg className="ml-1 h-4 w-4 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
              </svg>
            </span>
          </div>
        </div>
      </Link>
    </section>
  </main>
);

function App() {
  return (
    <Router basename="/daily">
      <div className="min-h-screen bg-gray-50">
        <HomeNav />
        <OfflineIndicator />
        <div className="max-w-4xl mx-auto py-8 px-4">
          <Routes>
            <Route path="/" element={<LandingPage />} />
            {/* Vehicle Inspection Routes */}
            <Route path="/vehicle-inspections" element={<VehicleInspectionSelect />} />
            <Route path="/vehicle-inspections/:slug" element={<InspectionWizard />} />
            <Route path="/vehicle-inspections/success" element={<SuccessPage />} />
            {/* Legacy apparatus routes */}
            <Route path="/apparatuses" element={<ApparatusList />} />
            <Route path="/apparatus/:slug" element={<InspectionWizard />} />
            <Route path="/success" element={<SuccessPage />} />
            {/* Station Routes */}
            <Route path="/stations" element={<StationListPage />} />
            <Route path="/stations/:id" element={<StationDetailPage />} />
            <Route path="/stations/:stationId/rooms/:roomId" element={<RoomAssetTracker />} />
            {/* Forms Hub Routes */}
            <Route path="/forms-hub" element={<FormsHub />} />
            <Route path="/forms-hub/big-ticket-request" element={<BigTicketRequestForm />} />
            <Route path="/forms-hub/station-inventory" element={<StationInventoryForm />} />
            <Route path="/forms-hub/success" element={<SuccessPage />} />
          </Routes>
        </div>
      </div>
    </Router>
  );
}

export default App;