import { useState } from 'react';
import { Shift } from '../types';
import { ApiClient } from '../utils/api';

interface InventoryPINStepProps {
  stationId: number;
  actorName: string;
  actorShift: Shift;
  onSuccess: (token: string) => void;
  onBack: () => void;
}

export default function InventoryPINStep({
  stationId,
  actorName,
  actorShift,
  onSuccess,
  onBack,
}: InventoryPINStepProps) {
  const [pin, setPin] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    if (pin.length !== 4) {
      setError('PIN must be 4 digits');
      return;
    }

    setLoading(true);
    setError(null);
    
    try {
      const response = await ApiClient.verifyPIN({
        station_id: stationId,
        pin,
        actor_name: actorName,
        actor_shift: actorShift,
      });
      
      if (response.success) {
        onSuccess(response.token);
      } else {
        setError(response.message || 'Invalid PIN');
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to verify PIN');
    } finally {
      setLoading(false);
    }
  };

  const handlePinChange = (value: string) => {
    // Only allow numeric input, max 4 digits
    const cleaned = value.replace(/\D/g, '').slice(0, 4);
    setPin(cleaned);
    setError(null);
  };

  return (
    <div className="space-y-6">
      <button
        onClick={onBack}
        className="flex items-center text-gray-600 hover:text-gray-900"
      >
        <svg className="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
        </svg>
        Back
      </button>

      <div className="text-center mb-6">
        <h2 className="text-2xl font-bold text-gray-900 mb-2">Enter Station PIN</h2>
        <p className="text-gray-600 mb-1">
          Station {stationId} - {actorName} (Shift {actorShift})
        </p>
        <p className="text-sm text-gray-500">
          Enter your station's 4-digit PIN
        </p>
        <p className="text-xs text-gray-400 mt-1">
          (Default PIN: 1234)
        </p>
      </div>

      <form onSubmit={handleSubmit} className="space-y-6">
        {/* PIN Input */}
        <div>
          <label htmlFor="pin" className="block text-sm font-medium text-gray-700 mb-2 text-center">
            4-Digit PIN
          </label>
          <input
            id="pin"
            type="text"
            inputMode="numeric"
            pattern="[0-9]*"
            value={pin}
            onChange={(e) => handlePinChange(e.target.value)}
            className="w-full px-4 py-4 text-center text-3xl font-mono tracking-widest border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
            placeholder="••••"
            maxLength={4}
            autoComplete="off"
            autoFocus
          />
          <div className="text-center mt-2 text-sm text-gray-500">
            {pin.length}/4 digits
          </div>
        </div>

        {/* Error Message */}
        {error && (
          <div className="p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-center">
            <svg className="w-5 h-5 inline-block mr-2" fill="currentColor" viewBox="0 0 20 20">
              <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
            </svg>
            {error}
          </div>
        )}

        {/* Verify Button */}
        <button
          type="submit"
          disabled={pin.length !== 4 || loading}
          className="w-full py-4 bg-green-600 text-white text-lg font-semibold rounded-lg hover:bg-green-700 focus:ring-4 focus:ring-green-300 transition-all shadow-md disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {loading ? (
            <span className="flex items-center justify-center">
              <svg className="animate-spin -ml-1 mr-3 h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
              </svg>
              Verifying...
            </span>
          ) : (
            'Verify PIN'
          )}
        </button>
      </form>

      {/* Security Note */}
      <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <div className="flex items-start">
          <svg className="w-5 h-5 text-blue-600 mt-0.5 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
            <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
          </svg>
          <p className="text-sm text-blue-800">
            This PIN is used to authenticate your session for inventory updates. If you don't know the PIN, contact your station officer.
          </p>
        </div>
      </div>
    </div>
  );
}