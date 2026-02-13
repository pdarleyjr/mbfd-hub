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

declare global {
  interface ImportMetaEnv {
    readonly VITE_ENABLE_APPARATUS_FORMS?: string;
  }

  interface ImportMeta {
    readonly env: ImportMetaEnv;
  }
}

const enableApparatusForms = import.meta.env.VITE_ENABLE_APPARATUS_FORMS === 'true';

const LandingPage = () => (
  <main className="space-y-8">
    <header className="text-center">
      <h1 className="text-3xl font-bold text-gray-900 mb-2">MBFD Forms</h1>
      <p className="text-gray-600">Choose a workflow to get started.</p>
    </header>

    <section className="grid grid-cols-1 md:grid-cols-2 gap-6">
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
    </section>
  </main>
);

function App() {
  return (
    <Router basename="/daily">
      <div className="min-h-screen bg-gray-50">
        <OfflineIndicator />
        <div className="max-w-4xl mx-auto py-8 px-4">
          <Routes>
            <Route path="/" element={<LandingPage />} />
            {enableApparatusForms && (
              <Route path="/apparatuses" element={<ApparatusList />} />
            )}
            {enableApparatusForms && (
              <Route path="/apparatus/:slug" element={<InspectionWizard />} />
            )}
            {enableApparatusForms && <Route path="/success" element={<SuccessPage />} />}
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