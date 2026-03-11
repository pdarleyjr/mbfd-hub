import { useState, useRef, useCallback } from 'react';
import { Link } from 'react-router-dom';
import SignatureCanvas from 'react-signature-canvas';
import { enqueueSubmission, processPendingSubmissions } from '../../lib/sync';

const STATIONS = [
  'Station 1 - Headquarters',
  'Station 2 - Riverside',
  'Station 3 - Palm Beach',
  'Station 4 - Northside',
  'Station 5 - Southgate',
];

const INSPECTION_TYPES = [
  'Monthly Safety Inspection',
  'Quarterly Compliance Inspection',
  'Annual Full Inspection',
  'Post-Incident Inspection',
];

interface ChecklistItem {
  id: string;
  label: string;
  category: string;
  status: 'pass' | 'fail' | 'na' | null;
}

const DEFAULT_CHECKLIST: Omit<ChecklistItem, 'status'>[] = [
  { id: 'fire_ext', label: 'Fire extinguishers inspected and current', category: 'Fire Safety' },
  { id: 'smoke_det', label: 'Smoke detectors functional', category: 'Fire Safety' },
  { id: 'co_det', label: 'CO detectors functional', category: 'Fire Safety' },
  { id: 'exit_signs', label: 'Exit signs illuminated and visible', category: 'Egress' },
  { id: 'exit_clear', label: 'Exit paths clear and unobstructed', category: 'Egress' },
  { id: 'elec_panels', label: 'Electrical panels accessible, no hazards', category: 'Electrical' },
  { id: 'gfci', label: 'GFCI outlets tested and functional', category: 'Electrical' },
  { id: 'hvac', label: 'HVAC system operating normally', category: 'Mechanical' },
  { id: 'plumbing', label: 'No plumbing leaks or damage', category: 'Mechanical' },
  { id: 'floors', label: 'Floors clean and free of trip hazards', category: 'General' },
  { id: 'lighting', label: 'All interior/exterior lighting functional', category: 'General' },
  { id: 'first_aid', label: 'First aid kits stocked and accessible', category: 'General' },
];

type OverallStatus = 'pass' | 'fail' | 'needs_attention';

interface FormData {
  station: string;
  inspectionType: string;
  date: string;
  checklist: ChecklistItem[];
  overallStatus: OverallStatus | '';
  notes: string;
  signature: string;
}

export default function StationInspectionWizard() {
  const [step, setStep] = useState(1);
  const [submitting, setSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const sigRef = useRef<SignatureCanvas | null>(null);

  const [form, setForm] = useState<FormData>({
    station: '',
    inspectionType: '',
    date: new Date().toISOString().split('T')[0],
    checklist: DEFAULT_CHECKLIST.map((item) => ({ ...item, status: null })),
    overallStatus: '',
    notes: '',
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
    if (step === 1) return !!form.station && !!form.inspectionType && !!form.date;
    if (step === 2) return form.checklist.every((item) => item.status !== null);
    if (step === 3) return !!form.overallStatus;
    if (step === 4) return !!form.signature;
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
        inspection_type: form.inspectionType,
        date: form.date,
        checklist: form.checklist.map(({ id, label, category, status }) => ({ id, label, category, status })),
        overall_status: form.overallStatus,
        notes: form.notes,
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
        <p className="text-neutral-500 max-w-md mx-auto">Your station inspection has been queued and will sync when online.</p>
        <Link to="/forms-hub" className="inline-flex items-center min-h-[44px] px-6 py-3 bg-red-600 text-white rounded-lg font-medium hover:bg-red-700 transition-colors">
          Back to Forms Hub
        </Link>
      </div>
    );
  }

  const stepLabels = ['Details', 'Checklist', 'Status', 'Signature', 'Review'];
  const categories = [...new Set(form.checklist.map((i) => i.category))];

  const statusConfig: Record<string, { label: string; color: string; bg: string; ring: string }> = {
    pass: { label: 'Pass', color: 'text-emerald-700', bg: 'bg-emerald-50', ring: 'ring-emerald-300' },
    fail: { label: 'Fail', color: 'text-red-700', bg: 'bg-red-50', ring: 'ring-red-300' },
    needs_attention: { label: 'Needs Attention', color: 'text-amber-700', bg: 'bg-amber-50', ring: 'ring-amber-300' },
  };

  return (
    <div className="max-w-2xl mx-auto">
      <div className="mb-8">
        <Link to="/forms-hub" className="inline-flex items-center text-neutral-500 hover:text-neutral-700 mb-4 min-h-[44px]">
          <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
          Back
        </Link>
        <h1 className="text-2xl font-bold text-neutral-800 font-heading">Station Inspection</h1>
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

      {/* Step 1 */}
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
            <label className="block text-sm font-medium text-neutral-700 mb-2">Inspection Type</label>
            <select value={form.inspectionType} onChange={(e) => update({ inspectionType: e.target.value })} className="w-full min-h-[44px] px-4 py-3 bg-white border border-neutral-300 rounded-lg text-neutral-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
              <option value="">Select type...</option>
              {INSPECTION_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
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
                  <div key={item.id} className="flex items-center justify-between bg-neutral-100 rounded-lg ring-1 ring-neutral-200/60 p-3 gap-3">
                    <span className="text-sm text-neutral-700 flex-1">{item.label}</span>
                    <div className="flex gap-1 flex-shrink-0">
                      {(['pass', 'fail', 'na'] as const).map((status) => (
                        <button key={status} onClick={() => updateChecklistItem(item.id, status)} className={`min-w-[44px] min-h-[44px] px-3 py-1 rounded-lg text-xs font-medium transition-colors ${item.status === status ? (status === 'pass' ? 'bg-emerald-600 text-white' : status === 'fail' ? 'bg-red-600 text-white' : 'bg-neutral-600 text-white') : 'bg-white text-neutral-600 ring-1 ring-neutral-200 hover:ring-neutral-300'}`}>
                          {status === 'na' ? 'N/A' : status.charAt(0).toUpperCase() + status.slice(1)}
                        </button>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Step 3: Overall Status + Notes */}
      {step === 3 && (
        <div className="space-y-6">
          <p className="text-neutral-600">Overall inspection result:</p>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            {(Object.entries(statusConfig) as [string, typeof statusConfig[string]][]).map(([key, cfg]) => (
              <button key={key} onClick={() => update({ overallStatus: key as OverallStatus })} className={`min-h-[44px] p-4 rounded-xl ring-1 transition-all text-center ${form.overallStatus === key ? `${cfg.bg} ${cfg.ring} ring-2 shadow-sm` : 'bg-neutral-100 ring-neutral-200 hover:ring-neutral-300'}`}>
                <div className={`text-lg font-semibold ${form.overallStatus === key ? cfg.color : 'text-neutral-700'}`}>{cfg.label}</div>
              </button>
            ))}
          </div>
          <div>
            <label className="block text-sm font-medium text-neutral-700 mb-2">Notes (optional)</label>
            <textarea value={form.notes} onChange={(e) => update({ notes: e.target.value })} rows={4} placeholder="Additional observations or concerns..." className="w-full px-4 py-3 bg-white border border-neutral-300 rounded-lg text-neutral-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent resize-none" />
          </div>
        </div>
      )}

      {/* Step 4: Signature */}
      {step === 4 && (
        <div className="space-y-4">
          <p className="text-neutral-600">Inspector signature:</p>
          <div className="border-2 border-dashed border-neutral-300 rounded-xl bg-white overflow-hidden">
            <SignatureCanvas ref={sigRef} penColor="#1a1a1a" canvasProps={{ className: 'w-full', style: { height: 200, width: '100%' } }} onEnd={handleSaveSig} />
          </div>
          <button onClick={handleClearSig} className="min-h-[44px] px-4 py-2 text-sm text-neutral-500 hover:text-neutral-700 underline">Clear Signature</button>
        </div>
      )}

      {/* Step 5: Review */}
      {step === 5 && (
        <div className="space-y-6">
          <div className="bg-neutral-100 rounded-xl ring-1 ring-neutral-200/60 p-6 space-y-4">
            <div><span className="text-sm text-neutral-500">Station</span><p className="font-medium text-neutral-800">{form.station}</p></div>
            <div><span className="text-sm text-neutral-500">Inspection Type</span><p className="font-medium text-neutral-800">{form.inspectionType}</p></div>
            <div><span className="text-sm text-neutral-500">Date</span><p className="font-medium text-neutral-800">{form.date}</p></div>
            <div>
              <span className="text-sm text-neutral-500">Checklist Summary</span>
              <div className="flex gap-4 mt-1">
                <span className="text-sm text-emerald-700 font-medium">{form.checklist.filter((i) => i.status === 'pass').length} Pass</span>
                <span className="text-sm text-red-700 font-medium">{form.checklist.filter((i) => i.status === 'fail').length} Fail</span>
                <span className="text-sm text-neutral-600 font-medium">{form.checklist.filter((i) => i.status === 'na').length} N/A</span>
              </div>
            </div>
            <div>
              <span className="text-sm text-neutral-500">Overall Status</span>
              <p className={`font-medium ${form.overallStatus ? statusConfig[form.overallStatus].color : ''}`}>{form.overallStatus ? statusConfig[form.overallStatus].label : ''}</p>
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
        {step < 5 ? (
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
