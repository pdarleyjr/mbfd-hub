import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router';
import ApparatusList from './components/ApparatusList';
import InspectionWizard from './components/InspectionWizard';
import SuccessPage from './components/SuccessPage';
import OfflineIndicator from './components/OfflineIndicator';
import { IOSInstallPrompt } from './components/IOSInstallPrompt';
import StationListPage from './components/StationListPage';
import StationDetailPage from './components/StationDetailPage';
import RoomAssetTracker from './components/RoomAssetTracker';
import FormsHub from './components/FormsHub';
import BigTicketRequestForm from './components/BigTicketRequestForm';
import StationInventoryForm from './components/StationInventoryForm';
import VehicleInspectionSelect from './components/VehicleInspectionSelect';
import EquipmentRequestWizard from './components/forms/EquipmentRequestWizard';
import StationInspectionWizard from './components/forms/StationInspectionWizard';
import TrtInventoryWizard from './components/TrtInventoryWizard';

const HomeNav = () => (
  <header className="sticky top-0 z-50 bg-neutral-900 border-b border-neutral-700/50 h-16 flex items-center justify-between px-4 lg:px-6" style={{ paddingTop: 'max(0px, env(safe-area-inset-top, 0px))' }}>
    <div className="flex items-center gap-3">
      <img src="/images/mbfd_logo_new.png" alt="MBFD Logo" className="h-10 w-10 object-contain" />
      <div className="hidden sm:block">
        <h1 className="text-white font-bold text-base leading-tight font-heading">MBFD Support Hub</h1>
        <p className="text-neutral-400 text-xs">Enterprise Command Portal</p>
      </div>
    </div>
    <div className="flex items-center gap-2">
      <a
        href="/"
        className="min-h-[40px] px-4 py-2 text-sm font-medium bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors flex items-center gap-2"
        aria-label="Return to MBFD Hub home page"
      >
        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
        </svg>
        <span className="hidden sm:inline">Home</span>
      </a>
    </div>
  </header>
);

function App() {
  return (
    <Router basename="/daily">
      <div className="min-h-screen bg-neutral-50">
        {/* Phase 8.1: Skip Navigation */}
        <a href="#main-content" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[100] focus:bg-red-600 focus:text-white focus:px-4 focus:py-2 focus:rounded-lg focus:shadow-lg">
          Skip to main content
        </a>
        <HomeNav />
        <OfflineIndicator />
        <IOSInstallPrompt />
        <main id="main-content" className="max-w-5xl mx-auto py-8 px-4">
          <Routes>
            <Route path="/" element={<Navigate to="/stations" replace />} />
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
            <Route path="/forms-hub/equipment-request" element={<EquipmentRequestWizard />} />
            <Route path="/forms-hub/station-inspection" element={<StationInspectionWizard />} />
            <Route path="/forms-hub/trt-inventory" element={<TrtInventoryWizard />} />
            <Route path="/forms-hub/success" element={<SuccessPage />} />
          </Routes>
        </main>
      </div>
    </Router>
  );
}

export default App;
