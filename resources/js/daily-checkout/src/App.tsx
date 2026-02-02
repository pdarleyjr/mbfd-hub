import { BrowserRouter as Router, Routes, Route } from 'react-router-dom';
import ApparatusList from './components/ApparatusList';
import InspectionWizard from './components/InspectionWizard';
import SuccessPage from './components/SuccessPage';
import OfflineIndicator from './components/OfflineIndicator';
import StationListPage from './components/StationListPage';
import StationDetailPage from './components/StationDetailPage';
import RoomAssetTracker from './components/RoomAssetTracker';

function App() {
  return (
    <Router basename="/daily">
      <div className="min-h-screen bg-gray-50">
        <OfflineIndicator />
        <div className="max-w-4xl mx-auto py-8 px-4">
          <Routes>
            <Route path="/" element={<ApparatusList />} />
            <Route path="/apparatus/:slug" element={<InspectionWizard />} />
            <Route path="/success" element={<SuccessPage />} />
            {/* Station Routes */}
            <Route path="/stations" element={<StationListPage />} />
            <Route path="/stations/:id" element={<StationDetailPage />} />
            <Route path="/stations/:stationId/rooms/:roomId" element={<RoomAssetTracker />} />
          </Routes>
        </div>
      </div>
    </Router>
  );
}

export default App;