import { useState, useRef, useCallback } from 'react';
import SignatureCanvas from 'react-signature-canvas';
import { submitOrQueue, type SubmissionOutcome } from '../../lib/sync';
import PreviousPageButton from '../PreviousPageButton';

const STATIONS = [
  'Station 1',
  'Station 2',
  'Station 3',
  'Station 4',
  'Station 6',
];

interface ChecklistItem {
  id: string;
  label: string;
  category: string;
  status: 'pass' | 'fail' | 'na' | null;
  failNotes: string;
  failImage: string;
}

const DEFAULT_CHECKLIST: Omit<ChecklistItem, 'status' | 'failNotes' | 'failImage'>[] = [
  // Apparatus Area
  { id: 'app_doors', label: 'Apparatus Doors', category: 'Apparatus Area' },
  { id: 'app_floors', label: 'Floors & Ramps', category: 'Apparatus Area' },
  { id: 'app_windows', label: 'Windows & Walls', category: 'Apparatus Area' },
  { id: 'app_generator', label: 'Emergency Generator Room', category: 'Apparatus Area' },
  // Dormitories
  { id: 'dorm_beds', label: 'Beds', category: 'Dormitories' },
  { id: 'dorm_floors', label: 'Floors', category: 'Dormitories' },
  { id: 'dorm_windows', label: 'Windows & Walls', category: 'Dormitories' },
  // Kitchen & Dining
  { id: 'kit_stove', label: 'Stove & Hood', category: 'Kitchen & Dining' },
  { id: 'kit_fridge', label: 'Refrigerator', category: 'Kitchen & Dining' },
  { id: 'kit_floors', label: 'Floors', category: 'Kitchen & Dining' },
  { id: 'kit_windows', label: 'Windows & Walls', category: 'Kitchen & Dining' },
  { id: 'kit_cabinets', label: 'Cabinets', category: 'Kitchen & Dining' },
  { id: 'kit_ext_system', label: 'Extinguishing System', category: 'Kitchen & Dining' },
  // Bathrooms
  { id: 'bath_showers', label: 'Showers', category: 'Bathrooms' },
  { id: 'bath_lavatory', label: 'Lavatory & Toilets', category: 'Bathrooms' },
  { id: 'bath_windows', label: 'Windows & Walls', category: 'Bathrooms' },
  { id: 'bath_floors', label: 'Floors', category: 'Bathrooms' },
  // Offices & Lobby
  { id: 'off_furnishings', label: 'Furnishings', category: 'Offices & Lobby' },
  { id: 'off_floors', label: 'Floors', category: 'Offices & Lobby' },
  { id: 'off_windows', label: 'Windows', category: 'Offices & Lobby' },
];

interface FormData {
  station: string;
  date: string;
  checklist: ChecklistItem[];
  extinguishingSystemDate: string;
  notes: string;
  sogMandate: boolean;
  signature: string;
}

export default function StationInspectionWizard() {
  const [step, setStep] = useState(1);
  const [submitting, setSubmitting] = useState(false);
  const [submissionOutcome, setSubmissionOutcome] = useState<SubmissionOutcome | null>(null);
  const [submissionError, setSubmissionError] = useState<string | null>(null);
  const sigRef = useRef<SignatureCanvas | null>(null);

  const [form, setForm] = useState<FormData>({
    station: '',
    date: new Date().toISOString().split('T')[0],
    checklist: DEFAULT_CHECKLIST.map((item) => ({ ...item, status: null, failNotes: '', failImage: '' })),
    extinguishingSystemDate: '',
    notes: '',
    sogMandate: false,
    signature: '',
  });

  const update = useCallback((patch: Partial<FormData>) => {
    setForm((prev) => ({ ...prev, ...patch }));
  }, []);

  const updateChecklistItem = (id: string, status: 'pass' | 'fail' | 'na') => {
    setForm((prev) => ({
      ...prev,
      checklist: prev.checklist.map((item) =>
        item.id === id
          ? {
              ...item,
              status: item.status === status ? null : status,
              // Clear fail data when switching away from fail
              failNotes: (item.status === status ? null : status) === 'fail' ? item.failNotes : '',
              failImage: (item.status === status ? null : status) === 'fail' ? item.failImage : '',
            }
          : item
      ),
    }));
  };

  const updateFailNotes = (id: string, notes: string) => {
    setForm((prev) => ({
      ...prev,
      checklist: prev.checklist.map((item) =>
        item.id === id ? { ...item, failNotes: notes } : item
      ),
    }));
  };

  const handleFailImage = (id: string, file: File) => {
    const reader = new FileReader();
    reader.onloadend = () => {
      setForm((prev) => ({
        ...prev,
        checklist: prev.checklist.map((item) =>
          item.id === id ? { ...item, failImage: reader.result as string } : item
        ),
      }));
    };
    reader.readAsDataURL(file);
  };

  const passAllCategory = (category: string) => {
    setForm((prev) => ({
      ...prev,
      checklist: prev.checklist.map((item) =>
        item.category === category
          ? { ...item, status: 'pass' as const, failNotes: '', failImage: '' }
          : item
      ),
    }));
  };

  const canNext = (): boolean => {
    if (step === 1) return !!form.station && !!form.date;
    if (step === 2) return form.checklist.every((item) => item.status !== null);
    if (step === 3) return !!form.signature && form.sogMandate;
    return true;
  };

  const handleClearSig = () => {
    sigRef.current?.clear();
    update({ signature: '' });
  };

  const handleSaveSig = () => {
    if (sigRef.current && !sigRef.current.isEmpty()) {
      update({ signature: sigRef.current.toDataURL('image/png') });
    }
  };

  const handleSubmit = async () => {
    setSubmissionError(null);
    setSubmitting(true);
    try {
      const outcome = await submitOrQueue('station_inspection', {
        station: form.station,
        inspection_type: 'Saturday Station Inspection',
        date: form.date,
        checklist: form.checklist.map(({ id, label, category, status, failNotes, failImage }) => ({ id, label, category, status, failNotes: failNotes || undefined, failImage: failImage || undefined })),
        extinguishing_system_date: form.extinguishingSystemDate,
        notes: form.notes,
        sog_mandate_acknowledged: form.sogMandate,
        signature: form.signature,
        submitted_at: new Date().toISOString(),
      }, '/api/public');
      setSubmissionOutcome(outcome);
    } catch (error) {
      setSubmissionError(error instanceof Error ? error.message : 'The inspection could not be submitted. Please review the form and try again.');
    } finally {
      setSubmitting(false);
    }
  };

  if (submissionOutcome) {
    return (
      <div className="text-center py-16 space-y-6">
        <div className="w-20 h-20 mx-auto bg-emerald-50 rounded-full flex items-center justify-center">
          <svg className="w-10 h-10 text-hub-success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <h2 className="text-2xl font-bold text-hub-ink font-heading">
          {submissionOutcome === 'submitted' ? 'Inspection Submitted' : 'Inspection Saved Offline'}
        </h2>
        <p className="text-hub-muted max-w-md mx-auto">
          {submissionOutcome === 'submitted'
            ? 'Your Saturday station inspection is available on the Admin Dashboard.'
            : 'Your inspection is safely queued on this device and will sync automatically when the connection returns.'}
        </p>
        <PreviousPageButton contextual fallback="/forms-hub" className="inline-flex items-center min-h-[44px] px-6 py-3 bg-hub-blue text-white rounded-lg font-medium hover:bg-hub-blue-strong transition-colors" />
      </div>
    );
  }

  const stepLabels = ['Station', 'Checklist', 'Sign & Confirm', 'Review'];
  const categories = [...new Set(form.checklist.map((i) => i.category))];

  return (
    <div className="max-w-2xl mx-auto">
      <div className="mb-8">
        <PreviousPageButton contextual fallback="/forms-hub" className="inline-flex items-center text-hub-muted hover:text-hub-ink-secondary mb-4 min-h-[44px]"/>
        <h1 className="text-2xl font-bold text-hub-ink font-heading">Saturday Station Inspection</h1>
        <p className="text-sm text-hub-muted mt-1">Miami Beach Fire Department — Weekly Facility & Apparatus Check</p>
      </div>

      {/* Stepper */}
      <nav className="flex items-center gap-2 mb-8 overflow-x-auto" aria-label="Progress">
        {stepLabels.map((label, i) => (
          <div key={label} className="flex items-center gap-2 flex-shrink-0">
            <div className={`w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium transition-colors ${i + 1 <= step ? 'bg-hub-blue text-white' : 'bg-hub-border text-hub-muted'}`}>
              {i + 1}
            </div>
            <span className="text-sm text-hub-ink-secondary hidden sm:inline">{label}</span>
            {i < stepLabels.length - 1 && <div className="w-6 h-px bg-hub-border" />}
          </div>
        ))}
      </nav>

      {/* Step 1: Station & Date */}
      {step === 1 && (
        <div className="space-y-6">
          <div>
            <label htmlFor="station-inspection-station" className="block text-sm font-medium text-hub-ink-secondary mb-2">Station</label>
            <select id="station-inspection-station" value={form.station} onChange={(e) => update({ station: e.target.value })} className="w-full min-h-[44px] px-4 py-3 bg-white border border-hub-border-strong rounded-lg text-hub-ink focus:outline-none focus:ring-2 focus:ring-hub-focus focus:border-transparent">
              <option value="">Select station...</option>
              {STATIONS.map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
          </div>
          <div>
            <label htmlFor="station-inspection-date" className="block text-sm font-medium text-hub-ink-secondary mb-2">Inspection Date</label>
            <input id="station-inspection-date" type="date" value={form.date} onChange={(e) => update({ date: e.target.value })} className="w-full min-h-[44px] px-4 py-3 bg-white border border-hub-border-strong rounded-lg text-hub-ink focus:outline-none focus:ring-2 focus:ring-hub-focus focus:border-transparent" />
          </div>
        </div>
      )}

      {/* Step 2: Checklist */}
      {step === 2 && (
        <div className="space-y-6">
          {categories.map((cat) => (
            <div key={cat}>
              <div className="flex items-center justify-between mb-3">
                <h3 className="text-sm font-semibold text-hub-muted uppercase tracking-wider">{cat}</h3>
                <button
                  type="button"
                  onClick={() => passAllCategory(cat)}
                  className="px-3 py-1.5 text-xs font-medium text-white bg-hub-header rounded-lg hover:bg-hub-header-elevated transition-colors focus:outline-none focus:ring-2 focus:ring-hub-focus focus:ring-offset-2"
                >
                  Pass All
                </button>
              </div>
              <div className="space-y-2">
                {form.checklist.filter((i) => i.category === cat).map((item) => (
                  <div key={item.id}>
                    <div className="flex items-center justify-between bg-hub-surface-muted rounded-lg ring-1 ring-hub-border/60 p-3 gap-3">
                      <span className="text-sm text-hub-ink-secondary flex-1">{item.label}</span>
                      <div className="flex gap-1 flex-shrink-0" role="group" aria-label={`${item.label} status`}>
                        {(['pass', 'fail', 'na'] as const).map((status) => (
                          <button type="button" key={status} onClick={() => updateChecklistItem(item.id, status)} aria-pressed={item.status === status} aria-label={`${item.label}: ${status === 'na' ? 'not applicable' : status}`} className={`min-w-[44px] min-h-[44px] px-3 py-1 rounded-lg text-xs font-medium transition-colors ${item.status === status ? (status === 'pass' ? 'bg-hub-success text-white' : status === 'fail' ? 'bg-red-600 text-white' : 'bg-hub-muted text-white') : 'bg-white text-hub-ink-secondary ring-1 ring-hub-border hover:ring-hub-border-strong'}`}>
                            {status === 'na' ? 'N/A' : status.charAt(0).toUpperCase() + status.slice(1)}
                          </button>
                        ))}
                      </div>
                    </div>
                    {/* Conditional fail inputs */}
                    {item.status === 'fail' && (
                      <div className="ml-4 mt-2 mb-1 space-y-2">
                        <textarea
                          value={item.failNotes}
                          onChange={(e) => updateFailNotes(item.id, e.target.value)}
                          rows={2}
                          placeholder="Describe the issue..."
                          className="w-full px-3 py-2 bg-white border border-hub-border-strong rounded-lg text-sm text-hub-ink focus:outline-none focus:ring-2 focus:ring-hub-focus focus:ring-offset-2 resize-none"
                        />
                        <div>
                          <label htmlFor={`station-inspection-photo-${item.id}`} className="block text-xs font-medium text-hub-muted mb-1">Photo (optional)</label>
                          <input
                            id={`station-inspection-photo-${item.id}`}
                            type="file"
                            accept="image/*"
                            capture="environment"
                            onChange={(e) => {
                              const file = e.target.files?.[0];
                              if (file) handleFailImage(item.id, file);
                            }}
                            className="block w-full text-sm text-hub-ink-secondary file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-hub-surface-muted file:text-hub-ink-secondary hover:file:bg-hub-border focus:outline-none focus:ring-2 focus:ring-hub-focus focus:ring-offset-2"
                          />
                          {item.failImage && (
                            <img src={item.failImage} alt="Fail evidence" className="mt-2 h-24 rounded-lg border border-hub-border object-cover" />
                          )}
                        </div>
                      </div>
                    )}
                    {/* Extinguishing System date input */}
                    {item.id === 'kit_ext_system' && (
                      <div className="ml-4 mt-2 mb-1">
                        <label htmlFor="station-inspection-extinguishing-system-date" className="block text-xs font-medium text-hub-muted mb-1">Extinguishing System Inspection Date</label>
                        <input id="station-inspection-extinguishing-system-date" type="date" value={form.extinguishingSystemDate} onChange={(e) => update({ extinguishingSystemDate: e.target.value })} className="w-full max-w-xs min-h-[44px] px-3 py-2 bg-white border border-hub-border-strong rounded-lg text-sm text-hub-ink focus:outline-none focus:ring-2 focus:ring-hub-focus focus:border-transparent" />
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </div>
          ))}
          {/* Notes */}
          <div>
            <label htmlFor="station-inspection-notes" className="block text-sm font-medium text-hub-ink-secondary mb-2">Notes (optional)</label>
            <textarea id="station-inspection-notes" value={form.notes} onChange={(e) => update({ notes: e.target.value })} rows={3} placeholder="Additional observations or deficiencies..." className="w-full px-4 py-3 bg-white border border-hub-border-strong rounded-lg text-hub-ink focus:outline-none focus:ring-2 focus:ring-hub-focus focus:border-transparent resize-none" />
          </div>
        </div>
      )}

      {/* Step 3: Signature + SOG Mandate */}
      {step === 3 && (
        <div className="space-y-6">
          <p id="station-inspection-signature-label" className="text-hub-ink-secondary">Inspector signature</p>
          <div className="border-2 border-dashed border-hub-border-strong rounded-xl bg-white overflow-hidden">
            <SignatureCanvas ref={sigRef} penColor="#1a1a1a" canvasProps={{ className: 'w-full', style: { height: 200, width: '100%' }, role: 'img', 'aria-labelledby': 'station-inspection-signature-label', 'aria-description': 'Draw your signature using a mouse, finger, or stylus.' }} onEnd={handleSaveSig} />
          </div>
          <button onClick={handleClearSig} className="min-h-[44px] px-4 py-2 text-sm text-hub-muted hover:text-hub-ink-secondary underline">Clear Signature</button>

          {/* SOG Mandate Acknowledgment */}
          <div className="bg-amber-50 border border-amber-200 rounded-xl p-4">
            <label className="flex items-start gap-3 cursor-pointer">
              <input
                type="checkbox"
                checked={form.sogMandate}
                onChange={(e) => update({ sogMandate: e.target.checked })}
                className="mt-1 w-5 h-5 rounded border-amber-400 text-hub-blue focus:ring-hub-focus"
              />
              <span className="text-sm text-amber-900 font-medium leading-relaxed">
                <strong>Saturday SOG Mandate:</strong> All equipment removed, inspected, and compartments deep cleaned per Standard Operating Guidelines.
              </span>
            </label>
          </div>
        </div>
      )}

      {/* Step 4: Review */}
      {step === 4 && (
        <div className="space-y-6">
          <div className="bg-hub-surface-muted rounded-xl ring-1 ring-hub-border/60 p-6 space-y-4">
            <div><span className="text-sm text-hub-muted">Station</span><p className="font-medium text-hub-ink">{form.station}</p></div>
            <div><span className="text-sm text-hub-muted">Date</span><p className="font-medium text-hub-ink">{form.date}</p></div>
            <div>
              <span className="text-sm text-hub-muted">Checklist Summary</span>
              <div className="flex gap-4 mt-1">
                <span className="text-sm text-hub-success font-medium">{form.checklist.filter((i) => i.status === 'pass').length} Pass</span>
                <span className="text-sm text-red-700 font-medium">{form.checklist.filter((i) => i.status === 'fail').length} Fail</span>
                <span className="text-sm text-hub-ink-secondary font-medium">{form.checklist.filter((i) => i.status === 'na').length} N/A</span>
              </div>
            </div>
            {form.extinguishingSystemDate && (
              <div><span className="text-sm text-hub-muted">Extinguishing System Date</span><p className="font-medium text-hub-ink">{form.extinguishingSystemDate}</p></div>
            )}
            <div>
              <span className="text-sm text-hub-muted">SOG Mandate</span>
              <p className="font-medium text-hub-success">✓ Acknowledged</p>
            </div>
            {form.notes && <div><span className="text-sm text-hub-muted">Notes</span><p className="text-hub-ink">{form.notes}</p></div>}
            {form.signature && <div><span className="text-sm text-hub-muted">Signature</span><img src={form.signature} alt="Signature" className="mt-2 h-16 border border-hub-border rounded bg-white" /></div>}
          </div>
        </div>
      )}

      {/* Navigation */}
      {submissionError && (
        <div className="mt-8 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm font-medium text-red-800" role="alert">
          {submissionError}
        </div>
      )}
      <div className="flex justify-between mt-8">
        <button onClick={() => setStep((s) => s - 1)} disabled={step === 1} className="min-h-[44px] px-6 py-3 text-hub-ink-secondary hover:text-hub-ink disabled:opacity-30 disabled:cursor-not-allowed">
          Previous
        </button>
        {step < 4 ? (
          <button onClick={() => setStep((s) => s + 1)} disabled={!canNext()} className="min-h-[44px] px-6 py-3 bg-hub-blue text-white rounded-lg font-medium hover:bg-hub-blue-strong transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
            Next
          </button>
        ) : (
          <button onClick={handleSubmit} disabled={submitting} className="min-h-[44px] px-8 py-3 bg-hub-blue text-white rounded-lg font-medium hover:bg-hub-blue-strong transition-colors disabled:opacity-50">
            {submitting ? 'Submitting...' : 'Submit Inspection'}
          </button>
        )}
      </div>
    </div>
  );
}
