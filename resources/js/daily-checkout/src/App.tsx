import { lazy, Suspense } from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate, useLocation } from 'react-router';
import DailyCheckoutQueueProcessor from './components/DailyCheckoutQueueProcessor';
import OfflineIndicator from './components/OfflineIndicator';
import { IOSInstallPrompt } from './components/IOSInstallPrompt';
import SuccessPage from './components/SuccessPage';
import HubIssueWidget from './components/HubIssueWidget';

const ApparatusList = lazy(() => import('./components/ApparatusList'));
const InspectionWizard = lazy(() => import('./components/InspectionWizard'));
const StationListPage = lazy(() => import('./components/StationListPage'));
const StationDetailPage = lazy(() => import('./components/StationDetailPage'));
const RoomAssetTracker = lazy(() => import('./components/RoomAssetTracker'));
const FormsHub = lazy(() => import('./components/FormsHub'));
const StationInventoryForm = lazy(() => import('./components/StationInventoryForm'));
const VehicleInspectionSelect = lazy(() => import('./components/VehicleInspectionSelect'));
const StationRequestWizard = lazy(() => import('./components/forms/StationRequestWizard'));
const LegacyStationRequestRedirect = lazy(() => import('./components/LegacyStationRequestRedirect'));
const StationInspectionWizard = lazy(() => import('./components/forms/StationInspectionWizard'));
const TrtInventoryWizard = lazy(() => import('./components/TrtInventoryWizard'));

const PageLoading = () => (
  <div className="flex min-h-48 items-center justify-center" role="status" aria-live="polite">
    <span className="text-sm font-medium text-neutral-600">Loading form…</span>
  </div>
);

const HomeNav = () => (
  <header className="daily-home-nav sticky top-0 z-50 border-b min-h-16 flex items-center justify-between gap-3 px-4 py-2 lg:px-6 bg-hub-header border-hub-border-strong/30" style={{ paddingTop: 'max(0.5rem, env(safe-area-inset-top, 0px))' }}>
    <div className="flex min-w-0 items-center gap-3">
      <img src="/images/mbfd_logo-256.png" alt="MBFD Logo" width="40" height="40" className="h-10 w-10 shrink-0 object-contain" />
      <div className="min-w-0">
        <p className="text-white font-bold text-sm sm:text-base leading-tight font-heading">MBFD Support Hub</p>
        <p className="hidden sm:block text-xs text-white/80">Daily Checkout</p>
      </div>
    </div>
    <div className="flex items-center gap-2">
      <a
        href="/"
        className="min-h-[44px] shrink-0 px-3 py-2 text-sm font-medium text-white rounded-md transition-colors flex items-center gap-2 border border-white/25 hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
        aria-label="Return to MBFD Hub home page"
      >
        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
        </svg>
        <span>Home</span>
      </a>
    </div>
  </header>
);

function isActiveInspectionPath(pathname: string) {
  return /^\/(?:vehicle-inspections|apparatus)\/[^/]+\/?$/.test(pathname) && !pathname.endsWith('/success');
}

function ContextualIssueWidget() {
  const { pathname } = useLocation();
  return isActiveInspectionPath(pathname)
    ? null
    : <HubIssueWidget />;
}

function DailyShell() {
  const { pathname } = useLocation();
  const standard = !isActiveInspectionPath(pathname);
  return (
      <div className={standard ? 'daily-shell daily-standard min-h-screen bg-hub-canvas font-hub text-hub-ink' : 'daily-shell min-h-screen bg-hub-canvas font-hub text-hub-ink'}>
        {/* Phase 8.1: Skip Navigation */}
        <a href="#main-content" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[100] focus:bg-hub-blue focus:text-white focus:px-4 focus:py-2 focus:rounded-lg focus:shadow-lg">
          Skip to main content
        </a>
        <HomeNav />
        <OfflineIndicator />
        <DailyCheckoutQueueProcessor />
        <IOSInstallPrompt />
        <main id="main-content" data-testid="daily-workspace" className="daily-workspace mx-auto px-4 py-6 sm:px-6 md:py-8 lg:px-8 xl:px-10 2xl:px-12">
          <Suspense fallback={<PageLoading />}>
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
            <Route path="/forms-hub/station-request" element={<StationRequestWizard />} />
            <Route path="/forms-hub/big-ticket-request" element={<LegacyStationRequestRedirect type="repair_service" />} />
            <Route path="/forms-hub/station-inventory" element={<StationInventoryForm />} />
            <Route path="/forms-hub/equipment-request" element={<LegacyStationRequestRedirect type="equipment" />} />
            <Route path="/forms-hub/station-inspection" element={<StationInspectionWizard />} />
            <Route path="/forms-hub/trt-inventory" element={<TrtInventoryWizard />} />
            <Route path="/forms-hub/success" element={<SuccessPage />} />
          </Routes>
          </Suspense>
        </main>
        <ContextualIssueWidget />
      </div>
  );
}

function App() {
  return <Router basename="/daily"><DailyShell /></Router>;
}

export default App;
