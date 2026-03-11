import { useState, useRef, useCallback } from 'react';
import { Link } from 'react-router-dom';
import SignatureCanvas from 'react-signature-canvas';
import { enqueueSubmission, processPendingSubmissions } from '../../lib/sync';

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
}

const DEFAULT_CHECKLIST: Omit<ChecklistItem, 'status'>[] = [
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
  // Apparatus Cleanliness
  { id: 'ac_insp_sheet', label: 'Insp Sheet', category: 'Apparatus Cleanliness' },
  { id: 'ac_exterior', label: 'Exterior', category: 'Apparatus Cleanliness' },
  { id: 'ac_cab', label: 'Cab', category: 'Apparatus Cleanliness' },
  { id: 'ac_compartments', label: 'Compartments', category: 'Apparatus Cleanliness' },
  { id: 'ac_undercarriage', label: 'Undercarriage', category: 'Apparatus Cleanliness' },
  { id: 'ac_wheels', label: 'Wheels', category: 'Apparatus Cleanliness' },
  // Equipment Cleanliness
  { id: 'ec_gas', label: 'Portable Gas Equip', category: 'Equipment Cleanliness' },
  { id: 'ec_electrical', label: 'Electrical Equip', category: 'Equipment Cleanliness' },
  { id: 'ec_hand_tools', label: 'Hand Tools', category: 'Equipment Cleanliness' },
  { id: 'ec_ladders', label: 'Ladders', category: 'Equipment Cleanliness' },
  // Spot Checks
  { id: 'sc_fuel', label: 'Fuel', category: 'Spot Checks' },
  { id: 'sc_oil', label: 'Oil', category: 'Spot Checks' },
  { id: 'sc_tires', label: 'Tires', category: 'Spot Checks' },
  { id: 'sc_oxygen', label: 'Portable Oxygen', category: 'Spot Checks' },
  { id: 'sc_booster', label: 'Booster Tank', category: 'Spot Checks' },
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
  const [submitted, setSubmitted] = useState(false);
  const sigRef = useRef<SignatureCanvas | null>(null);

  const [form, setForm] = useState<FormData>({
    station: '',
    date: new Date().toISOString().split('T')[0],
    checklist: DEFAULT_CHECKLIST.map((item) => ({ ...item, status: null })),
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
      checklist: prev.checklist.map((item) => item.id === id ? { ...item, status: item.status === status ? null : status } : item),
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
    setSubmitting(true);
    try {
      await enqueueSubmission('station_inspection', {
        station: form.station,
        inspection_type: 'Saturday Station Inspection',
        date: form.date,
        checklist: form.checklist.map(({ id, label, category, status }) => ({ id, label, category, status })),
        extinguishing_system_date: form.extinguishingSystemDate,
        notes: form.notes,
        sog_mandate_acknowledged: form.sogMandate,
        signature: form.signature,
        submitted_at: new Date().toISOString(),
      });
      processPendingSubmissions('/api/admin').catch(() => {});
      setSubmitted(true);
    } catch {
      alert('Failed to save. Your inspection will be retried automatically.');
    } finally {
      setSubmitting(false);
    }
  };

  if (submitted) {
    return (
      <div className="text-center py-16 space-y-6">
        <div className="w-20 h-20 mx-auto bg-emerald-50 rounded-full flex items-center justify-center">
          <svg className="w-10 h-10 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <h2 className="text-2xl font-bold text-neutral-800 font-heading">Inspection Submitted</h2>
        <p className="text-neutral-500 max-w-md mx-auto">Your Saturday station inspection has been queued and will sync when online.</p>
        <Link to="/forms-hub" className="inline-flex items-center min-h-[44px] px-6 py-3 bg-red-600 text-white rounded-lg font-medium hover:bg-red-700 transition-colors">
          Back to Forms Hub
        </Link>
      </div>
    );
  }

  const stepLabels = ['Station', 'Checklist', 'Sign & Confirm', 'Review'];
  const categories = [...new Set(form.checklist.map((i) => i.category))];

  return (
    <div className="max-w-2xl mx-auto">
      <div className="mb-8">
        <Link to="/forms-hub" className="inline-flex items-center text-neutral-500 hover:text-neutral-700 mb-4 min-h-[44px]">
          <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
          Back
        </Link>
        <h1 className="text-2xl font-bold text-neutral-800 font-heading">Saturday Station Inspection</h1>
        <p className="text-sm text-neutral-500 mt-1">Miami Beach Fire Department — Weekly Facility & Apparatus Check</p>
      </div>

      {/* Stepper */}
      <nav className="flex items-center gap-2 mb-8 overflow-x-auto" aria-label="Progress">
        {stepLabels.map((label, i) => (
          <div key={label} className="flex items-center gap-2 flex-shrink-0">
            <div className={`w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium transition-colors ${i + 1 <= step ? 'bg-red-600 text-white' : 'bg-neutral-200 text-neutral-500'}`}>
              {i + 1}
            </div>
            <span className="text-sm text-neutral-600 hidden sm:inline">{label}</span>
            {i < stepLabels.length - 1 && <div className="w-6 h-px bg-neutral-300" />}
          </div>
        ))}
      </nav>

      {/* Step 1: Station & Date */}
      {step === 1 && (
        <div className="space-y-6">
          <div>
            <label className="block text-sm font-medium text-neutral-700 mb-2">Station</label>
            <select value={form.station} onChange={(e) => update({ station: e.target.value })} className="w-full min-h-[44px] px-4 py-3 bg-white border border-neutral-300 rounded-lg text-neutral-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
              <option value="">Select station...</option>
              {STATIONS.map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-neutral-700 mb-2">Inspection Date</label>
            <input type="date" value={form.date} onChange={(e) => update({ date: e.target.value })} className="w-full min-h-[44px] px-4 py-3 bg-white border border-neutral-300 rounded-lg text-neutral-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent" />
          </div>
        </div>
      )}

      {/* Step 2: Checklist */}
      {step === 2 && (
        <div className="space-y-6">
          {categories.map((cat) => (
            <div key={cat}>
              <h3 className="text-sm font-semibold text-neutral-500 uppercase tracking-wider mb-3">{cat}</h3>
              <div className="space-y-2">
                {form.checklist.filter((i) => i.category === cat).map((item) => (
                  <div key={item.id}>
                    <div className="flex items-center justify-between bg-neutral-100 rounded-lg ring-1 ring-neutral-200/60 p-3 gap-3">
                      <span className="text-sm text-neutral-700 flex-1">{item.label}</span>
                      <div className="flex gap-1 flex-shrink-0">
                        {(['pass', 'fail', 'na'] as const).map((status) => (
                          <button key={status} onClick={() => updateChecklistItem(item.id, status)} className={`min-w-[44px] min-h-[44px] px-3 py-1 rounded-lg text-xs font-medium transition-colors ${item.status === status ? (status === 'pass' ? 'bg-emerald-600 text-white' : status === 'fail' ? 'bg-red-600 text-white' : 'bg-neutral-600 text-white') : 'bg-white text-neutral-600 ring-1 ring-neutral-200 hover:ring-neutral-300'}`}>
                            {status === 'na' ? 'N/A' : status.charAt(0).toUpperCase() + status.slice(1)}
                          </button>
                        ))}
                      </div>
                    </div>
                    {/* Extinguishing System date input */}
                    {item.id === 'kit_ext_system' && (
                      <div className="ml-4 mt-2 mb-1">
                        <label className="block text-xs font-medium text-neutral-500 mb-1">Extinguishing System Inspection Date</label>
                        <input type="date" value={form.extinguishingSystemDate} onChange={(e) => update({ extinguishingSystemDate: e.target.value })} className="w-full max-w-xs min-h-[40px] px-3 py-2 bg-white border border-neutral-300 rounded-lg text-sm text-neutral-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent" />
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </div>
          ))}
          {/* Notes */}
          <div>
            <label className="block text-sm font-medium text-neutral-700 mb-2">Notes (optional)</label>
            <textarea value={form.notes} onChange={(e) => update({ notes: e.target.value })} rows={3} placeholder="Additional observations or deficiencies..." className="w-full px-4 py-3 bg-white border border-neutral-300 rounded-lg text-neutral-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent resize-none" />
          </div>
        </div>
      )}

      {/* Step 3: Signature + SOG Mandate */}
      {step === 3 && (
        <div className="space-y-6">
          <p className="text-neutral-600">Inspector signature:</p>
          <div className="border-2 border-dashed border-neutral-300 rounded-xl bg-white overflow-hidden">
            <SignatureCanvas ref={sigRef} penColor="#1a1a1a" canvasProps={{ className: 'w-full', style: { height: 200, width: '100%' } }} onEnd={handleSaveSig} />
          </div>
          <button onClick={handleClearSig} className="min-h-[44px] px-4 py-2 text-sm text-neutral-500 hover:text-neutral-700 underline">Clear Signature</button>

          {/* SOG Mandate Acknowledgment */}
          <div className="bg-amber-50 border border-amber-200 rounded-xl p-4">
            <label className="flex items-start gap-3 cursor-pointer">
              <input
                type="checkbox"
                checked={form.sogMandate}
                onChange={(e) => update({ sogMandate: e.target.checked })}
                className="mt-1 w-5 h-5 rounded border-amber-400 text-red-600 focus:ring-red-500"
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
          <div className="bg-neutral-100 rounded-xl ring-1 ring-neutral-200/60 p-6 space-y-4">
            <div><span className="text-sm text-neutral-500">Station</span><p className="font-medium text-neutral-800">{form.station}</p></div>
            <div><span className="text-sm text-neutral-500">Date</span><p className="font-medium text-neutral-800">{form.date}</p></div>
            <div>
              <span className="text-sm text-neutral-500">Checklist Summary</span>
              <div className="flex gap-4 mt-1">
                <span className="text-sm text-emerald-700 font-medium">{form.checklist.filter((i) => i.status === 'pass').length} Pass</span>
                <span className="text-sm text-red-700 font-medium">{form.checklist.filter((i) => i.status === 'fail').length} Fail</span>
                <span className="text-sm text-neutral-600 font-medium">{form.checklist.filter((i) => i.status === 'na').length} N/A</span>
              </div>
            </div>
            {form.extinguishingSystemDate && (
              <div><span className="text-sm text-neutral-500">Extinguishing System Date</span><p className="font-medium text-neutral-800">{form.extinguishingSystemDate}</p></div>
            )}
            <div>
              <span className="text-sm text-neutral-500">SOG Mandate</span>
              <p className="font-medium text-emerald-700">✓ Acknowledged</p>
            </div>
            {form.notes && <div><span className="text-sm text-neutral-500">Notes</span><p className="text-neutral-800">{form.notes}</p></div>}
            {form.signature && <div><span className="text-sm text-neutral-500">Signature</span><img src={form.signature} alt="Signature" className="mt-2 h-16 border border-neutral-200 rounded bg-white" /></div>}
          </div>
        </div>
      )}

      {/* Navigation */}
      <div className="flex justify-between mt-8">
        <button onClick={() => setStep((s) => s - 1)} disabled={step === 1} className="min-h-[44px] px-6 py-3 text-neutral-600 hover:text-neutral-800 disabled:opacity-30 disabled:cursor-not-allowed">
          Previous
        </button>
        {step < 4 ? (
          <button onClick={() => setStep((s) => s + 1)} disabled={!canNext()} className="min-h-[44px] px-6 py-3 bg-red-600 text-white rounded-lg font-medium hover:bg-red-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
            Next
          </button>
        ) : (
          <button onClick={handleSubmit} disabled={submitting} className="min-h-[44px] px-8 py-3 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition-colors disabled:opacity-50">
            {submitting ? 'Submitting...' : 'Submit Inspection'}
          </button>
        )}
      </div>
    </div>
  );
}
