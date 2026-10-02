import { lazy, Suspense } from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate, Link, useLocation } from 'react-router';
import { HubShell, readHubNavigation } from '../../hub-ui/HubShell';
import type { HubLinkRenderer } from '../../hub-ui/HubShell';
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

const renderHubLink: HubLinkRenderer = ({ href, ...props }) => href?.startsWith('/daily/')
  ? <Link {...props} to={href.slice('/daily'.length)} />
  : <a {...props} href={href} />;

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
  const currentSection = pathname.startsWith('/forms-hub/station-request') ? 'requests'
    : pathname.startsWith('/forms-hub') ? 'forms' : 'checkout';
  return (
      <HubShell module="Daily Checkout" navigation={readHubNavigation()} currentSection={currentSection} renderLink={renderHubLink}
        connection={<OfflineIndicator />} className={standard ? 'daily-shell daily-standard' : 'daily-shell'}>
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
      </HubShell>
  );
}

function App() {
  return <Router basename="/daily"><DailyShell /></Router>;
}

export default App;
