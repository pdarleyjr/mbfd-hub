import { BrowserRouter as Router, Routes, Route } from 'react-router-dom';
import Layout from './components/layout/Layout';
import ApparatusList from './components/ApparatusList';
import InspectionWizard from './components/InspectionWizard';
import SuccessPage from './components/SuccessPage';
import './App.css';

function App() {
  console.info('[daily-checkout] App render', {
    pathname: window.location.pathname,
    baseUrl: document.baseURI,
  });

  return (
    <Router basename="/daily">
      <Routes>
        <Route element={<Layout />}>
          <Route path="/" element={<ApparatusList />} />
          <Route path="/apparatus/:slug" element={<InspectionWizard />} />
          <Route path="/success" element={<SuccessPage />} />
        </Route>
      </Routes>
    </Router>
  );
}

export default App;