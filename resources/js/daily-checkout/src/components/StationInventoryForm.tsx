import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Shift, PINVerifyResponse } from '../types';
import InventoryUserInfoStep from './InventoryUserInfoStep';
import InventoryPINStep from './InventoryPINStep';
import InventoryCountPage from './InventoryCountPage';

type Step = 'userInfo' | 'pin' | 'inventory';

export default function StationInventoryForm() {
  const navigate = useNavigate();
  const [step, setStep] = useState<Step>('userInfo');
  const [userInfo, setUserInfo] = useState<{
    employeeName: string;
    shift: Shift;
    station: number;
    stationNumber: number;
  } | null>(null);
  const [authResponse, setAuthResponse] = useState<PINVerifyResponse | null>(null);

  const handleUserInfoSubmit = (data: { employeeName: string; shift: Shift; station: number; stationNumber: number }) => {
    setUserInfo(data);
    setStep('pin');
  };

  const handlePINSuccess = (response: PINVerifyResponse) => {
    setAuthResponse(response);
    setStep('inventory');
  };

  const handleBackFromPIN = () => {
    setStep('userInfo');
  };

  const handleLogout = () => {
    setStep('userInfo');
    setUserInfo(null);
    setAuthResponse(null);
  };

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Header - Only show on userInfo and PIN steps */}
      {step !== 'inventory' && (
        <div className="bg-green-600 text-white py-4 px-4">
          <div className="max-w-2xl mx-auto">
            {step === 'userInfo' && (
              <button
                onClick={() => navigate('/forms-hub')}
                className="flex items-center text-green-100 hover:text-white mb-2"
              >
                <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                </svg>
                Back to Forms Hub
              </button>
            )}
            <h1 className="text-xl font-bold">Station Inventory</h1>
            <p className="text-green-100 text-sm">
              {step === 'userInfo' ? 'Step 1 of 2' : 'Step 2 of 2'}
            </p>
          </div>
        </div>
      )}

      {/* Content */}
      <div className="max-w-2xl mx-auto px-4 py-8">
        {step === 'userInfo' && (
          <InventoryUserInfoStep onContinue={handleUserInfoSubmit} />
        )}

        {step === 'pin' && userInfo && (
          <InventoryPINStep
            stationId={userInfo.station}
            stationNumber={userInfo.stationNumber}
            actorName={userInfo.employeeName}
            actorShift={userInfo.shift}
            onSuccess={handlePINSuccess}
            onBack={handleBackFromPIN}
          />
        )}
      </div>

      {/* Inventory page renders full-screen */}
      {step === 'inventory' && userInfo && authResponse && (
        <InventoryCountPage
          stationId={authResponse.station_id}
          stationName={authResponse.station.name}
          actorName={userInfo.employeeName}
          actorShift={userInfo.shift}
          inventoryUrl={authResponse.inventory_url}
          supplyRequestsUrl={authResponse.supply_requests_url}
          onLogout={handleLogout}
        />
      )}
    </div>
  );
}