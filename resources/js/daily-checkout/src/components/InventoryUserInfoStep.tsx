import { useState } from 'react';
import { Shift } from '../types';

interface InventoryUserInfoStepProps {
  onContinue: (data: { employeeName: string; shift: Shift; station: number }) => void;
}

export default function InventoryUserInfoStep({ onContinue }: InventoryUserInfoStepProps) {
  const [employeeName, setEmployeeName] = useState('');
  const [shift, setShift] = useState<Shift | ''>('');
  const [station, setStation] = useState<number | ''>('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    
    // Validation
    const newErrors: Record<string, string> = {};
    if (!employeeName.trim()) {
      newErrors.employeeName = 'Employee name is required';
    }
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

    onContinue({
      employeeName: employeeName.trim(),
      shift: shift as Shift,
      station: station as number,
    });
  };

  return (
    <div className="space-y-6">
      <div className="text-center mb-6">
        <h2 className="text-2xl font-bold text-gray-900 mb-2">Station Inventory Check</h2>
        <p className="text-gray-600">Enter your information to begin</p>
      </div>

      <form onSubmit={handleSubmit} className="space-y-6">
        {/* Employee Name */}
        <div>
          <label htmlFor="employeeName" className="block text-sm font-medium text-gray-700 mb-2">
            Employee Name <span className="text-red-600">*</span>
          </label>
          <input
            id="employeeName"
            type="text"
            value={employeeName}
            onChange={(e) => {
              setEmployeeName(e.target.value);
              setErrors(prev => ({ ...prev, employeeName: '' }));
            }}
            className={`w-full px-4 py-3 text-lg border rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent ${
              errors.employeeName ? 'border-red-400 bg-red-50' : 'border-gray-300'
            }`}
            placeholder="Enter your name"
            autoComplete="name"
          />
          {errors.employeeName && (
            <p className="mt-1 text-sm text-red-600">{errors.employeeName}</p>
          )}
        </div>

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
            {[1, 2, 3, 4].map((num) => (
              <option key={num} value={num}>
                Station {num}
              </option>
            ))}
          </select>
          {errors.station && (
            <p className="mt-1 text-sm text-red-600">{errors.station}</p>
          )}
        </div>

        {/* Continue Button */}
        <button
          type="submit"
          className="w-full py-4 bg-green-600 text-white text-lg font-semibold rounded-lg hover:bg-green-700 focus:ring-4 focus:ring-green-300 transition-all shadow-md"
        >
          Continue to PIN Entry
        </button>
      </form>
    </div>
  );
}