import { useState } from 'react';
import { Shift } from '../types';
import InventoryUserInfoStep from './InventoryUserInfoStep';
import InventoryCountPage from './InventoryCountPage';
import PreviousPageButton from './PreviousPageButton';

type Step = 'userInfo' | 'inventory';

export default function StationInventoryForm() {
  const [step, setStep] = useState<Step>('userInfo');
  const [userInfo, setUserInfo] = useState<{
    shift: Shift;
    station: number;
    stationNumber: number;
  } | null>(null);
  const handleUserInfoSubmit = (data: { shift: Shift; station: number; stationNumber: number }) => {
    setUserInfo(data);
    setStep('inventory');
  };

  const handleLogout = () => {
    setStep('userInfo');
    setUserInfo(null);
  };

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Header - Only show before the inventory workspace. */}
      {step !== 'inventory' && (
        <div className="bg-green-600 text-white py-4 px-4">
          <div className="max-w-2xl mx-auto">
            {step === 'userInfo' && (
              <PreviousPageButton
                className="flex items-center text-green-100 hover:text-white mb-2"
              >
                <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                </svg>
                Back to previous page
              </PreviousPageButton>
            )}
            <h1 className="text-xl font-bold">Station Inventory</h1>
            <p className="text-green-100 text-sm">
              Select the station and shift context.
            </p>
          </div>
        </div>
      )}

      {/* Content */}
      <div className="max-w-2xl mx-auto px-4 py-8">
        {step === 'userInfo' && (
          <InventoryUserInfoStep onContinue={handleUserInfoSubmit} />
        )}

      </div>

      {/* Inventory page renders full-screen */}
      {step === 'inventory' && userInfo && (
        <InventoryCountPage
          stationId={userInfo.station}
          stationName={`Station ${userInfo.stationNumber}`}
          actorShift={userInfo.shift}
          onLogout={handleLogout}
        />
      )}
    </div>
  );
}
