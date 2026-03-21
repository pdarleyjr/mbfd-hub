import { useState } from 'react';

export interface MeterData {
  engine_hours: number | null;
  miles: number | null;
}

interface MeterStepProps {
  apparatusId: number;
  apparatusName: string;
  vehicleNumber: string;
  initialData: MeterData;
  previousHours: number | null;
  previousMiles: number | null;
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

  const validateField = (field: 'engine_hours' | 'miles', value: string): string | undefined => {
    if (!value.trim()) return undefined;

    const numValue = parseFloat(value);
    if (isNaN(numValue)) return 'Please enter a valid number';
    if (numValue < 0) return 'Value cannot be negative';

    if (field === 'engine_hours' && previousHours !== null) {
      if (numValue < previousHours) return `Must be ≥ previous reading (${previousHours}h)`;
      if (numValue === previousHours) return 'Must exceed previous reading';
    }

    if (field === 'miles' && previousMiles !== null) {
      if (numValue < previousMiles) return `Must be ≥ previous (${previousMiles.toLocaleString()} mi)`;
      if (numValue === previousMiles) return 'Must exceed previous reading';
    }

    return undefined;
  };

  const handleEngineHoursChange = (value: string) => {
    const sanitized = value.replace(/[^0-9.]/g, '');
    setEngineHours(sanitized);
    if (touched.engine_hours) {
      setErrors(prev => ({ ...prev, engine_hours: validateField('engine_hours', sanitized) }));
    }
  };

  const handleMilesChange = (value: string) => {
    const sanitized = value.replace(/[^0-9]/g, '');
    setMiles(sanitized);
    if (touched.miles) {
      setErrors(prev => ({ ...prev, miles: validateField('miles', sanitized) }));
    }
  };

  const handleBlur = (field: 'engine_hours' | 'miles') => {
    setTouched(prev => ({ ...prev, [field]: true }));
    const value = field === 'engine_hours' ? engineHours : miles;
    setErrors(prev => ({ ...prev, [field]: validateField(field, value) }));
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const hoursError = validateField('engine_hours', engineHours);
    const milesError = validateField('miles', miles);
    setErrors({ engine_hours: hoursError, miles: milesError });
    setTouched({ engine_hours: true, miles: true });
    if (hoursError || milesError) return;

    onSubmit({
      engine_hours: engineHours.trim() ? parseFloat(engineHours) : null,
      miles: miles.trim() ? parseInt(miles, 10) : null,
    });
  };

  // PM status calculation
  const hoursSinceLastPm = previousHours !== null && engineHours.trim() && !errors.engine_hours
    ? parseFloat(engineHours) - previousHours
    : null;
  const isApproachingPm = hoursSinceLastPm !== null && hoursSinceLastPm >= 275 && hoursSinceLastPm < 300;
  const isPmDue = hoursSinceLastPm !== null && hoursSinceLastPm >= 300;

  return (
    <div className="max-w-lg mx-auto">
      {/* Header */}
      <div className="text-center mb-8">
        <h2 className="text-2xl font-bold text-neutral-800 font-heading">Meter Readings</h2>
        <p className="text-neutral-500 mt-1">{apparatusName} · Unit {vehicleNumber}</p>
      </div>

      {/* Previous readings — compact reference strip */}
      {(previousHours !== null || previousMiles !== null) && (
        <div className="flex items-center gap-6 px-4 py-3 mb-6 rounded-lg bg-neutral-100 ring-1 ring-neutral-200/60">
          <span className="text-xs font-semibold uppercase tracking-wider text-neutral-400">Previous</span>
          {previousHours !== null && (
            <span className="text-sm text-neutral-700">
              <span className="font-medium">{previousHours.toLocaleString()}</span>
              <span className="text-neutral-400 ml-1">hrs</span>
            </span>
          )}
          {previousMiles !== null && (
            <span className="text-sm text-neutral-700">
              <span className="font-medium">{previousMiles.toLocaleString()}</span>
              <span className="text-neutral-400 ml-1">mi</span>
            </span>
          )}
        </div>
      )}

      {/* PM Status Alerts */}
      {isPmDue && (
        <div className="mb-6 px-4 py-3 rounded-lg bg-red-50 ring-1 ring-red-200 flex items-start gap-3">
          <svg className="w-5 h-5 text-red-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
          </svg>
          <div>
            <p className="font-semibold text-red-800 text-sm">PM Service Due</p>
            <p className="text-red-700 text-xs mt-0.5">
              {hoursSinceLastPm?.toFixed(1)}h since last PM — 300h interval exceeded.
            </p>
          </div>
        </div>
      )}

      {isApproachingPm && !isPmDue && (
        <div className="mb-6 px-4 py-3 rounded-lg bg-amber-50 ring-1 ring-amber-200 flex items-start gap-3">
          <svg className="w-5 h-5 text-amber-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <div>
            <p className="font-semibold text-amber-800 text-sm">PM Approaching</p>
            <p className="text-amber-700 text-xs mt-0.5">
              {hoursSinceLastPm?.toFixed(1)}h of 300h cycle used.
            </p>
          </div>
        </div>
      )}

      {/* Form */}
      <form onSubmit={handleSubmit} className="space-y-5">
        <div>
          <label htmlFor="engine_hours" className="block text-sm font-semibold text-neutral-700 mb-1.5">
            Engine Hours
          </label>
          <div className="relative">
            <input
              type="text"
              id="engine_hours"
              inputMode="decimal"
              value={engineHours}
              onChange={(e) => handleEngineHoursChange(e.target.value)}
              onBlur={() => handleBlur('engine_hours')}
              className={`w-full px-4 py-3 text-lg font-medium rounded-lg border-2 transition-colors focus:outline-none ${
                errors.engine_hours
                  ? 'border-red-400 focus:border-red-500 bg-red-50/50'
                  : 'border-neutral-200 focus:border-red-500 bg-neutral-50'
              }`}
              placeholder={previousHours !== null ? `Previous: ${previousHours}` : 'Enter hours'}
            />
            <span className="absolute right-4 top-1/2 -translate-y-1/2 text-neutral-400 text-sm pointer-events-none">hrs</span>
          </div>
          {errors.engine_hours && (
            <p className="mt-1.5 text-xs font-medium text-red-600">{errors.engine_hours}</p>
          )}
        </div>

        <div>
          <label htmlFor="miles" className="block text-sm font-semibold text-neutral-700 mb-1.5">
            Odometer
          </label>
          <div className="relative">
            <input
              type="text"
              id="miles"
              inputMode="numeric"
              value={miles}
              onChange={(e) => handleMilesChange(e.target.value)}
              onBlur={() => handleBlur('miles')}
              className={`w-full px-4 py-3 text-lg font-medium rounded-lg border-2 transition-colors focus:outline-none ${
                errors.miles
                  ? 'border-red-400 focus:border-red-500 bg-red-50/50'
                  : 'border-neutral-200 focus:border-red-500 bg-neutral-50'
              }`}
              placeholder={previousMiles !== null ? `Previous: ${previousMiles.toLocaleString()}` : 'Enter miles'}
            />
            <span className="absolute right-4 top-1/2 -translate-y-1/2 text-neutral-400 text-sm pointer-events-none">mi</span>
          </div>
          {errors.miles && (
            <p className="mt-1.5 text-xs font-medium text-red-600">{errors.miles}</p>
          )}
        </div>

        {/* Live PM delta indicator */}
        {hoursSinceLastPm !== null && !errors.engine_hours && (
          <div className={`flex items-center justify-between px-4 py-2.5 rounded-lg text-sm ${
            isPmDue ? 'bg-red-50 text-red-700' : isApproachingPm ? 'bg-amber-50 text-amber-700' : 'bg-teal-50 text-teal-700'
          }`}>
            <span>Hours since PM</span>
            <span className="font-bold tabular-nums">{hoursSinceLastPm.toFixed(1)} / 300</span>
          </div>
        )}

        {/* Navigation */}
        <div className="flex gap-3 pt-4">
          <button
            type="button"
            onClick={onBack}
            className="flex-1 min-h-[48px] px-4 py-3 rounded-lg border-2 border-neutral-200 text-neutral-700 font-semibold hover:bg-neutral-50 active:bg-neutral-100 transition-colors touch-manipulation"
          >
            Back
          </button>
          <button
            type="submit"
            className="flex-1 min-h-[48px] px-4 py-3 rounded-lg bg-red-600 text-white font-semibold hover:bg-red-700 active:bg-red-800 transition-colors shadow-sm touch-manipulation"
          >
            Continue
          </button>
        </div>
      </form>
    </div>
  );
}
