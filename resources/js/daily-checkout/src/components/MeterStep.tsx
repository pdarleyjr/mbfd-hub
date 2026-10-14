import { useState, type RefObject } from 'react';
import { Clock, TriangleAlert } from 'lucide-react';
import type { PmHealthStatus } from '../types';

export interface MeterData {
  engine_hours: number | null;
  miles: number | null;
}

interface MeterStepProps {
  apparatusId: number;
  initialData: MeterData;
  pmHealth?: PmHealthStatus | null;
  previousHours: number | null;
  previousMiles: number | null;
  onSubmit: (data: MeterData) => void;
  onChange: (data: MeterData) => void;
  onBack: () => void;
  continueLabel: string;
  maxMiles: number;
  formRef: RefObject<HTMLFormElement | null>;
}

// Reading inputs keep an 18px reading size; data-input-size opts them out of the 16px coarse-pointer floor.
const inputClass = (error?: string) => `w-full rounded-hub-control border px-4 py-3 pr-14 text-lg font-medium tabular-nums text-hub-ink transition-colors focus:border-hub-focus focus:outline-none ${
  error ? 'border-hub-danger bg-hub-danger-bg' : 'border-hub-control-border bg-hub-well'
}`;

export default function MeterStep({
  pmHealth,
  initialData,
  previousHours,
  previousMiles,
  onSubmit,
  onChange,
  onBack,
  continueLabel,
  maxMiles,
  formRef,
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

    const numValue = Number(value);
    if (isNaN(numValue)) return 'Please enter a valid number';
    if (numValue < 0) return 'Value cannot be negative';

    if (!Number.isFinite(numValue) || numValue > (field === 'engine_hours' ? 9999999.9 : maxMiles)) return 'Reading is outside the supported range';
    if (field === 'engine_hours' && !/^\d+(\.\d)?$/.test(value)) return 'Enter hours with at most one decimal place';
    if (field === 'miles' && !/^\d+$/.test(value)) return 'Enter whole miles';

    return undefined;
  };

  const handleEngineHoursChange = (value: string) => {
    setEngineHours(value);
    if (!value.trim() || (/^-?\d+(\.\d*)?$/.test(value) && Number.isFinite(Number(value)))) onChange({ ...initialData, engine_hours: !value.trim() ? null : Number(value) });
    if (touched.engine_hours) {
      setErrors(prev => ({ ...prev, engine_hours: validateField('engine_hours', value) }));
    }
  };

  const handleMilesChange = (value: string) => {
    setMiles(value);
    if (!value.trim() || (/^-?\d+(\.\d*)?$/.test(value) && Number.isFinite(Number(value)))) onChange({ ...initialData, miles: !value.trim() ? null : Number(value) });
    if (touched.miles) {
      setErrors(prev => ({ ...prev, miles: validateField('miles', value) }));
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

  const hoursSinceLastPm = pmHealth?.hours_since_pm ?? null;
  const isApproachingPm = pmHealth?.status === 'yellow';
  const isPmDue = pmHealth?.status === 'red';

  return (
    <div data-hub-ui="2" className="inspection-panel mx-auto max-w-lg p-4 sm:p-5">
      <div className="mb-4">
        <h2 className="text-xl font-bold text-hub-ink font-heading">Readings · Optional</h2>
        <p className="mt-1 text-hub-n-600">Enter current readings when available. You may continue without them.</p>
      </div>

      {((engineHours.trim() !== '' && previousHours !== null && Number(engineHours) < previousHours) || (miles.trim() !== '' && previousMiles !== null && Number(miles) < previousMiles)) && <p className="mb-4 rounded-hub-control border border-hub-warning-border bg-hub-warning-bg px-3 py-2 text-sm text-hub-warning-strong">This is below the previous reading. Record what the display shows; it will be saved for review.</p>}

      {/* Previous readings: a compact reference well */}
      {(previousHours !== null || previousMiles !== null) && (
        <div className="hub-well mb-5 flex flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3">
          <span className="text-[13px] font-semibold text-hub-n-600">Previous</span>
          {previousHours !== null && (
            <span className="text-sm tabular-nums text-hub-ink">
              <span className="font-semibold">{previousHours.toLocaleString()}</span>
              <span className="ml-1 text-hub-n-600">hrs</span>
            </span>
          )}
          {previousMiles !== null && (
            <span className="text-sm tabular-nums text-hub-ink">
              <span className="font-semibold">{previousMiles.toLocaleString()}</span>
              <span className="ml-1 text-hub-n-600">mi</span>
            </span>
          )}
        </div>
      )}

      {/* PM status: the text carries the state; the callout tone reinforces it. */}
      {isPmDue && (
        <div className="mb-5 flex items-start gap-3 rounded-hub-control border border-hub-danger-border bg-hub-danger-bg px-4 py-3">
          <TriangleAlert className="mt-0.5 h-5 w-5 shrink-0 text-hub-danger" aria-hidden="true" />
          <div>
            <p className="text-sm font-semibold text-hub-danger">PM Service Due</p>
            <p className="mt-0.5 text-[13px] tabular-nums text-hub-n-800">
              {hoursSinceLastPm?.toFixed(1)}h since last PM · {pmHealth?.interval_hours}h service interval.
            </p>
          </div>
        </div>
      )}

      {isApproachingPm && !isPmDue && (
        <div className="mb-5 flex items-start gap-3 rounded-hub-control border border-hub-warning-border bg-hub-warning-bg px-4 py-3">
          <Clock className="mt-0.5 h-5 w-5 shrink-0 text-hub-warning" aria-hidden="true" />
          <div>
            <p className="text-sm font-semibold text-hub-warning-strong">PM Approaching</p>
            <p className="mt-0.5 text-[13px] tabular-nums text-hub-n-800">
              {hoursSinceLastPm?.toFixed(1)}h of {pmHealth?.interval_hours}h cycle used.
            </p>
          </div>
        </div>
      )}

      <form ref={formRef} onSubmit={handleSubmit} className="space-y-5">
        <div>
          <label htmlFor="engine_hours" className="mb-1.5 block text-sm font-semibold text-hub-ink">
            Engine Hours
          </label>
          <div className="relative">
            <input
              type="text"
              id="engine_hours"
              data-input-size="large"
              ref={input => { input?.setCustomValidity(validateField('engine_hours', engineHours) ?? ''); }}
              onInvalid={() => handleBlur('engine_hours')}
              inputMode="decimal"
              value={engineHours}
              onChange={(e) => handleEngineHoursChange(e.target.value)}
              onBlur={() => handleBlur('engine_hours')}
              aria-invalid={errors.engine_hours ? true : undefined}
              aria-describedby={errors.engine_hours ? 'engine_hours-error' : undefined}
              className={inputClass(errors.engine_hours)}
              placeholder={previousHours !== null ? `Previous: ${previousHours}` : 'Enter hours'}
            />
            <span className="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-sm text-hub-n-600" aria-hidden="true">hrs</span>
          </div>
          {errors.engine_hours && (
            <p id="engine_hours-error" className="mt-1.5 text-[13px] font-medium text-hub-danger">{errors.engine_hours}</p>
          )}
        </div>

        <div>
          <label htmlFor="miles" className="mb-1.5 block text-sm font-semibold text-hub-ink">
            Odometer
          </label>
          <div className="relative">
            <input
              type="text"
              id="miles"
              data-input-size="large"
              ref={input => { input?.setCustomValidity(validateField('miles', miles) ?? ''); }}
              onInvalid={() => handleBlur('miles')}
              inputMode="numeric"
              value={miles}
              onChange={(e) => handleMilesChange(e.target.value)}
              onBlur={() => handleBlur('miles')}
              aria-invalid={errors.miles ? true : undefined}
              aria-describedby={errors.miles ? 'miles-error' : undefined}
              className={inputClass(errors.miles)}
              placeholder={previousMiles !== null ? `Previous: ${previousMiles.toLocaleString()}` : 'Enter miles'}
            />
            <span className="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-sm text-hub-n-600" aria-hidden="true">mi</span>
          </div>
          {errors.miles && (
            <p id="miles-error" className="mt-1.5 text-[13px] font-medium text-hub-danger">{errors.miles}</p>
          )}
        </div>

        {/* Live PM delta indicator */}
        {hoursSinceLastPm !== null && !errors.engine_hours && (
          <div className="hub-well flex flex-wrap items-center justify-between gap-3 px-4 py-2.5 text-sm">
            <span className="text-hub-n-600">Hours since PM</span>
            <span className="flex items-center gap-2">
              <span className="font-bold tabular-nums text-hub-ink">{hoursSinceLastPm.toFixed(1)} / {pmHealth?.interval_hours}</span>
              <span className={`hub-tag ${isPmDue ? 'hub-tag--danger' : isApproachingPm ? 'hub-tag--warning' : 'hub-tag--success'}`}>{isPmDue ? 'Due' : isApproachingPm ? 'Due soon' : 'Current'}</span>
            </span>
          </div>
        )}

        <div className="flex gap-3 pt-2">
          <button type="button" onClick={onBack} className="hub-btn hub-btn--secondary flex-1 touch-manipulation">
            Back
          </button>
          <button type="submit" className="hub-btn hub-btn--primary hub-btn--wrap flex-1 touch-manipulation">
            {continueLabel}
          </button>
        </div>
      </form>
    </div>
  );
}
