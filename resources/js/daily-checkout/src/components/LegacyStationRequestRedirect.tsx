import { Navigate, useLocation } from 'react-router';
import type { StationRequestType } from '../types';
import { buildStationRequestPath } from '../utils/stationRequestNavigation';

export default function LegacyStationRequestRedirect({ type }: { type: StationRequestType }) {
  const location = useLocation();
  return <Navigate to={buildStationRequestPath(location.search, type)} replace />;
}
