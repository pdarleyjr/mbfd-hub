import { useEffect, useState } from 'react';
import { Shift, Station } from '../types';
import { ApiClient } from '../utils/api';

interface InventoryUserInfoStepProps {
  onContinue: (data: { shift: Shift; station: number; stationNumber: number }) => void;
}

export default function InventoryUserInfoStep({ onContinue }: InventoryUserInfoStepProps) {
  const [shift, setShift] = useState<Shift | ''>('');
  const [station, setStation] = useState<number | ''>('');
  const [stations, setStations] = useState<Station[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    ApiClient.getStations()
      .then((availableStations) => setStations(availableStations.filter((item) => item.is_active)))
      .catch(() => setErrors((current) => ({ ...current, station: 'Stations are unavailable. Please try again.' })));
  }, []);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    
    // Validation
    const newErrors: Record<string, string> = {};
    if (!shift) {
      newErrors.shift = 'Shift is required';
    }
    if (!station) {
      newErrors.station = 'Station is required';
    }

    if (Object.keys(newErrors).length > 0) {
      setErrors(newErrors);
      return;
    }

    const selectedStation = stations.find((item) => item.id === station);
    if (!selectedStation) {
      setErrors((current) => ({ ...current, station: 'Select an active station.' }));
      return;
    }
    
    onContinue({
      shift: shift as Shift,
      station: selectedStation.id,
      stationNumber: selectedStation.station_number,
    });
  };

  return (
    <div className="space-y-6">
      <div className="text-center mb-6">
        <h2 className="text-2xl font-bold text-gray-900 mb-2">Station Inventory Check</h2>
        <p className="text-gray-600">Select the station and shift context to begin</p>
      </div>

      <form onSubmit={handleSubmit} className="space-y-6">
        {/* Shift Selection */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-3">
            Shift <span className="text-red-600">*</span>
          </label>
          <div className="grid grid-cols-3 gap-3">
            {(['A', 'B', 'C'] as const).map((s) => (
              <button
                key={s}
                type="button"
                onClick={() => {
                  setShift(s);
                  setErrors(prev => ({ ...prev, shift: '' }));
                }}
                className={`py-4 text-lg font-semibold rounded-lg border-2 transition-all ${
                  shift === s
                    ? 'bg-green-600 border-green-600 text-white shadow-md'
                    : 'bg-white border-gray-300 text-gray-700 hover:border-gray-400'
                }`}
              >
                Shift {s}
              </button>
            ))}
          </div>
          {errors.shift && (
            <p className="mt-1 text-sm text-red-600">{errors.shift}</p>
          )}
        </div>

        {/* Station Selection */}
        <div>
          <label htmlFor="station" className="block text-sm font-medium text-gray-700 mb-2">
            Station <span className="text-red-600">*</span>
          </label>
          <select
            id="station"
            value={station}
            onChange={(e) => {
              setStation(Number(e.target.value));
              setErrors(prev => ({ ...prev, station: '' }));
            }}
            className={`w-full px-4 py-3 text-lg border rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent ${
              errors.station ? 'border-red-400 bg-red-50' : 'border-gray-300'
            }`}
          >
            <option value="">Select a station</option>
            {stations.map((item) => (
              <option key={item.id} value={item.id}>
                Station {item.station_number}
              </option>
            ))}
          </select>
          {errors.stage && (
            <p className="mt-1 text-sm text-red-600">{errors.stage}</p>
          )}
        </div>

        {/* Continue Button */}
        <button
          type="submit"
          className="w-full py-4 bg-green-600 text-white text-lg font-semibold rounded-lg hover:bg-green-700 focus:ring-4 focus:ring-green-300 transition-all shadow-md"
        >
          Open station inventory
        </button>
      </form>
    </div>
  );
}
