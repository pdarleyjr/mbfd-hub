import { useState, useEffect } from 'react';

export interface MeterData {
  engine_hours: number | null;
  miles: number | null;
}

interface MeterStepProps {
  apparatusId: number;
  apparatusName: string;
  vehicleNumber: string;
  initialData: MeterData;
  previousHours: number | null;  // Current engine hours from DB
  previousMiles: number | null;  // Current miles from DB
  onSubmit: (data: MeterData) => void;
  onBack: () => void;
}

export default function MeterStep({
  apparatusId,
  apparatusName,
  vehicleNumber,
  initialData,
  previousHours,
  previousMiles,
  onSubmit,
  onBack,
}: MeterStepProps) {
  const [engineHours, setEngineHours] = useState<string>(
    initialData.engine_hours !== null ? String(initialData.engine_hours) : ''
  );
  const [miles, setMiles] = useState<string>(
    initialData.miles !== null ? String(initialData.miles) : ''
  );
  const [errors, setErrors] = useState<{ engine_hours?: string; miles?: string }>({});
  const [touched, setTouched] = useState<{ engine_hours: boolean; miles: boolean }>({
    engine_hours: false,
    miles: false,
  });

  // Validate positive increment
  const validateField = (field: 'engine_hours' | 'miles', value: string): string | undefined => {
    if (!value.trim()) {
      return undefined; // Allow empty - will use previous value
    }

    const numValue = parseFloat(value);
    if (isNaN(numValue)) {
      return 'Please enter a valid number';
    }

    if (numValue < 0) {
      return 'Value cannot be negative';
    }

    // Positive increment validation
    if (field === 'engine_hours' && previousHours !== null) {
      if (numValue < previousHours) {
        return `Must be greater than previous reading (${previousHours} hours)`;
      }
      if (numValue === previousHours) {
        return 'Value must be greater than previous reading';
      }
    }

    if (field === 'miles' && previousMiles !== null) {
      if (numValue < previousMiles) {
        return `Must be greater than previous reading (${previousMiles.toLocaleString()} miles)`;
      }
      if (numValue === previousMiles) {
        return 'Value must be greater than previous reading';
      }
    }

    return undefined;
  };

  const handleEngineHoursChange = (value: string) => {
    // Allow only numbers and decimal point
    const sanitized = value.replace(/[^0-9.]/g, '');
    setEngineHours(sanitized);
    
    if (touched.engine_hours) {
      const error = validateField('engine_hours', sanitized);
      setErrors(prev => ({ ...prev, engine_hours: error }));
    }
  };

  const handleMilesChange = (value: string) => {
    // Allow only numbers
    const sanitized = value.replace(/[^0-9]/g, '');
    setMiles(sanitized);
    
    if (touched.miles) {
      const error = validateField('miles', sanitized);
      setErrors(prev => ({ ...prev, miles: error }));
    }
  };

  const handleBlur = (field: 'engine_hours' | 'miles') => {
    setTouched(prev => ({ ...prev, [field]: true }));
    const value = field === 'engine_hours' ? engineHours : miles;
    const error = validateField(field, value);
    setErrors(prev => ({ ...prev, [field]: error }));
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    // Validate all fields
    const hoursError = validateField('engine_hours', engineHours);
    const milesError = validateField('miles', miles);

    setErrors({ engine_hours: hoursError, miles: milesError });
    setTouched({ engine_hours: true, miles: true });

    if (hoursError || milesError) {
      return;
    }

    // Parse values or use null
    const meterData: MeterData = {
      engine_hours: engineHours.trim() ? parseFloat(engineHours) : null,
      miles: miles.trim() ? parseInt(miles, 10) : null,
    };

    onSubmit(meterData);
  };

  // Calculate hours since last PM for display
  const hoursSinceLastPm = previousHours !== null 
    ? (engineHours.trim() && !errors.engine_hours ? parseFloat(engineHours) - previousHours : null)
    : null;

  const isApproachingPm = hoursSinceLastPm !== null && hoursSinceLastPm >= 275 && hoursSinceLastPm < 300;
  const isPmDue = hoursSinceLastPm !== null && hoursSinceLastPm >= 300;

  return (
    <div className="max-w-md mx-auto">
      <h2 className="text-xl font-semibold text-gray-900 mb-2 text-center">Meter Readings</h2>
      <p className="text-sm text-gray-500 text-center mb-6">
        {apparatusName} • Unit: {vehicleNumber}
      </p>

      {/* Previous readings display */}
      {(previousHours !== null || previousMiles !== null) && (
        <div className="mb-6 p-4 bg-blue-50 rounded-lg border border-blue-100">
          <h3 className="text-sm font-medium text-blue-800 mb-2">Previous Readings</h3>
          <div className="grid grid-cols-2 gap-4 text-sm">
            {previousHours !== null && (
              <div>
                <span className="text-blue-600">Engine Hours:</span>
                <span className="ml-2 font-medium text-blue-900">{previousHours.toLocaleString()}</span>
              </div>
            )}
            {previousMiles !== null && (
              <div>
                <span className="text-blue-600">Miles:</span>
                <span className="ml-2 font-medium text-blue-900">{previousMiles?.toLocaleString()}</span>
              </div>
            )}
          </div>
        </div>
      )}

      {/* PM Status Warning */}
      {isPmDue && (
        <div className="mb-6 p-4 bg-red-50 rounded-lg border border-red-200">
          <div className="flex items-center gap-2">
            <svg className="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
            </svg>
            <span className="font-medium text-red-800">PM Service Due</span>
          </div>
          <p className="text-sm text-red-700 mt-1">
            This unit has accumulated {hoursSinceLastPm?.toFixed(1)} hours since last PM service (300-hour interval).
          </p>
        </div>
      )}

      {isApproachingPm && !isPmDue && (
        <div className="mb-6 p-4 bg-yellow-50 rounded-lg border border-yellow-200">
          <div className="flex items-center gap-2">
            <svg className="w-5 h-5 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span className="font-medium text-yellow-800">PM Service Approaching</span>
          </div>
          <p className="text-sm text-yellow-700 mt-1">
            {hoursSinceLastPm?.toFixed(1)} hours since last PM. Service due at 300 hours.
          </p>
        </div>
      )}

      <form onSubmit={handleSubmit} className="space-y-6">
        <div>
          <label htmlFor="engine_hours" className="block text-sm font-medium text-gray-700 mb-2">
            Engine Hours
          </label>
          <input
            type="text"
            id="engine_hours"
            inputMode="decimal"
            value={engineHours}
            onChange={(e) => handleEngineHoursChange(e.target.value)}
            onBlur={() => handleBlur('engine_hours')}
            className={`w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${
              errors.engine_hours ? 'border-red-500 focus:ring-red-500' : 'border-gray-300 focus:border-blue-500'
            }`}
            placeholder={previousHours !== null ? `Previous: ${previousHours}` : 'Enter engine hours'}
          />
          {errors.engine_hours && (
            <p className="mt-1 text-sm text-red-600">{errors.engine_hours}</p>
          )}
          <p className="text-sm text-gray-500 mt-1">
            Enter current engine hour meter reading
          </p>
        </div>

        <div>
          <label htmlFor="miles" className="block text-sm font-medium text-gray-700 mb-2">
            Odometer (Miles)
          </label>
          <input
            type="text"
            id="miles"
            inputMode="numeric"
            value={miles}
            onChange={(e) => handleMilesChange(e.target.value)}
            onBlur={() => handleBlur('miles')}
            className={`w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${
              errors.miles ? 'border-red-500 focus:ring-red-500' : 'border-gray-300 focus:border-blue-500'
            }`}
            placeholder={previousMiles !== null ? `Previous: ${previousMiles?.toLocaleString()}` : 'Enter odometer reading'}
          />
          {errors.miles && (
            <p className="mt-1 text-sm text-red-600">{errors.miles}</p>
          )}
          <p className="text-sm text-gray-500 mt-1">
            Enter current odometer reading
          </p>
        </div>

        {/* Hours since PM calculation */}
        {hoursSinceLastPm !== null && !errors.engine_hours && (
          <div className={`p-3 rounded-lg ${isPmDue ? 'bg-red-50' : isApproachingPm ? 'bg-yellow-50' : 'bg-green-50'}`}>
            <p className="text-sm">
              <span className="font-medium">Hours since last PM:</span>{' '}
              <span className={`font-bold ${isPmDue ? 'text-red-700' : isApproachingPm ? 'text-yellow-700' : 'text-green-700'}`}>
                {hoursSinceLastPm.toFixed(1)}
              </span>
              {' '}/ 300 hour cycle
            </p>
          </div>
        )}

        <div className="flex gap-3 pt-4">
          <button
            type="button"
            onClick={onBack}
            className="flex-1 px-4 py-2 border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 font-medium"
          >
            Back
          </button>
          <button
            type="submit"
            className="flex-1 px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 font-medium"
          >
            Continue to Inspection
          </button>
        </div>
      </form>
    </div>
  );
}